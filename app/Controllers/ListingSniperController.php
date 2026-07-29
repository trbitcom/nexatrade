<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CronLock;
use App\Models\Setting;
use App\Services\BinanceApiTimeoutException;
use App\Services\ListingSniperService;
use Throwable;

// cPanel Cron Job tarafindan, ana /api/auto-trade/run'dan cok daha sik (ör. her 1 dakikada bir)
// tetiklenmesi onerilir - Duyuru Avcisi'nin "milisaniyeler icinde tepki ver" gereksinimini
// ayri, hafif bir uc noktaya tasiyarak, ana AI dongusunun agir islerini yavaslatmadan saglar
final class ListingSniperController
{
    // 21 Temmuz'da eklendi - AutoTradeController::CRON_LOCK_NAME ile AYNI amac/mekanizma
    // (bkz. CronLock modeli), bu modul icin BAGIMSIZ kendi kilidi
    private const CRON_LOCK_NAME = 'listing_sniper';
    private const CRON_LOCK_TIMEOUT_SECONDS = 180;

    public function run(): void
    {
        header('Content-Type: application/json');

        if (!$this->isTokenValid()) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Yetkisiz istek: geçersiz sniper token\'ı.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!CronLock::acquire(self::CRON_LOCK_NAME, self::CRON_LOCK_TIMEOUT_SECONDS)) {
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'scan_skipped' => true,
                'reason' => 'Önceki işlem hâlâ devam ediyor (kilit aktif) - bu istek anında sonlandırıldı.',
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        try {
            $service = new ListingSniperService();
            $result = $service->run();

            http_response_code(200);
            echo json_encode(['status' => 'success'] + $result, JSON_UNESCAPED_UNICODE);
        } catch (BinanceApiTimeoutException $e) {
            // 21 Temmuz'da eklendi: Binance API'sinin (ozellikle /api/v3/exchangeInfo gibi buyuk
            // payload'larda) gecici yavasligi/zaman asimi artik "gercek" bir sunucu hatasi (500)
            // olarak DEGIL, beklenen/gecici bir durum olarak ele alinir - bir sonraki cron turunde
            // (Duyuru Avcisi icin genelde 1 dk sonra) kendiliginden duzelir
            error_log('NexaTrade ListingSniperController: Binance API zaman aşımı - ' . $e->getMessage());
            http_response_code(200);
            echo json_encode(['status' => 'error', 'message' => 'API Timeout'], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('NexaTrade ListingSniperController: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Sunucu hatası oluştu.'], JSON_UNESCAPED_UNICODE);
        } finally {
            CronLock::release(self::CRON_LOCK_NAME);
        }
    }

    private function isTokenValid(): bool
    {
        // bkz. AutoFuturesTradeController::isTokenValid() yorumu - CLI/crontab bypass'i AYNI ilke
        if (PHP_SAPI === 'cli') {
            return true;
        }

        $expectedToken = Setting::get('listing_sniper_token');

        if ($expectedToken === null) {
            $config = require __DIR__ . '/../../config/app.php';
            $expectedToken = (string) $config['listing_sniper_token'];
        }

        $providedToken = (string) ($_GET['token'] ?? '');

        return $providedToken !== '' && hash_equals($expectedToken, $providedToken);
    }
}
