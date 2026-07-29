<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CronLock;
use App\Models\Setting;
use App\Services\FuturesTradingService;
use Throwable;

// cPanel Cron Job tarafindan periyodik olarak tetiklenir (ör. her 15 dakikada bir, ana
// /api/auto-trade/run ile ayni sikta). Ana AI Avci (spot/UZUN) dongusunden tamamen BAGIMSIZ
// calisir - bu uc nokta sadece futures_trading_enabled=1 olan opt-in kullanicilari etkiler
final class AutoFuturesTradeController
{
    // 21 Temmuz'da eklendi - AutoTradeController::CRON_LOCK_NAME ile AYNI amac/mekanizma
    // (bkz. CronLock modeli), bu modul icin BAGIMSIZ kendi kilidi
    private const CRON_LOCK_NAME = 'futures_trade';
    private const CRON_LOCK_TIMEOUT_SECONDS = 180;

    public function run(): void
    {
        header('Content-Type: application/json');

        if (!$this->isTokenValid()) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Yetkisiz istek: geçersiz futures token\'ı.'], JSON_UNESCAPED_UNICODE);
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
            $service = new FuturesTradingService();
            $result = $service->run();

            http_response_code(200);
            echo json_encode(['status' => 'success'] + $result, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('NexaTrade AutoFuturesTradeController: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Sunucu hatası oluştu.'], JSON_UNESCAPED_UNICODE);
        } finally {
            CronLock::release(self::CRON_LOCK_NAME);
        }
    }

    private function isTokenValid(): bool
    {
        // 28 Temmuz'da eklendi (VPS/crontab gecisi): CLI'dan (sunucunun kendi crontab'i) tetiklenirse
        // token kontrolu atlanir - PHP_SAPI sunucu tarafindan belirlenir, bir HTTP istegi bunu asla
        // 'cli' olarak taklit edemez, bu yuzden guvenli bir bypass'tir. Web'den gelen istekler icin
        // token kontrolu AYNEN devam eder
        if (PHP_SAPI === 'cli') {
            return true;
        }

        $expectedToken = Setting::get('futures_trading_token');

        if ($expectedToken === null) {
            $config = require __DIR__ . '/../../config/app.php';
            $expectedToken = (string) $config['futures_trading_token'];
        }

        $providedToken = (string) ($_GET['token'] ?? '');

        return $providedToken !== '' && hash_equals($expectedToken, $providedToken);
    }
}
