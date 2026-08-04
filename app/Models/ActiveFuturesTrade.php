<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

// Binance Futures uzerinde acilan KISA (short) pozisyonlarin TEK dogru kaynagi.
// active_trades'ten (spot) BILINCLI olarak AYRI - bkz. database.sql'deki tablo yorumu
final class ActiveFuturesTrade
{
    public static function create(array $data): int
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'INSERT INTO active_futures_trades
                (user_id, pair, leverage, margin_type, open_order_id, quantity, entry_price,
                 liquidation_price, take_profit_price, stop_loss_price,
                 initial_take_profit_price, initial_stop_loss_price,
                 take_profit_order_id, stop_loss_order_id, status)
             VALUES
                (:user_id, :pair, :leverage, :margin_type, :open_order_id, :quantity, :entry_price,
                 :liquidation_price, :take_profit_price, :stop_loss_price,
                 :initial_take_profit_price, :initial_stop_loss_price,
                 :take_profit_order_id, :stop_loss_order_id, :status)'
        );

        $stmt->execute([
            ':user_id' => $data['user_id'],
            ':pair' => $data['pair'],
            ':leverage' => $data['leverage'],
            ':margin_type' => $data['margin_type'] ?? 'isolated',
            ':open_order_id' => $data['open_order_id'],
            ':quantity' => $data['quantity'],
            ':entry_price' => $data['entry_price'],
            ':liquidation_price' => $data['liquidation_price'] ?? null,
            ':take_profit_price' => $data['take_profit_price'],
            ':stop_loss_price' => $data['stop_loss_price'],
            // Giris anindaki degerlerin kalici kopyasi - applyTrailingStop() bu iki parametreyi
            // ICERMEZ, dolayisiyla bir daha asla yazilmazlar (bkz. database.sql migrasyon yorumu)
            ':initial_take_profit_price' => $data['take_profit_price'],
            ':initial_stop_loss_price' => $data['stop_loss_price'],
            ':take_profit_order_id' => $data['take_profit_order_id'] ?? null,
            ':stop_loss_order_id' => $data['stop_loss_order_id'] ?? null,
            ':status' => $data['status'] ?? 'open',
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function hasOpenPositionForPair(int $userId, string $pair): bool
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM active_futures_trades WHERE user_id = :user_id AND pair = :pair AND status = 'open'"
        );
        $stmt->execute([':user_id' => $userId, ':pair' => $pair]);

        return ((int) $stmt->fetchColumn()) > 0;
    }

    public static function findOpenForUser(int $userId): array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "SELECT * FROM active_futures_trades WHERE user_id = :user_id AND status = 'open' ORDER BY opened_at DESC"
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public static function countOpenForUser(int $userId): int
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM active_futures_trades WHERE user_id = :user_id AND status = 'open'");
        $stmt->execute([':user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    // Admin paneli istatistigi: platform genelinde su an acik olan toplam KISA pozisyon sayisi
    public static function countAllOpen(): int
    {
        $pdo = Database::getInstance();

        return (int) $pdo->query("SELECT COUNT(*) FROM active_futures_trades WHERE status = 'open'")->fetchColumn();
    }

    // Cron'un her calistirmasinda mutabakat (reconciliation) icin tum kullanicilardaki acik pozisyonlari getirir
    public static function findAllOpen(): array
    {
        $pdo = Database::getInstance();

        return $pdo->query("SELECT * FROM active_futures_trades WHERE status = 'open'")->fetchAll();
    }

    // Devre kesici (ardisik zarar limiti) icin: kullanicinin en son kapanan N KISA pozisyonunu getirir.
    // $withinHours: bkz. ActiveTrade::findRecentClosed() - ayni "eski zararlarin sonsuza kadar
    // yeniden tetiklemesi" bug'ina karsi ayni zaman siniri, spot ile TUTARLI davranis icin futures'ta da
    // bkz. ActiveTrade::findRecentClosed() yorumu - AYNI "loss streak reset" deseni futures icin de gecerli
    public static function findRecentClosed(int $userId, int $limit = 3, int $withinHours = 24, ?string $sinceTimestamp = null): array
    {
        $pdo = Database::getInstance();

        $sql = "SELECT * FROM active_futures_trades
             WHERE user_id = :user_id AND status != 'open'
               AND closed_at >= (NOW() - INTERVAL :hours HOUR)";

        if ($sinceTimestamp !== null) {
            $sql .= ' AND closed_at >= :since';
        }

        $sql .= ' ORDER BY closed_at DESC LIMIT :limit';

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':hours', $withinHours, PDO::PARAM_INT);

        if ($sinceTimestamp !== null) {
            $stmt->bindValue(':since', $sinceTimestamp);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public static function markClosed(int $id, string $status): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('UPDATE active_futures_trades SET status = :status, closed_at = NOW() WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $id]);
    }

    // Funding Rate entegrasyonu (4 Agustos): pozisyon KAPANDIKTAN sonra, o pozisyonun acik oldugu
    // sure boyunca GERCEKTEN tahsil edilmis/odenmis fonlama ucreti toplami - bkz.
    // BinanceFuturesService::getFundingFeeIncome() ve FuturesTradingService::finalizeClosedTrade()
    public static function setFundingFeeTotal(int $id, float $fundingFeeTotal): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('UPDATE active_futures_trades SET funding_fee_total = :fee WHERE id = :id');
        $stmt->execute([':fee' => $fundingFeeTotal, ':id' => $id]);
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT * FROM active_futures_trades WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    // Izleyen Stop (spot ActiveTrade::applyTrailingStop() ile AYNI semantik): eski Zarar Kes emri
    // iptal edilip (native korumaliysa) veya sadece DB esigi guncellenip (kendi-izleme modundaysa)
    // YENI seviyeye tasindiktan SONRA cagrilir. Kar Al emrine (take_profit_order_id) HIC DOKUNULMAZ -
    // futures'ta TP/SL spot'un OCO'sunun aksine BAGIMSIZ iki ayri emirdir, sadece SL tarafi degisir.
    // $stopLossOrderId NULL verilirse (native emir yerlestirilemedi/kendi-izleme moduna dusuldu)
    // pozisyon o andan itibaren SADECE DB esigiyle (mark fiyati karsilastirmasi) izlenmeye devam eder
    public static function applyTrailingStop(
        int $tradeId,
        int $stage,
        float $newStopLossPrice,
        ?int $stopLossOrderId,
        ?float $lowestPriceSeen = null
    ): void {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE active_futures_trades
             SET stop_loss_price = :sl_price, stop_loss_order_id = :sl_order_id,
                 trailing_stop_stage = :stage, lowest_price_seen = COALESCE(:lowest_price, lowest_price_seen)
             WHERE id = :id'
        );

        $stmt->execute([
            ':sl_price' => $newStopLossPrice,
            ':sl_order_id' => $stopLossOrderId,
            ':stage' => $stage,
            ':lowest_price' => $lowestPriceSeen,
            ':id' => $tradeId,
        ]);
    }

    // Asama 2'de (Sinirsiz Izleme) yeni bir dip fiyat gorulup de henuz Zarar Kes'i degistirmeye
    // deger bir iyilesme olusturmadigi durumlarda kullanilir - emre HIC dokunulmaz, sadece bir
    // sonraki turun dogru dipten devam etmesi icin deger kaydedilir (spot'un AYNI mantigi)
    public static function updateLowestPriceSeen(int $tradeId, float $lowestPriceSeen): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('UPDATE active_futures_trades SET lowest_price_seen = :lowest_price WHERE id = :id');
        $stmt->execute([':lowest_price' => $lowestPriceSeen, ':id' => $tradeId]);
    }

    // TP emri de SL emri de basarisiz olup pozisyon TAMAMEN kendi-izleme moduna dusurulmesi
    // gerektiginde kullanilir (bkz. FuturesTradingService::replaceFuturesStopLoss() - TP/SL'i
    // "yari korumali" birakmamak icin ikisi de senkron NULL'a cekilir)
    public static function clearNativeOrderReferences(int $tradeId): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE active_futures_trades SET take_profit_order_id = NULL, stop_loss_order_id = NULL WHERE id = :id'
        );
        $stmt->execute([':id' => $tradeId]);
    }
}
