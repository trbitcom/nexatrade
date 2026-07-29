<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use RuntimeException;
use Throwable;

// Binance'in genel (public) 24 saatlik ticker verisini tarayarak "hareketlenen" coinleri tespit eder
// Kimlik dogrulama gerektirmez; hesap islemleri icin BinanceService kullanilmalidir
final class MarketScanner
{
    private const BASE_URL = 'https://api.binance.com';
    private const CONNECT_TIMEOUT_SECONDS = 3;
    // /api/v3/ticker/24hr TUM pariteleri dondurur (~17 MB) - 5 saniye bu boyut icin bazen yetersiz
    // kalip zaman asimina uğruyordu (curl errno 28), bu yuzden bu spesifik uc nokta icin daha genis
    private const TIMEOUT_SECONDS = 5;
    private const LARGE_PAYLOAD_TIMEOUT_SECONDS = 15;

    // Hacim Trendi Filtresi (isVolumeIncreasing): eskiden 8 saatlik pencere (4s+4s) ve SIFIR
    // tolerans (recentVolume'un olderVolume'dan bir MIKTAR bile fazla olmasi sart) kullaniyordu -
    // bu, birkac saat once pompalanip su an sadece SAKINLESEN (ama hala islem yapilabilir) coinleri
    // bile eliyordu (ör. WLDUSDT, AI skoru 60 esigini rahatca gecmisken sirf bu filtrede kayboldu).
    // Agresif moda uygun esneltme: pencere 6 saate (3s+3s) daraltilarak DAHA GUNCEL momentuma
    // tepki verir, ve %15'lik bir tolerans eklenerek "yeni hacim eskiye göre biraz dusuk ama
    // COKMEMIS" durumlar da gecer - sadece NET/belirgin bir hacim cokusu hala eler
    private const VOLUME_TREND_LOOKBACK_HOURS = 6;
    private const VOLUME_TREND_TOLERANCE_PERCENT = 15.0;

    // SADECE beyaz liste (admin panelinden) aktifken kullanilir - bkz. getCoinLimit(). Beyaz liste
    // KAPALIYKEN artik DYNAMIC_POOL_SIZE (sabit Top 50) kullanilir, bu deger devreye girmez
    private const DEFAULT_TOP_N = 25;

    // Gurultuyu azaltmak icin dusuk hacimli/mikro-cap pariteleri tarama disi birakir (USDT cinsinden 24s hacim).
    // "Vur-Kaç" (scalping) modeline gecisle birlikte 5M'den 15M'e yukseltildi - pusu (ambush) tespiti
    // hizli/sert fiyat hareketleri aradigi icin, gercekten derin likiditesi olan coinlere odaklanmak
    // (yuzeysel/ince defter derinligindeki shitcoinlerin sahte sicramalarindan kacinmak) daha kritik hale geldi
    private const MIN_QUOTE_VOLUME = 15_000_000.0;

    // Kaldiracli/turev token sembollerini (ör. BTCUP, BTCDOWN) taramadan haric tutar
    private const EXCLUDED_BASE_SUFFIXES = ['UP', 'DOWN', 'BULL', 'BEAR'];

    // 13 Temmuz'da radar_check.php ile tespit edildi: stablecoin/fiat ciftleri (ör. USDCUSDT) dolar
    // karsisinda pratikte hic hareket etmez ama yuksek hacimleri yuzunden Top-N havuzunda GERCEK bir
    // altcoin'in yerini gereksiz yere isgal ediyordu - asla gercek bir alim firsati olusturmazlar,
    // sadece islemci/API kapasitesini bosa harcarlar. Taban varlik (base asset) burada listelenirse
    // sembol havuza HIC girmez (Top-N siralamasina bile dahil edilmez)
    private const EXCLUDED_BASE_ASSETS = [
        // USD stabilcoinleri (USD1: 13 Temmuz'da radar_check.php canli taramasinda yakalandi -
        // ilk listede yoktu, -%0.04 degisimle stablecoin davranisi sergiledigi icin eklendi)
        'USDC', 'FDUSD', 'TUSD', 'BUSD', 'DAI', 'USDP', 'USDD', 'GUSD', 'USD1', 'USDE', 'PYUSD', 'FRAX', 'LUSD',
        // Fiat / fiat-endeksli ciftler
        'EUR', 'TRY', 'GBP', 'AEUR',
    ];

    // --- Dinamik Hacim Havuzu ---
    // Eskiden ("Karma Radar") aday havuzu 3 sabit fiyat-degisim araligina (golge hacim/dipten donus/
    // erken momentum) bolunup ayri ayri doldurulurdu. "Vur-Kaç" (scalping) modeline gecisle birlikte
    // bu on-filtreleme kaldirildi: artik piyasanin en likit Top 50 coini FIYAT DEGISIMINDEN BAGIMSIZ
    // olarak havuza alinir, "tepeden alim" riskine karsi koruma ise AutoTradeController'daki RSI
    // sert filtresi + TechnicalScoreEngine'in 5dk/15dk pusu (ambush) tespiti tarafindan saglanir -
    // on-filtreleme ile potansiyel firsatlarin bastan elenmesi yerine, asil karar skorlama katmanina birakildi
    private const DYNAMIC_POOL_SIZE = 50;

    // 22 Temmuz'da eklendi: calculateMacroTrend()'in 90 GUNLUK versiyonuyla AYNI mantigin 24 SAATLIK
    // (gunluk) karsiligi - "Cift Katmanli Zirve Korumasi"nin mikro katmani. 90 gunluk zirveye uzak
    // ama SON birkac saatte sert pompalanip KENDI gunluk zirvesine yapisan bir coin (ör. LISTAUSDT
    // 13.9 dk'da Zarar Kes'e carpan olay), 90 gunluk kontrolden gecer ama bu YENI kontrol olmadan
    // yakalanamaz. Ek bir Binance cagrisi GEREKMEZ - highPrice/lowPrice zaten fetch24hrTickers()'in
    // tek seferlik ticker cevabinda mevcut, sadece kullanilmiyordu
    // @return array{high_24h: float, low_24h: float, position_percent_24h: float}|null
    private function calculateDailyPeakPosition(array $ticker): ?array
    {
        $high = (float) ($ticker['highPrice'] ?? 0);
        $low = (float) ($ticker['lowPrice'] ?? 0);
        $last = (float) ($ticker['lastPrice'] ?? 0);

        if ($high <= $low) {
            return null; // fiyat hic degismemis (bolme sifirla riski) - guvenli sekilde atla
        }

        $positionPercent = (($last - $low) / ($high - $low)) * 100;

        return [
            'high_24h' => $high,
            'low_24h' => $low,
            'position_percent_24h' => round(max(0.0, min(100.0, $positionPercent)), 1),
        ];
    }

    // Her satir: ['symbol' => 'BTCUSDT', 'priceChangePercent' => 3.42, 'quoteVolume' => 125000000.0,
    // 'lastPrice' => 65000.0, 'strategy_bucket' => 'dinamik_hacim'|'whitelist']
    // @param bool $ignoreWhitelist 25 Temmuz'da eklendi: FuturesTradingService icin - AI Avci'nin
    // Beyaz Listesi (spot icin backtest'te kanitlanmis YUKSELIS adaylarina odaklanmak amacli) short
    // pozisyon adaylarini da kisitlarsa, futures'in DUSUS adayi arama alani anlamsizca daralir (iki
    // modul ZIT yonlerde calisir, ayni liste ikisine de uymaz). true verilirse whitelist TAMAMEN
    // yok sayilir, her zaman Top 50 dinamik havuz kullanilir - spot'un whitelist ayarindan bagimsiz
    public function scanTopMovers(bool $ignoreWhitelist = false): array
    {
        $tickers = $this->fetch24hrTickers();
        $eligible = [];

        // Beyaz liste admin panelinden bos birakilirsa (varsayilan) hicbir kisitlama yapilmaz -
        // doldurulursa SADECE listedeki semboller aday havuzuna girer (backtestte kanitlanmis
        // coinlere odaklanmak icin). Dongu disinda TEK sefer okunur (binlerce ticker icin tekrar
        // tekrar veritabani sorgulamamak icin)
        $whitelist = $ignoreWhitelist ? [] : $this->getWhitelistedSymbols();
        // Kara liste whitelist durumundan (bos/dolu, ignoreWhitelist) TAMAMEN BAGIMSIZ HER ZAMAN
        // uygulanir - "kesin ve kalici" disarida tutma niyeti boyle yorumlandi
        $blacklist = $this->getBlacklistedSymbols();

        foreach ($tickers as $ticker) {
            $symbol = (string) ($ticker['symbol'] ?? '');

            if (!$this->isEligibleSymbol($symbol)) {
                continue;
            }

            if ($blacklist !== [] && in_array($symbol, $blacklist, true)) {
                continue;
            }

            if ($whitelist !== [] && !in_array($symbol, $whitelist, true)) {
                continue;
            }

            $quoteVolume = (float) ($ticker['quoteVolume'] ?? 0);

            if ($quoteVolume < self::MIN_QUOTE_VOLUME) {
                continue;
            }

            $eligibleRow = [
                'symbol' => $symbol,
                'priceChangePercent' => (float) ($ticker['priceChangePercent'] ?? 0),
                'quoteVolume' => $quoteVolume,
                'lastPrice' => (float) ($ticker['lastPrice'] ?? 0),
            ];

            $dailyPeak = $this->calculateDailyPeakPosition($ticker);

            if ($dailyPeak !== null) {
                $eligibleRow += $dailyPeak;
            }

            $eligible[] = $eligibleRow;
        }

        // Beyaz liste AKTIFKEN admin'in sectigi sembollerle sinirlanir (hacme gore azalan sirali,
        // getCoinLimit() kadar). KAPALIYKEN (varsayilan/normal calisma modu) piyasanin en likit
        // Top 50'si dinamik olarak havuza alinir - fiyat degisim yonunden BAGIMSIZ, cunku "tepeden
        // alim" korumasi artik on-filtrelemede degil, AutoTradeController'daki RSI sert filtresi +
        // TechnicalScoreEngine'in pusu (ambush) tespitinde
        if ($whitelist !== []) {
            usort($eligible, static fn (array $a, array $b): int => $b['quoteVolume'] <=> $a['quoteVolume']);
            $selected = array_slice($eligible, 0, $this->getCoinLimit());

            foreach ($selected as &$ticker) {
                $ticker['strategy_bucket'] = 'whitelist';
            }
            unset($ticker);

            return $selected;
        }

        usort($eligible, static fn (array $a, array $b): int => $b['quoteVolume'] <=> $a['quoteVolume']);
        $selected = array_slice($eligible, 0, self::DYNAMIC_POOL_SIZE);

        foreach ($selected as &$ticker) {
            $ticker['strategy_bucket'] = 'dinamik_hacim';
        }
        unset($ticker);

        return $selected;
    }

    // Once admin panelinden (DB) girilen degere bakar, yoksa config/app.php'deki varsayilana doner -
    // diger sayisal esiklerle (ör. listing_sniper_min_quote_volume) ayni oncelik sirasi
    private function getCoinLimit(): int
    {
        $dbValue = Setting::get('scanner_coin_limit');

        if ($dbValue !== null && (int) $dbValue > 0) {
            return (int) $dbValue;
        }

        $config = require __DIR__ . '/../../config/app.php';

        return (int) ($config['scanner_coin_limit'] ?? self::DEFAULT_TOP_N);
    }

    // Admin panelinden virgulle ayrilmis sembol listesini okur (ör. "SYNUSDT,BELUSDT,TLMUSDT").
    // Bos/tanimsizsa [] doner - bu, "kisitlama yok" anlamina gelir (scanTopMovers tum piyasayi tarar)
    private function getWhitelistedSymbols(): array
    {
        $dbValue = Setting::get('market_scanner_whitelist');

        if ($dbValue === null || trim($dbValue) === '') {
            return [];
        }

        $symbols = array_map(
            static fn (string $s): string => strtoupper(trim($s)),
            explode(',', $dbValue)
        );

        return array_values(array_filter($symbols, static fn (string $s): bool => $s !== ''));
    }

    // 26 Temmuz'da eklendi: gercek islem gecmisi raporunda (133 kapanan islem) BANKUSDT/DEXEUSDT/
    // BTCUSDT/ETHUSDT'nin TUTARLI olarak zararli ciktigi tespit edildi. Whitelist BOS oldugunda
    // (kisitlama yok, Top 50 dinamik havuz taranir) bu coinleri disarida tutmanin whitelist'ten
    // "cikaracak" bir yolu YOKTU - bu yuzden AYRI, whitelist'ten BAGIMSIZ bir kara liste eklendi.
    // Whitelist AKTIFKEN de (bilerek/yanlislikla bu coinler listeye eklenirse) AYNI sekilde uygulanir -
    // kara liste her zaman ONCELIKLIDIR. getWhitelistedSymbols() ile AYNI parse deseni
    // 26 Temmuz'da public'e cevrildi: Sosyal Radar'in AYRI aday kaynagi (fetchTradableUsdtSymbols,
    // AutoTradeController::fetchTradableSocialRadarSymbols icinde kullanilir) bu kara listeyi HIC
    // gormuyordu - scanTopMovers()'daki filtre SADECE Ana Radar'i kapsiyordu. Ayni gun BANKUSDT'nin
    // Sosyal Radar uzerinden 14 kez kara listeyi atlayarak alindigi tespit edildi (bkz. CHANGELOG).
    // Yeni bir kara liste yapisi KURULMADI - TEK merkezi kaynak (bu metod) iki radara da baglandi
    public function getBlacklistedSymbols(): array
    {
        $dbValue = Setting::get('market_scanner_blacklist');

        if ($dbValue === null) {
            $config = require __DIR__ . '/../../config/app.php';
            $dbValue = (string) ($config['market_scanner_blacklist'] ?? '');
        }

        if (trim($dbValue) === '') {
            return [];
        }

        $symbols = array_map(
            static fn (string $s): string => strtoupper(trim($s)),
            explode(',', $dbValue)
        );

        return array_values(array_filter($symbols, static fn (string $s): bool => $s !== ''));
    }

    // Son N saatteki hacmin ARTAN bir egilimde olup olmadigini kontrol eder: pencerenin ikinci
    // yarisindaki toplam hacim, ilk yarisindan fazlaysa true doner. Bu, "gercekten yeni/artan ilgi"
    // ile "24 saat once olmus tek seferlik buyuk bir islemin hala etkisini gostermesi" arasini ayirt eder.
    // Yeterli mum verisi yoksa (ör. yeni listelenen coin) true doner (fail-open - sistemi kilitlemez)
    // 10 Temmuz'da tespit edilen yapisal bug: endTime verilmeden istenen klines, Binance'in
    // davranisi geregi HENUZ KAPANMAMIS/olusmakta olan GUNCEL mumu da SON eleman olarak dondurur.
    // Bu yarim mum "recent" (son yari) grubuna giriyordu ve saatin ne kadari gecmis olursa olsun
    // HER ZAMAN tam bir saatlik mumdan daha az hacim tasidigi icin karsilastirmayi sistematik
    // olarak "hacim dusuyor" yonune cekiyordu - gercek piyasa durumundan tamamen BAGIMSIZ bir
    // yanlilik (bkz. radar_check.php ile canli yakalandi: BTC/ETH dahil 5/5 coin ayni filtrede
    // elenmisti). Duzeltme: bir FAZLA mum cekilip en sondaki (kapanmamis) mum ATILIR - karsilastirma
    // SADECE tamamen kapanmis mumlar arasinda yapilir
    public function isVolumeIncreasing(string $symbol, int $lookbackHours = self::VOLUME_TREND_LOOKBACK_HOURS): bool
    {
        $klines = $this->fetchKlines($symbol, '1h', $lookbackHours + 1);

        // Son eleman (en guncel) kapanmamis mum oldugu icin atilir - geriye TAM olarak
        // $lookbackHours adet, hepsi kapanmis mum kalmali
        if (count($klines) > $lookbackHours) {
            array_pop($klines);
        }

        if (count($klines) < $lookbackHours) {
            return true;
        }

        return $this->compareVolumeHalves($klines);
    }

    // isVolumeIncreasing()'in SAF hesaplama kismi - Binance'ten cekilmis (ve zaten kapanmamis
    // mumdan arindirilmis) $klines dizisini alir, HTTP cagrisi YAPMAZ. Ilk yariyi "eski", ikinci
    // yariyi "yeni" hacim olarak toplayip karsilastirir. VOLUME_TREND_TOLERANCE_PERCENT kadar bir
    // gerileme ARTIK "hacim dusuyor" sayilmaz - sadece bu payin OTESINDE net bir cokus elenir
    private function compareVolumeHalves(array $klines): bool
    {
        $half = intdiv(count($klines), 2);
        $olderVolume = 0.0;
        $recentVolume = 0.0;

        foreach ($klines as $index => $kline) {
            $volume = (float) ($kline[5] ?? 0);

            if ($index < $half) {
                $olderVolume += $volume;
            } else {
                $recentVolume += $volume;
            }
        }

        return $recentVolume >= $olderVolume * (1 - self::VOLUME_TREND_TOLERANCE_PERCENT / 100);
    }

    // Son 15-30 dakikada yasanan ani hacim sicramalarini tespit eder ("Vur-Kac" / scalping modelinin
    // erken teshis katmani): 5 dakikalik mumlarda son 15 dakikanin (3 mum) toplam hacmini, ONCEKI 15
    // dakikayla (3 mum) karsilastirir. isVolumeIncreasing() (8 saatlik, boolean) ile AYNI amaca
    // hizmet ETMEZ - bu cok daha kisa vadeli ve hassas bir "ani ilgi" sinyalidir.
    // @return float|null Carpan (ör. 3.0 = son 15dk hacmi, onceki 15dk'nin 3 kati). Yeterli mum
    // verisi yoksa null doner (fail-open - TechnicalScoreEngine bu durumda sadece o katkiyi atlar)
    // isVolumeIncreasing() ile AYNI 10 Temmuz duzeltmesi: endTime verilmeden istenen son mum
    // HENUZ KAPANMAMIS olabilir, bu da "son 15 dakika" toplamini sistematik olarak DUSUK gosterip
    // gercek hacim sicramalarini oldugundan daha zayif gostermesine yol acardi. Bir fazla mum
    // cekilip en sondaki (kapanmamis) mum atilir
    public function calculateVolumeDelta(string $symbol): ?float
    {
        $klines = $this->fetchKlines($symbol, '5m', 7);

        if (count($klines) > 6) {
            array_pop($klines);
        }

        if (count($klines) < 6) {
            return null;
        }

        return $this->computeVolumeDeltaRatio($klines);
    }

    // calculateVolumeDelta()'nin SAF hesaplama kismi - Binance'ten cekilmis (ve zaten kapanmamis
    // mumdan arindirilmis) $klines dizisini alir, HTTP cagrisi YAPMAZ
    private function computeVolumeDeltaRatio(array $klines): ?float
    {
        $olderVolume = 0.0;
        $recentVolume = 0.0;

        foreach ($klines as $index => $kline) {
            $volume = (float) ($kline[5] ?? 0);

            if ($index < 3) {
                $olderVolume += $volume;
            } else {
                $recentVolume += $volume;
            }
        }

        if ($olderVolume <= 0.0) {
            // Onceki 15 dakika hacimsizdi ama simdi hacim var - sabit bir tavan degerle guclu
            // bir sicrama olarak isaretle (sifira bolme riskinden kacinir)
            return $recentVolume > 0.0 ? 5.0 : null;
        }

        return $recentVolume / $olderVolume;
    }

    // RSI (Relative Strength Index) hesaplar - standart 14 periyotluk, 1 saatlik mumlarla.
    // RSI > 70: "asiri alinmis" (overbought) - fiyat cok hizli yukselmis, geri cekilme riski yuksek.
    // RSI < 30: "asiri satilmis" (oversold). Bu, AI skorundan BAGIMSIZ, gercek fiyat hareketine
    // dayali ikinci bir teknik dogrulama katmani - AI'nin tek karar verici olmasini engeller.
    // Yeterli mum verisi yoksa (ör. yeni listelenen coin) null doner, cagiran taraf bunu atlar.
    public function calculateRsi(string $symbol, int $period = 14): ?float
    {
        $klines = $this->fetchKlines($symbol, '1h', $period + 1);

        if (count($klines) < $period + 1) {
            return null;
        }

        $totalGain = 0.0;
        $totalLoss = 0.0;

        for ($i = 1; $i < count($klines); $i++) {
            $change = (float) $klines[$i][4] - (float) $klines[$i - 1][4];

            if ($change > 0) {
                $totalGain += $change;
            } else {
                $totalLoss += abs($change);
            }
        }

        if ($totalLoss == 0.0) {
            return 100.0;
        }

        $averageGain = $totalGain / $period;
        $averageLoss = $totalLoss / $period;
        $relativeStrength = $averageGain / $averageLoss;

        return 100 - (100 / (1 + $relativeStrength));
    }

    // 25 Temmuz'da eklendi: ATR (Average True Range), Izleyen Stop mesafesini piyasanin anlik
    // volatilitesine gore esneten yeni bir "Carpan" mekanizmasi icin - calculateRsi() ile AYNI
    // konvansiyon (1 saatlik mum, varsayilan 14 periyot). Fiyat MUTLAK degil, fiyatin YUZDESI
    // olarak donuyor - boylece farkli fiyat seviyelerindeki coinler (ör. BTCUSDT ~65000 vs
    // DOGEUSDT ~0.4) DOGRUDAN karsilastirilabilir/carpan hesabinda kullanilabilir. Yetersiz veri
    // veya sifir/negatif kapanis fiyati durumunda null doner (fail-open - cagiran taraf bu
    // durumda mevcut sabit mesafeyi degistirmeden kullanmaya devam eder)
    public function calculateAtr(string $symbol, int $period = 14): ?float
    {
        $klines = $this->fetchKlines($symbol, '1h', $period + 1);

        if (count($klines) < $period + 1) {
            return null;
        }

        $trueRanges = [];

        for ($i = 1; $i < count($klines); $i++) {
            $high = (float) $klines[$i][2];
            $low = (float) $klines[$i][3];
            $prevClose = (float) $klines[$i - 1][4];

            $trueRanges[] = max($high - $low, abs($high - $prevClose), abs($low - $prevClose));
        }

        $lastClose = (float) $klines[count($klines) - 1][4];

        if ($lastClose <= 0.0) {
            return null;
        }

        return (array_sum($trueRanges) / count($trueRanges)) / $lastClose * 100.0;
    }

    // Son 90 gunluk (3 aylik) GUNLUK mum verisinden coin'in makro fiyat araligini ve mevcut fiyatin
    // bu aralikta yuzde kacinci dilimde oldugunu hesaplar - GPT'nin SADECE anlik 24s harekete bakip
    // 3 aylik zirveye (dirence) cok yakin bir coine yanlislikla yuksek skor vermesini (FOMO/tepeden
    // alim riski) onlemek icin SentimentService promptuna eklenir. Veritabanina KAYDEDILMEZ, her
    // analiz aninda Binance'ten canli (on-the-fly) cekilir. Herhangi bir hata durumunda (API
    // zaman asimi, yetersiz gecmis vb.) null doner - cagiran taraf bu bilgiyi promptdan atlar,
    // sistem asla bu yuzden çökmez/durmaz
    // @return array{high_90d: float, low_90d: float, position_percent_90d: float}|null
    public function calculateMacroTrend(string $symbol): ?array
    {
        try {
            $klines = $this->fetchKlines($symbol, '1d', 90);

            // Yeni listelenen bir coin icin 90 gunluk gecmis olmayabilir - anlamli bir aralik
            // hesaplamak icin makul bir asgari (30 gun) aranir, aksi halde yaniltici olurdu
            if (count($klines) < 30) {
                return null;
            }

            $high = 0.0;
            $low = PHP_FLOAT_MAX;

            foreach ($klines as $kline) {
                $high = max($high, (float) $kline[2]);
                $low = min($low, (float) $kline[3]);
            }

            if ($high <= $low) {
                return null; // fiyat hic degismemis (bolme sifirla riski) - guvenli sekilde atla
            }

            $currentPrice = (float) $klines[count($klines) - 1][4];
            $positionPercent = (($currentPrice - $low) / ($high - $low)) * 100;

            return [
                'high_90d' => $high,
                'low_90d' => $low,
                'position_percent_90d' => round(max(0.0, min(100.0, $positionPercent)), 1),
            ];
        } catch (Throwable $e) {
            $this->logError("calculateMacroTrend: {$symbol} için hesaplanamadı - " . $e->getMessage());

            return null;
        }
    }

    // TechnicalScoreEngine'in ("Vur-Kaç" / scalping modeli: 15dk birincil zaman dilimi + 5dk pusu
    // tespiti + hacim deltasi birlestiren, GPT'den bagimsiz BELIRLEYICI kural motoru) skorunu,
    // canli piyasa verisiyle hesaplar. Yeterli mum verisi yoksa (ör. yeni listelenen coin) null
    // doner. GPT skoruna gore ikincil/bagimsiz bir onay katmani olarak kullanilir (bkz.
    // AutoTradeController'daki "pusu (ambush) kurtarma" blogu) - mevcut RSI(1h>=70)/BTC dusus/
    // hacim trendi (1h) sert filtrelerinin YERINE GECMEZ, onlara EK bir katmandir
    // @param float|null $rsi1h MarketScanner::calculateRsi() ile hesaplanan ESKI (1 saatlik) RSI -
    // artik sadece dusuk agirlikli bir baglam sinyali olarak kullanilir, opsiyoneldir
    public function calculateTechnicalScore(
        string $symbol,
        float $priceChangePercent,
        ?float $rsi1h
    ): ?array {
        // 15 DAKIKALIK zaman dilimi: scalping modelinin BIRINCIL karar verisi (MA20/MACD/RSI
        // buradan hesaplanir). MACD (EMA12/EMA26 + 9 periyotluk sinyal) icin yeterli isinma verisi
        // gerekir - 100 mum, EMA26'nin guvenilir sekilde otursun diye rahat bir pay birakir.
        // isVolumeIncreasing()/calculateVolumeDelta() ile AYNI 10 Temmuz duzeltmesi burada EKSIKTI:
        // endTime verilmeden istenen son mum HENUZ KAPANMAMIS olabilir - bu "guncel" (henuz
        // olusmakta olan) mum RSI/MACD icin "currentIndex" olarak kullanilinca, sadece o an gecen
        // fiyat titremesi yuzunden gecici bir "dipten donus" sinyali (ambush_detected) tetikleyip,
        // GPT'nin zaten reddettigi bir adayi gercek parayla ALINACAK sekilde "kurtarabiliyordu"
        // (bkz. AutoTradeController'daki Pusu kurtarma blogu). Bir fazla mum cekilip en sondaki
        // (kapanmamis) mum atilir - karsilastirma SADECE tamamen kapanmis mumlar arasinda yapilir
        $klines15m = $this->fetchKlines($symbol, '15m', 101);

        if (count($klines15m) > 100) {
            array_pop($klines15m);
        }

        if (count($klines15m) < 40) {
            return null;
        }

        $closes15m = array_map(static fn (array $k): float => (float) $k[4], $klines15m);

        // 5 DAKIKALIK zaman dilimi: SADECE "Pusu" (dipten donus) sinyali icin - RSI'in asiri
        // satimdan DONUS anini ve MACD'nin ERKEN pozitif kesisimini 15m'den cok daha erken yakalar.
        // Cekilemezse (ör. gecici API hatasi) pusu tespiti sessizce atlanir, skor hesaplanmaya devam eder.
        // AYNI kapanmamis-mum duzeltmesi burada da uygulanir (yukaridaki 15m ile ayni gerekce)
        $klines5m = $this->fetchKlines($symbol, '5m', 101);

        if (count($klines5m) > 100) {
            array_pop($klines5m);
        }

        $closes5m = count($klines5m) >= 40
            ? array_map(static fn (array $k): float => (float) $k[4], $klines5m)
            : null;

        $volumeDelta = $this->calculateVolumeDelta($symbol);

        $engine = new TechnicalScoreEngine();

        return $engine->calculateScore(
            $closes15m,
            count($closes15m) - 1,
            $priceChangePercent,
            $volumeDelta,
            $closes5m,
            $closes5m !== null ? count($closes5m) - 1 : null,
            $rsi1h
        );
    }

    // Coklu Pozisyon Cesitlendirme Filtresi icin: iki sembolun son $lookbackHours saatlik
    // GETIRI serisi (fiyat SEVIYESI degil - ikisi de trend halindeyken bu her zaman yuksek
    // "korele" gorunurdu; gercek gunluk hareket benzerligini olcen, ardisik kapanislar arasindaki
    // YUZDESEL DEGISIM serisi kullanilir) arasindaki Pearson korelasyon katsayisini hesaplar
    // (-1 ile +1 arasi, +1 = tam ayni yonde hareket). Yetersiz/hizasiz veri ya da API hatasi
    // durumunda null doner (fail-open - cagiran taraf bu bilgi olmadan devam eder, bir alimi
    // yanlislikla ENGELLEMEZ)
    public function calculatePriceCorrelation(string $symbolA, string $symbolB, int $lookbackHours = 48): ?float
    {
        try {
            $klinesA = $this->fetchKlines($symbolA, '1h', $lookbackHours + 1);
            $klinesB = $this->fetchKlines($symbolB, '1h', $lookbackHours + 1);
        } catch (Throwable $e) {
            return null;
        }

        if (count($klinesA) < 10 || count($klinesB) < 10) {
            return null;
        }

        $returnsA = $this->computeReturnSeries($klinesA);
        $returnsB = $this->computeReturnSeries($klinesB);

        // Iki sembolun mum sayisi (ör. biri yeni listelenmis) farkli olabilir - karsilastirma
        // SADECE ikisinde de mevcut olan en SON N getiriyle (hizali/ayni zaman penceresi) yapilir
        $n = min(count($returnsA), count($returnsB));

        if ($n < 10) {
            return null;
        }

        return $this->pearsonCorrelation(array_slice($returnsA, -$n), array_slice($returnsB, -$n));
    }

    // Ardisik kapanis fiyatlari arasindaki yuzdesel degisim serisini doner (ör. [0.012, -0.005, ...])
    private function computeReturnSeries(array $klines): array
    {
        $closes = array_map(static fn (array $k): float => (float) $k[4], $klines);
        $returns = [];

        for ($i = 1; $i < count($closes); $i++) {
            if ($closes[$i - 1] <= 0.0) {
                continue;
            }

            $returns[] = ($closes[$i] - $closes[$i - 1]) / $closes[$i - 1];
        }

        return $returns;
    }

    // Standart Pearson korelasyon katsayisi formulu - HTTP cagrisi YAPMAZ, saf hesaplama
    private function pearsonCorrelation(array $x, array $y): ?float
    {
        $n = count($x);

        if ($n < 2) {
            return null;
        }

        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $covariance = 0.0;
        $varianceX = 0.0;
        $varianceY = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $covariance += $dx * $dy;
            $varianceX += $dx * $dx;
            $varianceY += $dy * $dy;
        }

        if ($varianceX <= 0.0 || $varianceY <= 0.0) {
            return null; // biri hic hareket etmemis (sabit fiyat) - korelasyon tanimsiz
        }

        return $covariance / sqrt($varianceX * $varianceY);
    }

    // Binance'in mum (kline) verisini ceker - genel/public bir uc nokta oldugundan imza gerektirmez
    // Her mum: [acilis zamani, acilis, en yuksek, en dusuk, kapanis, hacim, ...]
    private function fetchKlines(string $symbol, string $interval, int $limit): array
    {
        $endpoint = '/api/v3/klines?' . http_build_query([
            'symbol' => $symbol,
            'interval' => $interval,
            'limit' => $limit,
        ]);

        $decoded = $this->fetchPublicJson($endpoint);

        return is_array($decoded) ? $decoded : [];
    }

    // BTC'nin kendi son 24 saatlik fiyat degisimini doner. Cogu altcoin BTC ile yuksek
    // korelasyonlu hareket eder - bir altcoin'in kendi hacim/momentum sinyali ne kadar iyi
    // gorunurse gorunsun, BTC belirgin dususteyse o altcoin de BTC ile birlikte surukelenebilir.
    // Cagiran taraf (AutoTradeController) bunu genel piyasa rejimi filtresi olarak kullanir.
    public function getBtcPriceChangePercent(): float
    {
        $tickers = $this->fetch24hrTickers();

        foreach ($tickers as $ticker) {
            if (($ticker['symbol'] ?? '') === 'BTCUSDT') {
                return (float) ($ticker['priceChangePercent'] ?? 0);
            }
        }

        return 0.0;
    }

    // TEK bir sembolun gercek 24 saatlik fiyat degisimi/hacmini, ZATEN onbellege alinmis
    // (fetch24hrTickers()'in ayni MarketScanner ornegi icinde TEK seferde cektigi ~2000+
    // paritelik liste) veriden doner - EK bir Binance API cagrisi YAPMAZ. Sosyal Radar
    // (CoinGecko trending) gibi MarketScanner'in kendi Top-N havuzu DISINDAN gelen adaylara
    // da gercek piyasa verisi beslemek icin eklendi - 15 Temmuz'da tespit edildi: bu adaylara
    // veri gitmeyince SentimentService "veri yok" genel promptuna dusuyor, GPT de dusuk bilgiyle
    // farkli coinler icin ayni/benzer skor+gerekceye (ör. hepsi 75, kalip cumleler) yoneliyordu
    // @return array{priceChangePercent: float, quoteVolume: float, lastPrice: float, high_24h?: float, low_24h?: float, position_percent_24h?: float}|null sembol bulunamazsa null
    public function getTickerData(string $symbol): ?array
    {
        try {
            $tickers = $this->fetch24hrTickers();
        } catch (Throwable $e) {
            return null;
        }

        foreach ($tickers as $ticker) {
            if (($ticker['symbol'] ?? '') === $symbol) {
                $tickerData = [
                    'priceChangePercent' => (float) ($ticker['priceChangePercent'] ?? 0),
                    'quoteVolume' => (float) ($ticker['quoteVolume'] ?? 0),
                    'lastPrice' => (float) ($ticker['lastPrice'] ?? 0),
                ];

                $dailyPeak = $this->calculateDailyPeakPosition($ticker);

                if ($dailyPeak !== null) {
                    $tickerData += $dailyPeak;
                }

                return $tickerData;
            }
        }

        return null;
    }

    // BTC'nin KISA vadeli (son N dakika, 1 dakikalik mumlarla) fiyat degisimini doner -
    // getBtcPriceChangePercent()'in (24 saatlik) aksine, Trade Post-Mortem'in "Lider Etkisi"
    // kuralinin bir pozisyon kapandigi ANDA BTC'nin ani bir dususte olup olmadigini tespit etmesi
    // icin kullanilir. Yetersiz veri/hata durumunda null doner (fail-open - analiz zincirini kilitlemez)
    public function getBtcRecentChangePercent(int $minutes = 15): ?float
    {
        try {
            $klines = $this->fetchKlines('BTCUSDT', '1m', $minutes + 1);
        } catch (Throwable $e) {
            return null;
        }

        if (count($klines) < 2) {
            return null;
        }

        $priceThen = (float) $klines[0][1]; // ilk mumun acilis fiyati (~N dakika once)
        $priceNow = (float) end($klines)[4]; // son mumun kapanis fiyati (guncel)

        if ($priceThen <= 0) {
            return null;
        }

        return (($priceNow - $priceThen) / $priceThen) * 100;
    }

    // Coklu Zaman Dilimi (MTF) Trend Filtresi: 1H/4H gibi BUYUK bir zaman diliminde, mevcut fiyatin
    // ana trend gostergesinin (EMA200) ALTINDA mi USTUNDE mi oldugunu doner. Kisa vadeli (15m/5m)
    // bir hacim patlamasi, ana trend hala dususteyse "Olu Kedi Sicramasi" (dead cat bounce) tuzagi
    // olabilir - bu kontrol GPT/pusu skorundan tamamen BAGIMSIZ, gercek fiyat konumuna dayalidir.
    // Yetersiz mum verisi varsa (ör. yeni listelenen coin) null doner (fail-open - sistemi kilitlemez)
    // @return bool|null true=fiyat EMA200 ALTINDA (dususte), false=USTUNDE/UZERINDE, null=yetersiz veri
    public function isPriceBelowLongTermTrend(string $symbol, string $interval, int $emaPeriod = 200): ?bool
    {
        try {
            $klines = $this->fetchKlines($symbol, $interval, $emaPeriod + 10);
        } catch (Throwable $e) {
            return null;
        }

        if (count($klines) < $emaPeriod) {
            return null;
        }

        $closes = array_map(static fn (array $k): float => (float) $k[4], $klines);
        $ema = $this->calculateFinalEma($closes, $emaPeriod);

        if ($ema === null) {
            return null;
        }

        return $closes[count($closes) - 1] < $ema;
    }

    // Bir kapanis fiyati serisinin SADECE SON (guncel) EMA degerini doner - TechnicalScoreEngine'in
    // tum seriyi doner emaSeries()'inden farkli olarak, burada tek bir "su an ustunde mi altinda mi"
    // karsilastirmasi yeterli oldugu icin daha hafif bir hesaplama kullanilir
    private function calculateFinalEma(array $closes, int $period): ?float
    {
        $n = count($closes);

        if ($n < $period) {
            return null;
        }

        $multiplier = 2 / ($period + 1);
        $ema = array_sum(array_slice($closes, 0, $period)) / $period;

        for ($i = $period; $i < $n; $i++) {
            $ema = (($closes[$i] - $ema) * $multiplier) + $ema;
        }

        return $ema;
    }

    // Emir Defteri (Order Book) Duvar Analizi: mevcut fiyatin $wallPercent kadar UZERINDEKI SATIS
    // (ask) toplam hacmini, AYNI oranda ALTINDAKI ALIS (bid) toplam hacmiyle karsilastirir. Satis
    // hacmi, alis hacminin $wallRatio katindan fazlaysa "Satis Duvari" tespit edilmis sayilir -
    // fiyatin bu seviyeye carpip geri donme (reddedilme) riski yuksektir. Binance'in genel (public)
    // /api/v3/depth uc noktasini kullanir, imza gerektirmez. Hata/yetersiz veri durumunda null
    // doner (fail-open - bir API hatasi asla yanlislikla bir alimi engellememeli)
    // @return array{ask_volume: float, bid_volume: float, ratio: float, wall_detected: bool}|null
    public function analyzeOrderBookWall(string $symbol, float $wallPercent = 3.0, float $wallRatio = 3.0): ?array
    {
        try {
            $depth = $this->fetchPublicJson('/api/v3/depth?' . http_build_query([
                'symbol' => strtoupper($symbol),
                'limit' => 500,
            ]));
        } catch (Throwable $e) {
            return null;
        }

        return $this->computeOrderBookWall($depth['bids'] ?? [], $depth['asks'] ?? [], $wallPercent, $wallRatio);
    }

    // analyzeOrderBookWall()'un SAF hesaplama kismi - Binance'ten cekilmis $bids/$asks dizilerini
    // parametre olarak alir (HTTP cagrisi YAPMAZ), boylece birim testlerinde gercek API'ye
    // baglanmadan senkron olarak dogrulanabilir
    // @param array<int, array{0: string, 1: string}> $bids [fiyat, miktar] ciftleri, BUYUKTEN KUCUGE sirali
    // @param array<int, array{0: string, 1: string}> $asks [fiyat, miktar] ciftleri, KUCUKTEN BUYUGE sirali
    private function computeOrderBookWall(array $bids, array $asks, float $wallPercent, float $wallRatio): ?array
    {
        if ($bids === [] || $asks === []) {
            return null;
        }

        // En iyi (best) bid/ask ortalamasi "mevcut fiyat" olarak kullanilir - ayrica bir ticker
        // cagrisi gerektirmez, dogrudan tahtanin kendisinden turetilir
        $bestBid = (float) $bids[0][0];
        $bestAsk = (float) $asks[0][0];
        $midPrice = ($bestBid + $bestAsk) / 2;

        if ($midPrice <= 0) {
            return null;
        }

        $askCeiling = $midPrice * (1 + $wallPercent / 100);
        $bidFloor = $midPrice * (1 - $wallPercent / 100);

        // Binance /depth yaniti FIYATA GORE SIRALI doner: asks KUCUKTEN BUYUGE, bids BUYUKTEN
        // KUCUGE - esik gecilince dongu erken sonlandirilabilir (tum 500 seviyeyi taramaya gerek yok)
        $askVolume = 0.0;

        foreach ($asks as $level) {
            $price = (float) $level[0];

            if ($price > $askCeiling) {
                break;
            }

            $askVolume += (float) $level[1];
        }

        $bidVolume = 0.0;

        foreach ($bids as $level) {
            $price = (float) $level[0];

            if ($price < $bidFloor) {
                break;
            }

            $bidVolume += (float) $level[1];
        }

        $ratio = $bidVolume > 0 ? $askVolume / $bidVolume : ($askVolume > 0 ? PHP_FLOAT_MAX : 0.0);

        return [
            'ask_volume' => $askVolume,
            'bid_volume' => $bidVolume,
            'ratio' => $ratio,
            'wall_detected' => $ratio >= $wallRatio,
        ];
    }

    // Su an SPOT'ta aktif islem goren (status=TRADING) tum USDT paritelerinin sembol listesini dondurur
    // "Yeni listelenen" tespiti icin kullanilir: bu liste onceki taramayla karsilastirilir.
    // 21 Temmuz'da tespit edildi: /api/v3/exchangeInfo TUM borsa bilgisini dondurur (ticker/24hr
    // kadar buyuk) ama yanlislikla varsayilan TIMEOUT_SECONDS (5sn) ile cagriliyordu - Duyuru
    // Avcisi'nin run()'inin EN BASINDA, hicbir try/catch OLMADAN cagrildigi icin bu zaman asimi
    // dogrudan ListingSniperController'a kadar yukselip HTTP 500'e donusuyordu (bkz. CHANGELOG)
    public function fetchTradableUsdtSymbols(): array
    {
        $exchangeInfo = $this->fetchPublicJson('/api/v3/exchangeInfo', self::LARGE_PAYLOAD_TIMEOUT_SECONDS);
        $symbols = [];

        foreach ($exchangeInfo['symbols'] ?? [] as $entry) {
            if (($entry['status'] ?? '') === 'TRADING' && ($entry['quoteAsset'] ?? '') === 'USDT') {
                $symbols[] = (string) $entry['symbol'];
            }
        }

        return $symbols;
    }

    // Duyuru Avcisi'nin genis taramasi icin: TUM USDT paritelerinin GUNCEL statusunu (TRADING/
    // PRE_TRADING/BREAK vb.) tek istekte ceker - fetchTradableUsdtSymbols()'in aksine status=TRADING
    // filtresi UYGULAMAZ, cunku asil hedef HENUZ TRADING olmayan (PRE_TRADING/BREAK) sembolleri
    // yakalamaktir. serverTime, Binance'in KENDI epoch zamanidir (ms) - paylasimli hostingin yerel
    // saati kaymis olabilecegi icin tight-poll dongusunun sure hesabinin buna guvenebilmesi icindir
    // @return array{server_time_epoch: int, statuses: array<string, string>} sembol => status
    public function fetchExchangeInfoStatuses(): array
    {
        $exchangeInfo = $this->fetchPublicJson('/api/v3/exchangeInfo', self::LARGE_PAYLOAD_TIMEOUT_SECONDS);
        $statuses = [];

        foreach ($exchangeInfo['symbols'] ?? [] as $entry) {
            if (($entry['quoteAsset'] ?? '') === 'USDT') {
                $statuses[(string) $entry['symbol']] = (string) ($entry['status'] ?? '');
            }
        }

        return [
            'server_time_epoch' => (int) ($exchangeInfo['serverTime'] ?? (time() * 1000)),
            'statuses' => $statuses,
        ];
    }

    // Tight-poll dongusunun HER turunda TEK bir sembolu sorgular - fetchExchangeInfoStatuses()'un
    // cektigi ~17 MB'lik tum borsa bilgisi yerine, Binance'in ?symbol= parametresini kullanan cok
    // daha hafif bir istek atar. Saniyede birkac kez cagrilabilecek kadar hafif olmasi, listelemenin
    // TAM ANini yakalayabilmek icin kritiktir
    // @return array{status: string, server_time_epoch: int}|null sembol artik mevcut degilse/gecici bir hata olursa null (fail-open - dongu bir sonraki turda tekrar dener)
    public function fetchSingleSymbolStatus(string $symbol): ?array
    {
        try {
            $exchangeInfo = $this->fetchPublicJson('/api/v3/exchangeInfo?' . http_build_query(['symbol' => $symbol]));
        } catch (Throwable $e) {
            return null;
        }

        $entry = $exchangeInfo['symbols'][0] ?? null;

        if ($entry === null) {
            return null;
        }

        return [
            'status' => (string) ($entry['status'] ?? ''),
            'server_time_epoch' => (int) ($exchangeInfo['serverTime'] ?? (time() * 1000)),
        ];
    }

    private function isEligibleSymbol(string $symbol): bool
    {
        if (!str_ends_with($symbol, 'USDT')) {
            return false;
        }

        $baseAsset = substr($symbol, 0, -4);

        if (in_array($baseAsset, self::EXCLUDED_BASE_ASSETS, true)) {
            return false;
        }

        foreach (self::EXCLUDED_BASE_SUFFIXES as $suffix) {
            if (str_ends_with($baseAsset, $suffix)) {
                return false;
            }
        }

        return true;
    }

    // Tum pariteleri iceren buyuk (~17 MB) yanit - ayni PHP istegi/MarketScanner ornegi icinde
    // (scanTopMovers + getBtcPriceChangePercent gibi) birden fazla kez cagrilirsa TEKRAR INDIRILMESIN
    // diye onbelleklenir. Bu, hem gereksiz yavasligi hem de zaman asimi riskini onler.
    private ?array $tickersCache = null;

    private function fetch24hrTickers(): array
    {
        if ($this->tickersCache === null) {
            $this->tickersCache = $this->fetchPublicJson('/api/v3/ticker/24hr', self::LARGE_PAYLOAD_TIMEOUT_SECONDS);
        }

        return $this->tickersCache;
    }

    // Binance'in genel (kimlik dogrulamasiz) uc noktalarina GET istegi atip JSON govdesini dondurur
    private function fetchPublicJson(string $endpoint, ?int $timeoutSeconds = null): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => self::BASE_URL . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds ?? self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
        ]);

        $rawResponse = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrno !== 0) {
            $this->logError("cURL hatasi ({$curlErrno}): {$curlError}");
            // BinanceApiTimeoutException (RuntimeException'dan turer) - cagiran taraf bunu genel
            // hatalardan AYIRT edip "gecici API yavasligi" icin farkli/zarif bir yanit verebilir
            throw new BinanceApiTimeoutException('Piyasa verisi alinamadi (baglanti hatasi veya zaman asimi).');
        }

        if ($httpCode >= 400) {
            $this->logError("HTTP {$httpCode} - {$endpoint} istegi basarisiz");
            throw new RuntimeException('Binance piyasa verisi servisi hata dondurdu.');
        }

        $decoded = json_decode((string) $rawResponse, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function logError(string $message): void
    {
        $logDir = __DIR__ . '/../../storage/logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $entry = sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $message, PHP_EOL);
        file_put_contents($logDir . '/market_scanner_errors.log', $entry, FILE_APPEND);
    }
}
