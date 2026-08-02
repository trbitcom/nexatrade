<?php

declare(strict_types=1);

// GUVENLIK BARIYERI: backtest.php ile AYNI ilke - CLI disinda calismasin
if (php_sapi_name() !== 'cli') {
    die('Sadece CLI üzerinden çalıştırılabilir!');
}

// 2 Agustos'ta eklendi: GIGGLEUSDT canli olayi (skor 100, MACD/RSI/hacim hepsi saglikli, yine de
// giristen dakikalar sonra -%5 dustu) sonrasi "girişte hiç volatilite/ATR filtresi yok, sadece
// trend yönü bakılıyor" tespiti test ediliyor. TEK ORNEKLE karar vermemek icin: TUM kapanan
// islemlerin giris anindaki (opened_at'tan ONCEKI 24 saatlik) gercek fiyat aralaigini Binance'in
// GECMIS kline verisinden hesaplayip, "yuksek oynaklikta girilen islemler gercekten daha mi cok
// kaybediyor" sorusuna GERCEK veriyle cevap arar. Sadece OKUR - hicbir alim/satim/DB yazimi yapmaz
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

use App\Core\Database;

$pdo = Database::getInstance();

$stmt = $pdo->query(
    "SELECT id, pair, entry_price, status, opened_at
     FROM active_trades
     WHERE status IN ('closed_profit', 'closed_loss')
     ORDER BY opened_at ASC"
);
$trades = $stmt->fetchAll();

echo "Toplam işlem: " . count($trades) . "\n";
echo "Her işlem için giriş öncesi 24s fiyat aralığı Binance'ten çekiliyor (yaklaşık " . round(count($trades) * 0.2 / 60, 1) . " dk sürebilir)...\n\n";

function fetchRangePercent(string $pair, string $openedAt, float $entryPrice): ?float
{
    $endTime = strtotime($openedAt) * 1000;
    $startTime = $endTime - (24 * 60 * 60 * 1000);

    $url = 'https://api.binance.com/api/v3/klines?' . http_build_query([
        'symbol' => strtoupper($pair),
        'interval' => '1h',
        'startTime' => $startTime,
        'endTime' => $endTime,
        'limit' => 24,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; NexaTradeBacktest/1.0)',
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false) {
        return null;
    }

    $klines = json_decode($response, true);

    if (!is_array($klines) || $klines === []) {
        return null;
    }

    $high = null;
    $low = null;

    foreach ($klines as $k) {
        $h = (float) $k[2];
        $l = (float) $k[3];
        $high = $high === null ? $h : max($high, $h);
        $low = $low === null ? $l : min($low, $l);
    }

    if ($high === null || $low === null || $entryPrice <= 0) {
        return null;
    }

    return (($high - $low) / $entryPrice) * 100;
}

$buckets = [
    '<5%'   => ['total' => 0, 'wins' => 0],
    '5-10%' => ['total' => 0, 'wins' => 0],
    '10-15%' => ['total' => 0, 'wins' => 0],
    '15-20%' => ['total' => 0, 'wins' => 0],
    '20%+'  => ['total' => 0, 'wins' => 0],
];

$processed = 0;
$skipped = 0;

foreach ($trades as $trade) {
    $rangePct = fetchRangePercent((string) $trade['pair'], (string) $trade['opened_at'], (float) $trade['entry_price']);

    if ($rangePct === null) {
        $skipped++;
        usleep(150000);
        continue;
    }

    $bucket = $rangePct < 5 ? '<5%'
        : ($rangePct < 10 ? '5-10%'
        : ($rangePct < 15 ? '10-15%'
        : ($rangePct < 20 ? '15-20%'
        : '20%+')));

    $buckets[$bucket]['total']++;
    if ($trade['status'] === 'closed_profit') {
        $buckets[$bucket]['wins']++;
    }

    $processed++;
    usleep(150000);
}

echo "İşlenen: {$processed}, atlanan (veri alınamadı): {$skipped}\n\n";
printf("%-10s %8s %8s %8s\n", "Aralık", "Toplam", "Kazanan", "Oran%");
echo str_repeat('-', 40) . "\n";

foreach ($buckets as $label => $data) {
    if ($data['total'] === 0) {
        continue;
    }
    $winRate = round($data['wins'] / $data['total'] * 100, 1);
    printf("%-10s %8d %8d %7.1f%%\n", $label, $data['total'], $data['wins'], $winRate);
}
