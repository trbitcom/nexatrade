<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

// Gecmis fiyat verisiyle, AutoTradeController/MarketScanner'daki MEKANIK filtrelerin (hacim esigi,
// asiri-pump limiti, RSI, BTC trend filtresi) + sabit kar-al/zarar-kes yuzdelerinin gecmiste nasil
// sonuc verecegini simule eder. Canli OpenAI cagrisini tekrar tekrar yapmak pahali/yavas olacagi icin
// AI skoru BU SERVISTE YOK - sadece "mekanik kurallar sinyal verseydi, sonuc ne olurdu" sorusuna
// cevap verir. Gercek botta AI skoru da ayrica bir filtredir, bu yuzden gercek sonuclar burada
// simule edilenden daha SEÇICİ (daha az islem) olabilir. scripts/backtest.php (CLI) ile ayni mantik -
// admin panelinden calistirilabilmesi icin yeniden kullanilabilir bir sinifa tasindi.
final class BacktestService
{
    // AutoTradeController/MarketScanner ile AYNI esikler - kurallarin tutarli test edilmesi icin
    private const MIN_QUOTE_VOLUME = 5_000_000.0;
    private const MAX_ALREADY_PUMPED_PERCENT = 25.0;
    private const BTC_DOWNTREND_THRESHOLD_PERCENT = -3.0;
    // 16 Temmuz'da 70'ten 75'e guncellendi: AutoTradeController 15 Temmuz'da (islem sikligi dusuk
    // bulunup) 75'e gevsetilmisti, backtest bunu takip etmiyordu - laboratuvar (backtest) ile saha
    // (canli) FARKLI esiklerle calisiyordu, sonuclar birebir karsilastirilamiyordu
    private const RSI_OVERBOUGHT_THRESHOLD = 75.0;
    // AutoTradeController::NEAR_24H_HIGH_THRESHOLD_PERCENT ile AYNI deger - kontrol RiskManagerService::
    // isNear24hHigh() SAF (stateless) fonksiyonu uzerinden yapilir, iki dosyada ayri mantik YAZILMAZ.
    // 98.0'dan 99.0'a GEVSETILDI (24 Temmuz) - AutoTradeController'daki AYNI degisiklikle senkron
    // tutulur, aksi halde laboratuvar (backtest) ile saha (canli) FARKLI esiklerle calisir
    private const NEAR_24H_HIGH_THRESHOLD_PERCENT = 99.0;
    // 27 Temmuz'da eklendi: ZAMAUSDT #186 zarar sonrasi tespit edilen "zirveden dususun ORTASINDA
    // alim" riskini test etmek icin - AutoTradeController::RECENT_PEAK_* ile AYNI degerler,
    // RiskManagerService::isFarBelowRecentPeak() SAF fonksiyonu uzerinden. HENUZ CANLIYA
    // TASINMADI - once bu backtest sonucuyla gercekten ise yarayip yaramadigi kanitlanmali
    // (bkz. proje kulturu: kanitsiz esik degisikligi yapilmaz). Baslangic degerleri BILEREK
    // ZAMAUSDT'yi (5 gunde %12.7 dusus) YAKALAYACAK sekilde secildi ki backtest anlamli olsun
    private const RECENT_PEAK_LOOKBACK_DAYS = 5;
    private const RECENT_PEAK_MAX_DROP_PERCENT = 10.0;
    private const RSI_PERIOD = 14;
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const TIMEOUT_SECONDS = 15;
    private const VOLUME_TREND_TOLERANCE_PERCENT = 15.0;
    // Binance spot taker komisyonu (BNB indirimsiz) - giris + cikis = 2 x %0.1. Gercek botta da
    // ayni oranda kesiliyor, backtest sonucu bunu dusmezse kar rakami gercekte olduğundan yuksek gorunur
    private const ROUND_TRIP_FEE_PERCENT = 0.2;

    // @param int|null $technicalScoreThreshold Verilirse, TechnicalScoreEngine'in belirleyici
    // (deterministik) skoru bu esigi gecmeyen adaylar da elenir - GPT'nin yerini alacak/destekleyecek
    // kural motorunun gecmis veride GERCEKTEN ise yarayip yaramadigini kanitlamak icin kullanilir
    // @param int|null $recentPeakLookbackHours 28 Temmuz'da eklendi: verilirse Zirve Mesafesi
    // filtresi (applyRecentPeakFilter) RECENT_PEAK_LOOKBACK_DAYS*24 (varsayilan 120 saat/5 gun)
    // yerine BU saat sayisini kullanir - PUMPUSDT #194 canli olayindan sonra (son 1-2 saatlik
    // YEREL zirveden dususun ortasinda alim) kisa vadeli bir pencereyi (ör. 2 saat) ayni
    // ilk denemeden (5 gunluk, fayda BULUNAMAMISTI) BAGIMSIZ test edebilmek icin
    // @param float|null $recentPeakMaxDropPercent Verilirse RECENT_PEAK_MAX_DROP_PERCENT (varsayilan
    // %10) yerine bu yuzdeyi kullanir - kisa pencerede daha kucuk bir dusus (ör. %1.5-2) anlamli olabilir
    // @param bool $requireMacdPositive 28 Temmuz'da eklendi: $macdHistogramSeries ONCEDEN hesaplaniyordu
    // ama HICBIR YERDE kullanilmiyordu - yani backtest simdiye kadar AutoTradeController'in canli
    // MACD kapisini (deterministicPass'in bir parcasi) HIC uygulamamisti, laboratuvar/saha burada
    // sessizce SENKRONSUZDU. Varsayilan false (eski/mevcut davranisi KORUR, scripts/backtest.php gibi
    // mevcut cagiranlari sessizce degistirmez) - true verilirse MACD histogrami pozitif olmayan
    // adaylar da (skor esigi ne olursa olsun) elenir, boylece "MACD kapisini kaldirsak ne olur"
    // sorusu AYNI donem/coin uzerinde iki ayri calistirmayla dogrudan karsilastirilabilir
    // @param bool $applyRecentPeakFilter true verilirse, RECENT_PEAK_LOOKBACK_DAYS gunun zirvesinden
    // RECENT_PEAK_MAX_DROP_PERCENT'ten fazla asagida olan adaylar da elenir (27 Temmuz, ZAMAUSDT #186
    // zarar sonrasi eklenen deneysel filtre) - varsayilan false, HENUZ CANLIYA TASINMADI, sadece
    // ayni sembol/donem uzerinde bu bayrakla/bayraksiz iki ayri calistirip KARSILASTIRMAK icin var
    // @return array{
    //     symbol: string, days: int, take_profit_percent: float, stop_loss_percent: float,
    //     total_trades: int, wins: int, losses: int, win_rate: float|null,
    //     avg_hours_to_resolve: float|null, cumulative_return_percent: float|null, fee_percent: float,
    //     trades: array<array{entry_price: float, outcome: string, hours_to_resolve: int, return_percent: float}>
    // }
    // NOT: cumulative_return_percent ve trades[].return_percent artik ROUND_TRIP_FEE_PERCENT dusulmus
    // (net) rakamlardir - win_rate ise TP/SL'den hangisinin once vurdugunu gosteren HAM isabet orani,
    // komisyondan etkilenmez (iki metrigi karistirmayin - biri sinyal kalitesi, digeri gercek kar)
    public function run(
        string $symbol,
        int $days,
        float $takeProfitPercent,
        float $stopLossPercent,
        ?int $technicalScoreThreshold = null,
        bool $applyRecentPeakFilter = false,
        bool $requireMacdPositive = false,
        ?int $recentPeakLookbackHours = null,
        ?float $recentPeakMaxDropPercent = null
    ): array {
        $technicalScoreEngine = new TechnicalScoreEngine();
        $riskManager = new RiskManagerService();
        $symbol = strtoupper(trim($symbol));

        if ($symbol === '') {
            throw new RuntimeException('Sembol boş olamaz.');
        }

        $days = max(1, min(365, $days));

        // 28 Temmuz'da eklendi: Zirve Mesafesi filtresinin FIILEN kullandigi pencere artik
        // $recentPeakLookbackHours verilirse ONU (kisa vadeli test icin, ör. 2 saat), yoksa eski
        // gun-bazli varsayilani kullanir - $peakWarmupHours (asagida, veri CEKME/isinma payi icin)
        // bu ikisinin BUYUGUNU alir ki hangi pencere secilirse secilsin yeterli gecmis veri olsun
        $effectiveRecentPeakLookbackHours = $recentPeakLookbackHours ?? (self::RECENT_PEAK_LOOKBACK_DAYS * 24);

        // 1 saatlik mumlarla calisiyoruz: gun basina 24 mum + isinma payi (rolling 24h zirvesi icin
        // en az 24, secili Zirve Mesafesi penceresi daha genisse ONU kullan - aksi halde ilk
        // birkac aday icin zirve eksik veriyle yanlis hesaplanirdi)
        $peakWarmupHours = max(24, $effectiveRecentPeakLookbackHours);
        $hoursNeeded = ($days * 24) + $peakWarmupHours + self::RSI_PERIOD;

        $symbolKlines = $this->fetchAllKlines($symbol, '1h', $hoursNeeded);
        $btcKlines = $this->fetchAllKlines('BTCUSDT', '1h', $hoursNeeded);

        if (count($symbolKlines) < 48 || count($btcKlines) < 48) {
            throw new RuntimeException('Yeterli geçmiş veri yok - coin çok yeni listelenmiş olabilir.');
        }

        $closes = array_map(static fn (array $k): float => (float) $k[4], $symbolKlines);
        $highs = array_map(static fn (array $k): float => (float) $k[2], $symbolKlines);
        $lows = array_map(static fn (array $k): float => (float) $k[3], $symbolKlines);
        $volumes = array_map(static fn (array $k): float => (float) $k[4] * (float) $k[5], $symbolKlines);
        $btcCloses = array_map(static fn (array $k): float => (float) $k[4], $btcKlines);

        // MACD histogrami TUM dizi icin TEK seferde hesaplanir (dongude her defasinda EMA'yi
        // bastan hesaplamak yerine) - indeksler $closes ile birebir hizali
        $macdHistogramSeries = ($technicalScoreThreshold !== null || $requireMacdPositive)
            ? $technicalScoreEngine->calculateMacdHistogramSeries($closes)
            : [];

        $n = count($symbolKlines);
        $trades = [];
        $openTradeUntil = -1;

        for ($i = $peakWarmupHours; $i < $n; $i++) {
            if ($i <= $openTradeUntil) {
                continue;
            }

            if (!$this->passesEntryFilters(
                $closes, $highs, $volumes, $btcCloses, $macdHistogramSeries, $i,
                $technicalScoreThreshold, $applyRecentPeakFilter, $requireMacdPositive,
                $effectiveRecentPeakLookbackHours, $recentPeakMaxDropPercent,
                $technicalScoreEngine, $riskManager
            )) {
                continue;
            }

            $entryPrice = $closes[$i];
            $takeProfitPrice = $entryPrice * (1 + $takeProfitPercent / 100);
            $stopLossPrice = $entryPrice * (1 - $stopLossPercent / 100);

            $outcome = null;
            $exitIndex = null;

            for ($j = $i + 1; $j < $n; $j++) {
                if ($lows[$j] <= $stopLossPrice) {
                    $outcome = 'LOSS';
                    $exitIndex = $j;
                    break;
                }

                if ($highs[$j] >= $takeProfitPrice) {
                    $outcome = 'WIN';
                    $exitIndex = $j;
                    break;
                }
            }

            if ($outcome === null) {
                continue;
            }

            // Komisyon dusmeden once brut sonuc, TP/SL yuzdesinin ta kendisiydi - gercekte her
            // islemden Binance giris+cikis komisyonu (ROUND_TRIP_FEE_PERCENT) kesilir, kazanan
            // islemi de biraz kucultur, kaybedeni biraz derinlestirir. Dusulmezse backtest kari
            // gercekte olacagindan sistematik olarak yuksek gosterir.
            $grossReturnPercent = $outcome === 'WIN' ? $takeProfitPercent : -$stopLossPercent;

            $trades[] = [
                'entry_price' => $entryPrice,
                'outcome' => $outcome,
                'hours_to_resolve' => $exitIndex - $i,
                'return_percent' => $grossReturnPercent - self::ROUND_TRIP_FEE_PERCENT,
            ];

            $openTradeUntil = $exitIndex;
        }

        $totalTrades = count($trades);

        if ($totalTrades === 0) {
            return [
                'symbol' => $symbol,
                'days' => $days,
                'take_profit_percent' => $takeProfitPercent,
                'stop_loss_percent' => $stopLossPercent,
                'total_trades' => 0,
                'wins' => 0,
                'losses' => 0,
                'win_rate' => null,
                'avg_hours_to_resolve' => null,
                'cumulative_return_percent' => null,
                'fee_percent' => self::ROUND_TRIP_FEE_PERCENT,
                'trades' => [],
            ];
        }

        $wins = count(array_filter($trades, static fn (array $t): bool => $t['outcome'] === 'WIN'));
        $losses = $totalTrades - $wins;

        $cumulativeReturn = 1.0;
        foreach ($trades as $t) {
            $cumulativeReturn *= (1 + $t['return_percent'] / 100);
        }

        return [
            'symbol' => $symbol,
            'days' => $days,
            'take_profit_percent' => $takeProfitPercent,
            'stop_loss_percent' => $stopLossPercent,
            'total_trades' => $totalTrades,
            'wins' => $wins,
            'losses' => $losses,
            'win_rate' => round(($wins / $totalTrades) * 100, 1),
            'avg_hours_to_resolve' => round(array_sum(array_column($trades, 'hours_to_resolve')) / $totalTrades, 1),
            'cumulative_return_percent' => round(($cumulativeReturn - 1) * 100, 2),
            'fee_percent' => self::ROUND_TRIP_FEE_PERCENT,
            'trades' => $trades,
        ];
    }

    // run() ve runTrailingStopComparison() TEK bu metodu paylasir - aday filtre zinciri
    // (hacim/pump/BTC trend/RSI/Zirve Yakinligi/Zirve Mesafesi/teknik skor/MACD) iki ayri yerde
    // YAZILMAZ, aksi halde iki backtest modu birbirinden sessizce sapabilir (proje kulturunde
    // defalarca yasanan lab/canli senkronsuzlugu ile ayni risk)
    private function passesEntryFilters(
        array $closes,
        array $highs,
        array $volumes,
        array $btcCloses,
        array $macdHistogramSeries,
        int $i,
        ?int $technicalScoreThreshold,
        bool $applyRecentPeakFilter,
        bool $requireMacdPositive,
        int $effectiveRecentPeakLookbackHours,
        ?float $recentPeakMaxDropPercent,
        TechnicalScoreEngine $technicalScoreEngine,
        RiskManagerService $riskManager
    ): bool {
        $priceChangePercent = (($closes[$i] - $closes[$i - 24]) / $closes[$i - 24]) * 100;
        $quoteVolume24h = array_sum(array_slice($volumes, $i - 23, 24));

        if ($quoteVolume24h < self::MIN_QUOTE_VOLUME) {
            return false;
        }

        if ($priceChangePercent > self::MAX_ALREADY_PUMPED_PERCENT) {
            return false;
        }

        if ($btcCloses[$i - 24] == 0.0) {
            return false;
        }

        $btcChangePercent = (($btcCloses[$i] - $btcCloses[$i - 24]) / $btcCloses[$i - 24]) * 100;

        if ($btcChangePercent <= self::BTC_DOWNTREND_THRESHOLD_PERCENT) {
            return false;
        }

        $rsi = $this->calculateRsiAt($closes, $i, self::RSI_PERIOD);

        if ($rsi !== null && $rsi >= self::RSI_OVERBOUGHT_THRESHOLD) {
            return false;
        }

        $high24h = max(array_slice($highs, $i - 23, 24));

        if ($riskManager->isNear24hHigh($closes[$i], $high24h, self::NEAR_24H_HIGH_THRESHOLD_PERCENT)) {
            return false;
        }

        if ($applyRecentPeakFilter) {
            $recentPeak = max(array_slice($highs, $i - ($effectiveRecentPeakLookbackHours - 1), $effectiveRecentPeakLookbackHours));
            $maxDropPercent = $recentPeakMaxDropPercent ?? self::RECENT_PEAK_MAX_DROP_PERCENT;

            if ($riskManager->isFarBelowRecentPeak($closes[$i], $recentPeak, $maxDropPercent)) {
                return false;
            }
        }

        if ($technicalScoreThreshold !== null) {
            $recentVolume = array_sum(array_slice($volumes, $i - 2, 3));
            $olderVolume = array_sum(array_slice($volumes, $i - 5, 3));
            $volumeDeltaRatio = $olderVolume > 0.0 ? $recentVolume / $olderVolume : null;

            $technicalResult = $technicalScoreEngine->calculateScore(
                $closes,
                $i,
                $priceChangePercent,
                $volumeDeltaRatio,
                null,
                null,
                $rsi
            );

            if ($technicalResult['score'] < $technicalScoreThreshold) {
                return false;
            }
        }

        if ($requireMacdPositive && ($macdHistogramSeries[$i] === null || $macdHistogramSeries[$i] <= 0.0)) {
            return false;
        }

        return true;
    }

    // 29 Temmuz'da eklendi: AutoTradeController::applyTrailingStopIfEligible() icindeki gercek
    // COK ASAMALI Izleyen Stop mantigini (Asama 1 kullanici-ayarli tetik/kilit, Asama 2 sabit
    // %4/%2.5, Asama 3 sabit %6/%4, ardindan Surekli Izleme) gecmis veride simule eder - run()'un
    // basit "sabit TP/SL, ilk hangisi vurursa" modelinden TAMAMEN FARKLI bir cikis mantigi. Aday
    // giris taramasi run() ile AYNI passesEntryFilters()'i kullanir (Zirve Mesafesi/MACD kapisi bu
    // modda KASITLI OLARAK uygulanmaz - sadece Izleyen Stop parametrelerini izole test etmek icin).
    //
    // ONEMLI SINIRLAMA: giris taramasi 1 saatlik mumlarla yapilir (run() ile ayni), ama Izleyen
    // Stop esikleri (%1.5-6 gibi dar araliklar) saatlik mumla ANLAMLI simule edilemeyecek kadar
    // hassastir - bu yuzden HER aday icin, giristen SONRAKI donem ayrica 15 dakikalik mumlarla
    // cekilip simule edilir. Yine de gercek canli sistemin dakikalik/saniyelik fiyat kontrolüne
    // gore hala DAHA KABA bir yaklasimdir - sonuc YON GOSTERICI kabul edilmeli, kesin kar rakami
    // olarak degil (bkz. cagiran taraf/kullaniciya aktarilan aciklama).
    public function runTrailingStopComparison(
        string $symbol,
        int $days,
        float $takeProfitPercent,
        float $stopLossPercent,
        float $stage1TriggerPercent,
        float $stage1LockPercent,
        float $trailingDistancePercent,
        ?int $technicalScoreThreshold = null
    ): array {
        $technicalScoreEngine = new TechnicalScoreEngine();
        $riskManager = new RiskManagerService();
        $symbol = strtoupper(trim($symbol));

        if ($symbol === '') {
            throw new RuntimeException('Sembol boş olamaz.');
        }

        $days = max(1, min(365, $days));
        $peakWarmupHours = 24;
        $hoursNeeded = ($days * 24) + $peakWarmupHours + self::RSI_PERIOD;

        $symbolKlines = $this->fetchAllKlines($symbol, '1h', $hoursNeeded);
        $btcKlines = $this->fetchAllKlines('BTCUSDT', '1h', $hoursNeeded);

        if (count($symbolKlines) < 48 || count($btcKlines) < 48) {
            throw new RuntimeException('Yeterli geçmiş veri yok - coin çok yeni listelenmiş olabilir.');
        }

        $closes = array_map(static fn (array $k): float => (float) $k[4], $symbolKlines);
        $highs = array_map(static fn (array $k): float => (float) $k[2], $symbolKlines);
        $volumes = array_map(static fn (array $k): float => (float) $k[4] * (float) $k[5], $symbolKlines);
        $btcCloses = array_map(static fn (array $k): float => (float) $k[4], $btcKlines);
        $openTimes = array_map(static fn (array $k): int => (int) $k[0], $symbolKlines);
        $closeTimes = array_map(static fn (array $k): int => (int) $k[6], $symbolKlines);

        $macdHistogramSeries = $technicalScoreThreshold !== null
            ? $technicalScoreEngine->calculateMacdHistogramSeries($closes)
            : [];

        $n = count($symbolKlines);
        $trades = [];
        $openTradeUntilTime = 0;

        for ($i = $peakWarmupHours; $i < $n; $i++) {
            if ($openTimes[$i] <= $openTradeUntilTime) {
                continue;
            }

            if (!$this->passesEntryFilters(
                $closes, $highs, $volumes, $btcCloses, $macdHistogramSeries, $i,
                $technicalScoreThreshold, false, false, 0, null,
                $technicalScoreEngine, $riskManager
            )) {
                continue;
            }

            $entryPrice = $closes[$i];

            // Giristen (bu 1s mumun kapanisindan) sonraki en fazla 7 gunluk pencereyi 15dk'lik
            // mumlarla ceker (672 mum, Binance'in tek istekteki 1000 limitinin altinda - sayfalama
            // gerekmez). 7 gun icinde ne SL ne TP/Izleyen Stop tetiklenmezse bu aday SAYILMAZ
            // (run()'daki "outcome===null ise atla" ile AYNI ilke)
            // Binance'in genel (imzasiz) hiz sinirina takilmamak icin adaylar arasi kucuk bir
            // bekleme - bir adayin 1h taramasi + 15dk cekimi zaten birkac istek, coklu aday/uzun
            // donemde art arda cok hizli istek Binance'i gecici olarak yavaslatabilir/reddedebilir
            usleep(200_000);
            $fineKlines = $this->fetchFineKlinesFrom($symbol, '15m', $closeTimes[$i] + 1, 672);

            if (count($fineKlines) < 4) {
                continue;
            }

            $fineHighs = array_map(static fn (array $k): float => (float) $k[2], $fineKlines);
            $fineLows = array_map(static fn (array $k): float => (float) $k[3], $fineKlines);
            $fineCloseTimes = array_map(static fn (array $k): int => (int) $k[6], $fineKlines);

            $result = $this->simulateMultiStageTrailingExit(
                $fineHighs,
                $fineLows,
                $entryPrice,
                $takeProfitPercent,
                $stopLossPercent,
                $stage1TriggerPercent,
                $stage1LockPercent,
                $trailingDistancePercent
            );

            if ($result === null) {
                // Cozulmedi - bir sonraki taramanin en azindan bu cekilen pencere boyunca ayni
                // adaya UST USTE binmemesi icin zamanı ilerlet, ama islem olarak SAYMA
                $openTradeUntilTime = end($fineCloseTimes);
                continue;
            }

            $returnPercent = (($result['exit_price'] - $entryPrice) / $entryPrice) * 100;

            $trades[] = [
                'entry_price' => $entryPrice,
                'exit_price' => $result['exit_price'],
                'outcome' => $result['outcome'],
                'hours_to_resolve' => round($result['candles_to_resolve'] / 4, 1),
                'return_percent' => $returnPercent - self::ROUND_TRIP_FEE_PERCENT,
            ];

            $openTradeUntilTime = $fineCloseTimes[$result['candles_to_resolve'] - 1];
        }

        $totalTrades = count($trades);

        if ($totalTrades === 0) {
            return [
                'symbol' => $symbol,
                'days' => $days,
                'total_trades' => 0,
                'wins' => 0,
                'losses' => 0,
                'win_rate' => null,
                'avg_hours_to_resolve' => null,
                'cumulative_return_percent' => null,
                'fee_percent' => self::ROUND_TRIP_FEE_PERCENT,
                'trades' => [],
            ];
        }

        $wins = count(array_filter($trades, static fn (array $t): bool => $t['outcome'] === 'WIN'));
        $losses = $totalTrades - $wins;

        $cumulativeReturn = 1.0;
        foreach ($trades as $t) {
            $cumulativeReturn *= (1 + $t['return_percent'] / 100);
        }

        return [
            'symbol' => $symbol,
            'days' => $days,
            'total_trades' => $totalTrades,
            'wins' => $wins,
            'losses' => $losses,
            'win_rate' => round(($wins / $totalTrades) * 100, 1),
            'avg_hours_to_resolve' => round(array_sum(array_column($trades, 'hours_to_resolve')) / $totalTrades, 1),
            'cumulative_return_percent' => round(($cumulativeReturn - 1) * 100, 2),
            'fee_percent' => self::ROUND_TRIP_FEE_PERCENT,
            'trades' => $trades,
        ];
    }

    // AutoTradeController::applyTrailingStopIfEligible()/applyContinuousTrailing()'in SAF
    // (Binance/DB'siz) matematiksel simulasyonu - Asama 1 (parametreli) -> Asama 2 (sabit %4/%2.5)
    // -> Asama 3 (sabit %6/%4) -> Surekli Izleme (zirveden trailingDistancePercent kadar asagida).
    // BILINCLI SADELESTIRME: canlidaki ATR bazli mesafe carpani burada YOK (sadece kullanicinin
    // girdigi sabit mesafe kullanilir) - amaç üç parametrenin (tetik/kilit/mesafe) net etkisini
    // izole gormek, ATR gürültüsü katmadan. @return null ise donem icinde (672 mum/7 gun) ne
    // Zarar Kes ne Kar Al/Izleyen Stop tetiklendi - cagiran taraf bu adayi SAYMAZ
    private function simulateMultiStageTrailingExit(
        array $fineHighs,
        array $fineLows,
        float $entryPrice,
        float $takeProfitPercent,
        float $stopLossPercent,
        float $stage1TriggerPercent,
        float $stage1LockPercent,
        float $trailingDistancePercent
    ): ?array {
        $takeProfitPrice = $entryPrice * (1 + $takeProfitPercent / 100);
        $stopPrice = $entryPrice * (1 - $stopLossPercent / 100);
        $highestSeen = $entryPrice;
        $stage = 0;
        $takeProfitActive = true;

        $stages = [
            1 => ['trigger' => $stage1TriggerPercent, 'lock' => $stage1LockPercent],
            2 => ['trigger' => 4.0, 'lock' => 2.5],
            3 => ['trigger' => 6.0, 'lock' => 4.0],
        ];
        $maxStage = 3;
        $n = count($fineHighs);

        for ($j = 0; $j < $n; $j++) {
            $high = $fineHighs[$j];
            $low = $fineLows[$j];

            // Zarar Kes/mevcut Stop kontrolu ONCE (dusuk once tetiklenir varsayimi - run()'daki
            // AYNI kotumser/guvenli yaklasim, mumun icinde gercekte hangisinin once oldugu bilinmez)
            if ($low <= $stopPrice) {
                return [
                    'outcome' => $stopPrice >= $entryPrice ? 'WIN' : 'LOSS',
                    'exit_price' => $stopPrice,
                    'candles_to_resolve' => $j + 1,
                ];
            }

            // Kar Al tavani - SADECE Surekli Izleme henuz ilk iyilesmeyi hesaplamadiysa gecerli
            // (canlidaki take_profit_removed===0 durumu)
            if ($takeProfitActive && $high >= $takeProfitPrice) {
                return [
                    'outcome' => 'WIN',
                    'exit_price' => $takeProfitPrice,
                    'candles_to_resolve' => $j + 1,
                ];
            }

            $highestSeen = max($highestSeen, $high);
            $changePercent = (($highestSeen - $entryPrice) / $entryPrice) * 100;

            for ($s = $stage + 1; $s <= $maxStage; $s++) {
                if ($changePercent < $stages[$s]['trigger']) {
                    break;
                }

                $candidateLock = $entryPrice * (1 + $stages[$s]['lock'] / 100);

                if ($candidateLock > $stopPrice) {
                    $stopPrice = $candidateLock;
                }

                $stage = $s;
            }

            if ($stage >= 1) {
                $candidateStop = $highestSeen * (1 - $trailingDistancePercent / 100);

                if ($takeProfitActive) {
                    $candidateStop = min($candidateStop, $takeProfitPrice * 0.999);
                }

                if ($candidateStop > $stopPrice) {
                    $stopPrice = $candidateStop;
                    $takeProfitActive = false;
                }
            }
        }

        return null;
    }

    // fetchKlines()'in startTime destekli karsiligi - runTrailingStopComparison() giristen
    // SONRAKI pencereyi cekmek icin kullanir (fetchKlines sadece endTime'a gore GERIYE doner,
    // burada ILERI yonde, belirli bir noktadan itibaren N mum lazim)
    private function fetchFineKlinesFrom(string $symbol, string $interval, int $startTime, int $limit): array
    {
        $params = ['symbol' => $symbol, 'interval' => $interval, 'startTime' => $startTime, 'limit' => $limit];
        $url = 'https://api.binance.com/api/v3/klines?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || $raw === false) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function calculateRsiAt(array $closes, int $index, int $period): ?float
    {
        if ($index < $period) {
            return null;
        }

        $totalGain = 0.0;
        $totalLoss = 0.0;

        for ($i = $index - $period + 1; $i <= $index; $i++) {
            $change = $closes[$i] - $closes[$i - 1];

            if ($change > 0) {
                $totalGain += $change;
            } else {
                $totalLoss += abs($change);
            }
        }

        if ($totalLoss == 0.0) {
            return 100.0;
        }

        $rs = ($totalGain / $period) / ($totalLoss / $period);

        return 100 - (100 / (1 + $rs));
    }

    private function fetchKlines(string $symbol, string $interval, int $limit, ?int $endTime = null): array
    {
        $params = ['symbol' => $symbol, 'interval' => $interval, 'limit' => $limit];

        if ($endTime !== null) {
            $params['endTime'] = $endTime;
        }

        $url = 'https://api.binance.com/api/v3/klines?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || $raw === false) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    // Binance klines limiti tek istekte max 1000 mum - gerekirse birden fazla istekle birlestirir
    private function fetchAllKlines(string $symbol, string $interval, int $totalCount): array
    {
        $all = [];
        $endTime = null;

        while (count($all) < $totalCount) {
            $remaining = $totalCount - count($all);
            $batch = $this->fetchKlines($symbol, $interval, min(1000, $remaining), $endTime);

            if ($batch === []) {
                break;
            }

            $all = array_merge($batch, $all);
            $endTime = ((int) $batch[0][0]) - 1;

            if (count($batch) < min(1000, $remaining)) {
                break;
            }
        }

        return $all;
    }
}
