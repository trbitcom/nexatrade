<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\ActiveFuturesTrade;
use App\Models\AiIntervention;
use App\Models\ApiKey;
use App\Models\BotLog;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use RuntimeException;
use Throwable;

// Binance Futures uzerinde SADECE KISA (short) pozisyon acan, kaldiracli/yuksek riskli opt-in modul.
// v1 kapsami bilincli olarak dar tutulur: sabit dusuk kaldirac (kullanicinin kendi ai_score_threshold'undan
// simetrik turetilen bir esikle), sadece isolated marj, kullanici basina en fazla 1 esolan pozisyon.
// AutoTradeController'in (spot/UZUN) LONG mantigina dokunmaz - tamamen paralel, bagimsiz calisir;
// tek ortak nokta RiskManagerService'in devre kesicisidir (paylasilan risk profili felsefesi)
final class FuturesTradingService
{
    private const MIN_ORDER_MARGIN_USDT = 5.0;
    // v1: kullanici basina ayni anda en fazla 1 acik KISA pozisyon - kaldirac riskini siki tutar
    private const MAX_ACTIVE_FUTURES_TRADES = 1;
    // GPT skoru (1-100, 100=en boğa) bu esigin ALTINDAYSA "yeterince ayı" sayilir - BUY_THRESHOLD'un (80)
    // simetrik yansimasi, short'lar long'larla AYNI siddette AI guveni gerektirir
    private const SHORT_MAX_SCORE_THRESHOLD = 20;
    // BTC son 24 saatte bu yuzdeden FAZLA yukseldiyse bu turda YENI short ACILMAZ - cogu altcoin
    // BTC ile yuksek korelasyonlu hareket eder, guclu bir BTC pompasinda "ayi" sinyaller bile yanilir
    private const BTC_UPTREND_THRESHOLD_PERCENT = 3.0;
    // RSI bu esigin ALTINDAYSA (asiri satilmis) short ACILMAZ - "tepede" degil "dipte" short'a girme riski
    private const RSI_OVERSOLD_THRESHOLD = 30.0;
    // Acik pozisyon mutabakati (reconcileOpenPositions) HER cron turunda calisir - kaldiracli
    // pozisyonlarda hizli tepki kritik, GPT/OpenAI cagrisi gerektirmez, ucretsiz ve hizli bir kontroldur.
    // YENI sinyal taramasi ise (GPT dahil) bu araliktan daha sik calismaz - cron'un kendisi 1 dk'da bir
    // tetiklense bile, taramanin/OpenAI maliyetinin 5x artmasini onler
    private const SCAN_INTERVAL_SECONDS = 300;
    private const LAST_SCAN_SETTING_KEY = 'futures_last_scan_at';

    // Ardisik Binance Klines cagrilari (calculateMacroTrend) arasindaki ufak bekleme - bkz.
    // AutoTradeController'daki ayni mantik: tarama kapasitesi buyudukce rate limit riski artiyordu
    private const KLINES_REQUEST_DELAY_MICROSECONDS = 100_000; // 0.1 saniye

    // --- Izleyen Stop (Futures) ---
    // 20 Temmuz'da eklendi - spot'taki AutoTradeController'in ayni iki-asamali tasarimi (ONCE sabit
    // bir esikte breakeven+pay kilitle, SONRA surekli izle). Tetik/kilit/izleme yuzdeleri artik SABIT
    // DEGIL - kullanici bazli DB ayarlari (bkz. ApiKey::getTrailingSettings(), applyTrailingStopIfEligible()
    // asagida), varsayilanlari spot'tan BILINCLI olarak daha SIKI/erken kalibre edilmisti (spot: %1.5
    // tetik/%0.3 kilit, futures varsayilani: %1.0/%0.2) - kaldiracli/likidasyon riski nedeniyle.
    // SHORT'ta yon TERSTIR: kar fiyat DUSTUKCE olusur, bu yuzden tetik/kilit/izleme hep "asagi" yonde okunmali

    // Zarar Kes mevcut seviyesine gore en az bu kadar iyilesmedikce (SHORT'ta "iyilesme" = ASAGI
    // inmesi) emir YENIDEN KURULMAZ - spot'taki AYNI "gereksiz iptal/yeniden-kur donguSu" onlemi
    private const FUTURES_TRAILING_MIN_IMPROVEMENT_PERCENT = 0.3;

    // bkz. AutoTradeController::MAX_SAFE_ELAPSED_SECONDS_BEFORE_BUY yorumu - AYNI RCA (22 Temmuz,
    // BANKUSDT/ERAUSDT): PHP'nin YAKALANAMAYAN zaman asimi, bir Binance emri basarili olduktan
    // HEMEN SONRA vurursa DB kaydi/koruma hic yapilamaz. Futures KALDIRACLI oldugu icin bu risk
    // spot'tan bile daha ciddi - ayni 180sn butcesinden 30sn guvenlik payi ayrilir
    private const MAX_SAFE_ELAPSED_SECONDS_BEFORE_OPEN = 180 - 30;

    private readonly TelegramService $telegram;
    private readonly RiskManagerService $riskManager;
    private float $requestStartedAt = 0.0;

    public function __construct()
    {
        $this->telegram = new TelegramService();
        $this->riskManager = new RiskManagerService();
    }

    // @return array{reconciled: int, scanned: array, selected: string|null, selected_score: int|null, opened: int}
    public function run(): array
    {
        $this->requestStartedAt = microtime(true);

        // Sistem Durumu widget'i icin: LAST_SCAN_SETTING_KEY'den FARKLI olarak HER turda (throttle'a
        // bakmadan) kosulsuz kaydedilir - ListingSniperService/SmartMoneyTracker::run()'daki AYNI
        // desen (bkz. DashboardController::apiSystemStatus)
        Setting::set('futures_last_run_at', (string) time());

        // Acik pozisyon takibi HER turda calisir (kaldiracli pozisyonlarda hizli tepki onemli, GPT
        // gerektirmez) - cron 1 dk'da bir tetiklenirse fiyat kontrolu de 1 dk'da bir yapilmis olur
        $reconciled = $this->reconcileOpenPositions();

        if (!$this->shouldRunScan()) {
            return ['reconciled' => $reconciled, 'scanned' => [], 'selected' => null, 'selected_score' => null, 'opened' => 0];
        }

        Setting::set(self::LAST_SCAN_SETTING_KEY, (string) time());

        // Tarama kapasitesi 10'dan 25'e cikarilinca, her aday icin ardisik Binance Klines + OpenAI
        // cagrisinin toplam suresi paylasimli hostinglerin varsayilan PHP max_execution_time'ini
        // (genelde 30sn) asabiliyor - bkz. AutoTradeController'daki ayni onlem
        set_time_limit(180);

        $scanner = new MarketScanner();
        // 25 Temmuz'da eklendi: AI Avci'nin Beyaz Listesi'nden BAGIMSIZ - o liste spot icin backtest'te
        // KANITLANMIS YUKSELIS adaylarina odaklanmak amacli secildi, futures ise TAM TERSINE DUSUS
        // adayi ariyor. Ayni listeyi ikisine uygulamak futures'in aday havuzunu anlamsizca daraltiyordu
        $topMovers = $scanner->scanTopMovers(ignoreWhitelist: true);

        // Sadece NEGATIF yonde hareket eden (dususte olan) adaylar short icin degerlendirilir -
        // scanTopMovers() hem yukselen hem dusen coinleri (|degisim| buyuklugune gore) dondurebilir
        $bearishCandidates = array_values(array_filter(
            $topMovers,
            static fn (array $m): bool => $m['priceChangePercent'] < 0
        ));

        if ($bearishCandidates === []) {
            return ['reconciled' => $reconciled, 'scanned' => [], 'selected' => null, 'selected_score' => null, 'opened' => 0];
        }

        $candidateSymbols = array_column($bearishCandidates, 'symbol');
        $marketDataMap = [];

        $isFirstMover = true;
        foreach ($bearishCandidates as $mover) {
            if (!$isFirstMover) {
                usleep(self::KLINES_REQUEST_DELAY_MICROSECONDS);
            }
            $isFirstMover = false;

            $marketDataMap[$mover['symbol']] = [
                'priceChangePercent' => $mover['priceChangePercent'],
                'quoteVolume' => $mover['quoteVolume'],
                // Karma Radar'in bu adayi hangi stratejiden sectigi - bkz. AutoTradeController'daki
                // ayni mantik, bot_logs.input_data icinde kalici olarak saklanir
                'strategy_bucket' => $mover['strategy_bucket'] ?? null,
            ];

            // 3 aylik makro trend (veritabanina kaydedilmez, aninda Binance'ten cekilir) - bkz.
            // AutoTradeController'daki ayni mantik: GPT'nin sadece 24s harekete bakip yanlis
            // yonde asiri emin olmasini onlemek icin
            $macroTrend = $scanner->calculateMacroTrend($mover['symbol']);

            if ($macroTrend !== null) {
                $marketDataMap[$mover['symbol']] += $macroTrend;
            }
        }

        // Deterministik Motor (4 Agustos'ta eklendi): AutoTradeController'daki AYNI 'decision_motor'
        // Setting'i paylasilir - musteri talebi "AI'a gerek yok, motor karar versin" (futures da spot
        // gibi GPT'siz calissin). TechnicalScoreEngine (MarketScanner::calculateTechnicalScore ile
        // sarilir) ZATEN CIFT YONLU: dusen fiyat/MA20, negatif MACD, azalan hacim gibi HER bearish
        // sinyal icin puani 50'den DUSURUR - yani DUSUK skor GERCEK bir "ayi" okumasidir, sadece
        // "boga sinyali yok" degil. Ayri bir bearish motor yazmaya gerek kalmadan AYNI fonksiyon short
        // adaylari icin de kullanilir - long'da YUKSEK skor arandigi gibi burada DUSUK skor aranir.
        // $analyses'in sekli (symbol/score/reason) SentimentService::analyzeMany() ile AYNI tutulur
        // ki asagidaki BTC filtresi/RSI/hacim dongusu HICBIR DEGISIKLIK gerektirmeden calismaya devam etsin
        if ($this->getDecisionMotor() === 'deterministic') {
            $analyses = [];

            foreach ($candidateSymbols as $symbol) {
                $priceChangePercent = (float) ($marketDataMap[$symbol]['priceChangePercent'] ?? 0.0);

                try {
                    $technicalScore = $scanner->calculateTechnicalScore($symbol, $priceChangePercent, null);
                } catch (Throwable $e) {
                    $technicalScore = null;
                }

                if ($technicalScore === null) {
                    continue;
                }

                $analyses[] = [
                    'symbol' => $symbol,
                    'score' => (int) $technicalScore['score'],
                    'reason' => (string) $technicalScore['reason'],
                ];

                $this->logFutures(sprintf(
                    'Deterministik Motor: %s için skor %d - %s',
                    $symbol,
                    $technicalScore['score'],
                    $technicalScore['reason']
                ));
            }
        } else {
            $sentiment = new SentimentService();
            $analyses = $sentiment->analyzeMany($candidateSymbols, $marketDataMap);
        }

        usort($analyses, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);

        $btcChangePercent = $scanner->getBtcPriceChangePercent();
        $btcUptrend = $btcChangePercent >= self::BTC_UPTREND_THRESHOLD_PERCENT;

        $selected = null;

        if ($btcUptrend) {
            $this->logFutures(sprintf(
                'BTC yükseliş filtresi: BTC son 24 saatte %%%.2f değişti (eşik %%%.2f), bu turda yeni short atlandı.',
                $btcChangePercent,
                self::BTC_UPTREND_THRESHOLD_PERCENT
            ));
        } else {
            // Ardisik Aday Deneme: $analyses skora gore ARTAN sirali (en dusuk/en "bearish" once) -
            // eskiden SADECE esigi gecen ILK aday RSI/hacim filtresinden gecirilir, o elenirse tur
            // TAMAMEN atlanirdi. Canli olayda tespit edildi: DEXEUSDT haftalarca 1. sirada kalip
            // surekli RSI filtresine takilirken, esigi gecen diger adaylarin hicbiri denenmiyordu.
            // Artik esigi gecen HER aday, biri RSI+hacim filtresini gecene (veya aday tukenene) kadar
            // sirayla denenir - skor esigini asan ilk adaydan sonra donguden cikilir (sirali oldugu icin)
            foreach ($analyses as $analysis) {
                if ($analysis['score'] > self::SHORT_MAX_SCORE_THRESHOLD) {
                    break;
                }

                $rsi = $scanner->calculateRsi($analysis['symbol']);

                if ($rsi !== null && $rsi < self::RSI_OVERSOLD_THRESHOLD) {
                    $this->logFutures(sprintf(
                        'RSI filtresi: %s için RSI %.1f (aşırı satılmış, eşik %.1f) - bu turda short atlandı.',
                        $analysis['symbol'],
                        $rsi,
                        self::RSI_OVERSOLD_THRESHOLD
                    ));
                    continue;
                }

                if (!$scanner->isVolumeIncreasing($analysis['symbol'])) {
                    $this->logFutures(sprintf(
                        'Hacim trendi filtresi: %s için son saatlerdeki hacim artmıyor - bu turda short atlandı.',
                        $analysis['symbol']
                    ));
                    continue;
                }

                $selected = $analysis;
                break;
            }
        }

        $opened = 0;

        if ($selected !== null) {
            $strategyBucket = $marketDataMap[$selected['symbol']]['strategy_bucket'] ?? null;
            $opened = $this->shortForAllUsers($selected['symbol'], (int) $selected['score'], $strategyBucket);
        }

        // Bu taramanin yapisal ozetini bot_logs'a kaydet (trade_type='futures' ile spot'tan ayrilir) -
        // input_data, GPT'ye gonderilen ham piyasa verisini (fiyat degisimi, hacim, 90 gunluk makro
        // trend) de icerir, ileride "GPT bu karari verirken piyasa nasildi?" backtest/analizi icin
        BotLog::create(
            scannedSymbols: $candidateSymbols,
            aiScores: $analyses,
            selectedSymbol: $selected['symbol'] ?? null,
            selectedScore: $selected['score'] ?? null,
            positionsOpened: $opened,
            tradeType: 'futures',
            inputData: $marketDataMap
        );

        return [
            'reconciled' => $reconciled,
            'scanned' => $candidateSymbols,
            'selected' => $selected['symbol'] ?? null,
            'selected_score' => $selected['score'] ?? null,
            'opened' => $opened,
        ];
    }

    // YENI sinyal taramasinin (GPT dahil) en son ne zaman yapildigini kontrol eder - cron cok sik
    // (ör. 1 dk) tetiklense bile tarama SCAN_INTERVAL_SECONDS'tan daha sik calismaz
    private function shouldRunScan(): bool
    {
        $lastScanAt = Setting::get(self::LAST_SCAN_SETTING_KEY);

        if ($lastScanAt === null) {
            return true;
        }

        return (time() - (int) $lastScanAt) >= self::SCAN_INTERVAL_SECONDS;
    }

    // AutoTradeController::getDecisionMotor() ile BIREBIR AYNI desen/Setting anahtari - 4 Agustos'ta
    // eklendi, futures'in da spot gibi GPT'siz (deterministik) calisabilmesi icin. Taninmayan/bos bir
    // deger fail-open GUVENLI VARSAYILANA (ai, mevcut/bilinen davranis) duser
    private function getDecisionMotor(): string
    {
        $motor = Setting::get('decision_motor');

        if ($motor === null) {
            $config = require __DIR__ . '/../../config/app.php';
            $motor = (string) ($config['decision_motor'] ?? 'ai');
        }

        return $motor === 'deterministic' ? 'deterministic' : 'ai';
    }

    private function shortForAllUsers(string $pair, int $score, ?string $strategyBucket = null): int
    {
        $users = ApiKey::findAllForFuturesTrade();
        $opened = 0;

        foreach ($users as $userKey) {
            $userId = (int) $userKey['user_id'];

            // bkz. Database::ensureConnected() yorumu - her kullanici icin Binance/AI cagrilari
            // birikince baglanti kopmus olabilir
            Database::ensureConnected();

            // Kullanicinin KENDI ai_score_threshold'unun simetrigi: uzun icin >=threshold gerekiyorsa,
            // short icin <=(100-threshold) gerekir - ayni "guven siddeti" iki yonde de korunur
            $userThreshold = (int) ($userKey['ai_score_threshold'] ?? 80);

            if ($score > (100 - $userThreshold)) {
                continue;
            }

            if (ActiveFuturesTrade::countOpenForUser($userId) >= self::MAX_ACTIVE_FUTURES_TRADES) {
                continue;
            }

            if (ActiveFuturesTrade::hasOpenPositionForPair($userId, $pair)) {
                continue;
            }

            try {
                $futures = new BinanceFuturesService($userKey['api_key'], $userKey['secret_key']);
                $usdtBalance = $futures->getUsdtBalance();
                $openPositionsMargin = $this->calculateOpenFuturesMargin($userId);
                $totalEquity = $usdtBalance + $openPositionsMargin;
            } catch (Throwable $e) {
                $this->logFutures("Kullanıcı #{$userId}: futures bakiyesi alınamadı - " . $e->getMessage());
                continue;
            }

            $blockReason = $this->riskManager->checkFuturesCircuitBreaker($userId, $userKey, $totalEquity);

            if ($blockReason !== null) {
                $this->logFutures("Kullanıcı #{$userId} futures devre kesici: {$blockReason}");

                // Kullanici kilitli kaldigi surece cron her dondugunde ayni Telegram mesaji
                // tekrar tekrar atilmasin diye gunde en fazla 1 bildirim gonderilir (spot ile
                // AYNI susturucu sutununu paylasir - kullanici tek bir kisi, ayri saymaya gerek yok)
                if (!ApiKey::hasSentCooldownNotifToday($userId)) {
                    $this->notifyAdminAndCustomer($userId, "⛔ [NexaTrade Futures] Devre Kesici Tetiklendi\nSebep: {$blockReason}");
                    ApiKey::markCooldownNotifSent($userId);
                }

                continue;
            }

            try {
                if ($this->openShort($futures, $userId, $userKey, $pair, $usdtBalance, $openPositionsMargin, $totalEquity, $strategyBucket)) {
                    $opened++;
                }
            } catch (Throwable $e) {
                $this->logFutures("Kullanıcı #{$userId} için {$pair} short açılırken hata: " . $e->getMessage());
                continue;
            }
        }

        return $opened;
    }

    private function openShort(
        BinanceFuturesService $futures,
        int $userId,
        array $userKey,
        string $pair,
        float $usdtBalance,
        float $openPositionsMargin,
        float $totalEquity,
        ?string $strategyBucket = null
    ): bool {
        $budgetPercent = (float) ($userKey['auto_trade_budget_percent'] ?? 10.0);
        $maxPortfolioRiskPercent = (float) ($userKey['max_portfolio_risk_percent'] ?? 30.0);
        $baseLeverage = max(1, (int) ($userKey['futures_leverage'] ?? 3));
        $leverage = $this->calculateDynamicLeverage($pair, $baseLeverage);

        // budgetPercent burada MARJ'a uygulanir (spot'taki gibi "bakiyenin yuzdesi") - gercek pozisyon
        // buyuklugu (notional) bu marjin kaldiracla carpimidir. Boylece "butce yuzdesi" kavrami
        // spot ile futures arasinda TUTARLI kalir: her iki modulde de "ne kadar nakdimi riske atiyorum"
        $margin = $usdtBalance * ($budgetPercent / 100);

        if ($margin < self::MIN_ORDER_MARGIN_USDT) {
            throw new RuntimeException(sprintf(
                'Hesaplanan marj asgari limitin altında: %.2f USDT (bakiye: %.2f USDT, oran: %%%.1f)',
                $margin,
                $usdtBalance,
                $budgetPercent
            ));
        }

        // Portfoy risk tavani MARJ uzerinden hesaplanir (kaldiracli notional degil) - kaldirac zaten
        // likidasyon riskini ayrica tasidigi icin, "ne kadar nakdi taahhut ettim" olcusu marjdir
        $projectedMarginExposure = $openPositionsMargin + $margin;
        $projectedExposurePercent = $totalEquity > 0 ? ($projectedMarginExposure / $totalEquity) * 100 : 0.0;

        if ($totalEquity > 0 && $projectedExposurePercent > $maxPortfolioRiskPercent) {
            throw new RuntimeException(sprintf(
                'Toplam portföy risk tavanı aşılıyor: açık marj + yeni işlem %%%.1f (tavan %%%.1f)',
                $projectedExposurePercent,
                $maxPortfolioRiskPercent
            ));
        }

        $price = $futures->getPrice($pair);

        if ($price <= 0) {
            throw new RuntimeException("Geçerli fiyat alınamadı: {$pair}");
        }

        $filters = $futures->getSymbolFilters($pair);
        $stepSize = $filters['step_size'] > 0 ? $filters['step_size'] : 0.001;
        $tickSize = $filters['tick_size'] > 0 ? $filters['tick_size'] : 0.00000001;

        // Kaldiracli notional uzerinden HEDEFLENEN (henuz yuvarlanmamis) miktar - LotSizeGuardService
        // BUNU degerlendirir, floorToStep()'in kendisini DEGIL (kaldirac butceyi buyuttugu icin
        // yuvarlama kaybinin ORANSAL etkisi spot'takinden bile daha az onemli GORUNEBILIR, ama
        // likidasyon riski zaten var oldugu icin ayni %0.5 disiplinini burada da uygulamak dogru)
        $notional = $margin * $leverage;
        $targetQuantity = $notional / $price;

        // LOT_SIZE (Adim Yuvarlama) Guvenlik Kalkani: spot tarafinda kullanilan AYNI paylasilan
        // LotSizeGuardService - Futures'ta LONG/SHORT ayrimi yapilmaz, bu modulun v1 kapsaminda
        // ZATEN SADECE SHORT (bkz. asagidaki 'SELL' ile ACILIS) girisi var, kalkan bu TEK giris
        // noktasinin hemen onune eklendi. safe=false ise giris emri ATLANIR (denenmeden), tıpkı
        // spot'taki AutoTradeController/ListingSniperService/SmartMoneyTracker'daki gibi
        $lotSizeGuard = LotSizeGuardService::evaluate($targetQuantity, $stepSize);

        if (!$lotSizeGuard['safe']) {
            $this->logFutures(sprintf(
                'İşlem İptal Edildi: Futures %s için LOT_SIZE yuvarlama kaybı (%%%.2f) güvenlik sınırını (%%%.1f) aşıyor.',
                $pair,
                $lotSizeGuard['fire_percent'],
                LotSizeGuardService::DEFAULT_MAX_SLIPPAGE_TOLERANCE_PERCENT
            ));

            AiIntervention::record(
                $userId,
                $pair,
                'lot_size_guard',
                sprintf(
                    'Futures LOT_SIZE yuvarlama kaybı (%%%.2f) güvenlik sınırını (%%%.1f) aştığı için giriş işlemi iptal edildi.',
                    $lotSizeGuard['fire_percent'],
                    LotSizeGuardService::DEFAULT_MAX_SLIPPAGE_TOLERANCE_PERCENT
                )
            );

            return false;
        }

        $quantity = $lotSizeGuard['floored_quantity'];

        if ($quantity <= 0) {
            throw new RuntimeException('Hesaplanan miktar sıfır veya negatif çıktı.');
        }

        // bkz. MAX_SAFE_ELAPSED_SECONDS_BEFORE_OPEN yorumu: geri donusu olmayan pozisyon acma
        // cagrisindan HEMEN once - guvenli payin disina ciktiysak (olasi PHP zaman asimi riski)
        // YENI bir kaldiracli pozisyon hic acilmaz, aday bir sonraki cron turunde tekrar denenir
        $elapsedSeconds = microtime(true) - $this->requestStartedAt;

        if ($elapsedSeconds > self::MAX_SAFE_ELAPSED_SECONDS_BEFORE_OPEN) {
            throw new RuntimeException(sprintf(
                'Güvenli zaman payı aşıldı (%.1fsn geçti, limit %dsn) - olası PHP zaman aşımı riskine karşı bu turda yeni pozisyon açılmadı.',
                $elapsedSeconds,
                self::MAX_SAFE_ELAPSED_SECONDS_BEFORE_OPEN
            ));
        }

        $leverageResult = $futures->setLeverage($pair, $leverage);

        if (!$leverageResult['success']) {
            throw new RuntimeException('Kaldıraç ayarlanamadı: ' . $leverageResult['error']);
        }

        // Pozisyonu ACAN emir: short'ta once SAT (piyasa fiyatindan odunc alip satar), kapanista ALINIR
        $openResult = $futures->placeMarketOrder($pair, 'SELL', $quantity);

        if (!$openResult['success']) {
            Order::create([
                'user_id' => $userId,
                'pair' => $pair,
                'side' => 'SELL',
                'type' => 'MARKET',
                'quantity' => $quantity,
                'price' => $price,
                'total' => round($quantity * $price, 8),
                'binance_order_id' => null,
                'status' => 'FAILED',
                'error_message' => $openResult['error'],
                'strategy_bucket' => $strategyBucket,
            ]);

            throw new RuntimeException('Futures SHORT açma emri başarısız: ' . $openResult['error']);
        }

        // KRITIK DUZELTME: futures MARKET emrinin aninda donen POST yaniti, gerceklesme (fill)
        // verisini her zaman yansitmayabiliyor - bazen executedQty/cumQuote gecici olarak 0 donuyor,
        // halbuki emir gercekte doluyor (bkz. reconcileSelfMonitoredTrade()'deki AYNI duzeltme,
        // SYNUSDT'de canli tespit edildi). Bu duzeltme ONCEDEN sadece KAPANIS yolunda vardi - ACILIS
        // yolu korumasizdi: $cumQuote=0 iken $executedQty>0 gelirse entryPrice=0/executedQty=0
        // hesaplaniyordu, bu da TP/SL fiyatlarini (entryPrice'a gore hesaplanir) sifira, dolayisiyla
        // pozisyonu fiilen anlik olarak kendi-izleme modunde "her mark fiyatinda SL'e carpmis" gibi
        // gosterip yanlis PNL ile aninda kapatmaya yol aciyordu. executedQty<=0 ise istenen miktara,
        // cumQuote<=0 ise (emir onceki YINE ISTEK ONCESI cekilen guncel) piyasa fiyatina geri dusulur
        $raw = $openResult['raw'];
        $executedQty = (float) ($raw['executedQty'] ?? 0);
        if ($executedQty <= 0) {
            $executedQty = $quantity;
        }
        // Futures emir yanitinda spot'taki 'cummulativeQuoteQty' degil 'cumQuote' alani kullanilir
        $cumQuote = (float) ($raw['cumQuote'] ?? 0);
        $entryPrice = $cumQuote > 0 ? $cumQuote / $executedQty : $price;

        $openOrderId = $this->criticalPersist(fn () => Order::create([
            'user_id' => $userId,
            'pair' => $pair,
            'side' => 'SELL',
            'type' => 'MARKET',
            'quantity' => $executedQty,
            'price' => $entryPrice,
            'total' => $cumQuote > 0 ? $cumQuote : $notional,
            'binance_order_id' => $openResult['order_id'],
            'status' => 'FILLED',
            'strategy_bucket' => $strategyBucket,
        ]), $userId, $pair, 'Futures SHORT Açılışı');

        $this->protectShortPosition(
            $futures,
            $userId,
            $pair,
            $openOrderId,
            $entryPrice,
            $executedQty,
            $leverage,
            $tickSize,
            $margin,
            (float) ($userKey['take_profit_percent'] ?? 20.0),
            (float) ($userKey['stop_loss_percent'] ?? 10.0)
        );

        return true;
    }

    // Acilisin hemen ardindan Kar Al/Zarar Kes hedef fiyatlarini hesaplayip kaydeder. NOT: Binance'e
    // STOP_MARKET/TAKE_PROFIT_MARKET emri GONDERILMEZ - bazi dusuk hacimli/yeni futures sozlesmelerinde
    // bu emir tipleri "-4120 Order type not supported, Algo Order API kullanin" hatasiyla reddediliyor
    // (parametre farketmeksizin, closePosition VE reduceOnly+miktar ikisi de denendi, ikisi de reddedildi).
    // HIBRIT model: ONCE Binance'e native STOP_MARKET/TAKE_PROFIT_MARKET (closePosition=true) emri
    // denenir - bu, sunucumuz cevrimdisi kalsa bile borsanin ANLIK tetikleyecegi en guvenli yontemdir.
    // Bazi dusuk hacimli/yeni futures sozlesmelerinde Binance bu emir tipini "-4120 Order type not
    // supported" hatasiyla reddedebiliyor (SYNUSDT'de dogrulandi) - byle bir durumda basarili olan
    // tek emir (varsa) iptal edilip DB'ye order ID'siz kaydedilir; reconcileOpenPositions() bu durumu
    // tespit edip KENDI mark-fiyati izleme mekanizmasina (en fazla bir cron araligi gecikmeli) duser
    private function protectShortPosition(
        BinanceFuturesService $futures,
        int $userId,
        string $pair,
        int $openOrderId,
        float $entryPrice,
        float $quantity,
        int $leverage,
        float $tickSize,
        float $margin,
        float $takeProfitPercent,
        float $stopLossPercent
    ): void {

        // SHORT'ta yon TERSTIR: kar, fiyat DUSTUKCE olusur - TP entry'nin ALTINDA, SL entry'nin USTUNDEDIR
        $takeProfitPrice = $this->floorToStep($entryPrice * (1 - $takeProfitPercent / 100), $tickSize);
        $stopTriggerPrice = $this->floorToStep($entryPrice * (1 + $stopLossPercent / 100), $tickSize);

        $liquidationPrice = null;

        try {
            $positionRisk = $futures->getPositionRisk($pair);
            $liquidationPrice = $positionRisk['liquidation_price'] > 0 ? $positionRisk['liquidation_price'] : null;

            // Zarar kes, likidasyon fiyatindan ONCE tetiklenmeli - degilse pozisyon fiilen korumasizdir.
            // Kendi izleme mekanizmamiz en fazla bir cron araligi (5 dk) gecikebildigi icin bu kontrol
            // ozellikle onemli - likidasyona cok yakin bir zarar kes, cron gecikmesinde yetismeyebilir
            if ($liquidationPrice !== null && $stopTriggerPrice >= $liquidationPrice) {
                $this->logFutures(sprintf(
                    'KRİTİK: Kullanıcı #%d %s - hesaplanan zarar kes (%.8f) likidasyon fiyatının (%.8f) ÖTESİNDE! Kaldıraç: %dx',
                    $userId,
                    $pair,
                    $stopTriggerPrice,
                    $liquidationPrice,
                    $leverage
                ));
            }
        } catch (Throwable $e) {
            // Likidasyon fiyati sadece bilgi/uyari amacli - alinamamasi pozisyonu durdurmaz
        }

        // ONCE native dene: iki emir de BASARILI olursa borsa aninda tetikler (en guvenli yol)
        $takeProfitResult = $futures->placeTakeProfitMarketOrder($pair, 'BUY', $takeProfitPrice);
        $stopLossResult = $futures->placeStopMarketOrder($pair, 'BUY', $stopTriggerPrice);

        $nativeProtected = $takeProfitResult['success'] && $stopLossResult['success'];
        $takeProfitOrderId = null;
        $stopLossOrderId = null;

        if ($nativeProtected) {
            $takeProfitOrderId = $takeProfitResult['order_id'];
            $stopLossOrderId = $stopLossResult['order_id'];
        } else {
            // Biri/ikisi reddedildi - basarili olan varsa yetim kalmasin diye iptal et, ikisini de
            // NULL birakarak reconcileOpenPositions()'in kendi izleme mekanizmasina dusmesini sagla
            if ($takeProfitResult['success']) {
                $futures->cancelOrder($pair, $takeProfitResult['order_id']);
            }
            if ($stopLossResult['success']) {
                $futures->cancelOrder($pair, $stopLossResult['order_id']);
            }

            $this->logFutures(sprintf(
                'Kullanıcı #%d %s: native TP/SL emri reddedildi (TP: %s | SL: %s) - kendi izleme mekanizmasına düşüldü.',
                $userId,
                $pair,
                $takeProfitResult['success'] ? 'OK' : $takeProfitResult['error'],
                $stopLossResult['success'] ? 'OK' : $stopLossResult['error']
            ));
        }

        ActiveFuturesTrade::create([
            'user_id' => $userId,
            'pair' => $pair,
            'leverage' => $leverage,
            'margin_type' => 'isolated',
            'open_order_id' => $openOrderId,
            'quantity' => $quantity,
            'entry_price' => $entryPrice,
            'liquidation_price' => $liquidationPrice,
            'take_profit_price' => $takeProfitPrice,
            'stop_loss_price' => $stopTriggerPrice,
            'take_profit_order_id' => $takeProfitOrderId,
            'stop_loss_order_id' => $stopLossOrderId,
            'status' => 'open',
        ]);

        $liquidationText = $liquidationPrice !== null ? $this->formatPrice($liquidationPrice) : 'bilinmiyor';
        $protectionText = $nativeProtected ? 'borsa üzerinde, anlık' : 'izleme ile, ~5 dk içinde tetiklenir';

        $this->notifyCustomer(
            $userId,
            "🔻 [NexaTrade Futures] Yeni KISA Pozisyon Açıldı!\n" .
            "Coin: {$pair} | Giriş: {$this->formatPrice($entryPrice)} | Kaldıraç: {$leverage}x | Marj: {$margin}$\n" .
            "🛡️ Kâr Al: {$this->formatPrice($takeProfitPrice)} | Zarar Kes: {$this->formatPrice($stopTriggerPrice)} ({$protectionText})\n" .
            "⚠️ Tahmini Likidasyon: {$liquidationText}"
        );
    }

    // Acik KISA pozisyonlarin mutabakati - HIBRIT modelin iki dalini da destekler:
    // 1) Native korumali (take_profit_order_id/stop_loss_order_id dolu): Binance'teki emir
    //    durumlarini kontrol eder, gerceklesen bacagi bulup bekleyeni iptal eder ("temizlikci" rolu)
    // 2) Kendi izlememize dusmus (order ID'ler NULL): anlik mark fiyatini kendimiz kontrol edip
    //    esik asilinca KENDI market emrimizle kapatir (tepki suresi en fazla bir cron araligi)
    private function reconcileOpenPositions(): int
    {
        $openTrades = ActiveFuturesTrade::findAllOpen();
        $reconciledCount = 0;

        foreach ($openTrades as $trade) {
            $tradeId = (int) $trade['id'];
            $userId = (int) $trade['user_id'];
            $pair = (string) $trade['pair'];

            // bkz. Database::ensureConnected() yorumu (AutoTradeController'daki ayni RCA, 22 Temmuz) -
            // her pozisyon icin Binance/AI cagrilari birikince baglanti kopmus olabilir
            Database::ensureConnected();

            try {
                $apiKey = ApiKey::findByUser($userId)[0] ?? null;

                if ($apiKey === null) {
                    continue;
                }

                $futures = new BinanceFuturesService($apiKey['api_key'], $apiKey['secret_key']);
                $isNativeProtected = $trade['take_profit_order_id'] !== null || $trade['stop_loss_order_id'] !== null;

                $reconciledCount += $isNativeProtected
                    ? $this->reconcileNativeProtectedTrade($futures, $trade)
                    : $this->reconcileSelfMonitoredTrade($futures, $trade);
            } catch (Throwable $e) {
                $this->logFutures("Futures pozisyon #{$tradeId} mutabakatı sırasında hata: " . $e->getMessage());
                continue;
            }
        }

        return $reconciledCount;
    }

    // Native TP/SL emri girilmis bir pozisyon icin: Binance'teki emir durumlarini kontrol eder.
    // Biri FILLED ise digerini iptal eder (futures'ta spot'taki gibi tek bir OCO grubu yok, iki
    // bagimsiz emir ayri ayri iptal edilmeli) - aksi halde ters yonde YENI bir pozisyon acilma riski dogar
    private function reconcileNativeProtectedTrade(BinanceFuturesService $futures, array $trade): int
    {
        $pair = (string) $trade['pair'];

        // Izleyen Stop: HER turda, fill kontrolunden ONCE denenir - spot'taki "OCO hala EXECUTING'se
        // trailing dene" mantigina en yakin sirali karsilik. Eger bu tam da bu turda TP/SL FILLED
        // olmussa, asagidaki cancelOrder() denemesi zaten dolmus bir emri iptal etmeye calisip
        // zararsizca basarisiz olur (Binance boyle bir iptali reddeder) - trailing sessizce hicbir
        // sey yapmaz, hemen altindaki fill-kontrolu turu normal sekilde tamamlar
        $this->applyTrailingStopIfEligible($futures, $trade);

        $filledLeg = null;
        $pendingOrderId = null;

        if ($trade['take_profit_order_id'] !== null) {
            $tpStatus = $futures->getOrderStatus($pair, (int) $trade['take_profit_order_id']);

            if (strtoupper((string) ($tpStatus['status'] ?? '')) === 'FILLED') {
                $filledLeg = $tpStatus;
                $pendingOrderId = $trade['stop_loss_order_id'];
            }
        }

        if ($filledLeg === null && $trade['stop_loss_order_id'] !== null) {
            $slStatus = $futures->getOrderStatus($pair, (int) $trade['stop_loss_order_id']);

            if (strtoupper((string) ($slStatus['status'] ?? '')) === 'FILLED') {
                $filledLeg = $slStatus;
                $pendingOrderId = $trade['take_profit_order_id'];
            }
        }

        if ($filledLeg === null) {
            // Ne TP ne SL dolmus - pozisyonun borsada hala GERCEKTEN acik olup olmadigini dogrula
            // (likidasyon ihtimali - bu durumda kendi emirlerimiz hicbir zaman FILLED gorunmeyebilir)
            return $this->closeIfGoneFromExchange($futures, $trade);
        }

        if ($pendingOrderId !== null) {
            $futures->cancelOrder($pair, (int) $pendingOrderId);
        }

        $executedQty = (float) ($filledLeg['executedQty'] ?? 0);
        if ($executedQty <= 0) {
            $executedQty = (float) $trade['quantity'];
        }
        $avgPrice = (float) ($filledLeg['avgPrice'] ?? 0);
        $exitPrice = $avgPrice > 0 ? $avgPrice : (float) ($filledLeg['stopPrice'] ?? $filledLeg['price'] ?? 0);

        // isProfit ARTIK hangi bacagin (TP/SL) gerceklestigine degil, finalizeClosedTrade() icinde
        // GERCEK PNL isaretine gore belirleniyor - bkz. o metodun basindaki KRITIK DUZELTME yorumu
        $this->finalizeClosedTrade($futures, $trade, $exitPrice, $executedQty, $filledLeg['orderId'] ?? null);

        return 1;
    }

    // Native emir REDDEDILMIS (order ID'ler NULL) bir pozisyon icin: anlik mark fiyatini kendimiz
    // kontrol edip Kar Al/Zarar Kes esigi asilinca KENDI market emrimizle kapatir
    private function reconcileSelfMonitoredTrade(BinanceFuturesService $futures, array $trade): int
    {
        $tradeId = (int) $trade['id'];
        $pair = (string) $trade['pair'];

        $positionRisk = $futures->getPositionRisk($pair);

        if (abs($positionRisk['position_amt']) < 0.0000001) {
            return $this->closeIfGoneFromExchange($futures, $trade);
        }

        $markPrice = $positionRisk['mark_price'];

        if ($markPrice <= 0) {
            return 0;
        }

        // Izleyen Stop: mark fiyati zaten yukarida cekildi, EK bir Binance cagrisi yapmadan
        // dogrudan kullanilir - bu fonksiyon kendi-izleme modunda oldugu icin (stop_loss_order_id
        // NULL) applyTrailingStopIfEligible() SADECE DB'deki esigi gunceller, Binance'e emir GITMEZ
        $this->applyTrailingStopIfEligible($futures, $trade, $markPrice);

        // DB'de trailing az once guncellenmis olabilir - bu turun geri kalaninda DOGRU (taze)
        // esikleri kullanmak icin taze satiri yeniden oku (aksi halde asagidaki $takeProfitPrice/
        // $stopLossPrice hala BU FONKSIYONUN basindaki ESKI $trade dizisinden okunurdu)
        $refreshedTrade = ActiveFuturesTrade::findById($tradeId);
        $trade = $refreshedTrade ?? $trade;

        $takeProfitPrice = (float) $trade['take_profit_price'];
        $stopLossPrice = (float) $trade['stop_loss_price'];

        // SHORT: kar fiyat DUSTUKCE olusur (mark <= TP), zarar fiyat YUKSELDIKCE olusur (mark >= SL)
        $hitTakeProfit = $markPrice <= $takeProfitPrice;
        $hitStopLoss = $markPrice >= $stopLossPrice;

        if (!$hitTakeProfit && !$hitStopLoss) {
            return 0;
        }

        $quantity = (float) $trade['quantity'];

        $closeResult = $futures->placeMarketOrder($pair, 'BUY', $quantity, true);

        if (!$closeResult['success']) {
            $this->logFutures("Pozisyon #{$tradeId} ({$pair}) - eşik aşıldı ama kapatma emri başarısız: " . $closeResult['error']);
            return 0;
        }

        // KRITIK DUZELTME: futures MARKET emrinin aninda donen POST yaniti, gerceklesme (fill)
        // verisini her zaman yansitmayabiliyor - bazen executedQty/cumQuote gecici olarak 0 donuyor,
        // halbuki emir gercekte doluyor. Bunu koru koru 0 olarak kaydetmek "0 miktar, $0 toplam" gibi
        // hayali kayitlar uretip PNL'i yanlis sekilde sisiriyordu (bu gece SYNUSDT testlerinde oldu).
        // executedQty<=0 ise istenen miktara, cumQuote<=0 ise anlik mark fiyatina geri dusulur
        $raw = $closeResult['raw'];
        $executedQty = (float) ($raw['executedQty'] ?? 0);
        if ($executedQty <= 0) {
            $executedQty = $quantity;
        }
        $cumQuote = (float) ($raw['cumQuote'] ?? 0);
        $exitPrice = $cumQuote > 0 ? $cumQuote / $executedQty : $markPrice;

        // isProfit ARTIK hangi esigin (TP/SL) asildigina degil, finalizeClosedTrade() icinde
        // GERCEK PNL isaretine gore belirleniyor - bkz. o metodun basindaki KRITIK DUZELTME yorumu
        $this->finalizeClosedTrade($futures, $trade, $exitPrice, $executedQty, $closeResult['order_id'] ?? null);

        return 1;
    }

    // Izleyen Stop (Futures) - AutoTradeController::applyTrailingStopIfEligible() ile AYNI iki-asamali
    // akis, SHORT icin ayna simetrisiyle. $currentPrice ONCEDEN biliniyorsa (reconcileSelfMonitoredTrade
    // zaten mark fiyatini cekmisti) TEKRAR bir Binance cagrisi yapilmaz - NULL verilirse (native yoldan
    // cagrildiginda) burada taze cekilir
    private function applyTrailingStopIfEligible(BinanceFuturesService $futures, array $trade, ?float $currentPrice = null): void
    {
        $pair = (string) $trade['pair'];
        $entryPrice = (float) $trade['entry_price'];

        if ($entryPrice <= 0) {
            return;
        }

        if ($currentPrice === null) {
            try {
                $currentPrice = $futures->getPrice($pair);
            } catch (Throwable $e) {
                return;
            }
        }

        if ($currentPrice <= 0) {
            return;
        }

        // Kullaniciya ozel, Dashboard'dan duzenlenebilir tetik/kilit/izleme yuzdeleri (bkz.
        // ApiKey::getTrailingSettings() - eskiden burasi sabit FUTURES_TRAILING_* const'lariydi)
        $trailingSettings = ApiKey::getTrailingSettings((int) $trade['user_id']);

        if (!$trailingSettings['futures_trailing_stop_enabled']) {
            return; // kullanici Futures Izleyen Stop'u kapatmis - sabit TP/SL emirlerine dokunulmaz
        }

        $currentStage = (int) $trade['trailing_stop_stage'];

        if ($currentStage < 1) {
            $this->applyDiscreteFuturesTrailingStage($futures, $trade, $currentPrice, $entryPrice, $trailingSettings);

            return;
        }

        // Asama 1 zaten kilitlenmis - Asama 2 (Sinirsiz Izleme) HER turda surekli calisir
        $this->applyContinuousFuturesTrailing($futures, $trade, $currentPrice, $trailingSettings['futures_trailing_distance_percent']);
    }

    // Asama 1 (+trigger -> entry-lock): SHORT'ta kar fiyat DUSTUKCE olusur, bu yuzden "kar yuzdesi" =
    // (entryPrice - currentPrice) / entryPrice. Kilitlenen Zarar Kes, entry'nin ALTINA (kucuk ama
    // GARANTI bir kar noktasina) cekilir - spot'un "entry+lock'a cek" mantiginin ayna simetrigi
    private function applyDiscreteFuturesTrailingStage(BinanceFuturesService $futures, array $trade, float $currentPrice, float $entryPrice, array $trailingSettings): void
    {
        $pair = (string) $trade['pair'];
        $userId = (int) $trade['user_id'];
        $triggerPercent = $trailingSettings['futures_trailing_trigger_percent'];
        $lockPercent = $trailingSettings['futures_trailing_lock_percent'];
        $changePercent = (($entryPrice - $currentPrice) / $entryPrice) * 100;

        if ($changePercent < $triggerPercent) {
            return; // henuz esige ulasilmadi
        }

        $newStopTargetPrice = $entryPrice * (1 - $lockPercent / 100);
        $appliedStopPrice = $this->replaceFuturesStopLoss($futures, $trade, $newStopTargetPrice, 1, null);

        if ($appliedStopPrice === null) {
            return; // basarisizlik zaten replaceFuturesStopLoss icinde loglandi/bildirildi
        }

        $this->logFutures(sprintf(
            'Futures Kâr Kilitlendi: %s için Zarar Kes %%%s seviyesine çekildi.',
            $pair,
            $this->formatPercentTrim($lockPercent)
        ));

        $this->notifyCustomer($userId, sprintf(
            "🔒 [NexaTrade Futures] Kâr Kilitlendi!\nCoin: %s\nPozisyon %%%.2f kâra ulaştı, Zarar Kes seviyesi %%%s kâr noktasına (%s) çekildi.",
            $pair,
            $changePercent,
            $this->formatPercentTrim($lockPercent),
            $this->formatPrice($appliedStopPrice)
        ));
    }

    // Asama 2 (Sinirsiz Izleme): Asama 1 kilitlendikten SONRA, fiyat dusmeye devam ettikce Zarar
    // Kes'i goruledigi en DUSUK fiyatin (lowest_price_seen) $distancePercent kadar USTUNDE tutmaya
    // devam eder - spot'un highest_price_seen mantiginin SHORT icin ayna simetrisi
    private function applyContinuousFuturesTrailing(BinanceFuturesService $futures, array $trade, float $currentPrice, float $distancePercent): void
    {
        $tradeId = (int) $trade['id'];
        $userId = (int) $trade['user_id'];
        $pair = (string) $trade['pair'];
        $currentStopLoss = (float) $trade['stop_loss_price'];
        $takeProfitPrice = (float) $trade['take_profit_price'];
        $storedLowest = $trade['lowest_price_seen'] !== null ? (float) $trade['lowest_price_seen'] : null;
        $lowestPriceSeen = $storedLowest !== null ? min($storedLowest, $currentPrice) : $currentPrice;
        $finalStage = (int) $trade['trailing_stop_stage'];

        $candidateStopPrice = $lowestPriceSeen * (1 + $distancePercent / 100);
        // Guvenlik siniri: Zarar Kes, Kar Al seviyesinin ASLA altina/esitine inemez (SHORT'ta SL
        // her zaman TP'nin USTUNDE olmalidir) - spot'taki AYNI korumanin ayna simetrisi
        $candidateStopPrice = max($candidateStopPrice, $takeProfitPrice * 1.001);

        $minImprovement = $currentStopLoss * (self::FUTURES_TRAILING_MIN_IMPROVEMENT_PERCENT / 100);

        // SHORT'ta "iyilesme" = Zarar Kes'in ASAGI inmesi (mevcut seviyeden en az minImprovement kadar dusuk olmali)
        if ($candidateStopPrice > $currentStopLoss - $minImprovement) {
            if ($storedLowest === null || $lowestPriceSeen < $storedLowest) {
                ActiveFuturesTrade::updateLowestPriceSeen($tradeId, $lowestPriceSeen);
            }

            return;
        }

        $appliedStopPrice = $this->replaceFuturesStopLoss($futures, $trade, $candidateStopPrice, $finalStage, $lowestPriceSeen);

        if ($appliedStopPrice === null) {
            return;
        }

        $this->logFutures(sprintf(
            'Futures Kâr Kilitlendi (İzleme): %s için Zarar Kes en düşük fiyatın (%s) %%%s üzerine, %s seviyesine çekildi.',
            $pair,
            $this->formatPrice($lowestPriceSeen),
            $this->formatPercentTrim($distancePercent),
            $this->formatPrice($appliedStopPrice)
        ));

        $this->notifyCustomer($userId, sprintf(
            "📈 [NexaTrade Futures] Kâr İzleniyor!\nCoin: %s\nYeni dip: %s\nZarar Kes seviyesi %s'e çekildi (dibin %%%s üzerinde).",
            $pair,
            $this->formatPrice($lowestPriceSeen),
            $this->formatPrice($appliedStopPrice),
            $this->formatPercentTrim($distancePercent)
        ));
    }

    // Kâr kilitleme mekaniginin ORTAK cekirdegi - AutoTradeController::replaceOcoWithNewStop() ile
    // AYNI amaca hizmet eder, ama futures'ta TP/SL spot'un OCO'sunun aksine BAGIMSIZ iki ayri emir
    // oldugu icin SADECE Zarar Kes emri iptal/yeniden kurulur, Kar Al emrine HIC DOKUNULMAZ.
    // Kendi-izleme modundaki (stop_loss_order_id NULL) pozisyonlar icin gercek bir Binance cagrisi
    // bile YAPILMAZ - sadece DB esigi guncellenir, mark fiyati karsilastirmasi bunu zaten kullanir.
    // Basarili olursa GERCEK (tick'e yuvarlanmis) Zarar Kes fiyatini doner, basarisiz olursa null doner
    private function replaceFuturesStopLoss(BinanceFuturesService $futures, array $trade, float $newStopTargetPrice, int $newStage, ?float $lowestPriceSeen): ?float
    {
        $tradeId = (int) $trade['id'];
        $userId = (int) $trade['user_id'];
        $pair = (string) $trade['pair'];
        $takeProfitPrice = (float) $trade['take_profit_price'];
        $oldStopLossOrderId = $trade['stop_loss_order_id'] !== null ? (int) $trade['stop_loss_order_id'] : null;

        // Zarar Kes, Kar Al seviyesinin ASLA altina/esitine inemez (SHORT'ta SL > TP olmalidir) -
        // normalde imkansizdir ama guvenli tarafta kalmak icin kontrol edilir
        if ($newStopTargetPrice <= $takeProfitPrice) {
            return null;
        }

        try {
            $filters = $futures->getSymbolFilters($pair);
            $tickSize = $filters['tick_size'] > 0 ? $filters['tick_size'] : 0.00000001;
        } catch (Throwable $e) {
            $tickSize = 0.00000001;
        }

        $newStopTriggerPrice = $this->floorToStep($newStopTargetPrice, $tickSize);

        // KENDI-IZLEME MODU: gercek bir Binance Zarar Kes emri YOK - sadece DB esigi guncellenir,
        // bir sonraki reconcileSelfMonitoredTrade() turu zaten bu YENI esige gore mark fiyatini kontrol eder
        if ($oldStopLossOrderId === null) {
            $this->criticalPersist(
                fn () => ActiveFuturesTrade::applyTrailingStop($tradeId, $newStage, $newStopTriggerPrice, null, $lowestPriceSeen),
                $userId,
                $pair,
                'Futures Kâr Kilitleme (kendi-izleme eşik güncellemesi)'
            );

            return $newStopTriggerPrice;
        }

        // NATIVE KORUMALI MOD: gercek STOP_MARKET emrini iptal edip YENI fiyattan yeniden kurar -
        // Kar Al emri (take_profit_order_id) TAMAMEN AYRI bir emir oldugu icin buna HIC DOKUNULMAZ
        $cancelResult = $futures->cancelOrder($pair, $oldStopLossOrderId);

        if (!$cancelResult['success']) {
            $this->logFutures(sprintf(
                'Futures Kâr Kilitleme: Kullanıcı #%d %s - mevcut Zarar Kes emri iptal edilemedi: %s',
                $userId,
                $pair,
                $cancelResult['error']
            ));

            return null; // eski emir hala gecerli/korumali - bir sonraki turda tekrar denenir
        }

        $newStopLossResult = $futures->placeStopMarketOrder($pair, 'BUY', $newStopTriggerPrice);

        if (!$newStopLossResult['success']) {
            // Ilk deneme basarisizsa bir kez daha dene (spot'taki AYNI desen) - gecici bir
            // Binance hatasi/oran siniri olabilir
            $newStopLossResult = $futures->placeStopMarketOrder($pair, 'BUY', $newStopTriggerPrice);
        }

        if (!$newStopLossResult['success']) {
            // KRITIK TASARIM KARARI: SADECE Zarar Kes'i NULL birakip Kar Al emrini (hala Binance'te
            // aktif) dokunulmadan birakmak, reconcileOpenPositions()'un isNativeProtected kontrolunu
            // (take_profit_order_id VEYA stop_loss_order_id - OR mantigi) yaniltip pozisyonu "native
            // korumali" sanip SADECE Kar Al tarafini kontrol eden reconcileNativeProtectedTrade()'e
            // dusururdu - Zarar Kes tarafi bu durumda ASLA kontrol edilmezdi (ne native ne kendi-izleme).
            // Bunun onune gecmek icin Kar Al emri de iptal edilip pozisyon TAMAMEN kendi-izleme moduna
            // dusurulur - protectShortPosition()'daki ayni "ikisi de dolu ya da ikisi de NULL" kuralina
            // uygun kalinir. reconcileSelfMonitoredTrade() zaten HEM TP HEM SL'i mark fiyatindan dogru kontrol eder
            if ($trade['take_profit_order_id'] !== null) {
                $futures->cancelOrder($pair, (int) $trade['take_profit_order_id']);
            }

            $this->criticalPersist(
                fn () => ActiveFuturesTrade::applyTrailingStop($tradeId, $newStage, $newStopTriggerPrice, null, $lowestPriceSeen),
                $userId,
                $pair,
                'Futures Kâr Kilitleme (kendi-izleme moduna düşürme kaydı)'
            );

            $this->logFutures(sprintf(
                'Futures Kâr Kilitleme: Kullanıcı #%d %s - yeni Zarar Kes emri girilemedi, pozisyon TAMAMEN kendi-izleme moduna düşürüldü (Kâr Al emri de iptal edildi): %s',
                $userId,
                $pair,
                $newStopLossResult['error']
            ));

            $this->notifyAdminAndCustomer(
                $userId,
                "⚠️ [NexaTrade Futures] Koruma Modu Değişti\nCoin: {$pair}\n\nBorsa üzerindeki emirler iptal edilip pozisyon kendi izleme mekanizmamıza alındı (koruma seviyeleri korunuyor, sadece tepki süresi ~5 dakikaya kadar uzayabilir)."
            );

            return $newStopTriggerPrice;
        }

        $this->criticalPersist(
            fn () => ActiveFuturesTrade::applyTrailingStop($tradeId, $newStage, $newStopTriggerPrice, $newStopLossResult['order_id'], $lowestPriceSeen),
            $userId,
            $pair,
            'Futures Kâr Kilitleme (yeni Zarar Kes kaydı)'
        );

        return $newStopTriggerPrice;
    }

    // "%2.0" yerine "%2" gibi gereksiz sondaki sifirlari kirpar - spot'taki AYNI yardimci (sadece log/bildirim okunabilirligi icin)
    private function formatPercentTrim(float $value): string
    {
        return rtrim(rtrim(sprintf('%.2f', $value), '0'), '.');
    }

    // Pozisyon borsada artik gercekten acik degilse (likidasyon veya elle kapatma ihtimali) -
    // kendi kayitlarimizi da kapali olarak isaretleyip admin'i uyarir. Gercek exit fiyati/PNL
    // bilinmedigi icin Order kaydi olusturulmaz, sadece durum senkronize edilir
    private function closeIfGoneFromExchange(BinanceFuturesService $futures, array $trade): int
    {
        $tradeId = (int) $trade['id'];
        $userId = (int) $trade['user_id'];
        $pair = (string) $trade['pair'];

        $positionRisk = $futures->getPositionRisk($pair);

        if (abs($positionRisk['position_amt']) >= 0.0000001) {
            return 0; // hala gercekten acik, henuz TP/SL'e ulasmamis
        }

        ActiveFuturesTrade::markClosed($tradeId, 'closed_manual');
        $this->logFutures("Pozisyon #{$tradeId} ({$pair}) borsada artık açık değil (muhtemelen manuel kapatıldı veya likidasyon) - kayıt kapatıldı.");
        $this->notifyAdminAndCustomer($userId, "⚠️ [NexaTrade Futures] Pozisyon #{$tradeId} ({$pair}) borsada artık açık değil.\nKayıt buna göre güncellendi. Beklenmedik bir kapanışsa (likidasyon) hesabı kontrol edin.");

        return 1;
    }

    // Kapanan bir pozisyon icin Order kaydini olusturur, ActiveFuturesTrade'i kapali isaretler
    // ve gercek PNL'i hesaplayip musteriye bildirir - hem native hem kendi-izleme yolundan cagrilir.
    // KRITIK DUZELTME (22 Temmuz): isProfit parametresi KALDIRILDI - spot'taki AYNI bug futures'ta
    // da vardi (status hangi esigin/bacagin tetiklendigine gore belirleniyordu, GERCEK PNL'e gore
    // degil). Izleyen Stop, SHORT pozisyonda Zarar Kes'i girisin ALTINA cektiginde (kar kilitleme),
    // SONRADAN o seviye tetiklense bile pozisyon GERCEKTE karda kapanmis olur - simdi isProfit
    // SADECE cikis/giris fiyat karsilastirmasindan (SHORT icin: exit <= entry) hesaplaniyor
    private function finalizeClosedTrade(BinanceFuturesService $futures, array $trade, float $exitPrice, float $executedQty, ?int $binanceOrderId): void
    {
        $tradeId = (int) $trade['id'];
        $userId = (int) $trade['user_id'];
        $pair = (string) $trade['pair'];
        $exitTotal = round($exitPrice * $executedQty, 8);
        $entryPrice = (float) $trade['entry_price'];

        // SHORT: kar, fiyat DUSTUKCE olusur - cikis fiyati giris fiyatina esit veya ondan DUSUKSE karda
        $isProfit = $exitPrice <= $entryPrice;

        // Funding Rate entegrasyonu (4 Agustos): pozisyonun ACIK oldugu TUM sure boyunca GERCEKTEN
        // tahsil edilmis/odenmis fonlama ucretini (Binance Income History, TAHMINI/anlik oran DEGIL)
        // ceker - musteri talebi: "gunlerce acik kalan pozisyonlarda bu maliyet/gelir hesaba
        // katilmiyor". Basarisiz olursa (agdaki gecici bir sorun) 0.0 doner (fail-open) - PNL
        // bildirimini/kapanisi ASLA engellemez, sadece o turda fonlama bilgisi eksik kalir
        $openedAtMs = strtotime((string) $trade['opened_at']) * 1000;
        $nowMs = (int) (microtime(true) * 1000);
        $fundingFeeTotal = $futures->getFundingFeeIncome($pair, $openedAtMs, $nowMs);
        ActiveFuturesTrade::setFundingFeeTotal($tradeId, $fundingFeeTotal);

        $this->criticalPersist(function () use ($userId, $pair, $executedQty, $exitPrice, $exitTotal, $binanceOrderId, $trade, $tradeId, $isProfit): void {
            // Idempotenslik korumasi - bkz. Order::existsByBinanceOrderId() yorumu/AutoTradeController::
            // finalizeSpotClose() ile AYNI mantik (Order::create basarili + hemen sonraki markClosed
            // patlarsa pozisyon DB'de 'open' kalir, bir sonraki cron AYNI kapanisi tekrar kaydetmeye calisirdi)
            if ($binanceOrderId === null || !Order::existsByBinanceOrderId($binanceOrderId)) {
                Order::create([
                    'user_id' => $userId,
                    'pair' => $pair,
                    'side' => 'BUY',
                    'type' => 'MARKET',
                    'quantity' => $executedQty,
                    'price' => $exitPrice,
                    'total' => $exitTotal,
                    'binance_order_id' => $binanceOrderId,
                    'parent_order_id' => (int) $trade['open_order_id'],
                    'status' => 'FILLED',
                    'strategy_bucket' => $this->resolveParentStrategyBucket((int) $trade['open_order_id']),
                ]);
            }

            ActiveFuturesTrade::markClosed($tradeId, $isProfit ? 'closed_profit' : 'closed_loss');
        }, $userId, $pair, 'Futures Pozisyon Kapanışı');

        // SHORT PNL: entry'de SATILAN deger - exit'te (kapanista) GERI ALINAN deger
        $entryTotal = $entryPrice * $executedQty;
        $pnlAmount = $entryTotal - $exitTotal;
        $pnlPercent = $entryPrice > 0 ? (($entryPrice - $exitPrice) / $entryPrice) * 100 : 0.0;

        // Net PNL = brut fiyat PNL'i + fonlama ucreti - Binance'in kendi isareti (negatif=odedik,
        // pozitif=aldik) zaten dogru yonde oldugu icin DOGRUDAN TOPLANIR, cikarma islemi YAPILMAZ
        $netPnlAmount = $pnlAmount + $fundingFeeTotal;
        $fundingLine = abs($fundingFeeTotal) >= 0.01
            ? sprintf("\nFonlama Ücreti: %+.2f USDT | Net PNL: %+.2f USDT", $fundingFeeTotal, $netPnlAmount)
            : '';

        $this->notifyCustomer($userId, sprintf(
            "%s [NexaTrade Futures] KISA Pozisyon Kapandı (%s)\nCoin: %s | Kaldıraç: %dx\nGiriş: %s → Çıkış: %s\nPNL: %+.2f USDT (%+.2f%%)%s",
            $isProfit ? '✅' : '🔻',
            $isProfit ? 'Kâr Al' : 'Zarar Kes',
            $pair,
            (int) $trade['leverage'],
            $this->formatPrice($entryPrice),
            $this->formatPrice($exitPrice),
            $pnlAmount,
            $pnlPercent,
            $fundingLine
        ));
    }

    // Kullanicinin ACIK futures pozisyonlarinin toplam MARJINI hesaplar (notional/kaldirac) -
    // devre kesici ve portfoy risk tavaninin marj uzerinden dogru calismasi icin gerekir
    private function calculateOpenFuturesMargin(int $userId): float
    {
        $margin = 0.0;

        foreach (ActiveFuturesTrade::findOpenForUser($userId) as $trade) {
            $leverage = max(1, (int) $trade['leverage']);
            $notional = (float) $trade['entry_price'] * (float) $trade['quantity'];
            $margin += $notional / $leverage;
        }

        return $margin;
    }

    // --- ATR'ye Bağlı Dinamik Kaldıraç (4 Ağustos, müşteri talebi) ---
    // AutoTradeController'daki İzleyen Stop mesafesi ATR çarpanına (bkz. o dosyada "ATR Bazlı
    // Volatilite Çarpanı") KESİNLİKLE DOKUNULMADI - bu TAMAMEN AYRI, sadece giriş anındaki
    // KALDIRACI etkileyen bağımsız bir mekanizma. Müşterinin kendi ayarladığı kaldıraç bir
    // TAVANDIR: oynaklık yüksekse ALTINA inilir, düşük/normalse ASLA YUKARI ÇIKILMAZ (tek yönlü,
    // konservatif - müşteri "sabit bırak" dedi, "artır" demedi). 15 dakikalık ATR kullanılır
    // (AutoTradeController'ın 1 saatlik referansından BİLİNÇLİ farklı - futures girişi kısa vadeli
    // bir karar, o anki ANLIK oynaklığa bakmalı). Referans eşik (%0.4) İLK TAHMİN -
    // ATR_REFERENCE_PERCENT (1 saatlik, %0.8) gibi gerçek veriyle hiç doğrulanmadı, ileride
    // ayarlanması gerekebilir. ATR alınamazsa (null/hata) fail-open: kullanıcının kendi ayarı
    // değişmeden kullanılır - bu kontrol asla bir girişi ENGELLEMEZ, sadece boyutunu küçültebilir
    private const DYNAMIC_LEVERAGE_ATR_PERIOD = 14;
    private const DYNAMIC_LEVERAGE_ATR_REFERENCE_PERCENT = 0.4;

    private function calculateDynamicLeverage(string $pair, int $baseLeverage): int
    {
        try {
            $atrPercent = (new MarketScanner())->calculateAtr($pair, self::DYNAMIC_LEVERAGE_ATR_PERIOD, '15m');
        } catch (Throwable $e) {
            return $baseLeverage;
        }

        if ($atrPercent === null) {
            return $baseLeverage;
        }

        if ($atrPercent > self::DYNAMIC_LEVERAGE_ATR_REFERENCE_PERCENT * 2) {
            // Çok oynak (referansın 2 katından fazla): kaldıracı üçte bire indir
            $adjusted = (int) floor($baseLeverage / 3);
        } elseif ($atrPercent > self::DYNAMIC_LEVERAGE_ATR_REFERENCE_PERCENT) {
            // Orta oynaklık: kaldıracı üçte iki oranına indir
            $adjusted = (int) floor($baseLeverage * 2 / 3);
        } else {
            // Sakin piyasa: müşterinin kendi ayarı aynen kalır, YÜKSELTİLMEZ
            return $baseLeverage;
        }

        $finalLeverage = max(1, $adjusted);

        if ($finalLeverage !== $baseLeverage) {
            $this->logFutures(sprintf(
                'Dinamik Kaldıraç: %s için 15dk ATR %%%.2f (referans %%%.2f) - kaldıraç %dx yerine %dx kullanılacak.',
                $pair,
                $atrPercent,
                self::DYNAMIC_LEVERAGE_ATR_REFERENCE_PERCENT,
                $baseLeverage,
                $finalLeverage
            ));
        }

        return $finalLeverage;
    }

    // Kapanis siparisi kendi strategy_bucket'ini YENIDEN hesaplamaz - pozisyonu ACAN ilk SATIS
    // (short-open) siparisinin etiketini miras alir. finalizeClosedTrade() ilk acilistan SAATLER/GUNLER
    // sonra, ayri bir cron turunda calisabildigi icin bellekte artik yok - DB'den okunur
    private function resolveParentStrategyBucket(?int $parentOrderId): ?string
    {
        if ($parentOrderId === null) {
            return null;
        }

        $parent = Order::findById($parentOrderId);

        return $parent['strategy_bucket'] ?? null;
    }

    private function floorToStep(float $value, float $stepSize): float
    {
        if ($stepSize <= 0) {
            return floor($value * 10000) / 10000;
        }

        return floor($value / $stepSize) * $stepSize;
    }

    private function formatPrice(float $price): string
    {
        $formatted = rtrim(rtrim(sprintf('%.8f', $price), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function notifyCustomer(int $userId, string $message): void
    {
        $chatId = User::findTelegramChatId($userId);

        if ($chatId !== null) {
            $this->telegram->notifyUser($chatId, "Kullanıcı: #{$userId}\n" . $message);
        }
    }

    private function notifyAdminAndCustomer(int $userId, string $message): void
    {
        $chatId = User::findTelegramChatId($userId);

        if ($chatId !== null) {
            $this->telegram->notifyUser($chatId, $message);
        }

        $this->telegram->notifyAdmin("Kullanıcı: #{$userId}\n" . $message);
    }

    // bkz. AutoTradeController::criticalPersist() - AYNI RCA'nin (22 Temmuz, BANKUSDT/ERAUSDT) ardindan
    // yapilan tam denetimde AYNI "Binance basarili ama DB yazimi patlarsa sessizce sadece dosyaya
    // loglanip gecilir" deseni bu dosyada da bulundu - futures KALDIRACLI oldugu icin (likidasyon
    // riski) spot'tan bile daha kritik. Istisna YUTULMAZ (rethrow)
    private function criticalPersist(callable $fn, int $userId, string $pair, string $context): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $this->logFutures("KRİTİK: Kullanıcı #{$userId} {$pair} - {$context} sonrası kayıt başarısız: " . $e->getMessage());

            $this->notifyAdminAndCustomer(
                $userId,
                "🚨 ACİL: {$context} Sonrası Sistem Kaydı Başarısız! (Futures)\nCoin: {$pair}\n\nBinance işlemi muhtemelen gerçekleşti ama veritabanına yazılamadı. Lütfen borsa hesabını (kaldıraçlı pozisyon!) manuel olarak kontrol edin."
            );

            throw $e;
        }
    }

    private function logFutures(string $message): void
    {
        $logDir = __DIR__ . '/../../storage/logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $entry = sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $message, PHP_EOL);
        file_put_contents($logDir . '/futures_trading.log', $entry, FILE_APPEND);
    }
}
