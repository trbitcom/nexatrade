<?php

declare(strict_types=1);

// GÜVENLİK BARİYERİ: scripts/backtest.php ile AYNI gerekce - sadece CLI'dan çalışabilir
if (php_sapi_name() !== 'cli') {
    die('Sadece CLI üzerinden çalıştırılabilir!');
}

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../app/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

use App\Services\BacktestService;

// 29 Temmuz'da eklendi: BacktestService::runTrailingStopComparison() ile İKİ farklı İzleyen Stop
// (Aşama 1 Tetik/Kilit) + ortak Mesafe parametresini AYNI coin/dönem üzerinde yan yana karşılaştırır.
// "Gemini'nin Altın Oran" önerisi (Tetik %2.0/Kilit %0.5) ile mevcut canlı ayarı (Tetik %1.5/Kilit
// %1.0) doğrudan gerçek veriyle kıyaslamak için - kanıtsız parametre değişikliği yapılmaz kuralı.
//
// Kullanim: php scripts/compare_trailing_stops.php [GUN=14] [TP=8] [SL=2.5] [MESAFE=1.5]
//           [MEVCUT_TETIK=1.5] [MEVCUT_KILIT=1.0] [ONERI_TETIK=2.0] [ONERI_KILIT=0.5]

$days = isset($argv[1]) ? (int) $argv[1] : 14;
$takeProfitPercent = isset($argv[2]) ? (float) $argv[2] : 8.0;
$stopLossPercent = isset($argv[3]) ? (float) $argv[3] : 2.5;
$distancePercent = isset($argv[4]) ? (float) $argv[4] : 1.5;
$currentTrigger = isset($argv[5]) ? (float) $argv[5] : 1.5;
$currentLock = isset($argv[6]) ? (float) $argv[6] : 1.0;
$proposedTrigger = isset($argv[7]) ? (float) $argv[7] : 2.0;
$proposedLock = isset($argv[8]) ? (float) $argv[8] : 0.5;

// optimize_thresholds.php ile AYNI 8 coin - istatistiksel guvenilirlik icin
$symbols = ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT', 'ADAUSDT', 'AVAXUSDT', 'DOGEUSDT'];

echo "=== İzleyen Stop Karşılaştırması | son {$days} gün | Kâr Al %{$takeProfitPercent} / Zarar Kes %{$stopLossPercent} / Mesafe %{$distancePercent} ===\n";
echo "Mevcut : Tetik %{$currentTrigger} / Kilit %{$currentLock}\n";
echo "Öneri  : Tetik %{$proposedTrigger} / Kilit %{$proposedLock}\n";
echo "(Not: Aşama 2/3 - sabit %4/%2.5 ve %6/%4 - ve Sürekli İzleme her iki senaryoda da AYNI, ATR çarpanı simüle EDİLMEZ - bkz. BacktestService yorumu)\n\n";

$service = new BacktestService();
$rows = [];
$cumulative = ['mevcut' => ['trades' => 0, 'pnl' => 0.0], 'oneri' => ['trades' => 0, 'pnl' => 0.0]];

foreach ($symbols as $symbol) {
    foreach (['mevcut' => [$currentTrigger, $currentLock], 'oneri' => [$proposedTrigger, $proposedLock]] as $label => [$trigger, $lock]) {
        fwrite(STDERR, "Çalışıyor: {$symbol} / {$label}...\n");

        // Her coin/senaryo cifti arasinda daha uzun bir bekleme - ilk denemede (200ms'lik ic
        // bekleme YETERSIZ kaldi) Binance'in genel hiz siniri arka arkaya gelen 1h+BTC ana
        // veri cekimlerini (fine-kline'lardan ONCEKI adim) sessizce reddedip "yeterli veri yok"
        // hatasina yol acti - coin sorunlu degildi, istek sikligi sorunluydu
        sleep(2);

        try {
            $result = $service->runTrailingStopComparison($symbol, $days, $takeProfitPercent, $stopLossPercent, $trigger, $lock, $distancePercent);
        } catch (Throwable $e) {
            $rows[] = ['senaryo' => "{$symbol} / {$label}", 'toplam' => '-', 'kazanan' => '-', 'oran' => '-', 'pnl' => 'HATA: ' . $e->getMessage()];
            continue;
        }

        $rows[] = [
            'senaryo' => "{$symbol} / {$label}",
            'toplam' => (string) $result['total_trades'],
            'kazanan' => (string) $result['wins'],
            'oran' => $result['win_rate'] === null ? '-' : number_format($result['win_rate'], 1),
            'pnl' => $result['cumulative_return_percent'] === null ? '-' : number_format($result['cumulative_return_percent'], 2),
        ];

        $cumulative[$label]['trades'] += $result['total_trades'];
        $cumulative[$label]['pnl'] += $result['cumulative_return_percent'] ?? 0.0;
    }
}

$columns = [
    'senaryo' => ['baslik' => 'Coin/Senaryo', 'genislik' => 20],
    'toplam' => ['baslik' => 'Toplam İşlem', 'genislik' => 14],
    'kazanan' => ['baslik' => 'Kazanan', 'genislik' => 9],
    'oran' => ['baslik' => 'Kazanma Oranı (%)', 'genislik' => 18],
    'pnl' => ['baslik' => 'Net PNL (%)', 'genislik' => 18],
];

echo "\n";
$headerLine = '';
$separatorLine = '';
foreach ($columns as $col) {
    $headerLine .= '| ' . str_pad($col['baslik'], $col['genislik']) . ' ';
    $separatorLine .= '|' . str_repeat('-', $col['genislik'] + 2);
}
echo $headerLine . "|\n";
echo $separatorLine . "|\n";

foreach ($rows as $row) {
    $line = '';
    foreach ($columns as $key => $col) {
        $line .= '| ' . str_pad((string) $row[$key], $col['genislik']) . ' ';
    }
    echo $line . "|\n";
}

echo "\n=== Genel Özet (Tüm Coinlerin Toplamı) ===\n\n";
foreach ($cumulative as $label => $totals) {
    echo str_pad(strtoupper($label), 10) . " -> Toplam İşlem: " . str_pad((string) $totals['trades'], 4) . " | Kümülatif Net PNL: " . number_format($totals['pnl'], 2) . "%\n";
}
