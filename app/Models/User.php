<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class User
{
    public static function findByEmail(string $email): array|false
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);

        return $stmt->fetch();
    }

    public static function findById(int $id): array|false
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch();
    }

    public static function verifyPassword(string $plainPassword, string $hashedPassword): bool
    {
        return password_verify($plainPassword, $hashedPassword);
    }

    // Yeni kullanici kaydi olusturur; sifreyi hicbir zaman duz metin olarak saklamaz
    // Hesap 'passive' (onay bekliyor) olarak baslar - admin panelinden onaylanmadan giris yapilamaz
    public static function create(string $name, string $email, string $plainPassword): int
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password, role, status) VALUES (:name, :email, :password, :role, :status)'
        );

        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':password' => password_hash($plainPassword, PASSWORD_DEFAULT),
            ':role' => 'user',
            ':status' => 'passive',
        ]);

        return (int) $pdo->lastInsertId();
    }

    // --- Admin paneli icin ---

    // Kullanicilari, API anahtari/AI Avci durumu ve acik pozisyon sayisiyla birlikte getirir
    // Onemli: API anahtarinin kendisi (sifreli bile olsa) hicbir zaman bu sorguya dahil edilmez -
    // admin baska bir kullanicinin borsa kimlik bilgilerini goremez, sadece "var/yok" bilgisini gorur
    public static function findAllWithStats(): array
    {
        $pdo = Database::getInstance();

        return $pdo->query(
            "SELECT
                u.id, u.name, u.email, u.role, u.status, u.created_at,
                u.is_risk_accepted, u.risk_accepted_at,
                (uak.id IS NOT NULL) AS has_api_key,
                COALESCE(uak.auto_trade_enabled, 0) AS auto_trade_enabled,
                COALESCE(uak.auto_trade_budget_percent, 10.0) AS auto_trade_budget_percent,
                (SELECT COUNT(*) FROM active_trades t WHERE t.user_id = u.id AND t.status = 'open') AS open_positions,
                (uak.circuit_breaker_until IS NOT NULL AND uak.circuit_breaker_until > NOW()) AS circuit_breaker_active,
                DATE_FORMAT(uak.circuit_breaker_until, '%d.%m.%Y %H:%i') AS circuit_breaker_until_formatted
             FROM users u
             LEFT JOIN user_api_keys uak ON uak.user_id = u.id
             ORDER BY u.created_at DESC"
        )->fetchAll();
    }

    public static function countAll(): int
    {
        $pdo = Database::getInstance();

        return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public static function updateStatus(int $userId, string $status): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('UPDATE users SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $userId]);
    }

    public static function updateRole(int $userId, string $role): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
        $stmt->execute([':role' => $role, ':id' => $userId]);
    }

    // Kullaniciyi ve ON DELETE CASCADE ile bagli TUM verilerini (API anahtarlari, siparisler,
    // acik pozisyonlar) kalici olarak siler - geri alinamaz, cagiran taraf (AdminController)
    // kendi hesabini silmeyi ayrica engeller
    public static function delete(int $userId): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
    }

    // Kullanicinin kendi ad/e-posta bilgilerini gunceller (Dashboard > Hesap Bilgileri)
    public static function updateProfile(int $userId, string $name, string $email): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('UPDATE users SET name = :name, email = :email WHERE id = :id');
        $stmt->execute([':name' => $name, ':email' => $email, ':id' => $userId]);
    }

    // Sifreyi hicbir zaman duz metin olarak saklamaz - hem kullanicinin kendi sifre degisikliginde
    // hem admin'in bir kullaniciyi sifirlamasinda (gormeden) kullanilir
    public static function updatePassword(int $userId, string $plainPassword): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
        $stmt->execute([':password' => password_hash($plainPassword, PASSWORD_DEFAULT), ':id' => $userId]);
    }

    // Kullanici, Hos Geldiniz/Risk Bildirimi modalindaki zorunlu onay kutusunu isaretleyip
    // formu gonderdiginde cagrilir - bir daha geri alinamaz/gosterilmez (musteri onboarding)
    public static function acceptRisk(int $userId): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('UPDATE users SET is_risk_accepted = 1, risk_accepted_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $userId]);
    }

    // --- Telegram "Deep Linking" entegrasyonu ---

    // Kullanicinin bir dogrulama tokeni yoksa uretip kaydeder, varsa oldugu gibi doner
    // (Settings sayfasi her acildiginda cagrilir - boylece "Telegram'i Bagla" linki her zaman gecerli bir token tasir)
    public static function ensureTelegramVerifyToken(int $userId): string
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT telegram_verify_token FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $existing = $stmt->fetchColumn();

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        // uniqid() yerine kriptografik olarak guvenli, tahmin edilemez bir token uretilir
        // (baska bir kullanicinin hesabini token tahmin ederek baglama riskine karsi)
        $token = bin2hex(random_bytes(16));

        $update = $pdo->prepare('UPDATE users SET telegram_verify_token = :token WHERE id = :id');
        $update->execute([':token' => $token, ':id' => $userId]);

        return $token;
    }

    public static function findByTelegramVerifyToken(string $token): array|false
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT * FROM users WHERE telegram_verify_token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);

        return $stmt->fetch();
    }

    // Dogrulama basarili oldugunda chat_id'yi kalici olarak baglar ve tek kullanimlik tokeni sifirlar
    public static function linkTelegramChat(int $userId, string $chatId): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE users SET telegram_chat_id = :chat_id, telegram_verify_token = NULL WHERE id = :id'
        );
        $stmt->execute([':chat_id' => $chatId, ':id' => $userId]);
    }

    // AutoTradeController'in bildirimleri dogrudan ilgili musteriye yonlendirebilmesi icin kullanilir
    // Kullanici henuz Telegram'ini baglamadiysa null doner - cagiran taraf bunu admin fallback'i olarak yorumlar
    public static function findTelegramChatId(int $userId): ?string
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT telegram_chat_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $value = $stmt->fetchColumn();

        return ($value === false || $value === null || $value === '') ? null : (string) $value;
    }
}
