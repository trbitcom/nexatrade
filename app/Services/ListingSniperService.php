<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActiveTrade;
use App\Models\AiIntervention;
use App\Models\ApiKey;
use App\Models\BotLog;
use App\Models\KnownSymbol;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use RuntimeException;
use Throwable;

// Binance'e yeni eklenen bir USDT paritesini, AI onayi beklemeden aninda MARKET ALIM yapip
// agresif sabit %20 Kar Al / %2 Zarar Kes OCO'suyla korumaya alan yuksek riskli modul.
// Sadece "listing_sniper_enabled=1" olan opt-in kullanicilar icin calisir; devre kesiciye
// (RiskManagerService) TABIDIR - AI Avci ile aynı ardisik-kayip/gunluk-zarar limitlerini paylasir.
// AutoTradeController'in mevcut detectNewListings() ile AYNI known_symbols tablosunu kullanir,
// ama ATOMIK claimIfNew() ile: cakisan iki cron tetiklemesinin ayni listelemeyi cift almasi engellenir
final class ListingSniperService
{
    private const TAKE_PROFIT_PERCENT = 20.0;
    private const STOP_LOSS_PERCENT = 2.0;
    private const MIN_ORDER_BUDGET_USDT = 5.0;
    private const FEE_SAFETY_MARGIN = 0.999;
    // orders.strategy_bucket icin sabit deger - bu modulun tum siparisleri GPT/piyasa taramasindan
    // degil dogrudan duyuru tespitinden geldigi icin baglama bagli hesaplama gerekmez
    private const STRATEGY_BUCKET = 'announcement_hunter';
    // Katı zaman aşımı: Binance yanıt vermezse istek en fazla 5 saniyede kesilir, sistemi asla kilitlemez
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TIMEOUT_SECONDS = 5;

    // Binance'in "listelenmek uzere" durumlari - bir sembol bu statulerden birine gectiginde,
    // TRADING'e gecisi genelde dakikalar icinde olur. detectNewListings()'in yakaladigi "TRADING
    // listesine cikti" farkindan CHOK ONCE, listelenmenin ILK sinyalidir
    private const WATCHED_STATUSES = ['PRE_TRADING', 'BREAK'];

    // Tight-poll dongusunun TOPLAM suresi ust siniri - cPanel'in varsayilan max_execution_time'ini
    // (genelde 30sn) asip istegin yarida kesilmesini onlemek icin bilincli olarak altinda tutulur
    private const TIGHT_POLL_MAX_SECONDS = 25;

    // Izlenen her hedef, dongu icinde bu araliklarla (1 saniye) tekrar sorgulanir - listelemenin
    // TAM anini kacirmayacak kadar sik, ama Binance'i gereksiz yere bombalamayacak kadar seyrek
    private const TIGHT_POLL_INTERVAL_MICROSECONDS = 1_000_000;

    // Bir hedef bu sureden UZUN suredir hala TRADING'e gecmediyse (ör. Binance listelemeyi iptal
    // etti/erteledi), izleme sonsuza kadar surmesin diye 'failed' olarak isaretlenip birakilir
    private const PENDING_STALE_HOURS = 6;

    private readonly TelegramService $telegram;
    private readonly RiskManagerService $riskManager;

    public function __construct()
    {
        $this->telegram = new TelegramService();
        $this->riskManager = new RiskManagerService();
    }

    // @return array{new_listings_detected: int, positions_opened: int, pending_targets_watched: int}
    public function run(): array
    {
        // Sistem Durumu widget'i icin: bu metodun cagrildigi HER an kosulsuz kaydedilir - "cron
        // gercekten tetikleniyor mu" sorusuna, notlu/hata loglarindan BAGIMSIZ, guvenilir bir cevap
        // verir (bkz. DashboardController::apiSystemStatus). SmartMoneyTracker::run()'daki AYNI desen
        Setting::set('listing_sniper_last_run_at', (string) time());

        // Bu servis cok sik (ör. her 1 dakikada bir) tetiklenir - asagidaki tight-poll dongusu
        // en fazla TIGHT_POLL_MAX_SECONDS surebilir, buraya makul bir tampon payi eklenir ki
        // cPanel'in varsayilan max_execution_time'i istegi yarida kesmesin (ama SINIRSIZ degil,
        // sistemi asla kilitlemez)
        set_time_limit(self::TIGHT_POLL_MAX_SECONDS + 15);

        $scanner = new MarketScanner();
        $liveSymbols = $scanner->fetchTradableUsdtSymbols();

        // Ilk calistirma (tablo bosken): tum mevcut pariteleri taban olarak kaydet, hicbirini "yeni" sayma
        // (aksi halde ilk turda TUM piyasa "yeni listelendi" sanilip topluca alim yapilirdi)
        if (KnownSymbol::count() === 0) {
            KnownSymbol::insertMany($liveSymbols, isBootstrap: true);

            return ['new_listings_detected' => 0, 'positions_opened' => 0, 'pending_targets_watched' => 0];
        }

        $newlyClaimed = [];

        foreach ($liveSymbols as $symbol) {
            if (KnownSymbol::claimIfNew($symbol)) {
                $newlyClaimed[] = $symbol;
            }
        }

        $positionsOpened = 0;

        foreach ($newlyClaimed as $symbol) {
            $positionsOpened += $this->snipeListingForAllUsers($symbol);
        }

        // PRE_TRADING/BREAK durumundaki (henuz TRADING olmamis ama Binance'in gundemine ZATEN
        // aldigi) tamamen YENI sembolleri tespit edip izlemeye al - bunlar yukaridaki TRADING
        // diff'inin yakaladigi andan COK ONCE, listelenmenin ilk erken sinyalidir
        $pendingWatched = $this->detectAndClaimPendingListings($scanner);

        // Izlenen hedefler icin zaman sinirli sik poll dongusu - listelemeye son 1-2 dakika kala
        // Binance statuyu TRADING'e cevirdigi ani, bir sonraki cron tetiklemesini (1 dk'ya kadar
        // uzak olabilir) beklemeden, MUMKUN OLAN EN KISA surede yakalamak icin
        $positionsOpened += $this->pollPendingTargets($scanner);

        return [
            'new_listings_detected' => count($newlyClaimed),
            'positions_opened' => $positionsOpened,
            'pending_targets_watched' => $pendingWatched,
        ];
    }

    // Binance exchangeInfo'yu GENIS tarayip PRE_TRADING/BREAK durumundaki USDT paritelerini bulur ve
    // atomik olarak (KnownSymbol::claimPending) izlemeye alir - claimPending zaten bilinen (daha
    // once TRADING olmus/bootstrap) sembolleri KENDI ICINDE eler (bakim penceresi/yeniden acilma
    // ile karistirmamak icin), burada donen sayi SADECE yeni izlemeye alinan hedefleri kapsar
    private function detectAndClaimPendingListings(MarketScanner $scanner): int
    {
        try {
            $exchangeInfo = $scanner->fetchExchangeInfoStatuses();
        } catch (Throwable $e) {
            $this->logSniper('PRE_TRADING/BREAK taraması başarısız: ' . $e->getMessage());

            return 0;
        }

        $claimed = 0;

        foreach ($exchangeInfo['statuses'] as $symbol => $status) {
            if (!in_array($status, self::WATCHED_STATUSES, true)) {
                continue;
            }

            if (KnownSymbol::claimPending($symbol, $status, date('Y-m-d H:i:s'))) {
                $claimed++;
                $this->logSniper("{$symbol}: durum '{$status}' - izlemeye alındı (sniper_status=pending).");
            }
        }

        return $claimed;
    }

    // Izlenen (sniper_status=pending) hedefleri, cPanel'i cokertmeyecek SINIRLI bir sure boyunca
    // (TIGHT_POLL_MAX_SECONDS) sik sik (TIGHT_POLL_INTERVAL_MICROSECONDS araliklarla) tek tek
    // sorgular. Herhangi biri TRADING'e gectigi anda ANINDA alima gecilir. Cok uzun suredir
    // (PENDING_STALE_HOURS) hala TRADING'e gecmemis hedefler 'failed' olarak isaretlenip izlemeden
    // cikarilir - aksi halde sonsuza kadar her turda gereksiz API cagrisi biriktirirlerdi
    private function pollPendingTargets(MarketScanner $scanner): int
    {
        $targets = KnownSymbol::findPendingSniperTargets();

        if ($targets === []) {
            return 0;
        }

        $positionsOpened = 0;
        $deadline = microtime(true) + self::TIGHT_POLL_MAX_SECONDS;

        while ($targets !== [] && microtime(true) < $deadline) {
            foreach ($targets as $index => $target) {
                $symbol = (string) $target['symbol'];

                if ($this->isPendingTargetStale($target)) {
                    KnownSymbol::markSniperFailed($symbol);
                    $this->logSniper("{$symbol}: {$this->hoursSinceFirstSeen($target)} saattir TRADING'e geçmedi, izleme sonlandırıldı (failed).");
                    unset($targets[$index]);
                    continue;
                }

                $liveStatus = $scanner->fetchSingleSymbolStatus($symbol);

                if ($liveStatus === null) {
                    continue; // gecici API hatasi - bu turda atla, dongu devam eder
                }

                if ($liveStatus['status'] === 'TRADING') {
                    // Cakisan bir cron calistirmasi (bir onceki hala tight-poll donguSundeyken
                    // baslamis olabilir) bu sembolu ZATEN claim etmis olabilir - rowCount()===0
                    // ise BASKA bir surec kazandi, bu surec HICBIR ALIM DENEMEDEN atlar (cift alim
                    // koruması - bkz. KnownSymbol::claimSniperExecution() yorumu)
                    if (!KnownSymbol::claimSniperExecution($symbol)) {
                        unset($targets[$index]);
                        continue;
                    }

                    $this->logSniper("{$symbol}: TRADING durumuna geçti, anlık alım tetikleniyor.");
                    $positionsOpened += $this->snipeListingForAllUsers($symbol);
                    KnownSymbol::markSniperExecuted($symbol);
                    unset($targets[$index]);
                }
            }

            $targets = array_values($targets);

            if ($targets === [] || microtime(true) >= $deadline) {
                break;
            }

            usleep(self::TIGHT_POLL_INTERVAL_MICROSECONDS);
        }

        return $positionsOpened;
    }

    private function isPendingTargetStale(array $target): bool
    {
        $firstSeenAt = strtotime((string) $target['first_seen_at']);

        if ($firstSeenAt === false) {
            return false;
        }

        return (time() - $firstSeenAt) >= self::PENDING_STALE_HOURS * 3600;
    }

    private function hoursSinceFirstSeen(array $target): int
    {
        $firstSeenAt = strtotime((string) $target['first_seen_at']);

        return $firstSeenAt === false ? 0 : intdiv(time() - $firstSeenAt, 3600);
    }

    private function snipeListingForAllUsers(string $symbol): int
    {
        // Flaş Çöküş Koruması: bu modül AI/duygu onayı BEKLEMEDEN anında alım yaptığı için
        // (bilinçli tasarım) BTC'nin normal -%24s düşüş filtresi gibi HİÇBİR piyasa rejimi
        // koruması yoktu - 16 Temmuz'da yapılan koda bakış sırasında tespit edildi: bir flaş
        // çöküş sırasında yeni listelenen (zaten oynak/ince likiditeli) bir coin anında alınıp
        // sabit %2 Zarar Kes'e neredeyse garanti çarpardı. AutoTradeController'daki AYNI paylaşılan
        // RiskManagerService::checkFlashCrash() burada da kullanılıyor - kullanıcı bazlı değil,
        // tüm kullanıcılar için TEK seferde kontrol edilir
        $flashCrashReason = $this->riskManager->checkFlashCrash();

        if ($flashCrashReason !== null) {
            $this->logSniper("Flaş Çöküş Koruması: {$symbol} için alım atlandı - {$flashCrashReason}");

            return 0;
        }

        try {
            $minQuoteVolume = $this->getMinQuoteVolume();
            $quoteVolume = $this->fetchQuoteVolume($symbol);

            if ($quoteVolume < $minQuoteVolume) {
                $this->logSniper(sprintf(
                    '%s: asgari likidite eşiği ($%.2f) geçilmedi (mevcut: $%.2f), atlanıyor.',
                    $symbol,
                    $minQuoteVolume,
                    $quoteVolume
                ));

                return 0;
            }
        } catch (Throwable $e) {
            $this->logSniper("{$symbol}: likidite kontrolü başarısız - " . $e->getMessage());

            return 0;
        }

        $users = ApiKey::findAllForListingSniper();
        $opened = 0;

        foreach ($users as $userKey) {
            $userId = (int) $userKey['user_id'];

            try {
                $totalEquity = $this->calculateTotalEquity($userKey, $userId);
            } catch (Throwable $e) {
                $this->logSniper("Kullanıcı #{$userId}: bakiye/özkaynak hesaplanamadı - " . $e->getMessage());
                continue;
            }

            $blockReason = $this->riskManager->checkCircuitBreaker($userId, $userKey, $totalEquity);

            if ($blockReason !== null) {
                $this->logSniper("Kullanıcı #{$userId} devre kesici: {$blockReason}");
                continue;
            }

            try {
                if ($this->buyAndProtect($userId, $userKey, $symbol)) {
                    $opened++;
                }
            } catch (Throwable $e) {
                $this->logSniper("Kullanıcı #{$userId} için {$symbol} sniper alımı başarısız: " . $e->getMessage());
            }
        }

        // Duyuru Avcısı GPT/AI puanlamasını tamamen bypass ettiği icin normal bot_logs akisina
        // (AutoTradeController/FuturesTradingService) hic girmez - bu yuzden burada, admin panelindeki
        // "Son Bot Çalıştırmaları" gorunurlugu icin minimal bir kayit birakilir (strategy_bucket'in
        // TEK dogru kaynagi artik orders.strategy_bucket kolonu - bkz. buyAndProtect()/Order::create()).
        // En az 1 kullaniciya gercekten alim yapildiysa (opened > 0) kaydedilir - basarisiz/atlanan
        // denemeler icin log gurultusu yaratmaz
        if ($opened > 0) {
            BotLog::create(
                scannedSymbols: [$symbol],
                aiScores: [],
                selectedSymbol: $symbol,
                selectedScore: null,
                positionsOpened: $opened,
                notes: 'Duyuru Avcısı (AI onayı bypass edildi) - anlık listeleme alımı',
                tradeType: 'spot',
                inputData: [$symbol => ['strategy_bucket' => self::STRATEGY_BUCKET]]
            );
        }

        return $opened;
    }

    private function buyAndProtect(int $userId, array $userKey, string $symbol): bool
    {
        $binance = new BinanceService($userKey['api_key'], $userKey['secret_key']);
        // KRITIK: auto_trade_budget_percent (AI Avci) ile PAYLASILMAZ - sniper korlemesine (AI
        // onayi beklemeden) alim yaptigi icin TAMAMEN bagimsiz, dusuk tutulmasi onerilen kendi
        // sutununu kullanir (varsayilan %5)
        $budgetPercent = (float) ($userKey['sniper_budget_percent'] ?? 5.0);
        $maxPortfolioRiskPercent = (float) ($userKey['max_portfolio_risk_percent'] ?? 30.0);

        // Butce artik sabit USDT degil, guncel bakiyenin bir yuzdesi - hesap buyudukce/kucul dukce olcekler
        $usdtBalance = $this->getAssetBalance($binance, 'USDT');
        $budget = $usdtBalance * ($budgetPercent / 100);

        if ($budget < self::MIN_ORDER_BUDGET_USDT) {
            return false;
        }

        // Toplam portfoy risk tavani: acik pozisyonlar + bu yeni islem, toplam ozkaynagin
        // belirlenen yuzdesini asiyorsa yeni alim acilmaz
        $openTrades = ActiveTrade::findOpenForUser($userId);
        $openPositionsCost = 0.0;

        foreach ($openTrades as $trade) {
            $openPositionsCost += (float) $trade['entry_price'] * (float) $trade['quantity'];
        }

        $totalEquity = $usdtBalance + $openPositionsCost;
        $projectedExposurePercent = $totalEquity > 0 ? (($openPositionsCost + $budget) / $totalEquity) * 100 : 0.0;

        if ($totalEquity > 0 && $projectedExposurePercent > $maxPortfolioRiskPercent) {
            $this->logSniper(sprintf(
                'Kullanıcı #%d: Toplam portföy risk tavanı aşılıyor (%%%.1f, tavan %%%.1f), %s atlandı.',
                $userId,
                $projectedExposurePercent,
                $maxPortfolioRiskPercent,
                $symbol
            ));

            return false;
        }

        $price = $binance->getPrice($symbol);

        if ($price <= 0) {
            return false;
        }

        try {
            $filters = $binance->getSymbolFilters($symbol);
            $stepSize = $filters['step_size'] > 0 ? $filters['step_size'] : 0.0001;
            $minNotional = $filters['min_notional'] ?? 0.0;
        } catch (Throwable $e) {
            $stepSize = 0.0001;
            $minNotional = 0.0;
        }

        // LOT_SIZE (Adim Yuvarlama) Guvenlik Kalkani: bkz. LotSizeGuardService yorumu -
        // AutoTradeController ile PAYLASILAN, tum otonom alim modullerinin ayni sekilde uyguladigi kontrol
        $lotSizeGuard = LotSizeGuardService::evaluate($budget / $price, $stepSize);

        if (!$lotSizeGuard['safe']) {
            $this->logSniper(sprintf(
                'İşlem İptal Edildi: %s için LOT_SIZE yuvarlama kaybı (%%%.2f) güvenlik sınırını (%%%.1f) aşıyor.',
                $symbol,
                $lotSizeGuard['fire_percent'],
                LotSizeGuardService::DEFAULT_MAX_SLIPPAGE_TOLERANCE_PERCENT
            ));

            AiIntervention::record(
                $userId,
                $symbol,
                'lot_size_guard',
                sprintf(
                    'LOT_SIZE yuvarlama kaybı (%%%.2f) güvenlik sınırını (%%%.1f) aştığı için alım iptal edildi.',
                    $lotSizeGuard['fire_percent'],
                    LotSizeGuardService::DEFAULT_MAX_SLIPPAGE_TOLERANCE_PERCENT
                )
            );

            return false;
        }

        $quantity = $lotSizeGuard['floored_quantity'];

        // MIN_NOTIONAL (Asgari İşlem Tutarı) Güvenlik Kontrolü (4 Ağustos, AutoTradeController'da
        // VICUSDT #369 canlı olayında bulunan AYNI boşluk buraya da uygulandı): alım başarılı olup
        // sonrasındaki OCO'nun Zarar Kes bacağı (sabit %STOP_LOSS_PERCENT altı) asgari işlem
        // tutarını karşılamayabilir. min_notional alınamazsa (0.0) kontrol atlanır (fail-open)
        if ($minNotional > 0) {
            $worstCasePrice = $price * (1 - self::STOP_LOSS_PERCENT / 100);
            $worstCaseNotional = $quantity * $worstCasePrice;

            if ($worstCaseNotional < $minNotional) {
                $this->logSniper(sprintf(
                    'İşlem Atlandı: %s için asgari işlem tutarı (NOTIONAL) riski - Zarar Kes bacağının değeri (~%.2f USDT) borsanın asgari sınırının (%.2f USDT) altında kalabilir.',
                    $symbol,
                    $worstCaseNotional,
                    $minNotional
                ));

                AiIntervention::record(
                    $userId,
                    $symbol,
                    'min_notional_guard',
                    sprintf(
                        'Zarar Kes bacağının asgari işlem tutarını (%.2f USDT) karşılayamama riski nedeniyle işlem atlandı.',
                        $minNotional
                    )
                );

                return false;
            }
        }

        $buyResult = $binance->placeOrder($symbol, 'BUY', 'MARKET', $quantity);

        if (!$buyResult['success']) {
            Order::create([
                'user_id' => $userId,
                'pair' => $symbol,
                'side' => 'BUY',
                'type' => 'MARKET',
                'quantity' => $quantity,
                'price' => $price,
                'total' => $budget,
                'binance_order_id' => null,
                'status' => 'FAILED',
                'error_message' => $buyResult['error'],
                'strategy_bucket' => self::STRATEGY_BUCKET,
            ]);

            $this->logSniper("Kullanıcı #{$userId}: {$symbol} MARKET BUY başarısız - " . $buyResult['error']);

            return false;
        }

        $raw = $buyResult['raw'];
        $executedQty = (float) ($raw['executedQty'] ?? $quantity);
        $cumulativeQuote = (float) ($raw['cummulativeQuoteQty'] ?? 0);
        $entryPrice = $executedQty > 0 ? $cumulativeQuote / $executedQty : $price;
        // Komisyon Takibi: $raw'daki fills[] icin EK bir API cagrisi gerekmez
        $commission = BinanceService::extractFillCommission($raw);

        $buyOrderId = $this->criticalPersist(fn () => Order::create([
            'user_id' => $userId,
            'pair' => $symbol,
            'side' => 'BUY',
            'type' => 'MARKET',
            'quantity' => $executedQty > 0 ? $executedQty : $quantity,
            'price' => $entryPrice,
            'total' => $cumulativeQuote > 0 ? $cumulativeQuote : $budget,
            'binance_order_id' => $buyResult['order_id'],
            'commission' => $commission['commission'],
            'commission_asset' => $commission['commission_asset'],
            'status' => 'FILLED',
            'strategy_bucket' => self::STRATEGY_BUCKET,
        ]), $userId, $symbol, 'Duyuru Avcısı Alımı');

        $this->protectWithAggressiveOco(
            $binance,
            $userId,
            $symbol,
            $buyOrderId,
            $entryPrice,
            $executedQty > 0 ? $executedQty : $quantity,
            $budget
        );

        return true;
    }

    // AI Avci'nin kullaniciya ozel take_profit_percent/stop_loss_percent'inin AKSINE, Duyuru Avcisi
    // HER ZAMAN sabit agresif %20/%2 kullanir - bu gorev tarafindan acikca istenen davranistir
    private function protectWithAggressiveOco(
        BinanceService $binance,
        int $userId,
        string $symbol,
        int $buyOrderId,
        float $entryPrice,
        float $boughtQuantity,
        float $budget
    ): void {
        try {
            $filters = $binance->getSymbolFilters($symbol);
            $stepSize = $filters['step_size'] > 0 ? $filters['step_size'] : 0.0001;
            $tickSize = $filters['tick_size'] > 0 ? $filters['tick_size'] : 0.00000001;
        } catch (Throwable $e) {
            $stepSize = 0.0001;
            $tickSize = 0.00000001;
        }

        $takeProfitPrice = $this->floorToStep($entryPrice * (1 + self::TAKE_PROFIT_PERCENT / 100), $tickSize);
        $stopTriggerPrice = $this->floorToStep($entryPrice * (1 - self::STOP_LOSS_PERCENT / 100), $tickSize);

        $ocoQuantity = $this->floorToStep($boughtQuantity * self::FEE_SAFETY_MARGIN, $stepSize);

        if ($ocoQuantity <= 0) {
            $this->logSniper("Kullanıcı #{$userId}: OCO miktarı sıfır çıktı ({$symbol}).");

            return;
        }

        // stopLimitPrice verilmiyor (null) - duz STOP_LOSS (piyasa) emri, slippage riski yok
        // (bkz. BinanceService::placeOCOOrder yorumu) - yeni listelenen coinler ozellikle
        // volatil oldugu icin bu, sniper pozisyonlari icin daha da kritik
        $ocoResult = $binance->placeOCOOrder($symbol, 'SELL', $ocoQuantity, $takeProfitPrice, $stopTriggerPrice);

        if (!$ocoResult['success']) {
            Order::create([
                'user_id' => $userId,
                'pair' => $symbol,
                'side' => 'SELL',
                'type' => 'OCO',
                'quantity' => $ocoQuantity,
                'price' => $takeProfitPrice,
                'total' => round($ocoQuantity * $takeProfitPrice, 8),
                'binance_order_id' => null,
                'parent_order_id' => $buyOrderId,
                'status' => 'FAILED',
                'error_message' => $ocoResult['error'],
                'strategy_bucket' => self::STRATEGY_BUCKET,
            ]);

            $this->logSniper(
                "KRİTİK: Kullanıcı #{$userId} için {$symbol} (Duyuru Avcısı) alındı ama OCO girilemedi: " . $ocoResult['error']
            );

            $this->notifyAdminAndCustomer(
                $userId,
                "🚨 ACİL: Duyuru Avcısı OCO Emri Girilemedi, Pozisyon Korumasız!\n" .
                "Coin: {$symbol}\n" .
                "Hata: {$ocoResult['error']}\n\n" .
                'Lütfen borsa hesabını manuel olarak kontrol edin.'
            );

            return;
        }

        $this->criticalPersist(function () use ($userId, $symbol, $buyOrderId, $ocoQuantity, $entryPrice, $takeProfitPrice, $stopTriggerPrice, $ocoResult): void {
            $activeTradeId = ActiveTrade::create([
                'user_id' => $userId,
                'pair' => $symbol,
                'buy_order_id' => $buyOrderId,
                'quantity' => $ocoQuantity,
                'entry_price' => $entryPrice,
                'take_profit_price' => $takeProfitPrice,
                'stop_loss_price' => $stopTriggerPrice,
                'oco_order_list_id' => $ocoResult['order_list_id'],
                'take_profit_order_id' => $ocoResult['take_profit_order_id'],
                'stop_loss_order_id' => $ocoResult['stop_loss_order_id'],
                'status' => 'open',
            ]);

            ActiveTrade::addFillRecord($activeTradeId, $buyOrderId, $ocoQuantity, $entryPrice, 'initial');
        }, $userId, $symbol, 'Duyuru Avcısı Pozisyon Kaydı');

        $this->notifyCustomer(
            $userId,
            "🎯 [NexaTrade] Duyuru Avcısı Pozisyon Açtı!\n" .
            "Coin: {$symbol} | Giriş: {$this->formatPrice($entryPrice)} | Bütçe: {$budget}$\n\n" .
            '🛡️ Agresif Koruma — Kâr Al: %20 | Zarar Kes: %2'
        );
    }

    private function getMinQuoteVolume(): float
    {
        $dbValue = Setting::get('listing_sniper_min_quote_volume');

        if ($dbValue !== null) {
            return (float) $dbValue;
        }

        $config = require __DIR__ . '/../../config/app.php';

        return (float) ($config['listing_sniper_min_quote_volume'] ?? 10000.0);
    }

    // Yeni listelenen sembolun asgari likidite kontrolu icin 24 saatlik islem hacmini ceker
    // (genel/public bir Binance uc noktasidir, imza gerektirmez)
    private function fetchQuoteVolume(string $symbol): float
    {
        $url = 'https://api.binance.com/api/v3/ticker/24hr?symbol=' . urlencode($symbol);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
        ]);

        $rawResponse = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrno !== 0 || $httpCode >= 400) {
            throw new RuntimeException("{$symbol} için 24hr ticker alınamadı (HTTP {$httpCode}).");
        }

        $decoded = json_decode((string) $rawResponse, true);

        return (float) ($decoded['quoteVolume'] ?? 0);
    }

    private function getAssetBalance(BinanceService $binance, string $asset): float
    {
        foreach ($binance->getBalances() as $balance) {
            if ($balance['asset'] === $asset) {
                return $balance['free'];
            }
        }

        return 0.0;
    }

    // Devre kesicinin gunluk zarar yuzdesini SABIT butce yerine TOPLAM ozkaynaga (bakiye + acik
    // pozisyonlarin maliyeti) gore hesaplayabilmesi icin gerekir
    private function calculateTotalEquity(array $userKey, int $userId): float
    {
        $binance = new BinanceService($userKey['api_key'], $userKey['secret_key']);
        $usdtBalance = $this->getAssetBalance($binance, 'USDT');

        $openPositionsCost = 0.0;

        foreach (ActiveTrade::findOpenForUser($userId) as $trade) {
            $openPositionsCost += (float) $trade['entry_price'] * (float) $trade['quantity'];
        }

        return $usdtBalance + $openPositionsCost;
    }

    private function floorToPrecision(float $value, int $decimals): float
    {
        $factor = 10 ** $decimals;

        return floor($value * $factor) / $factor;
    }

    // Borsanin gercek LOT_SIZE/PRICE_FILTER adimina (stepSize/tickSize) gore asagi yuvarlar
    private function floorToStep(float $value, float $stepSize): float
    {
        if ($stepSize <= 0) {
            return $this->floorToPrecision($value, 4);
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

    // bkz. AutoTradeController::criticalPersist() - AYNI RCA (22 Temmuz, BANKUSDT/ERAUSDT) sonrasi
    // tespit edildi: Binance basarili ama DB yazimi patlarsa sessizce sadece dosyaya loglanip
    // geciliyordu. Duyuru Avcisi AI onayi BEKLEMEDEN aninda alim yaptigi icin (bkz. CLAUDE.md) bu
    // risk ozellikle onemli - hicbir AI/insan onayi olmadan aninda para hareket ediyor
    private function criticalPersist(callable $fn, int $userId, string $pair, string $context): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $this->logSniper("KRİTİK: Kullanıcı #{$userId} {$pair} - {$context} sonrası kayıt başarısız: " . $e->getMessage());

            $this->notifyAdminAndCustomer(
                $userId,
                "🚨 ACİL: {$context} Sonrası Sistem Kaydı Başarısız! (Duyuru Avcısı)\nCoin: {$pair}\n\nBinance işlemi muhtemelen gerçekleşti ama veritabanına yazılamadı. Lütfen borsa hesabını manuel olarak kontrol edin."
            );

            throw $e;
        }
    }

    private function logSniper(string $message): void
    {
        $logDir = __DIR__ . '/../../storage/logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $entry = sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $message, PHP_EOL);
        file_put_contents($logDir . '/listing_sniper.log', $entry, FILE_APPEND);
    }
}
