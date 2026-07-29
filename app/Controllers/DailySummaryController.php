<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CronLock;
use App\Models\Setting;
use App\Services\DailySummaryService;
use Throwable;

// cPanel Cron Job tarafindan GUNDE BIR KEZ (00:00) tetiklenmesi icin tasarlandi - ListingSniperController
// ile AYNI token/CronLock deseni, ama SIKLIK tamamen farkli (digerleri dakikalar/saatler, bu gunde bir)
final class DailySummaryController
{
    private const CRON_LOCK_NAME = 'daily_summary';
    private const CRON_LOCK_TIMEOUT_SECONDS = 300;

    public function run(): void
    {
        header('Content-Type: application/json');

        if (!$this->isTokenValid()) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Yetkisiz istek: geçersiz özet token\'ı.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!CronLock::acquire(self::CRON_LOCK_NAME, self::CRON_LOCK_TIMEOUT_SECONDS)) {
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'skipped' => true,
                'reason' => 'Önceki özet gönderimi hâlâ devam ediyor (kilit aktif).',
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        try {
            $service = new DailySummaryService();
            $result = $service->run();

            http_response_code(200);
            echo json_encode(['status' => 'success'] + $result, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('NexaTrade DailySummaryController: ' . $e->getMessage());
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

        $expectedToken = Setting::get('daily_summary_token');

        if ($expectedToken === null) {
            $config = require __DIR__ . '/../../config/app.php';
            $expectedToken = (string) $config['daily_summary_token'];
        }

        $providedToken = (string) ($_GET['token'] ?? '');

        return $providedToken !== '' && hash_equals($expectedToken, $providedToken);
    }
}
