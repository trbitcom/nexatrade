<?php

declare(strict_types=1);

// GÜVENLİK BARİYERİ: scripts/backtest.php ile AYNI gerekce - .htaccess sadece app/, config/,
// database.sql'i engelliyor, scripts/ korumasiz - sadece CLI'dan calisabilir hale getirildi.
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

// "TP/SL Optimizasyonu": scripts/optimize_thresholds.php'nin (24 Temmuz) devami - o tur, esik
// filtresinin zarari ~%50 azalttigini ama SABIT %2.0 TP / %1.5 SL orani + komisyon (%0.2) yuzunden
// hicbir esigin (0/40 senaryo) pozitife GECEMEDIGINI ortaya cikardi. Odak esikten TP/SL oranina
// kaydirildi - technicalScoreThreshold burada SABIT tutulur (asagidaki sonuca gore secildi),
// TP/SL degerleri taranir. Amac: bu cIplak (mekanik-filtre-sadece) backtest ortaminda bile
// komisyonu yenip net pozitife gecen bir TP/SL ikilisi var mi, varsa hangisi.
//
// TECHNICAL_SCORE_THRESHOLD = 70 SECILDI (65 DEGIL): 24 Temmuz'un genel ozet tablosunda esik 70
// (toplam -158.69 USDT) NEREDEYSE HER coinde esik 65'ten (-180.68) daha iyi cikti - 65 aslinda
// esik 60'tan (-173.20) bile kotuydu. "En stabil" ikisi degil, TEK basina 70.
const TECHNICAL_SCORE_THRESHOLD = 70;

// Kullanim: php scripts/optimize_tpsl.php [GUN_SAYISI=90]
$days = isset($argv[1]) ? (int) $argv[1] : 90;

$symbols = ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT', 'ADAUSDT', 'AVAXUSDT', 'DOGEUSDT'];
$tpValues = [2.5, 3.0, 4.0, 5.0];
$slValues = [1.0, 1.2, 1.5, 2.0];

// Risk/Odul oranini artirmak icin taranacak kombinasyonlar - SADECE TP > SL olanlar calisir
// (su anki deger araliklarinda tumu zaten gecerli, min TP=2.5 > max SL=2.0 - ileride daha kucuk
// TP/daha buyuk SL denenirse bu kontrol devreye girer)
$combos = [];
foreach ($tpValues as $tp) {
    foreach ($slValues as $sl) {
        if ($tp > $sl) {
            $combos[] = ['tp' => $tp, 'sl' => $sl, 'label' => "TP{$tp}/SL{$sl}"];
        }
    }
}

echo "=== TP/SL Optimizasyonu | son {$days} gün | technicalScoreThreshold=" . TECHNICAL_SCORE_THRESHOLD . " (sabit) ===\n";
echo "(Not: gerçek AI skoru BacktestService'te yok, bkz. dosya başı yorumu - " . count($combos) . " kombinasyon × " . count($symbols) . " coin = " . (count($combos) * count($symbols)) . " çalıştırma)\n\n";

$cumulative = [];
foreach ($combos as $combo) {
    $cumulative[$combo['label']] = ['trades' => 0, 'pnl' => 0.0];
}

$service = new BacktestService();
$rows = [];

foreach ($symbols as $symbol) {
    foreach ($combos as $combo) {
        fwrite(STDERR, "Çalışıyor: {$symbol} / {$combo['label']}...\n");

        try {
            $result = $service->run($symbol, $days, $combo['tp'], $combo['sl'], TECHNICAL_SCORE_THRESHOLD);
        } catch (Throwable $e) {
            $rows[] = [
                'senaryo' => "{$symbol} / {$combo['label']}",
                'toplam' => '-',
                'kazanan' => '-',
                'oran' => '-',
                'pnl' => 'HATA: ' . $e->getMessage(),
            ];
            continue;
        }

        $rows[] = [
            'senaryo' => "{$symbol} / {$combo['label']}",
            'toplam' => (string) $result['total_trades'],
            'kazanan' => (string) $result['wins'],
            'oran' => $result['win_rate'] === null ? '-' : number_format($result['win_rate'], 1),
            'pnl' => $result['cumulative_return_percent'] === null ? '-' : number_format($result['cumulative_return_percent'], 2),
        ];

        $cumulative[$combo['label']]['trades'] += $result['total_trades'];
        $cumulative[$combo['label']]['pnl'] += $result['cumulative_return_percent'] ?? 0.0;
    }
}

// Sabit genislikli, hizalanmis CLI tablosu - basit str_pad ile (ek bir kutuphane gerekmez)
$columns = [
    'senaryo' => ['baslik' => 'TP/SL Senaryosu', 'genislik' => 24],
    'toplam' => ['baslik' => 'Toplam İşlem', 'genislik' => 14],
    'kazanan' => ['baslik' => 'Kazanan', 'genislik' => 9],
    'oran' => ['baslik' => 'Kazanma Oranı (%)', 'genislik' => 18],
    'pnl' => ['baslik' => 'Net PNL (USDT)', 'genislik' => 18],
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

// Genel özet: her TP/SL kombinasyonunun TÜM coinler genelindeki toplamı - "altın oran" hangisi
// sorusunun cevabı buradan okunmalı, tek tek coin satırlarından değil
$summaryColumns = [
    'kombinasyon' => ['baslik' => 'TP/SL Kombinasyonu', 'genislik' => 24],
    'trades' => ['baslik' => 'Toplam İşlem (Tüm Coinler)', 'genislik' => 26],
    'pnl' => ['baslik' => 'Kümülatif Net PNL (Tüm Coinler)', 'genislik' => 30],
];

echo "\n=== Genel Özet (TP/SL Kombinasyonu Başına Tüm Coinlerin Toplamı) ===\n\n";
$headerLine = '';
$separatorLine = '';
foreach ($summaryColumns as $col) {
    $headerLine .= '| ' . str_pad($col['baslik'], $col['genislik']) . ' ';
    $separatorLine .= '|' . str_repeat('-', $col['genislik'] + 2);
}
echo $headerLine . "|\n";
echo $separatorLine . "|\n";

foreach ($cumulative as $label => $totals) {
    $line = '| ' . str_pad((string) $label, $summaryColumns['kombinasyon']['genislik']) . ' ';
    $line .= '| ' . str_pad((string) $totals['trades'], $summaryColumns['trades']['genislik']) . ' ';
    $line .= '| ' . str_pad(number_format($totals['pnl'], 2), $summaryColumns['pnl']['genislik']) . ' ';
    echo $line . "|\n";
}
