<?php

declare(strict_types=1);

namespace App\Core;

// Guvenli oturum baslatma ve yonetim islemlerini tek noktadan saglar
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'cookie_secure' => self::isHttps(),
                'use_strict_mode' => true,
            ]);
        }
    }

    // 16 Temmuz'da tespit edildi: cookie_secure hic ayarlanmiyordu (varsayilan kapali) - canli
    // ortam HTTPS olsa bile, oturum cerezi (user_id/user_role tasir) duz metin HTTP uzerinden de
    // gonderilebilir kaliyordu (ör. bir HTTPS yonlendirmesi devreye girmeden once). Sabit `true`
    // verilemez - yerel XAMPP gelistirme ortami HTTP uzerinden calisir, bu durumda cerez hic
    // gonderilmez ve giris tamamen bozulurdu. Bu yuzden ortami CANLI tespit edilir
    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        // cPanel/LiteSpeed bir ters proxy arkasindaysa gercek protokolu bu basliktan okur
        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();

        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        self::start();

        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }
}
