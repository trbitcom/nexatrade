<?php

declare(strict_types=1);

// GÜVENLİK BARİYERİ: proje kökü/public disindaki bu script, .htaccess'in SADECE app/, config/,
// database.sql'i engellemesi nedeniyle (scripts/ ayrica engellenmedigi icin) dogrudan URL ile
// cagrilabiliyordu (web SAPI'de $argc/$argv olmadigi icin 500 hatasi veriyordu, ama yine de PHP
// tarafindan calistiriliyordu - istenmeyen bir yuzey). Sadece CLI'dan calisabilir hale getirildi.
if (php_sapi_name() !== 'cli') {
    die('Sadece CLI üzerinden çalıştırılabilir!');
}

// 24 Temmuz'da BacktestService::run()'i CAGIRACAK sekilde refactor edildi - eskiden burada AYNI
// mekanik filtrelerin (hacim esigi, RSI, BTC trend, Zirve Yakinligi) TAM BAGIMSIZ bir kopyasi
// vardi, ve senkronsuz kalmisti (RSI 70.0'da kilitli kalmisti, BacktestService 75.0'a
// guncellenmisti; Zirve Yakinligi/isNear24hHigh hic yoktu; komisyon dusumu de yoktu). Artik TEK
// bir kural motoru var (BacktestService + RiskManagerService::isNear24hHigh()) - bu script sadece
// ince bir CLI sarmalayici, admin panelindeki BacktestController ile AYNI kaynagi kullanir
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

// Gecmis fiyat verisiyle, AutoTradeController'daki MEKANIK filtrelerin (hacim esigi, asiri-pump
// limiti, RSI, BTC trend filtresi, Zirve Yakinligi) + sabit kar-al/zarar-kes yuzdelerinin gecmiste
// nasil sonuc verecegini simule eder. Canli OpenAI cagrisini tekrar tekrar yapmak pahali/yavas
// olacagi icin AI skoru BU SCRIPTTE YOK - sadece "mekanik kurallar sinyal verseydi, sonuc ne
// olurdu" sorusuna cevap verir. Gercek botta AI skoru da ayrica bir filtredir, bu yuzden gercek
// sonuclar burada simule edilenden daha SEÇICİ (daha az islem) olabilir.
//
// Kullanim: php scripts/backtest.php SEMBOL [GUN_SAYISI] [KAR_AL_YUZDE] [ZARAR_KES_YUZDE] [ZIRVE_MESAFESI=0]
// Ornek:    php scripts/backtest.php TLMUSDT 90 20 10
// Ornek (27 Temmuz'da eklenen deneysel Zirve Mesafesi filtresiyle): php scripts/backtest.php ZAMAUSDT 30 5 5 1

if ($argc < 2) {
    fwrite(STDERR, "Kullanim: php scripts/backtest.php SEMBOL [GUN_SAYISI=90] [KAR_AL_YUZDE=20] [ZARAR_KES_YUZDE=10] [ZIRVE_MESAFESI=0]\n");
    exit(1);
}

$symbol = strtoupper($argv[1]);
$days = isset($argv[2]) ? (int) $argv[2] : 90;
$takeProfitPercent = isset($argv[3]) ? (float) $argv[3] : 20.0;
$stopLossPercent = isset($argv[4]) ? (float) $argv[4] : 10.0;
// 27 Temmuz'da eklendi: ZAMAUSDT #186 zarar sonrasi eklenen deneysel "Zirve Mesafesi" filtresini
// (bkz. BacktestService::RECENT_PEAK_*) acip kapatmak icin - varsayilan kapali (0), eski davranis degismez
$applyRecentPeakFilter = isset($argv[5]) && $argv[5] === '1';

echo "=== Backtest: {$symbol} | son {$days} gun | Kar Al %{$takeProfitPercent} / Zarar Kes %{$stopLossPercent} ===\n";
echo "(Not: Bu sadece mekanik filtreleri test eder - gercek botta AI skoru da ayrica devreye girer)\n";
echo 'Zirve Mesafesi filtresi: ' . ($applyRecentPeakFilter ? 'AKTIF' : 'kapali') . "\n\n";
echo "Veri cekiliyor...\n";

try {
    $result = (new BacktestService())->run($symbol, $days, $takeProfitPercent, $stopLossPercent, null, $applyRecentPeakFilter);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if ($result['total_trades'] === 0) {
    echo "Bu donemde hicbir zaman filtrelerin hepsini gecen bir sinyal olusmadi.\n";
    exit(0);
}

echo "\n--- Sonuc ---\n";
echo "Toplam simule islem  : {$result['total_trades']}\n";
echo "Kazanan              : {$result['wins']}\n";
echo "Kaybeden             : {$result['losses']}\n";
echo "Kazanma orani         : %{$result['win_rate']}\n";
echo "Ortalama sonuclanma   : {$result['avg_hours_to_resolve']} saat\n";
echo "Komisyon (giris+cikis): %{$result['fee_percent']}\n";
echo "Net kumulatif getiri  : %{$result['cumulative_return_percent']}\n";
