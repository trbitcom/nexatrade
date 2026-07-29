<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Models\ApiKey;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\BinanceService;
use App\Services\TelegramService;
use RuntimeException;
use Throwable;

// TradingView (veya benzeri) servislerden gelen webhook sinyallerini karsilar ve emre donusturur
final class WebhookController
{
    // Binance'in spot piyasalarda genel olarak kabul ettigi asgari islem tutari (USDT)
    private const MIN_ORDER_BUDGET_USDT = 5.0;

    private readonly TelegramService $telegram;

    public function __construct()
    {
        $this->telegram = new TelegramService();
    }

    public function handle(): void
    {
        header('Content-Type: application/json');

        $rawPayload = file_get_contents('php://input') ?: '';
        $data = json_decode($rawPayload, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Geçersiz JSON verisi'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!$this->isTokenValid($data)) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Yetkisiz istek: geçersiz webhook token.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pair = strtoupper((string) ($data['pair'] ?? $data['ticker'] ?? 'UNKNOWN'));
        $side = strtoupper((string) ($data['action'] ?? $data['side'] ?? 'UNKNOWN'));
        $percentage = (float) ($data['percentage'] ?? 0);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

        try {
            $pdo = Database::getInstance();

            $stmt = $pdo->prepare(
                'INSERT INTO webhook_logs (pair, action, payload, ip_address) VALUES (:pair, :action, :payload, :ip_address)'
            );

            $stmt->execute([
                ':pair' => $pair,
                ':action' => $side,
                ':payload' => $rawPayload,
                ':ip_address' => $ipAddress,
            ]);

            $logId = (int) $pdo->lastInsertId();

            $processedUsers = 0;

            // BUY: kasa yuzdesi zorunlu (ne kadar USDT ile pozisyon acilacagini belirler)
            // SELL: yuzdeye ihtiyac yok, elde ne kadar coin varsa tamami satilarak pozisyon kapatilir
            $isValidBuy = $side === 'BUY' && $percentage > 0 && $percentage <= 100;
            $isValidSell = $side === 'SELL';

            if ($isValidBuy) {
                $processedUsers = $this->executeBuyForAllUsers($pair, $percentage);
            } elseif ($isValidSell) {
                $processedUsers = $this->executeSellForAllUsers($pair);
            }

            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'message' => 'Webhook başarıyla alındı ve işlendi.',
                'log_id' => $logId,
                'processed_users' => $processedUsers,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Sunucu hatası oluştu.'], JSON_UNESCAPED_UNICODE);
        }
    }

    // Token, JSON payload icinde ("token") veya URL parametresinde (?token=...) gonderilebilir
    private function isTokenValid(array $data): bool
    {
        // Once admin panelinden (DB) girilen token'a bak, yoksa config/app.php'deki degere don
        $expectedToken = Setting::get('webhook_token');

        if ($expectedToken === null) {
            $config = require __DIR__ . '/../../config/app.php';
            $expectedToken = (string) $config['webhook_token'];
        }

        $providedToken = (string) ($data['token'] ?? $_GET['token'] ?? '');

        return $providedToken !== '' && hash_equals($expectedToken, $providedToken);
    }

    // API anahtari kayitli tum aktif kullanicilar icin, kendi kasa yuzdelerine gore ALIS (pozisyon acma) emri girer
    // Bir kullanicinin hatasi (ör. yetersiz bakiye) digerlerini etkilemesin diye her biri kendi try-catch'i icinde calisir
    private function executeBuyForAllUsers(string $pair, float $percentage): int
    {
        $activeUsers = ApiKey::findAllForActiveUsers();
        $processedCount = 0;

        foreach ($activeUsers as $userKey) {
            try {
                $binance = new BinanceService($userKey['api_key'], $userKey['secret_key']);

                // 1) Kullanicinin USDT bakiyesinden, sinyaldeki yuzdeye gore islem butcesini hesapla
                $usdtBalance = $this->getAssetBalance($binance, 'USDT');
                $budget = $usdtBalance * ($percentage / 100);

                if ($budget < self::MIN_ORDER_BUDGET_USDT) {
                    throw new RuntimeException(sprintf(
                        'Yetersiz bakiye/butce: %.2f USDT (asgari %.2f USDT gerekli)',
                        $budget,
                        self::MIN_ORDER_BUDGET_USDT
                    ));
                }

                // 2) Anlik fiyati cekip butceyi coin miktarina cevir
                $price = $binance->getPrice($pair);

                if ($price <= 0) {
                    throw new RuntimeException("Gecerli fiyat alinamadi: {$pair}");
                }

                // 3) LOT_SIZE hatasi almamak icin guvenli bir ondalik seviyesine yuvarla
                $quantity = round($budget / $price, 4);

                if ($quantity <= 0) {
                    throw new RuntimeException('Hesaplanan miktar sifir veya negatif cikti.');
                }

                $result = $binance->placeOrder($pair, 'BUY', 'MARKET', $quantity);
                $this->recordOrderResult($userKey['user_id'], $pair, 'BUY', $result, $quantity, $price, $budget);

                $processedCount++;
            } catch (Throwable $e) {
                $this->logAutomationError("Kullanici #{$userKey['user_id']} (BUY) islenirken hata: " . $e->getMessage());
                continue;
            }
        }

        return $processedCount;
    }

    // API anahtari kayitli tum aktif kullanicilar icin, elde tutulan coinin tamamini satarak pozisyonu kapatir
    private function executeSellForAllUsers(string $pair): int
    {
        $baseAsset = $this->extractBaseAsset($pair);
        $activeUsers = ApiKey::findAllForActiveUsers();
        $processedCount = 0;

        foreach ($activeUsers as $userKey) {
            try {
                $binance = new BinanceService($userKey['api_key'], $userKey['secret_key']);

                // 1) USDT degil, satilacak coinin (ör. BTC) mevcut bakiyesini cek
                $assetBalance = $this->getAssetBalance($binance, $baseAsset);

                if ($assetBalance <= 0) {
                    throw new RuntimeException("Satilacak {$baseAsset} bakiyesi yok.");
                }

                $price = $binance->getPrice($pair);

                if ($price <= 0) {
                    throw new RuntimeException("Gecerli fiyat alinamadi: {$pair}");
                }

                // 2) LOT_SIZE icin ASAGI yuvarla: yukari yuvarlama, elde olmayan miktari satmaya calisip
                //    Binance'ten "yetersiz bakiye" hatasi almamiza yol acabilir
                $quantity = $this->floorToPrecision($assetBalance, 4);
                $estimatedNotional = $quantity * $price;

                if ($quantity <= 0 || $estimatedNotional < self::MIN_ORDER_BUDGET_USDT) {
                    throw new RuntimeException(sprintf(
                        'Satis tutari asgari islem limitinin (Min Notional) altinda: %.2f USDT (asgari %.2f USDT gerekli)',
                        $estimatedNotional,
                        self::MIN_ORDER_BUDGET_USDT
                    ));
                }

                $result = $binance->placeOrder($pair, 'SELL', 'MARKET', $quantity);
                $this->recordOrderResult($userKey['user_id'], $pair, 'SELL', $result, $quantity, $price, $estimatedNotional);

                $processedCount++;
            } catch (Throwable $e) {
                $this->logAutomationError("Kullanici #{$userKey['user_id']} (SELL) islenirken hata: " . $e->getMessage());
                continue;
            }
        }

        return $processedCount;
    }

    // Binance'ten donen emir sonucunu (basarili/basarisiz) orders tablosuna kaydeder
    // Basarili emirlerde gercek gerceklesen miktar/fiyati kullanir, yoksa hesaplanan tahmini degerlere duser
    private function recordOrderResult(
        int $userId,
        string $pair,
        string $side,
        array $result,
        float $fallbackQuantity,
        float $fallbackPrice,
        float $fallbackTotal
    ): void {
        if (!$result['success']) {
            Order::create([
                'user_id' => $userId,
                'pair' => $pair,
                'side' => $side,
                'type' => 'MARKET',
                'quantity' => $fallbackQuantity,
                'price' => $fallbackPrice,
                'total' => $fallbackTotal,
                'binance_order_id' => null,
                'status' => 'FAILED',
                'error_message' => $result['error'] ?? null,
            ]);

            return;
        }

        $raw = $result['raw'];
        $executedQty = (float) ($raw['executedQty'] ?? $fallbackQuantity);
        $cumulativeQuote = (float) ($raw['cummulativeQuoteQty'] ?? 0);
        $avgPrice = $executedQty > 0 ? $cumulativeQuote / $executedQty : $fallbackPrice;
        // Komisyon Takibi: $raw'daki fills[] icin EK bir API cagrisi gerekmez
        $commission = BinanceService::extractFillCommission($raw);

        // bkz. AutoTradeController::criticalPersist() - AYNI RCA (22 Temmuz, BANKUSDT/ERAUSDT)
        // denetiminde bu dosyada da AYNI desen bulundu: Binance emri basarili olduktan SONRA
        // Order::create() patlarsa (ör. DB kesintisi) bu satir olmadan sessizce gecilirdi - webhook
        // kaynakli islemlerin Telegram bildirimi/koruma mekanizmasi zaten YOK (bkz. CLAUDE.md, bu
        // basit bir dis sinyal alicisi), o yuzden DB kaydi TEK gorunurluk kaynagi - kaybolursa
        // musteri Binance'te gerceklesen bir islemi sistemde HICBIR YERDE goremez
        try {
            Order::create([
                'user_id' => $userId,
                'pair' => $pair,
                'side' => $side,
                'type' => 'MARKET',
                'quantity' => $executedQty > 0 ? $executedQty : $fallbackQuantity,
                'price' => $avgPrice,
                'total' => $cumulativeQuote > 0 ? $cumulativeQuote : $fallbackTotal,
                'binance_order_id' => $result['order_id'],
                'commission' => $commission['commission'],
                'commission_asset' => $commission['commission_asset'],
                'status' => 'FILLED',
            ]);
        } catch (Throwable $e) {
            $this->logAutomationError("KRİTİK: Kullanıcı #{$userId} {$pair} - webhook emri Binance'te gerçekleşti ama kayıt başarısız: " . $e->getMessage());

            $chatId = User::findTelegramChatId($userId);
            $alert = "🚨 ACİL: Webhook Emri Sonrası Sistem Kaydı Başarısız!\nCoin: {$pair}\nİşlem: {$side}\n\nBinance işlemi muhtemelen gerçekleşti ama veritabanına yazılamadı. Lütfen borsa hesabını manuel olarak kontrol edin.";

            if ($chatId !== null) {
                $this->telegram->notifyUser($chatId, $alert);
            }

            $this->telegram->notifyAdmin("Kullanıcı: #{$userId}\n" . $alert);

            throw $e;
        }
    }

    // "BTCUSDT" -> "BTC" gibi, paritenin USDT (quote asset) kismini cikarip taban varligi doner
    private function extractBaseAsset(string $pair): string
    {
        if (str_ends_with($pair, 'USDT')) {
            return substr($pair, 0, -4);
        }

        return $pair;
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

    // round()'un aksine her zaman asagi yuvarlar; elde tutulandan fazlasini satma riskini ortadan kaldirir
    private function floorToPrecision(float $value, int $decimals): float
    {
        $factor = 10 ** $decimals;

        return floor($value * $factor) / $factor;
    }

    private function logAutomationError(string $message): void
    {
        $logDir = __DIR__ . '/../../storage/logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $entry = sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $message, PHP_EOL);
        file_put_contents($logDir . '/webhook_errors.log', $entry, FILE_APPEND);
    }
}
