<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CronLock;
use App\Models\Setting;
use App\Services\SmartMoneyTracker;
use Throwable;

// cPanel Cron Job tarafindan orta sikte (ör. her 5 dakikada bir) tetiklenmesi onerilir -
// Etherscan sorgulari ana AI dongusunden bagimsiz, kendi hafif uc noktasinda calisir
final class SmartMoneyController
{
    // 21 Temmuz'da eklendi - AutoTradeController::CRON_LOCK_NAME ile AYNI amac/mekanizma
    // (bkz. CronLock modeli), bu modul icin BAGIMSIZ kendi kilidi
    private const CRON_LOCK_NAME = 'smart_money';
    private const CRON_LOCK_TIMEOUT_SECONDS = 180;

    public function run(): void
    {
        header('Content-Type: application/json');

        if (!$this->isTokenValid()) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Yetkisiz istek: geçersiz token.'], JSON_UNESCAPED_UNICODE);
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
            $tracker = new SmartMoneyTracker();
            $result = $tracker->run();

            http_response_code(200);
            echo json_encode(['status' => 'success'] + $result, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('NexaTrade SmartMoneyController: ' . $e->getMessage());
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

        $expectedToken = Setting::get('smart_money_token');

        if ($expectedToken === null) {
            $config = require __DIR__ . '/../../config/app.php';
            $expectedToken = (string) $config['smart_money_token'];
        }

        $providedToken = (string) ($_GET['token'] ?? '');

        return $providedToken !== '' && hash_equals($expectedToken, $providedToken);
    }
}
