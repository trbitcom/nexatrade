<?php

declare(strict_types=1);

namespace App\Services;

// Borsa API anahtarlari gibi hassas verileri AES-256-CBC ile sifreler/cozer
final class Encryption
{
    private const CIPHER_METHOD = 'aes-256-cbc';

    private static ?string $key = null;

    private static function getKey(): string
    {
        if (self::$key === null) {
            $config = require __DIR__ . '/../../config/database.php';
            self::$key = $config['encryption_key'];
        }

        return self::$key;
    }

    public static function encrypt(string $data): string
    {
        $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
        $iv = random_bytes($ivLength);

        $encrypted = openssl_encrypt($data, self::CIPHER_METHOD, self::getKey(), OPENSSL_RAW_DATA, $iv);

        // Cozme islemi icin IV, sifreli verinin basina eklenip birlikte base64 ile saklanir
        return base64_encode($iv . $encrypted);
    }

    public static function decrypt(string $data): string|false
    {
        $decoded = base64_decode($data, true);

        if ($decoded === false) {
            return false;
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
        $iv = substr($decoded, 0, $ivLength);
        $encrypted = substr($decoded, $ivLength);

        return openssl_decrypt($encrypted, self::CIPHER_METHOD, self::getKey(), OPENSSL_RAW_DATA, $iv);
    }
}
