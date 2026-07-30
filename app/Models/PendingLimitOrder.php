<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

// 27 Temmuz'da eklendi: eski "aktif bekleme" tabanlı Pullback Kalkanı'nın yerini alan, borsanın
// kendi order book'unda bekleyen GERÇEK bir limit alış emrini temsil eder. AutoTradeController'ın
// huntForAllUsers()'ı artık piyasa emriyle ANINDA almıyor - sinyal fiyatının biraz altına bir LIMIT
// emir koyup burada "beklemede" olarak kaydediyor, gerçek pozisyon (ActiveTrade) SADECE emir
// gerçekten dolunca oluşturuluyor (bkz. AutoTradeController::checkPendingLimitOrders(), Fast
// Tracker'dan 1dk'da bir çağrılır). PendingSignal (Ardışık Çift Onay) ile KARIŞTIRILMAMALI - o,
// "aday birkaç tur üst üste sinyal veriyor mu" sorusuna bakar ve bu tablodan TAMAMEN ayrıdır,
// bir sinyal önce PendingSignal'de 2 tur onaylanır, SONRA burada gerçek bir emre dönüşür
final class PendingLimitOrder
{
    public static function create(array $data): int
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'INSERT INTO pending_limit_orders
                (user_id, pair, binance_order_id, limit_price, quantity, budget, budget_percent,
                 take_profit_percent, stop_loss_percent, strategy_bucket, score, rsi_1h, rsi_15m)
             VALUES
                (:user_id, :pair, :binance_order_id, :limit_price, :quantity, :budget, :budget_percent,
                 :take_profit_percent, :stop_loss_percent, :strategy_bucket, :score, :rsi_1h, :rsi_15m)'
        );

        $stmt->execute([
            ':user_id' => $data['user_id'],
            ':pair' => $data['pair'],
            ':binance_order_id' => $data['binance_order_id'],
            ':limit_price' => $data['limit_price'],
            ':quantity' => $data['quantity'],
            ':budget' => $data['budget'],
            ':budget_percent' => $data['budget_percent'],
            ':take_profit_percent' => $data['take_profit_percent'],
            ':stop_loss_percent' => $data['stop_loss_percent'],
            ':strategy_bucket' => $data['strategy_bucket'],
            ':score' => $data['score'],
            ':rsi_1h' => $data['rsi_1h'],
            ':rsi_15m' => $data['rsi_15m'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    // huntForAllUsers()'daki hasOpenPositionForPair() kontrolünün bekleyen-emir karşılığı - aynı
    // kullanıcı+parite için zaten bekleyen bir limit emri varsa ikinci bir tane daha KONULMAZ
    public static function existsForUserAndPair(int $userId, string $pair): bool
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM pending_limit_orders WHERE user_id = :user_id AND pair = :pair'
        );
        $stmt->execute([':user_id' => $userId, ':pair' => $pair]);

        return ((int) $stmt->fetchColumn()) > 0;
    }

    // checkPendingLimitOrders()'ın her Fast Tracker turunda dolup dolmadığını kontrol edeceği TÜM
    // bekleyen emirler - küçük bir tablo olduğu için (aktif kullanıcı sayısı kadar) filtre gerekmez
    public static function findAll(): array
    {
        $pdo = Database::getInstance();

        return $pdo->query('SELECT * FROM pending_limit_orders ORDER BY id ASC')->fetchAll();
    }

    // Dashboard "Bekleyen Emirler" paneli icin (31 Temmuz) - musterinin "kactan/ne kadarlik alacagini
    // ONCEDEN gormek istiyorum" talebi. remaining_seconds MySQL'in KENDI saatiyle hesaplanir (bkz.
    // SymbolCooldown::getCooldownUntil AYNI ilke) - PHP tarafinda placed_at uzerinden ayrica
    // strtotime()/time() KARSILASTIRMASI YAPILMAZ. 900 (15 dk) sabiti AutoTradeController::
    // PENDING_LIMIT_ORDER_TIMEOUT_MINUTES ile SENKRON tutulmali - degisirse burasi da guncellenmeli
    public static function findByUser(int $userId): array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'SELECT *,
                    GREATEST(0, 900 - TIMESTAMPDIFF(SECOND, placed_at, NOW())) AS remaining_seconds
             FROM pending_limit_orders
             WHERE user_id = :user_id
             ORDER BY placed_at DESC'
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('DELETE FROM pending_limit_orders WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
