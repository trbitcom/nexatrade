<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Services\BacktestService;
use Throwable;

// Sadece admin erisebilir - gecmis fiyat verisiyle mekanik filtreleri test eder, gercek para/islem
// icermez (salt okunur, dis API'den sadece genel/public kline verisi ceker)
final class BacktestController
{
    public function index(): void
    {
        AuthMiddleware::requireAdmin();

        $result = null;
        $errorMessage = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $symbol = strtoupper(trim((string) ($_POST['symbol'] ?? '')));
            $days = (int) ($_POST['days'] ?? 90);
            $takeProfitPercent = (float) ($_POST['take_profit_percent'] ?? 20.0);
            $stopLossPercent = (float) ($_POST['stop_loss_percent'] ?? 10.0);

            // Bos birakilirsa (varsayilan) teknik motor devreye girmez, sadece mekanik filtreler calisir
            $technicalScoreRaw = trim((string) ($_POST['technical_score_threshold'] ?? ''));
            $technicalScoreThreshold = $technicalScoreRaw === '' ? null : (int) $technicalScoreRaw;

            // 27 Temmuz'da eklendi: deneysel Zirve Mesafesi filtresi (bkz. BacktestService::RECENT_PEAK_*)
            // - varsayilan kapali, kutucuk isaretlenmezse eski davranis degismez
            $applyRecentPeakFilter = isset($_POST['apply_recent_peak_filter']);

            if ($symbol === '') {
                $errorMessage = 'Sembol boş olamaz (ör. TLMUSDT).';
            } elseif ($days < 1 || $days > 365) {
                $errorMessage = 'Gün sayısı 1 ile 365 arasında olmalıdır.';
            } elseif ($takeProfitPercent <= 0 || $stopLossPercent <= 0) {
                $errorMessage = 'Kâr al / zarar kes yüzdeleri 0\'dan büyük olmalıdır.';
            } elseif ($technicalScoreThreshold !== null && ($technicalScoreThreshold < 1 || $technicalScoreThreshold > 100)) {
                $errorMessage = 'Teknik skor eşiği 1 ile 100 arasında olmalıdır.';
            } else {
                try {
                    $service = new BacktestService();
                    $result = $service->run($symbol, $days, $takeProfitPercent, $stopLossPercent, $technicalScoreThreshold, $applyRecentPeakFilter);
                } catch (Throwable $e) {
                    $errorMessage = $e->getMessage();
                }
            }
        }

        require __DIR__ . '/../Views/admin/backtest.php';
    }
}
