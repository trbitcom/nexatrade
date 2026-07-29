<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

// Admin panelinden degistirilebilen genel ayarlar (OpenAI anahtari, webhook/cron token'lari)
// Bir anahtar burada kayitli degilse (veya bos ise) cagiran taraf config/app.php'deki degere geri dusmelidir
final class Setting
{
    public static function get(string $key): ?string
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT value FROM app_settings WHERE `key` = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null || $value === '' ? null : (string) $value;
    }

    public static function set(string $key, string $value): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'INSERT INTO app_settings (`key`, `value`) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    // Deger + son guncelleme zamanini birlikte doner; ureten tarafin cache suresi dolmus mu
    // kontrol edebilmesi icin kullanilir (ör. AI piyasa nabzi ozeti her istekte yeniden uretilmesin diye)
    public static function getMeta(string $key): ?array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT `value`, `updated_at` FROM app_settings WHERE `key` = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    // Admin panelinde hangi ayarlarin DB'den geldigini (dosyadan degil) gostermek icin kullanilir
    public static function all(): array
    {
        $pdo = Database::getInstance();

        $rows = $pdo->query('SELECT `key`, `value`, updated_at FROM app_settings')->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = $row;
        }

        return $result;
    }
}
