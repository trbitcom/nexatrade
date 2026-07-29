<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

// Singleton: uygulama boyunca tek bir PDO baglantisi kullanilmasini saglar
final class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

    private function __construct()
    {
        $config = require __DIR__ . '/../../config/database.php';
        $db = $config['db'];

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $db['host'],
            $db['dbname'],
            $db['charset']
        );

        try {
            $this->connection = new PDO($dsn, $db['username'], $db['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            $this->logError($e->getMessage());
            throw new PDOException('Veritabani baglantisi kurulamadi.');
        }
    }

    // Disaridan "new" ile ornek olusturulmasini engeller
    private function __clone(): void
    {
    }

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance->connection;
    }

    // 22 Temmuz'da canli tespit edildi: AutoTradeController::run() gibi uzun suren cron istekleri
    // (set_time_limit(180), her aday icin 12sn Pullback Kalkani beklemesi + Binance/OpenAI-Groq-Gemini
    // API cagrilari) siklikla DB'ye HIC dokunmadan onlarca saniye geciriyor - bu sure paylasimli
    // hosting MySQL'inin wait_timeout'unu (genelde dusuk, 60-120sn) asarsa sunucu baglantiyi
    // SESSIZCE kapatiyor. Tek bir PDO nesnesi omur boyu (singleton) yeniden kullanildigi icin bir
    // sonraki sorgu "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away" ile patliyor -
    // bu, run() genelinde YAKALANAMAYAN bir hata degil (Throwable), ama TUM turu (o anki taramanin
    // geri kalanini) iptal ediyordu. Uzun bekleme/dis API cagrisi ICEREN dongulerin basinda
    // cagirilmasi onerilir - baglanti oldugu gibi calisirsa hicbir ek maliyeti yok (ucuz SELECT 1),
    // kopmussa SESSIZCE yeniden kurar
    public static function ensureConnected(): void
    {
        if (self::$instance === null) {
            self::getInstance();

            return;
        }

        try {
            self::$instance->connection->query('SELECT 1');
        } catch (PDOException $e) {
            self::$instance = null;
            self::getInstance();
        }
    }

    // Baglanti hatalarini dosyaya kaydeder
    private function logError(string $message): void
    {
        $logDir = __DIR__ . '/../../storage/logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logMessage = sprintf('[%s] DB Baglanti Hatasi: %s%s', date('Y-m-d H:i:s'), $message, PHP_EOL);
        file_put_contents($logDir . '/error.log', $logMessage, FILE_APPEND);
    }
}
