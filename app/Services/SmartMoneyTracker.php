<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\ActiveTrade;
use App\Models\AiIntervention;
use App\Models\ApiKey;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use RuntimeException;
use Throwable;

// Admin tarafindan izlenen "balina" cuzdanlarinin Etherscan uzerindeki ERC-20 token hareketlerini
// takip eder. Izlenen bir cuzdan onemli buyuklukte bir token aldiginda VE bu token'in Binance'te
// gercekten islem goren bir USDT paritesi varsa, opt-in (smart_money_enabled=1) kullanicilar icin
// AYNI yonde (BUY) bir pozisyon acar - kullanicinin KENDI take_profit/stop_loss yuzdeleriyle
// (Duyuru Avcisi'nin aksine, burada agresif sabit bir oran kullanilmaz).
// Devre kesiciye (RiskManagerService) tabidir.
//
// ONEMLI SINIRLAMA: Etherscan'in dondurdugu "tokenSymbol" alani, token sozlesmesinin sahibi
// tarafindan serbestce belirlenen bir metadata'dir (saldirgan kontrolunde olabilir). Bu yuzden
// sembol eslesmesi TEK BASINA guvenilir degildir - bu servis, turetilen pariteyi Binance'te
// GERCEKTEN islem gormesini VE bir asgari likidite esigini gecmesini sart kosarak riski sinirlar
// (tamamen ortadan kaldirmaz).
final class SmartMoneyTracker
{
    private const ETHERSCAN_URL = 'https://api.etherscan.io/api';
    // Katı zaman aşımı: Etherscan yanıt vermezse istek en fazla 5 saniyede kesilir, sistemi asla kilitlemez
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TIMEOUT_SECONDS = 5;
    private const MIN_ORDER_BUDGET_USDT = 5.0;
    private const FEE_SAFETY_MARGIN = 0.999;
    // 15 Temmuz denetiminde: izlenen cuzdanlar (Wintermute/DWF Labs gibi piyasa yapicilari dahil)
    // GERCEKTEN yapilandirilmis olsa da haftalarca sinyal_detected=0 kaldigi tespit edildi.
    // Kismi sebep: bu deger eskiden 20'ydi - yuksek hacimli bir cuzdanin "son 20 token islemi"
    // saatler icinde tukenebiliyor ve cogunlukla GIDEN (satis) islemlerden olusabiliyor, GELEN
    // (alim niteligindeki) bir transfer bu dar pencereye hic girmeden kayabiliyordu. 50'ye
    // cikarilarak yakalama sansi ~2.5 kat artirildi - Etherscan'in ucretsiz kota siniri
    // (5 istek/sn, 100.000/gun) icin hala onemsiz bir maliyet
    private const MAX_TX_PER_WALLET = 50;
    private const WATCHED_WALLETS_KEY = 'smart_money_watched_wallets';

    private readonly string $apiKey;
    private readonly TelegramService $telegram;
    private readonly RiskManagerService $riskManager;

    public function __construct()
    {
        $dbValue = Setting::get('etherscan_api_key');

        if ($dbValue !== null) {
            $this->apiKey = $dbValue;
        } else {
            $config = require __DIR__ . '/../../config/app.php';
            $this->apiKey = (string) ($config['etherscan_api_key'] ?? '');
        }

        $this->telegram = new TelegramService();
        $this->riskManager = new RiskManagerService();
    }

    // @return array{wallets_checked: int, signals_detected: int, positions_opened: int}
    public function run(): array
    {
        // Sistem Durumu widget'i icin: bu metodun cagrildigi HER an (asagidaki erken donusler
        // dahil) kosulsuz kaydedilir - "cron gercekten tetikleniyor mu" sorusuna, notlu/hata
        // loglarindan BAGIMSIZ, guvenilir bir cevap verir (bkz. DashboardController::apiSystemStatus)
        Setting::set('smart_money_last_run_at', (string) time());

        if ($this->apiKey === '') {
            error_log('NexaTrade SmartMoneyTracker: ETHERSCAN_API_KEY tanımlı değil, tarama atlanıyor.');

            return ['wallets_checked' => 0, 'signals_detected' => 0, 'positions_opened' => 0];
        }

        // Flaş Çöküş Koruması: bir balina alımı gercek bir sinyal olsa da, BTC'nin sert bir ani
        // cokus yasadigi bir anda YENI bir pozisyon acmak (kaldiracsiz spot olsa bile) genel
        // piyasa panigine yakalanma riskini tasir - AutoTradeController/ListingSniperService ile
        // AYNI paylasilan RiskManagerService::checkFlashCrash() kontrolu burada da uygulanir.
        // Devre kesiciden (checkCircuitBreaker, asagida kullanici bazli zaten calisiyor) FARKLI -
        // bu kullanici bazli degil, TUM taramayi TEK seferde durdurur
        $flashCrashReason = $this->riskManager->checkFlashCrash();

        if ($flashCrashReason !== null) {
            error_log("NexaTrade SmartMoneyTracker: Flaş Çöküş Koruması - {$flashCrashReason}");

            return ['wallets_checked' => 0, 'signals_detected' => 0, 'positions_opened' => 0];
        }

        $wallets = $this->loadWatchedWallets();

        if ($wallets === []) {
            return ['wallets_checked' => 0, 'signals_detected' => 0, 'positions_opened' => 0];
        }

        $scanner = new MarketScanner();
        $tradableSymbols = $scanner->fetchTradableUsdtSymbols();

        $signalsDetected = 0;
        $positionsOpened = 0;

        foreach ($wallets as $wallet) {
            $address = (string) ($wallet['address'] ?? '');

            if ($address === '') {
                continue;
            }

            try {
                $transfers = $this->fetchIncomingTransfers($address);
            } catch (Throwable $e) {
                error_log("NexaTrade SmartMoneyTracker: {$address} için transfer alınamadı - " . $e->getMessage());
                continue;
            }

            foreach ($transfers as $transfer) {
                $txHash = (string) ($transfer['hash'] ?? '');

                if ($txHash === '' || !$this->claimTransaction($txHash)) {
                    continue; // zaten islenmis (veya baska bir surec ayni anda islemis)
                }

                $pair = strtoupper(trim((string) ($transfer['tokenSymbol'] ?? ''))) . 'USDT';

                if (!in_array($pair, $tradableSymbols, true)) {
                    continue; // Binance'te gercekten islem gormeyen bir pariteye asla guvenilmez
                }

                try {
                    $usdValue = $this->estimateTransferUsdValue($transfer, $pair);
                } catch (Throwable $e) {
                    continue;
                }

                if ($usdValue < $this->getMinTransferUsd()) {
                    continue;
                }

                $signalsDetected++;
                $positionsOpened += $this->copyTradeForAllUsers($pair, $wallet['label'] ?? $address, $usdValue);
            }
        }

        return [
            'wallets_checked' => count($wallets),
            'signals_detected' => $signalsDetected,
            'positions_opened' => $positionsOpened,
        ];
    }

    // @return array<array{address: string, label: string}>
    private function loadWatchedWallets(): array
    {
        $cached = Setting::get(self::WATCHED_WALLETS_KEY);

        if ($cached === null) {
            return [];
        }

        $decoded = json_decode($cached, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function fetchIncomingTransfers(string $address): array
    {
        $url = self::ETHERSCAN_URL . '?' . http_build_query([
            'module' => 'account',
            'action' => 'tokentx',
            'address' => $address,
            'sort' => 'desc',
            'page' => 1,
            'offset' => self::MAX_TX_PER_WALLET,
            'apikey' => $this->apiKey,
        ]);

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
            throw new RuntimeException("Etherscan API HTTP {$httpCode}.");
        }

        $decoded = json_decode((string) $rawResponse, true);
        $result = $decoded['result'] ?? [];

        if (!is_array($result)) {
            return [];
        }

        // Sadece izlenen cuzdana GELEN (alim niteliginde olabilecek) transferleri dikkate al
        return array_values(array_filter(
            $result,
            static fn (array $tx): bool => strtolower((string) ($tx['to'] ?? '')) === strtolower($address)
        ));
    }

    // Transfer edilen token miktarini (ondalik duzeltmesiyle) guncel Binance fiyatiyla carpip
    // yaklasik USD degerini hesaplar
    private function estimateTransferUsdValue(array $transfer, string $pair): float
    {
        $rawValue = (string) ($transfer['value'] ?? '0');
        $decimals = (int) ($transfer['tokenDecimal'] ?? 18);

        $tokenQuantity = ((float) $rawValue) / (10 ** $decimals);

        if ($tokenQuantity <= 0) {
            return 0.0;
        }

        // Fiyat sorgusu icin gecici, anahtarsiz bir BinanceService yeterlidir (getPrice genel bir uc noktadir)
        $binance = new BinanceService('', '');
        $price = $binance->getPrice($pair);

        return $tokenQuantity * $price;
    }

    // Ayni islem hash'inin, cakisan iki cron tetiklemesi tarafindan iki kez islenmesini
    // ATOMIK olarak engeller (ListingSniperService::claimIfNew ile ayni desen)
    private function claimTransaction(string $txHash): bool
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('INSERT IGNORE INTO smart_money_seen_txs (tx_hash) VALUES (:tx_hash)');
        $stmt->execute([':tx_hash' => $txHash]);

        return $stmt->rowCount() === 1;
    }

    private function copyTradeForAllUsers(string $pair, string $walletLabel, float $usdValue): int
    {
        $users = ApiKey::findAllForSmartMoney();
        $opened = 0;

        foreach ($users as $userKey) {
            $userId = (int) $userKey['user_id'];

            try {
                $totalEquity = $this->calculateTotalEquity($userKey, $userId);
            } catch (Throwable $e) {
                continue;
            }

            $blockReason = $this->riskManager->checkCircuitBreaker($userId, $userKey, $totalEquity);

            if ($blockReason !== null) {
                continue;
            }

            try {
                if ($this->buyAndProtect($userId, $userKey, $pair, $walletLabel, $usdValue)) {
                    $opened++;
                }
            } catch (Throwable $e) {
                error_log("NexaTrade SmartMoneyTracker: Kullanıcı #{$userId} için {$pair} kopya alımı başarısız - " . $e->getMessage());
            }
        }

        return $opened;
    }

    private function buyAndProtect(int $userId, array $userKey, string $pair, string $walletLabel, float $usdValue): bool
    {
        $binance = new BinanceService($userKey['api_key'], $userKey['secret_key']);
        $takeProfitPercent = (float) $userKey['take_profit_percent'];
        $stopLossPercent = (float) $userKey['stop_loss_percent'];
        $budgetPercent = (float) ($userKey['auto_trade_budget_percent'] ?? 10.0);
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
            return false;
        }

        $price = $binance->getPrice($pair);

        if ($price <= 0) {
            return false;
        }

        try {
            $filters = $binance->getSymbolFilters($pair);
            $stepSize = $filters['step_size'] > 0 ? $filters['step_size'] : 0.0001;
        } catch (Throwable $e) {
            $stepSize = 0.0001;
        }

        // LOT_SIZE (Adim Yuvarlama) Guvenlik Kalkani: bkz. LotSizeGuardService yorumu -
        // AutoTradeController ile PAYLASILAN, tum otonom alim modullerinin ayni sekilde uyguladigi kontrol
        $lotSizeGuard = LotSizeGuardService::evaluate($budget / $price, $stepSize);

        if (!$lotSizeGuard['safe']) {
            error_log(sprintf(
                'NexaTrade SmartMoneyTracker: İşlem İptal Edildi: %s için LOT_SIZE yuvarlama kaybı (%%%.2f) güvenlik sınırını (%%%.1f) aşıyor.',
                $pair,
                $lotSizeGuard['fire_percent'],
                LotSizeGuardService::DEFAULT_MAX_SLIPPAGE_TOLERANCE_PERCENT
            ));

            AiIntervention::record(
                $userId,
                $pair,
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

        $buyResult = $binance->placeOrder($pair, 'BUY', 'MARKET', $quantity);

        if (!$buyResult['success']) {
            Order::create([
                'user_id' => $userId,
                'pair' => $pair,
                'side' => 'BUY',
                'type' => 'MARKET',
                'quantity' => $quantity,
                'price' => $price,
                'total' => $budget,
                'binance_order_id' => null,
                'status' => 'FAILED',
                'error_message' => $buyResult['error'],
            ]);

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
            'pair' => $pair,
            'side' => 'BUY',
            'type' => 'MARKET',
            'quantity' => $executedQty > 0 ? $executedQty : $quantity,
            'price' => $entryPrice,
            'total' => $cumulativeQuote > 0 ? $cumulativeQuote : $budget,
            'binance_order_id' => $buyResult['order_id'],
            'commission' => $commission['commission'],
            'commission_asset' => $commission['commission_asset'],
            'status' => 'FILLED',
        ]), $userId, $pair, 'Akıllı Para Kopya Alımı');

        $this->protectPosition(
            $binance,
            $userId,
            $pair,
            $buyOrderId,
            $entryPrice,
            $executedQty > 0 ? $executedQty : $quantity,
            $takeProfitPercent,
            $stopLossPercent,
            $walletLabel,
            $usdValue
        );

        return true;
    }

    private function protectPosition(
        BinanceService $binance,
        int $userId,
        string $pair,
        int $buyOrderId,
        float $entryPrice,
        float $boughtQuantity,
        float $takeProfitPercent,
        float $stopLossPercent,
        string $walletLabel,
        float $usdValue
    ): void {
        try {
            $filters = $binance->getSymbolFilters($pair);
            $stepSize = $filters['step_size'] > 0 ? $filters['step_size'] : 0.0001;
            $tickSize = $filters['tick_size'] > 0 ? $filters['tick_size'] : 0.00000001;
        } catch (Throwable $e) {
            $stepSize = 0.0001;
            $tickSize = 0.00000001;
        }

        $takeProfitPrice = $this->floorToStep($entryPrice * (1 + $takeProfitPercent / 100), $tickSize);
        $stopTriggerPrice = $this->floorToStep($entryPrice * (1 - $stopLossPercent / 100), $tickSize);

        $ocoQuantity = $this->floorToStep($boughtQuantity * self::FEE_SAFETY_MARGIN, $stepSize);

        if ($ocoQuantity <= 0) {
            return;
        }

        // stopLimitPrice verilmiyor (null) - duz STOP_LOSS (piyasa) emri, slippage riski yok
        // (bkz. BinanceService::placeOCOOrder yorumu)
        $ocoResult = $binance->placeOCOOrder($pair, 'SELL', $ocoQuantity, $takeProfitPrice, $stopTriggerPrice);

        if (!$ocoResult['success']) {
            Order::create([
                'user_id' => $userId,
                'pair' => $pair,
                'side' => 'SELL',
                'type' => 'OCO',
                'quantity' => $ocoQuantity,
                'price' => $takeProfitPrice,
                'total' => round($ocoQuantity * $takeProfitPrice, 8),
                'binance_order_id' => null,
                'parent_order_id' => $buyOrderId,
                'status' => 'FAILED',
                'error_message' => $ocoResult['error'],
            ]);

            $this->notifyAdminAndCustomer(
                $userId,
                "🚨 ACİL: Akıllı Para Kopyalayıcı OCO Emri Girilemedi, Pozisyon Korumasız!\n" .
                "Coin: {$pair}\n" .
                "Hata: {$ocoResult['error']}\n\n" .
                'Lütfen borsa hesabını manuel olarak kontrol edin.'
            );

            return;
        }

        $this->criticalPersist(function () use ($userId, $pair, $buyOrderId, $ocoQuantity, $entryPrice, $takeProfitPrice, $stopTriggerPrice, $ocoResult): void {
            $activeTradeId = ActiveTrade::create([
                'user_id' => $userId,
                'pair' => $pair,
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
        }, $userId, $pair, 'Akıllı Para Pozisyon Kaydı');

        $this->notifyCustomer(
            $userId,
            sprintf(
                "🐳 [NexaTrade] Akıllı Para Kopyalandı!\nCoin: %s | Giriş: %s\nİzlenen Cüzdan: %s (~$%s alım)",
                $pair,
                $this->formatPrice($entryPrice),
                $walletLabel,
                number_format($usdValue, 0)
            )
        );
    }

    private function getMinTransferUsd(): float
    {
        $dbValue = Setting::get('smart_money_min_transfer_usd');

        if ($dbValue !== null) {
            return (float) $dbValue;
        }

        $config = require __DIR__ . '/../../config/app.php';

        return (float) ($config['smart_money_min_transfer_usd'] ?? 50000.0);
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
    // tespit edildi: Binance basarili ama DB yazimi patlarsa sessizce sadece error_log'a yazilip
    // geciliyordu, hicbir Telegram uyarisi gitmiyordu
    private function criticalPersist(callable $fn, int $userId, string $pair, string $context): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            error_log("NexaTrade SmartMoneyTracker: KRİTİK: Kullanıcı #{$userId} {$pair} - {$context} sonrası kayıt başarısız: " . $e->getMessage());

            $this->notifyAdminAndCustomer(
                $userId,
                "🚨 ACİL: {$context} Sonrası Sistem Kaydı Başarısız! (Akıllı Para)\nCoin: {$pair}\n\nBinance işlemi muhtemelen gerçekleşti ama veritabanına yazılamadı. Lütfen borsa hesabını manuel olarak kontrol edin."
            );

            throw $e;
        }
    }
}
