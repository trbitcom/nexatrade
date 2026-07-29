<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\ActiveFuturesTrade;
use App\Models\ActiveTrade;
use App\Models\ApiKey;
use App\Models\Order;
use App\Models\User;
use Throwable;

// 25 Temmuz'da eklendi (kullanici talebi): her gece 00:00'da (cPanel Cron Job, gunde bir kez) her
// musteriye KENDI hesabinin gunluk ozetini Telegram'dan gonderir - "Bildirim Yonlendirmesi" desenine
// uyar (bkz. CLAUDE.md): rutin bir bildirim oldugu icin SADECE ilgili musterinin kendi Telegram'ina
// gider, admin'e dusmez. Bakiye cekme HARIC her sey saf DB sorgusu - Binance cagrisi BASARISIZ olursa
// (gecici API hatasi) o kullanicinin ozeti bakiye SATIRI OLMADAN gonderilir, TUM ozeti iptal etmez
final class DailySummaryService
{
    public function run(): array
    {
        $pdo = Database::getInstance();

        // Sadece Telegram'ini baglamis VE hesabi aktif olan kullanicilar - baglanmamissa gonderilecek
        // bir yer yok, pasif/banli hesaba gonderim anlamsiz
        $stmt = $pdo->query(
            "SELECT id, name FROM users WHERE status = 'active' AND telegram_chat_id IS NOT NULL"
        );
        $users = $stmt->fetchAll();

        $telegram = new TelegramService();
        $sentCount = 0;

        foreach ($users as $user) {
            $userId = (int) $user['id'];

            try {
                $chatId = User::findTelegramChatId($userId);

                if ($chatId === null) {
                    continue;
                }

                $message = $this->buildSummaryMessage($userId, (string) $user['name']);

                if ($telegram->notifyUser($chatId, $message)) {
                    $sentCount++;
                }
            } catch (Throwable $e) {
                // Fail-open: bir kullanicinin ozeti hata verirse (ör. Binance API gecici sorunu)
                // diger kullanicilarin ozetlerini ENGELLEMEZ, sadece error_log'a yazar
                error_log("NexaTrade DailySummaryService: kullanıcı #{$userId} için özet oluşturulamadı - " . $e->getMessage());
            }
        }

        return ['users_notified' => $sentCount, 'total_users' => count($users)];
    }

    private function buildSummaryMessage(int $userId, string $name): string
    {
        // 28 Temmuz'da DUZELTILDI: eskiden calculateTodayPNL() (CURDATE()) kullanilirdi - bu cron
        // TAM gece yarisinda tetiklendigi icin "bugun" o anda 0 saniye gecmis oluyor, ozet HER ZAMAN
        // "0 islem" gosteriyordu. Artik biten (dun'un) takvim gununu ozetliyor - bkz. Order::calculateYesterdayPNL() yorumu
        $yesterdayPnl = Order::calculateYesterdayPNL($userId);
        $openSpot = ActiveTrade::findOpenForUser($userId);
        $openFutures = ActiveFuturesTrade::findOpenForUser($userId);

        $pnlEmoji = $yesterdayPnl['net_pnl'] >= 0 ? '🟢' : '🔴';
        $pnlSign = $yesterdayPnl['net_pnl'] >= 0 ? '+' : '';
        $winRate = $yesterdayPnl['total_trades'] > 0
            ? round($yesterdayPnl['wins'] / $yesterdayPnl['total_trades'] * 100, 1)
            : null;

        $lines = [
            "📊 [NexaTrade] Günlük Hesap Özeti",
            "Dün ({$this->yesterday()}) kapanan işlem: {$yesterdayPnl['total_trades']} ({$yesterdayPnl['wins']} kazanan)" . ($winRate !== null ? " - %{$winRate}" : ''),
            "{$pnlEmoji} Dünkü net PNL: {$pnlSign}{$yesterdayPnl['net_pnl']} USDT",
            "Açık spot pozisyon: " . count($openSpot),
            "Açık futures pozisyon: " . count($openFutures),
        ];

        $balanceLine = $this->tryFetchBalance($userId);

        if ($balanceLine !== null) {
            $lines[] = "Güncel bakiye: {$balanceLine} USDT";
        }

        return implode("\n", $lines);
    }

    // Binance bakiye cagrisi BASARISIZ olursa (gecici API hatasi, kullanicinin gecersiz/eksik
    // anahtari) null doner - cagiran taraf bu satiri ozetten ATLAR, TUM ozeti iptal etmez
    private function tryFetchBalance(int $userId): ?string
    {
        try {
            $apiKeys = ApiKey::findByUser($userId);

            if ($apiKeys === []) {
                return null;
            }

            $binance = new BinanceService($apiKeys[0]['api_key'], $apiKeys[0]['secret_key']);

            foreach ($binance->getBalances() as $balance) {
                if ($balance['asset'] === 'USDT') {
                    return number_format($balance['free'], 2);
                }
            }

            return null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function yesterday(): string
    {
        return date('d.m.Y', strtotime('-1 day'));
    }
}
