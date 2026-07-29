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

// "Esik Optimizasyonu": BacktestService::run()'in gercek AI skorunu (GPT tabanli, bu serviste
// HIC YOK) DEGIL, TechnicalScoreEngine'in deterministik 1-100 puanini (opsiyonel
// $technicalScoreThreshold parametresi) test edebildigini kullanir - bu, RiskProfileService'teki
// gercek profil esikleriyle (Guvenli=65, Dengeli=55) AYNI 1-100 olcekte, "AI skoru"nun en yakin
// test edilebilir yerine gecen degeri. Amac: her esigin gecmis veride kac islem, ne kazanma orani
// ve ne net PNL urettigini TEK tabloda karsilastirmak.
//
// Kullanim: php scripts/optimize_thresholds.php [GUN_SAYISI=90] [KAR_AL_YUZDE=2.0] [ZARAR_KES_YUZDE=1.5]

$days = isset($argv[1]) ? (int) $argv[1] : 90;
$takeProfitPercent = isset($argv[2]) ? (float) $argv[2] : 2.0;
$stopLossPercent = isset($argv[3]) ? (float) $argv[3] : 1.5;

// 24 Temmuz'da 3'ten 8 coine genisletildi: istatistiksel guvenilirligi artirmak (daha fazla ornek)
// ve farkli volatilite karakterlerini (majors, orta-buyuk altcoin, meme) kapsamak icin
$symbols = ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT', 'ADAUSDT', 'AVAXUSDT', 'DOGEUSDT'];
$thresholds = [null, 55, 60, 65, 70];

// Esik basina TUM coinlerin toplamini tutar - "en iyi esik TEK bir coin icin degil, genel havuz
// icin hangisi" sorusuna cevap verir. Sadece basarili (hataya dusmemis) senaryolar toplanir
$cumulative = [];
foreach ($thresholds as $threshold) {
    $label = $threshold === null ? 'filtresiz' : (string) $threshold;
    $cumulative[$label] = ['trades' => 0, 'pnl' => 0.0];
}

echo "=== Eşik Optimizasyonu | son {$days} gün | Kâr Al %{$takeProfitPercent} / Zarar Kes %{$stopLossPercent} ===\n";
echo "(Not: technicalScoreThreshold test edilir - gerçek AI skoru BacktestService'te yok, bkz. dosya başı yorumu)\n\n";

$service = new BacktestService();
$rows = [];

foreach ($symbols as $symbol) {
    foreach ($thresholds as $threshold) {
        $label = $threshold === null ? 'filtresiz' : (string) $threshold;
        fwrite(STDERR, "Çalışıyor: {$symbol} / eşik={$label}...\n");

        try {
            $result = $service->run($symbol, $days, $takeProfitPercent, $stopLossPercent, $threshold);
        } catch (Throwable $e) {
            $rows[] = [
                'senaryo' => "{$symbol} / {$label}",
                'toplam' => '-',
                'kazanan' => '-',
                'oran' => '-',
                'pnl' => 'HATA: ' . $e->getMessage(),
            ];
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

// Sabit genislikli, hizalanmis CLI tablosu - basit str_pad ile (ek bir kutuphane gerekmez)
$columns = [
    'senaryo' => ['baslik' => 'Eşik/Senaryo', 'genislik' => 22],
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

// Genel özet: her eşiğin TÜM coinler genelindeki toplamı - "en iyi eşik hangisi" sorusunun
// havuz-genelindeki cevabı, tek tek coin satırlarından değil buradan okunmalı
$summaryColumns = [
    'esik' => ['baslik' => 'Eşik', 'genislik' => 22],
    'trades' => ['baslik' => 'Toplam İşlem (Tüm Coinler)', 'genislik' => 26],
    'pnl' => ['baslik' => 'Kümülatif Net PNL (Tüm Coinler)', 'genislik' => 30],
];

echo "\n=== Genel Özet (Eşik Başına Tüm Coinlerin Toplamı) ===\n\n";
$headerLine = '';
$separatorLine = '';
foreach ($summaryColumns as $col) {
    $headerLine .= '| ' . str_pad($col['baslik'], $col['genislik']) . ' ';
    $separatorLine .= '|' . str_repeat('-', $col['genislik'] + 2);
}
echo $headerLine . "|\n";
echo $separatorLine . "|\n";

foreach ($cumulative as $label => $totals) {
    // PHP'nin bilinen davranisi: sayisal gorunumlu string anahtarlar ("55") dizi anahtari olarak
    // KULLANILDIGINDA otomatik int'e donusur - $label burada int gelebilir, str_pad strict_types
    // altinda bunu kabul etmez, acikca (string) cast gerekir
    $line = '| ' . str_pad((string) $label, $summaryColumns['esik']['genislik']) . ' ';
    $line .= '| ' . str_pad((string) $totals['trades'], $summaryColumns['trades']['genislik']) . ' ';
    $line .= '| ' . str_pad(number_format($totals['pnl'], 2), $summaryColumns['pnl']['genislik']) . ' ';
    echo $line . "|\n";
}
