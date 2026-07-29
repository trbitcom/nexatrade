<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Order
{
    // 30 Temmuz'da GUNCELLENDI (VPS/Guzelhosting gecis gunu istatistik sifirlama talebi uzerine):
    // 29-30 Temmuz'daki yogun mutabakat/test/manuel duzeltme islemleri (SOL/PEPE/ONDO/COTI elle
    // kapatma, test alim-satimlari) "kazanma orani/tamamlanan islem" istatistiklerini kirletiyordu -
    // gercek trading performansini yansitmiyordu. KASITLI OLARAK gercek veri (orders/active_trades)
    // SILINMEDI/degistirilmedi, sadece istatistik hesaplama penceresi bugune cekildi - bkz. asagidaki
    // eski gerekce (22 Temmuz, ilk 3 "kirli" islemi dislamak icin ayni mekanizma kullanilmisti,
    // o zamanki 8 Temmuz kesimi artik gecerliligini yitirdi, bu yeni kesim onun yerini alir):
    // "22 Temmuz'da canli diagnostik ile tespit edildi: en buyuk 5 gercek zarardan 3'u (TLMUSDT/
    // BELUSDT/HMSTRUSDT, ortalama -%10, digerlerinin ~6 kati) 6-7 Temmuz'a ait - platformun
    // strategy_bucket takibi/mevcut Izleyen Stop-Zarar Kes olgunlugu OTURMADAN ONCEKI ilk gunlerine."
    // KASITLI OLARAK "strategy_bucket IS NOT NULL" filtresi DEGIL, TARIH kesimi kullanilir: webhook
    // kaynakli (WebhookController::recordOrderResult) islemler de strategy_bucket'i HICBIR ZAMAN
    // set etmez - NULL kontrolu, gelecekteki GECERLI webhook islemlerini de sessizce istatistik
    // disi birakirdi.
    // DUZELTME (ayni gun): ilk denemede yanlislikla '2026-07-30 00:00:00' yazilmisti - VPS'in
    // GERCEK saat dilimi/tarihi (dogrudan `date` ile dogrulandi) hala 29 Temmuz oldugundan, kesim
    // GELECEGE ayarlanmis oluyordu - bugunun (BANKUSDT #231 dahil) HICBIR gercek islemi sayilamiyordu.
    // 21:00 secildi: gunun erken saatlerindeki mutabakat/test gurultusunu (SOL/PEPE/ONDO/COTI elle
    // kapatma) hala disliyor, ama VPS canliya alindiktan sonraki gercek islemleri (EULUSDT #229,
    // BANKUSDT #231 vb.) icine alir
    private const STATS_CUTOFF_AT = '2026-07-29 21:00:00';

    // 26 Temmuz'da eklendi: gercek islem gecmisi raporunda (133 kapanan islem) kar/zarar durumu
    // Binance komisyonu DUSULMEDEN hesaplaniyordu - marjinal karli (ör. +%0.05) bir islem gercekte
    // ~%0.2 komisyon sonrasi ZARAR olabiliyordu ama "kazanan" sayiliyordu. Gercek komisyon verisi
    // orders.commission/commission_asset'te KAYITLI ama COGUNLUKLA BNB/altcoin cinsinden (127 BNB,
    // ~10 farkli altcoin, sadece 27 USDT) - hepsini USDT karsiligina cevirmek ek fiyat sorgulari
    // gerektirirdi. Bunun yerine Binance'in standart Spot oranina (Alis %0.1 + Satis %0.1) dayanan
    // DUZ bir tahmin kullanilir - BNB indirimi kullanan hesaplar icin bu tahmin hafif MUHAFAZAKAR
    // kalir (gercek ucret muhtemelen biraz daha dusuk), yani "net kar" asla OLDUGUNDAN FAZLA gosterilmez
    // public: AutoTradeController (kapanis Telegram bildirimindeki Brut/Kesinti/Net kirilimi) ve
    // ApiKey (Izleyen Stop Kilit seviyesi asgari-guvenlik kontrolu) AYNI sabiti kullanir - TEK
    // dogru kaynak, birbirinden bagimsiz iki yerde ayni orani elle tekrar yazmaktan kacinilir
    public const FEE_RATE_PER_LEG = 0.001;

    public static function create(array $data): int
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'INSERT INTO orders (user_id, pair, side, type, quantity, price, total, binance_order_id, parent_order_id, status, error_message, strategy_bucket, commission, commission_asset)
             VALUES (:user_id, :pair, :side, :type, :quantity, :price, :total, :binance_order_id, :parent_order_id, :status, :error_message, :strategy_bucket, :commission, :commission_asset)'
        );

        $stmt->execute([
            ':user_id' => $data['user_id'],
            ':pair' => $data['pair'],
            ':side' => $data['side'],
            ':type' => $data['type'],
            ':quantity' => $data['quantity'],
            ':price' => $data['price'],
            ':total' => $data['total'],
            ':binance_order_id' => $data['binance_order_id'] ?? null,
            ':parent_order_id' => $data['parent_order_id'] ?? null,
            ':status' => $data['status'],
            ':error_message' => $data['error_message'] ?? null,
            ':strategy_bucket' => $data['strategy_bucket'] ?? null,
            // Komisyon Takibi: basarisiz (FAILED) emirlerde her zaman NULL kalir - gerceklesmeyen
            // bir emrin komisyonu olmaz. Kaynagi BinanceService::extractFillCommission()/
            // getCommissionForOrder() - cagiran taraf bunlari calistirip sonucu buraya gecirir
            ':commission' => $data['commission'] ?? null,
            ':commission_asset' => $data['commission_asset'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    // Idempotenslik korumasi (bkz. AutoTradeController::finalizeSpotClose/FuturesTradingService::
    // finalizeClosedTrade): bir kapanis kaydinin retry'da IKI KEZ INSERT edilip PNL'i cift saymasini
    // onlemek icin - 22 Temmuz'da tespit edildi (Order::create basarili + hemen sonraki markClosed
    // patlarsa pozisyon DB'de 'open' kalir, bir sonraki cron AYNI FILLED emri tekrar bulup ayni
    // kapanisi yeniden kaydetmeye calisirdi)
    public static function existsByBinanceOrderId(int $binanceOrderId): bool
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT 1 FROM orders WHERE binance_order_id = :binance_order_id LIMIT 1');
        $stmt->execute([':binance_order_id' => $binanceOrderId]);

        return $stmt->fetchColumn() !== false;
    }

    public static function updateStatus(int $orderId, string $status): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('UPDATE orders SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $orderId]);
    }

    // Islem gecmisi tablosu icin kullanicinin en son islemlerini getirir
    // Trade Post-Mortem: SATIS siparisleri icin, onu dogurmus ALIS siparisinin (parent_order_id)
    // actiğı pozisyonun active_trades.loss_reason'ı LEFT JOIN ile eklenir - ALIS siparilerinde
    // (parent_order_id NULL oldugu icin) ve karla kapanan pozisyonlarda (loss_reason hic yazilmadigi
    // icin) bu alan dogal olarak NULL kalir, dashboard'daki tooltip sadece doluysa gosterilir
    public static function findRecentByUser(int $userId, int $limit = 10): array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'SELECT o.*, at.loss_reason
             FROM orders o
             LEFT JOIN active_trades at ON at.buy_order_id = o.parent_order_id
             WHERE o.user_id = :user_id
             ORDER BY o.created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // AI Monolog panelinde "POZİSYON AÇILDI" yazisinin CAPRAZ KULLANICI veri sizintisi yapmamasi
    // icin: bot_logs TUM kullanicilar arasinda PAYLASILAN tek bir tablo - positions_opened, o tarama
    // turunde pozisyon acilan TUM kullanicilarin TOPLAM SAYISIDIR, hangi kullanici(lar) oldugunu
    // BELIRTMEZ. 10 Temmuz'da tespit edildi: Kullanici #6, aslinda Kullanici #1'in actigi bir
    // pozisyonu kendi panelinde "POZİSYON AÇILDI" olarak goruyordu. Bu metod, o tarama satirinin
    // GERCEKTEN bu kullanici icin bir alim urettigini (kendi orders gecmisiyle capraz kontrol
    // ederek) dogrular - $runAt ile ayni cron isteginde olusturuldugu icin siparis, tarama zamanindan
    // sonraki birkac dakika icinde olusmus olmalidir (kisa bir tolerans penceresi kullanilir)
    public static function hasFilledBuyNear(int $userId, string $pair, string $runAt): bool
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "SELECT 1 FROM orders
             WHERE user_id = :user_id AND pair = :pair AND side = 'buy' AND status = 'filled'
               AND created_at BETWEEN :run_at_start AND DATE_ADD(:run_at_end, INTERVAL 5 MINUTE)
             LIMIT 1"
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':pair' => $pair,
            ':run_at_start' => $runAt,
            ':run_at_end' => $runAt,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    // Devre kesici (gunluk maksimum zarar limiti) icin: takvim gununden bagimsiz, "su andan
    // geriye 24 saat" kayan pencerede KAPANMIS (gerceklesmis/realized) islemlerin net kar/zararini
    // doner. Kayan pencere kullanilir cunku zarar gece yarisini gectigi anda "sifirlanmasi" yanlis
    // bir guven verir.
    // 10 Temmuz'da tespit edilen KRITIK bug: eski sorgu SADECE ham "SAT toplami - ALIS toplami"
    // fark ediyordu (parent_order_id JOIN'i YOKTU) - yani YENI ACILAN bir pozisyonun ALIS tutari,
    // henuz eslesen bir SATIS kaydi olmadigi icin dogrudan "zarar" olarak sayiliyordu (bir kullanici
    // 13.51 USDT'lik YENI bir alim yaptiginda, devre kesici bunu "13.51 USDT zarar" sanip hesabi
    // KILITLEDI). Artik SADECE gercekten KAPANMIS (bir ALIS + o ALIS'in parent_order_id ile
    // eslesen bir SATIS ciftinin) net farki sayilir - acik pozisyona baglanan anapara ASLA bu
    // toplama dahil olmaz, cunku eslesen bir SATIS satiri olusana kadar JOIN'e hic girmez
    //
    // 16 Temmuz'da tespit edilen 2. KRITIK bug: Kademeli Kar Alma eklenince bir ALIS artik IKI
    // SATIS satirina sahip olabiliyor (kismi + nihai kapanis), ikisi de AYNI parent_order_id'yi
    // tasiyor - eski `INNER JOIN orders s` (gruplama olmadan) bu durumda AYNI ALIS icin 2 satir
    // uretip b.total'i (alis maliyetini) IKI KEZ dusuyordu, gercek kari buyuk bir zarar gibi
    // gosteriyordu (ör. $100 alis + $60 kismi satis + $50 nihai satis = gercekte +$10 kar iken,
    // eski sorgu -$40 + -$50 = -$90 hesapliyordu). Artik satislar ONCE parent_order_id'ye gore
    // GRUPLANIP toplaniyor, b.total SADECE BIR KEZ dusuluyor - "kayan pencere" kontrolu, pozisyonun
    // EN SON (nihai) satisinin ne zaman oldugu uzerinden yapiliyor (pozisyon TAMAMEN kapanana kadar
    // "kapanmis" sayilmaz)
    // 25 Temmuz'da eklendi - Gece Yarısı Hesap Özeti (DailySummaryService) icin. calculateRolling24hPNL()'in
    // KASITLI olarak AKSINE, burada TAKVIM GUNU sinirlari kullanilir - "kayan pencere" yaklasimi
    // devre kesici gibi surekli kontroller icin dogruyken, gunluk bir OZET raporun butun amaci
    // "biten gun (takvim gunu) ne oldu" sorusuna cevap vermektir, farkli bir ihtiyac.
    // 28 Temmuz'da DUZELTILDI (isim + sinir): eskiden CURDATE() (yani "bugun so far") kullaniliyordu -
    // ama bu cron TAM gece yarisinda (00:00) tetiklendigi icin "bugun" o anda 0 saniye gecmis
    // oluyordu, ozet HER ZAMAN "0 islem" gosteriyordu (canli olayda tespit edildi: 28 Temmuz gece
    // yarisi ozeti, bir onceki gunun ZEC/ONDO/ESP islemlerini goz ardi edip "0 kapanan islem" yazdi).
    // Artik acikca DUN'u (CURDATE()-1 GUN) hesaplar - raporun gonderildigi anda BITMIS olan takvim
    // gununu ozetler, tam da yorumun orijinal niyetiyle eslesir
    // @return array{total_trades: int, wins: int, net_pnl: float}
    public static function calculateYesterdayPNL(int $userId): array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN agg.total_sold > b.total THEN 1 ELSE 0 END), 0) AS wins,
                COALESCE(SUM(agg.total_sold - b.total), 0) AS net_pnl
             FROM orders b
             INNER JOIN (
                 SELECT parent_order_id, SUM(total) AS total_sold, MAX(created_at) AS last_sold_at
                 FROM orders WHERE side = 'sell' AND status = 'filled'
                 GROUP BY parent_order_id
             ) agg ON agg.parent_order_id = b.id
             WHERE b.user_id = :user_id AND b.side = 'buy' AND b.status = 'filled'
               AND agg.last_sold_at >= CURDATE() - INTERVAL 1 DAY
               AND agg.last_sold_at < CURDATE()"
        );
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();

        return [
            'total_trades' => (int) $row['total'],
            'wins' => (int) $row['wins'],
            'net_pnl' => round((float) $row['net_pnl'], 2),
        ];
    }

    public static function calculateRolling24hPNL(int $userId): float
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(agg.total_sold - b.total), 0) AS pnl
             FROM orders b
             INNER JOIN (
                 SELECT parent_order_id, SUM(total) AS total_sold, MAX(created_at) AS last_sold_at
                 FROM orders
                 WHERE side = 'sell' AND status = 'filled'
                 GROUP BY parent_order_id
             ) agg ON agg.parent_order_id = b.id
             WHERE b.user_id = :user_id
               AND b.side = 'buy' AND b.status = 'filled'
               AND agg.last_sold_at >= (NOW() - INTERVAL 24 HOUR)"
        );
        $stmt->execute([':user_id' => $userId]);

        return (float) $stmt->fetchColumn();
    }

    // Günlük / haftalık / aylık kayan pencere P&L + kazanma oranı
    // Kayan pencere (INTERVAL) kullanılır: gece yarısı sıfırlanma yanılgısına düşmez
    // win_rate: parent_order_id üzerinden eşleşen BUY+SELL çiftlerinde satış > alış ise WIN
    // 10 Temmuz'da calculateRolling24hPNL()'de bulunan AYNI bug bu fonksiyonda da vardı (SADECE
    // ham ALIŞ/SATIŞ toplam farkı, açık pozisyonların anaparasını "zarar" gibi gösteriyordu) -
    // artık win_rate sorgusuyla AYNI parent_order_id JOIN'i kullanılıyor, tek sorguya birleştirildi.
    // 16 Temmuz'da calculateRolling24hPNL()'deki AYNI 2. bug (Kademeli Kar Alma -> bir ALIS'a
    // iki SATIS -> parent_order_id JOIN'i cift sayiyordu) burada da vardi, AYNI grup-once
    // duzeltmesiyle giderildi (bkz. o metodun yorumu)
    // 22 Temmuz'da eklendi: STATS_CUTOFF_AT'tan once acilan ALIS'lar disarida birakilir - bkz.
    // sabitin basindaki yorum (3 erken donem uc-deger islemi win_rate/PNL istatistiklerini
    // carpitiyordu). calculateRolling24hPNL() KASITLI OLARAK degistirilmedi - devre kesicinin
    // 24 saatlik penceresine bu kadar eski islemler zaten hicbir zaman girmiyor
    public static function calculatePnlSummary(int $userId): array
    {
        $pdo = Database::getInstance();
        $feeRate = self::FEE_RATE_PER_LEG;

        // "net" ifadeleri: (agg.total_sold - b.total) BRUT kar/zarar - (b.total + agg.total_sold)*feeRate
        // TAHMINI komisyon (alis+satis bacaklarinin ikisi de - bkz. FEE_RATE_PER_LEG yorumu)
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN (agg.total_sold - b.total) - (b.total + agg.total_sold) * {$feeRate} > 0 THEN 1 ELSE 0 END), 0) AS wins,
                COALESCE(SUM(agg.total_sold - b.total), 0) AS gross_total,
                COALESCE(SUM(CASE WHEN agg.last_sold_at >= NOW() - INTERVAL 1 DAY  THEN (agg.total_sold - b.total) - (b.total + agg.total_sold) * {$feeRate} ELSE 0 END), 0) AS daily,
                COALESCE(SUM(CASE WHEN agg.last_sold_at >= NOW() - INTERVAL 7 DAY  THEN (agg.total_sold - b.total) - (b.total + agg.total_sold) * {$feeRate} ELSE 0 END), 0) AS weekly,
                COALESCE(SUM(CASE WHEN agg.last_sold_at >= NOW() - INTERVAL 30 DAY THEN (agg.total_sold - b.total) - (b.total + agg.total_sold) * {$feeRate} ELSE 0 END), 0) AS monthly
             FROM orders b
             INNER JOIN (
                 SELECT parent_order_id, SUM(total) AS total_sold, MAX(created_at) AS last_sold_at
                 FROM orders
                 WHERE side = 'sell' AND status = 'filled'
                 GROUP BY parent_order_id
             ) agg ON agg.parent_order_id = b.id
             WHERE b.user_id = :user_id
               AND b.side = 'buy' AND b.status = 'filled'
               AND b.created_at >= :stats_cutoff_at"
        );
        $stmt->execute([':user_id' => $userId, ':stats_cutoff_at' => self::STATS_CUTOFF_AT]);
        $row = $stmt->fetch();

        $total   = (int) ($row['total'] ?? 0);
        $wins    = (int) ($row['wins']  ?? 0);
        $winRate = $total > 0 ? round(($wins / $total) * 100, 1) : null;

        return [
            // Komisyon dusulmus GERCEK PNL - "kazanan/kaybeden" tanimi ve win_rate ile TUTARLI olsun
            // diye ayni fee-aware formul kullanilir (bkz. FEE_RATE_PER_LEG yorumu)
            'daily_profit'   => round((float) ($row['daily']   ?? 0), 2),
            'weekly_profit'  => round((float) ($row['weekly']  ?? 0), 2),
            'monthly_profit' => round((float) ($row['monthly'] ?? 0), 2),
            // Komisyon dusulmemis HAM toplam - Brut/Net kirilimi gostermek isteyen caginlar icin
            'gross_profit_total' => round((float) ($row['gross_total'] ?? 0), 2),
            'win_rate'       => $winRate,
            'total_trades'   => $total,
            'wins'           => $wins,
        ];
    }

    // Sembol Soguma suresini kisaltma karari icin (bkz. AutoTradeController - Zarar Kes/Erken Kacis
    // soguma noktalari): TEK bir paritenin kullanicidaki tum-zamanlar performansini doner.
    // calculateSymbolBreakdown()'daki AYNI JOIN deseni, sadece tek pair'e daraltilmis (o metodun
    // TUMUNU cekip PHP'de filtrelemek yerine dogrudan tek satirlik sorgu - her Zarar Kes/Erken
    // Kacis olayinda calisacagi icin hafif olmasi onemli)
    public static function calculateSymbolPerformance(int $userId, string $pair): array
    {
        $pdo = Database::getInstance();
        $feeRate = self::FEE_RATE_PER_LEG;

        // Komisyon dusulmus GERCEK net kar - "Kanitlanmis Kazanan Istisnasi" (resolveSymbolCooldownHours)
        // bu deger > 0 mi diye bakiyor, artik sadece HAM degil GERCEK karliligi gerektiriyor
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM((agg.total_sold - b.total) - (b.total + agg.total_sold) * {$feeRate}), 0) AS net_profit
             FROM orders b
             INNER JOIN (
                 SELECT parent_order_id, SUM(total) AS total_sold
                 FROM orders
                 WHERE side = 'sell' AND status = 'filled'
                 GROUP BY parent_order_id
             ) agg ON agg.parent_order_id = b.id
             WHERE b.user_id = :user_id
               AND b.pair = :pair
               AND b.side = 'buy' AND b.status = 'filled'
               AND b.created_at >= :stats_cutoff_at"
        );
        $stmt->execute([':user_id' => $userId, ':pair' => $pair, ':stats_cutoff_at' => self::STATS_CUTOFF_AT]);
        $row = $stmt->fetch();

        return [
            'total_trades' => (int) ($row['total'] ?? 0),
            'net_profit'   => round((float) ($row['net_profit'] ?? 0), 2),
        ];
    }

    // "Performans Analizi" modalı icin: hangi stratejinin (dipten_donus/golge_hacim/erken_momentum/
    // whitelist/announcement_hunter) gercekten kazandirdigini gosterir - calculatePnlSummary()'deki
    // AYNI parent_order_id JOIN'i, sadece b.strategy_bucket'e gore gruplanir. strategy_bucket NULL
    // olabilir (webhook kaynakli islemler HICBIR ZAMAN set etmez, bkz. STATS_CUTOFF_AT yorumu) -
    // bu satirlar filtrelenmez, PHP tarafinda 'diger' anahtar altinda toplanir
    public static function calculateStrategyBreakdown(int $userId): array
    {
        $pdo = Database::getInstance();
        $feeRate = self::FEE_RATE_PER_LEG;

        $stmt = $pdo->prepare(
            "SELECT
                b.strategy_bucket,
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN (agg.total_sold - b.total) - (b.total + agg.total_sold) * {$feeRate} > 0 THEN 1 ELSE 0 END), 0) AS wins,
                COALESCE(SUM(agg.total_sold - b.total), 0) AS gross_profit,
                COALESCE(SUM((agg.total_sold - b.total) - (b.total + agg.total_sold) * {$feeRate}), 0) AS net_profit
             FROM orders b
             INNER JOIN (
                 SELECT parent_order_id, SUM(total) AS total_sold
                 FROM orders
                 WHERE side = 'sell' AND status = 'filled'
                 GROUP BY parent_order_id
             ) agg ON agg.parent_order_id = b.id
             WHERE b.user_id = :user_id
               AND b.side = 'buy' AND b.status = 'filled'
               AND b.created_at >= :stats_cutoff_at
             GROUP BY b.strategy_bucket
             ORDER BY net_profit DESC"
        );
        $stmt->execute([':user_id' => $userId, ':stats_cutoff_at' => self::STATS_CUTOFF_AT]);

        return array_map(static function (array $row): array {
            $total = (int) $row['total'];
            $wins = (int) $row['wins'];

            return [
                'strategy_bucket' => $row['strategy_bucket'],
                'total_trades'    => $total,
                'wins'            => $wins,
                'win_rate'        => $total > 0 ? round(($wins / $total) * 100, 1) : null,
                'gross_profit'    => round((float) $row['gross_profit'], 2),
                'net_profit'      => round((float) $row['net_profit'], 2),
            ];
        }, $stmt->fetchAll());
    }

    // "Performans Analizi" modalı icin: hangi paritenin gercekten kazandirdigini/kaybettirdigini
    // gosterir - calculateStrategyBreakdown()'daki AYNI JOIN deseni, b.pair'e gore gruplanir.
    // LIMIT: cok sayida farkli parite islenmis olsa bile modal makul boyutta kalsin diye
    public static function calculateSymbolBreakdown(int $userId, int $limit = 50): array
    {
        $pdo = Database::getInstance();
        $feeRate = self::FEE_RATE_PER_LEG;

        $stmt = $pdo->prepare(
            "SELECT
                b.pair,
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN (agg.total_sold - b.total) - (b.total + agg.total_sold) * {$feeRate} > 0 THEN 1 ELSE 0 END), 0) AS wins,
                COALESCE(SUM(agg.total_sold - b.total), 0) AS gross_profit,
                COALESCE(SUM((agg.total_sold - b.total) - (b.total + agg.total_sold) * {$feeRate}), 0) AS net_profit
             FROM orders b
             INNER JOIN (
                 SELECT parent_order_id, SUM(total) AS total_sold
                 FROM orders
                 WHERE side = 'sell' AND status = 'filled'
                 GROUP BY parent_order_id
             ) agg ON agg.parent_order_id = b.id
             WHERE b.user_id = :user_id
               AND b.side = 'buy' AND b.status = 'filled'
               AND b.created_at >= :stats_cutoff_at
             GROUP BY b.pair
             ORDER BY net_profit DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':stats_cutoff_at', self::STATS_CUTOFF_AT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static function (array $row): array {
            $total = (int) $row['total'];
            $wins = (int) $row['wins'];

            return [
                'pair'         => $row['pair'],
                'total_trades' => $total,
                'wins'         => $wins,
                'win_rate'     => $total > 0 ? round(($wins / $total) * 100, 1) : null,
                'gross_profit' => round((float) $row['gross_profit'], 2),
                'net_profit'   => round((float) $row['net_profit'], 2),
            ];
        }, $stmt->fetchAll());
    }

    // Admin panelindeki "AI Skor Bandına Göre Performans" modülü icin: calculateSymbolBreakdown()'daki
    // AYNI buy/sell JOIN deseni, TEK farkla - ai_entry_score SADECE active_trades'te var (orders'ta yok),
    // o yuzden active_trades.buy_order_id UZERINDEN alis (buy) bacagina bir katman daha eklenir.
    // TUM kullanicilar kapsanir (userId parametresi YOK - bu kasitli, admin'in butun platformu
    // tek turda gormesi icin). ai_entry_score NULL olan islemler (Duyuru Avcisi/Akilli Para gibi AI
    // onayi BEKLEMEDEN alan moduller) disarida birakilir - bu bant SADECE AI Avci/Futures motorunun
    // GERCEKTEN skorladigi islemleri yansitmali. Kazanan/kaybeden PNL ISARETINDEN (agg.total_sold >
    // b.total) belirlenir, active_trades.status etiketinden DEGIL - closed_manual da dahil olur (gercek
    // PNL'i var, sadece nasil kapandigi farkli), sadece 'open'/'failed' disarida kalir (henuz/hic
    // gerceklesmemis PNL)
    // @return array<array{score_band: string, total_trades: int, wins: int, win_rate: float|null, net_profit: float}>
    public static function calculateScoreBandBreakdown(): array
    {
        $pdo = Database::getInstance();
        $feeRate = self::FEE_RATE_PER_LEG;

        $stmt = $pdo->prepare(
            "SELECT
                CASE
                    WHEN t.ai_entry_score < 50 THEN '< 50'
                    WHEN t.ai_entry_score < 60 THEN '50-59'
                    WHEN t.ai_entry_score < 70 THEN '60-69'
                    WHEN t.ai_entry_score < 80 THEN '70-79'
                    ELSE '80+'
                END AS score_band,
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN (agg.total_sold - b.total) - (b.total + agg.total_sold) * {$feeRate} > 0 THEN 1 ELSE 0 END), 0) AS wins,
                COALESCE(SUM(agg.total_sold - b.total), 0) AS gross_profit,
                COALESCE(SUM((agg.total_sold - b.total) - (b.total + agg.total_sold) * {$feeRate}), 0) AS net_profit
             FROM active_trades t
             INNER JOIN orders b ON b.id = t.buy_order_id
             INNER JOIN (
                 SELECT parent_order_id, SUM(total) AS total_sold
                 FROM orders
                 WHERE side = 'sell' AND status = 'filled'
                 GROUP BY parent_order_id
             ) agg ON agg.parent_order_id = b.id
             WHERE t.ai_entry_score IS NOT NULL
               AND t.status IN ('closed_profit', 'closed_loss', 'closed_manual')
               AND b.created_at >= :stats_cutoff_at
             GROUP BY score_band"
        );
        $stmt->execute([':stats_cutoff_at' => self::STATS_CUTOFF_AT]);

        $rows = $stmt->fetchAll();

        // Bant sirasi SQL'in dogal GROUP BY sirasindan BAGIMSIZ, dusukten yukseye SABITLENIR -
        // View katmani her zaman ayni sirayla render eder, veri olmayan bant listeden DUSMEZ (0 ile gosterilir)
        $bandOrder = ['< 50', '50-59', '60-69', '70-79', '80+'];
        $byBand = array_column($rows, null, 'score_band');

        return array_map(static function (string $band) use ($byBand): array {
            $row = $byBand[$band] ?? null;
            $total = $row !== null ? (int) $row['total'] : 0;
            $wins = $row !== null ? (int) $row['wins'] : 0;

            return [
                'score_band'   => $band,
                'total_trades' => $total,
                'wins'         => $wins,
                'win_rate'     => $total > 0 ? round(($wins / $total) * 100, 1) : null,
                'gross_profit' => $row !== null ? round((float) $row['gross_profit'], 2) : 0.0,
                'net_profit'   => $row !== null ? round((float) $row['net_profit'], 2) : 0.0,
            ];
        }, $bandOrder);
    }

    // "Geçmiş İşlemler" modalı icin: SON İşlemler tablosuyla AYNI satir bicimini (o.*, loss_reason),
    // sadece tarih araligiyla sinirlandirilmis olarak doner - findRecentByUser()'daki AYNI JOIN
    // deseni, LIMIT sadece asiri buyuk sonuc kumesine karsi bir tavan (gercek sinirlama $days'tir)
    public static function findByUserInPeriod(int $userId, int $days, int $limit = 500): array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'SELECT o.*, at.loss_reason
             FROM orders o
             LEFT JOIN active_trades at ON at.buy_order_id = o.parent_order_id
             WHERE o.user_id = :user_id
               AND o.created_at >= (NOW() - INTERVAL :days DAY)
             ORDER BY o.created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // "Geçmiş İşlemler" modalındaki günlük/haftalık/aylık ozet seridi icin: calculatePnlSummary()'deki
    // AYNI parent_order_id JOIN'i (kismi kar alma cift-sayimina VE acik pozisyon anaparasinin zarar
    // gibi gorunmesine karsi zaten duzeltilmis) ama TEK bir donem penceresine gore win/loss/net kar
    public static function calculatePeriodSummary(int $userId, int $days): array
    {
        $pdo = Database::getInstance();
        $feeRate = self::FEE_RATE_PER_LEG;

        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN (agg.total_sold - b.total) - (b.total + agg.total_sold) * {$feeRate} > 0 THEN 1 ELSE 0 END), 0) AS wins,
                COALESCE(SUM(agg.total_sold - b.total), 0) AS gross_profit,
                COALESCE(SUM((agg.total_sold - b.total) - (b.total + agg.total_sold) * {$feeRate}), 0) AS net_profit
             FROM orders b
             INNER JOIN (
                 SELECT parent_order_id, SUM(total) AS total_sold, MAX(created_at) AS last_sold_at
                 FROM orders
                 WHERE side = 'sell' AND status = 'filled'
                 GROUP BY parent_order_id
             ) agg ON agg.parent_order_id = b.id
             WHERE b.user_id = :user_id
               AND b.side = 'buy' AND b.status = 'filled'
               AND b.created_at >= :stats_cutoff_at
               AND agg.last_sold_at >= (NOW() - INTERVAL :days DAY)"
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->bindValue(':stats_cutoff_at', self::STATS_CUTOFF_AT);
        $stmt->execute();
        $row = $stmt->fetch();

        $total   = (int) ($row['total'] ?? 0);
        $wins    = (int) ($row['wins']  ?? 0);
        $winRate = $total > 0 ? round(($wins / $total) * 100, 1) : null;

        return [
            'total_trades' => $total,
            'wins'         => $wins,
            'win_rate'     => $winRate,
            'gross_profit' => round((float) ($row['gross_profit'] ?? 0), 2),
            'net_profit'   => round((float) ($row['net_profit'] ?? 0), 2),
        ];
    }

    // "Son İşlemler" detay modali icin: bir siparisi, SADECE o siparis kullaniciya aitse getirir -
    // baska bir kullanicinin siparis ID'sini tahmin ederek detayina erisilmesini engeller
    public static function findByIdForUser(int $orderId, int $userId): ?array
    {
        $pdo = Database::getInstance();

        // findRecentByUser() ile AYNI LEFT JOIN - Trade Post-Mortem'in loss_reason'ini
        // detay modalinda da gosterebilmek icin (bkz. apiOrderDetail)
        $stmt = $pdo->prepare(
            'SELECT o.*, at.loss_reason
             FROM orders o
             LEFT JOIN active_trades at ON at.buy_order_id = o.parent_order_id
             WHERE o.id = :id AND o.user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([':id' => $orderId, ':user_id' => $userId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    // Bir SAT siparisinin PNL'ini hesaplamak icin onu dogurmus ALIS siparisini (parent_order_id) getirir.
    // Kullanici kontrolu YAPILMAZ - cagiran taraf (apiOrderDetail) zaten findByIdForUser ile once
    // cocuk siparisin kullaniciya ait oldugunu dogrulamis olur, parent AYNI kullaniciya ait olmak ZORUNDADIR
    public static function findById(int $orderId): ?array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $orderId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    // Admin paneli istatistigi: platform genelinde bugun tamamlanan (FILLED) toplam islem sayisi
    public static function countFilledToday(): int
    {
        $pdo = Database::getInstance();

        return (int) $pdo->query(
            "SELECT COUNT(*) FROM orders WHERE status = 'filled' AND DATE(created_at) = CURDATE()"
        )->fetchColumn();
    }
}
