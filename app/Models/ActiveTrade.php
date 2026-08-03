<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

// Acik pozisyonlarin (AI Avci'nin OCO korumasiyla actigi islemler) TEK dogru kaynagi
final class ActiveTrade
{
    public static function create(array $data): int
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'INSERT INTO active_trades
                (user_id, pair, buy_order_id, quantity, entry_price, take_profit_price, stop_loss_price,
                 initial_take_profit_price, initial_stop_loss_price,
                 oco_order_list_id, take_profit_order_id, stop_loss_order_id, status, ai_entry_score,
                 is_sl_tightened, entry_rsi_1h, entry_rsi_15m)
             VALUES
                (:user_id, :pair, :buy_order_id, :quantity, :entry_price, :take_profit_price, :stop_loss_price,
                 :initial_take_profit_price, :initial_stop_loss_price,
                 :oco_order_list_id, :take_profit_order_id, :stop_loss_order_id, :status, :ai_entry_score,
                 :is_sl_tightened, :entry_rsi_1h, :entry_rsi_15m)'
        );

        $stmt->execute([
            ':user_id' => $data['user_id'],
            ':pair' => $data['pair'],
            ':buy_order_id' => $data['buy_order_id'],
            ':quantity' => $data['quantity'],
            ':entry_price' => $data['entry_price'],
            ':take_profit_price' => $data['take_profit_price'],
            ':stop_loss_price' => $data['stop_loss_price'],
            // Giris anindaki degerlerin kalici kopyasi - bkz. database.sql migrasyon yorumu.
            // Hicbir UPDATE metodu (applyTrailingStop/applyDcaFill/applyPartialTakeProfit) bu iki
            // parametreyi ICERMEZ, dolayisiyla bir daha asla yazilmazlar
            ':initial_take_profit_price' => $data['take_profit_price'],
            ':initial_stop_loss_price' => $data['stop_loss_price'],
            ':oco_order_list_id' => $data['oco_order_list_id'] ?? null,
            ':take_profit_order_id' => $data['take_profit_order_id'] ?? null,
            ':stop_loss_order_id' => $data['stop_loss_order_id'] ?? null,
            ':status' => $data['status'] ?? 'open',
            ':ai_entry_score' => $data['ai_entry_score'] ?? null,
            // bkz. AutoTradeController::WICK_SHIELD_MULTIPLIER yorumu - varsayilan 1 (zaten
            // "sikilastirilmis/uygulanamaz" sayilir) ki bu alani bilmeyen gelecekteki bir cagiran
            // yanlislikla fitil korumasi bekleyen ama gercekte genis SL ile acilmamis bir satir
            // yaratmasin
            ':is_sl_tightened' => $data['is_sl_tightened'] ?? 1,
            // 26 Temmuz'da eklendi: giris anindaki 1 saatlik/15 dakikalik RSI degerlerinin kalici
            // kaydi - hicbir filtre/karar mantigini DEGISTIRMEZ, sadece ileride ("giriste RSI'i
            // esige yakin olan islemler daha mi cok kaybediyor?" gibi sorulara) GERCEK veriyle
            // cevap verebilmek icin biriktirilir (bkz. ESPUSDT #180 post-mortem)
            ':entry_rsi_1h' => $data['entry_rsi_1h'] ?? null,
            ':entry_rsi_15m' => $data['entry_rsi_15m'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    // Tek bir pozisyonu ID'ye gore TAZE okur - reconcileActiveTrades() dongusundeki $trade
    // degiskeni AYNI turda baska bir mekanizma (ör. applyTrailingStopIfEligible) tarafindan
    // guncellenmis olabilir (PHP dizileri referansla degil kopyalanarak gectigi icin dongu
    // degiskeni STALE kalir) - tightenStopLossIfEligible() bu yuzden karar vermeden once
    // guncel trailing_stop_stage/partial_tp_executed/is_sl_tightened degerlerini burdan okur
    // seconds_since_opened: MySQL'in KENDI saatiyle (NOW()) hesaplanir - PHP tarafinda opened_at
    // uzerinden strtotime()/time() KARSILASTIRMASI YAPILMAMALI (bkz. tightenStopLossIfEligible'da
    // 31 Temmuz'da bulunan hata: VPS'te MariaDB Europe/Istanbul, PHP varsayilan UTC oldugu icin
    // strtotime(opened_at) - PHP'nin time()'indan 3 saat ILERI cikiyordu, fark HER ZAMAN buyuk bir
    // NEGATIF sayi oluyordu - Fitil Korumasi sikilastirmasi SONSUZA KADAR "henuz vakti gelmedi"
    // saniyordu, pozisyonlar hep genis/gevsek Zarar Kes'te kalip niyet edilenden cok daha az korumali
    // oluyordu). Ayni SymbolCooldown/PendingLimitOrder ilkesi burada da uygulanir
    public static function findById(int $id): ?array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT *, TIMESTAMPDIFF(SECOND, opened_at, NOW()) AS seconds_since_opened FROM active_trades WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    // Fitil Korumasi sikilastirmasi basariyla uygulandiginda (ya da baska bir mekanizma SL'e
    // zaten dokunmus oldugu icin artik anlamsiz hale geldiginde) bir daha denenmesin diye isaretler
    public static function markSlTightened(int $tradeId): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('UPDATE active_trades SET is_sl_tightened = 1 WHERE id = :id');
        $stmt->execute([':id' => $tradeId]);
    }

    // Belirli bir kullanici + parite icin acik pozisyon var mi (AI Avci ayni coini ust uste almasin diye)
    public static function hasOpenPositionForPair(int $userId, string $pair): bool
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM active_trades WHERE user_id = :user_id AND pair = :pair AND status = 'open'"
        );
        $stmt->execute([':user_id' => $userId, ':pair' => $pair]);

        return ((int) $stmt->fetchColumn()) > 0;
    }

    // Dashboard'daki "Aktif Avlar" widget'i icin kullanicinin acik pozisyonlarini getirir
    public static function findOpenForUser(int $userId): array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "SELECT * FROM active_trades WHERE user_id = :user_id AND status = 'open' ORDER BY opened_at DESC"
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public static function countOpenForUser(int $userId): int
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM active_trades WHERE user_id = :user_id AND status = 'open'");
        $stmt->execute([':user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    // Admin paneli istatistigi: platform genelinde su an acik olan toplam pozisyon sayisi
    public static function countAllOpen(): int
    {
        $pdo = Database::getInstance();

        return (int) $pdo->query("SELECT COUNT(*) FROM active_trades WHERE status = 'open'")->fetchColumn();
    }

    // Cron'un her calistirmasinda mutabakat (reconciliation) icin tum kullanicilardaki acik pozisyonlari getirir
    // 28 Temmuz'da ORDER BY eklendi: eskiden sirasiz donuyordu - AutoTradeController'daki reconcile
    // dongusune bir zaman butcesi eklenince (cron-job.org'un sabit 30sn zaman asimi asilirsa turu
    // erken kesip kalanlari bir sonraki tura birakir), sirasiz donus AYNI pozisyonun her seferinde
    // sona kalip surekli ac kalmasi riskini tasirdi. opened_at ASC ile en uzun suredir acik olan
    // (dolayisiyla en cok risk biriktirmis) pozisyonlar her turda ONCELIKLE kontrol edilir
    public static function findAllOpen(): array
    {
        $pdo = Database::getInstance();

        return $pdo->query("SELECT * FROM active_trades WHERE status = 'open' ORDER BY opened_at ASC")->fetchAll();
    }

    // Devre kesici (ardisik zarar limiti) icin: kullanicinin en son kapanan N pozisyonunu getirir
    // (status ne olursa olsun - closed_profit/closed_loss/closed_manual). Cagiran taraf hepsinin
    // 'closed_loss' olup olmadigini kontrol ederek ust uste zarar patlamasini tespit eder.
    // $withinHours: SADECE bu sure icinde kapanan islemler dikkate alinir - 10 Temmuz'da tespit
    // edilen bug: bu sinir olmadan, gunler/haftalar once olmus 3 eski zarar SONSUZA KADAR "guncel
    // seri" sayilip kilit her acildiginda (24s dolunca ya da admin manuel actiginda) hesabin YENI
    // hicbir islem yapmasina gerek kalmadan aninda yeniden tetikleniyordu (bkz. CHANGELOG)
    // $sinceTimestamp verilirse (bkz. ApiKey::getLossStreakResetAt/resetLossStreak) bu tarihten
    // ONCE kapanan islemler "ardisik zarar" sayimina hic girmez - admin'in bilinclil olarak "eski
    // seriyi unut" dedigi (23 Temmuz, devre kesicinin surekli yeniden tetiklenip hicbir yeni islemin
    // denenmesine FIRSAT vermedigi kisir donguye karsi eklendi) noktadan itibaren TEMIZ bir sayim baslar -
    // GECMIS kapanis kayitlarina (loss_reason/status) HICBIR sekilde dokunulmaz, sadece bu sorgu tarafindan
    // "gormezden gelinirler"
    public static function findRecentClosed(int $userId, int $limit = 3, int $withinHours = 24, ?string $sinceTimestamp = null): array
    {
        $pdo = Database::getInstance();

        $sql = "SELECT * FROM active_trades
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

        $stmt = $pdo->prepare('UPDATE active_trades SET status = :status, closed_at = NOW() WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $id]);
    }

    // Trade Post-Mortem: zararla kapanan bir pozisyon icin TradePostMortemService'in tespit ettigi
    // kok nedeni kaydeder - markClosed() ile AYRI cagrilir (analiz, kapanistan hemen sonra ama
    // kapanmayi ASLA beklemez/bloklamaz, analiz basarisiz olsa bile pozisyon zaten kapanmis olur)
    public static function setLossReason(int $id, string $reason): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('UPDATE active_trades SET loss_reason = :reason WHERE id = :id');
        $stmt->execute([':reason' => $reason, ':id' => $id]);
    }

    // DCA (maliyet dusurme) alimi sonrasi pozisyonun "guncel" degerlerini yerinde gunceller:
    // entry_price/quantity artik ORIJINAL degil, TUM fillerin agirlikli ortalamasidir.
    // dca_rounds_used HER ZAMAN 1'e set edilir (buy zaten gerceklesti - basarili/basarisiz OCO
    // farketmeksizin bu pozisyonun DCA hakki tuketildi, ikinci bir DCA denemesi asla yapilmamali).
    // $ocoListId/$tpOrderId/$slOrderId NULL verilirse (yeni OCO yerlestirilemedi), pozisyon
    // 'open' durumda ama korumasiz kalir - reconcileActiveTrades()'teki mevcut
    // "if (oco_order_list_id === null) continue" korumasi sayesinde bir sonraki tur bunu
    // yanlislikla "kapandi" saymaz, sadece atlar (admin/musteri KRITIK uyariyla zaten haberdar edilir)
    public static function applyDcaFill(
        int $tradeId,
        float $newEntryPrice,
        float $newQuantity,
        float $newTakeProfitPrice,
        float $newStopLossPrice,
        ?int $ocoListId,
        ?int $takeProfitOrderId,
        ?int $stopLossOrderId
    ): void {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE active_trades
             SET entry_price = :entry_price, quantity = :quantity,
                 take_profit_price = :tp_price, stop_loss_price = :sl_price,
                 oco_order_list_id = :oco_list_id, take_profit_order_id = :tp_order_id,
                 stop_loss_order_id = :sl_order_id, dca_rounds_used = 1
             WHERE id = :id'
        );

        $stmt->execute([
            ':entry_price' => $newEntryPrice,
            ':quantity' => $newQuantity,
            ':tp_price' => $newTakeProfitPrice,
            ':sl_price' => $newStopLossPrice,
            ':oco_list_id' => $ocoListId,
            ':tp_order_id' => $takeProfitOrderId,
            ':sl_order_id' => $stopLossOrderId,
            ':id' => $tradeId,
        ]);
    }

    // Kademeli Izleyen Stop (Dinamik Kar Kilitleme): pozisyon bir kar esigine (ör. +%2.5) ulastiginda
    // Zarar Kes seviyesi yukari cekilir - eski OCO iptal edilip AYNI miktar ve AYNI Kar Al fiyatiyla,
    // SADECE Zarar Kes'i yeni (daha yuksek) seviyeye tasiyan bir OCO yerlestirildikten SONRA cagrilir.
    // $stage (1 veya 2) kaydedilir ki asamalar asla GERIYE gitmesin (fiyat sonra dusse bile stage
    // 2'den stage 1'e donulmez - AutoTradeController bunu zaten kontrol eder, burasi sadece deger
    // yazar). $highestPriceSeen SADECE Asama 3'te (Sinirsiz Izleme, stage=2 iken tekrar tekrar
    // cagrilir) kullanilir - discrete Asama 1/2 gecislerinde null gecilir
    public static function applyTrailingStop(
        int $tradeId,
        int $stage,
        float $newStopLossPrice,
        ?int $ocoListId,
        ?int $takeProfitOrderId,
        ?int $stopLossOrderId,
        ?float $highestPriceSeen = null
    ): void {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE active_trades
             SET stop_loss_price = :sl_price, oco_order_list_id = :oco_list_id,
                 take_profit_order_id = :tp_order_id, stop_loss_order_id = :sl_order_id,
                 trailing_stop_stage = :stage, highest_price_seen = COALESCE(:highest_price, highest_price_seen)
             WHERE id = :id'
        );

        $stmt->execute([
            ':sl_price' => $newStopLossPrice,
            ':oco_list_id' => $ocoListId,
            ':tp_order_id' => $takeProfitOrderId,
            ':sl_order_id' => $stopLossOrderId,
            ':stage' => $stage,
            ':highest_price' => $highestPriceSeen,
            ':id' => $tradeId,
        ]);
    }

    // Kar Al Tavanini Kaldirma (22 Temmuz): Sinirsiz Izleme aktiflesince (ilk kez VEYA sonraki her
    // guncellemede) cagrilir - oco_order_list_id/take_profit_order_id KALICI olarak NULL'a cekilir
    // (artik bir OCO grubu yok), stop_loss_order_id TEKIL Zarar Kes emrini isaret eder,
    // take_profit_removed=1 isaretlenir (reconcileActiveTrades()'in bu satiri "korumasiz" degil
    // "SL-only modda" olarak tanimasi icin - bkz. o metodun yorumu). trailing_stop_stage=2'ye
    // sabitlenir (0/1'den ayirt edilebilsin diye - "Sinirsiz Izleme'ye ulasti VE Kar Al kaldirildi"
    // anlaminda, eski 2-asamali sistemin kalinti degerinden farkli bir anlamda kullanilir)
    public static function applyTakeProfitRemoval(
        int $tradeId,
        float $newStopLossPrice,
        ?int $stopLossOrderId,
        ?float $highestPriceSeen
    ): void {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE active_trades
             SET stop_loss_price = :sl_price, oco_order_list_id = NULL, take_profit_order_id = NULL,
                 stop_loss_order_id = :sl_order_id, trailing_stop_stage = 2, take_profit_removed = 1,
                 highest_price_seen = COALESCE(:highest_price, highest_price_seen)
             WHERE id = :id'
        );

        $stmt->execute([
            ':sl_price' => $newStopLossPrice,
            ':sl_order_id' => $stopLossOrderId,
            ':highest_price' => $highestPriceSeen,
            ':id' => $tradeId,
        ]);
    }

    // Asama 3'te (Sinirsiz Izleme) yeni bir zirve fiyat gorulup de henuz Zarar Kes'i degistirmeye
    // deger (TRAILING_STOP_MIN_IMPROVEMENT_PERCENT) bir iyilesme olusturmadigi durumlarda kullanilir -
    // OCO'ya HIC dokunulmaz, sadece bir sonraki turun dogru zirveden devam etmesi icin deger kaydedilir
    // Korumasiz Pozisyon Alarmi (31 Temmuz, Volkan #243 BANKUSDT canli olayindan sonra eklendi):
    // OCO/tekil Zarar Kes emri hic girilememis bir pozisyon eskiden reconcileActiveTradesInternal()
    // tarafindan SESSIZCE ve SONSUZA KADAR atlaniyordu - bu yuzden gunlerce fark edilmeden acik
    // kalabiliyordu. Artik her mutabakat turunde bu metod cagrilir: throttle suresi (saat) gecmisse
    // TEK bir atomik UPDATE ile hem "gonderildi" damgasini basar hem true doner (cagiran taraf true
    // donerse alarm gonderir) - MySQL'in KENDI saatiyle (NOW()) calisir, PHP time()/strtotime()
    // KARSILASTIRMASI YAPILMAZ (bkz. SymbolCooldown::getCooldownUntil AYNI ilke)
    public static function shouldSendUnprotectedAlert(int $tradeId, int $throttleHours): bool
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE active_trades
             SET unprotected_alert_sent_at = NOW()
             WHERE id = :id
               AND (unprotected_alert_sent_at IS NULL OR unprotected_alert_sent_at <= DATE_SUB(NOW(), INTERVAL :hours HOUR))'
        );
        $stmt->bindValue(':id', $tradeId, PDO::PARAM_INT);
        $stmt->bindValue(':hours', $throttleHours, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    // Yukselis Uyarisi: musteriye bilgi amacli Telegram bildirimi gonderilen en son tam yuzde
    // esigini kaydeder (bkz. AutoTradeController::checkRiseAlert) - fiyat bir sonraki esigi
    // gecmeden ayni seviye icin tekrar bildirim gonderilmesin diye
    public static function updateRiseAlertLastPercent(int $tradeId, int $percent): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('UPDATE active_trades SET rise_alert_last_percent = :percent WHERE id = :id');
        $stmt->execute([':percent' => $percent, ':id' => $tradeId]);
    }

    public static function updateHighestPriceSeen(int $tradeId, float $highestPriceSeen): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('UPDATE active_trades SET highest_price_seen = :highest_price WHERE id = :id');
        $stmt->execute([':highest_price' => $highestPriceSeen, ':id' => $tradeId]);
    }

    // Trade Diagnostics: highest_price_seen'DEN KASITLI olarak AYRI (bkz. o sutunun ve migration'in
    // yorumu) - trailing/kismi kar alma durumundan BAGIMSIZ, pozisyon acik oldugu surece HER cron
    // turunda cagrilir. Tek atomik UPDATE ile (once SELECT etmeden) yaris durumu riski olmadan
    // guncellenir - CASE ifadesi hem "hic deger yok" (NULL) hem "yeni deger daha ac/derin" durumunu
    // tek sorguda kapsar
    public static function updatePriceExtremes(int $tradeId, float $currentPrice): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE active_trades
             SET highest_price_reached = CASE WHEN highest_price_reached IS NULL OR :price_high > highest_price_reached THEN :price_high_val ELSE highest_price_reached END,
                 lowest_price_reached = CASE WHEN lowest_price_reached IS NULL OR :price_low < lowest_price_reached THEN :price_low_val ELSE lowest_price_reached END
             WHERE id = :id'
        );
        $stmt->execute([
            ':price_high' => $currentPrice,
            ':price_high_val' => $currentPrice,
            ':price_low' => $currentPrice,
            ':price_low_val' => $currentPrice,
            ':id' => $tradeId,
        ]);
    }

    // OCO iptal edildi ama yerine YENI bir koruma emri girilemedi (ör. Erken Kaçış'ta market
    // satisi basarisiz oldu) - pozisyon "acik ama korumasiz" duruma duser. oco_order_list_id
    // NULL'a cekilir ki reconcileActiveTrades() bunu ARTIK GECERSIZ olan eski OCO ID'siyle
    // sorgulamaya calismasin, sadece "acik ama korumasiz" olarak izlemeye devam etsin
    public static function clearOcoReference(int $tradeId): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE active_trades
             SET oco_order_list_id = NULL, take_profit_order_id = NULL, stop_loss_order_id = NULL
             WHERE id = :id'
        );
        $stmt->execute([':id' => $tradeId]);
    }

    // Dogal Mod: musteri "Emirleri Iptal Et" ile koruma emrini BILEREK iptal etti - bkz. database.sql
    // migrasyon yorumu. Cagiran taraf (DashboardController::apiCancelPositionOrders) Binance'teki
    // OCO/tekil Zarar Kes emrini BURADAN ONCE iptal etmis olmali - bu metod sadece o gercegi DB'ye
    // yansitir (clearOcoReference ile AYNI temizlik + manual_mode=1). 4 Agustos'ta artik GERI
    // ALINABILIR - bkz. disableManualMode() (musteri talebi: GIGGLEUSDT #326 canli deneyiminde
    // Dogal Moddaki bir pozisyona geri donup tekrar koruma koyacak bir yol olmadigi fark edildi)
    public static function enableManualMode(int $tradeId): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE active_trades
             SET manual_mode = 1, oco_order_list_id = NULL, take_profit_order_id = NULL, stop_loss_order_id = NULL
             WHERE id = :id'
        );
        $stmt->execute([':id' => $tradeId]);
    }

    // "Korumaya Al" (4 Agustos, enableManualMode()'un tersi): Dogal Moddaki bir pozisyona GUNCEL
    // fiyata gore yeni bir OCO kondu - bkz. DashboardController::apiRearmProtection(). Cagiran
    // taraf Binance'te OCO'yu BURADAN ONCE basariyla yerlestirmis olmali. trailing_stop_stage/
    // highest_price_seen SIFIRLANIR (Dogal Mod suresince trailing hic calismadigi icin eski
    // deger anlamsiz/gecersiz kalirdi) - bu, fiyat acisindan YENI bir koruma donemi baslangicidir
    public static function disableManualMode(
        int $tradeId,
        float $newTakeProfitPrice,
        float $newStopLossPrice,
        int $ocoOrderListId,
        ?int $takeProfitOrderId,
        ?int $stopLossOrderId
    ): void {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE active_trades
             SET manual_mode = 0, take_profit_price = :tp_price, stop_loss_price = :sl_price,
                 oco_order_list_id = :oco_id, take_profit_order_id = :tp_order_id, stop_loss_order_id = :sl_order_id,
                 trailing_stop_stage = 0, highest_price_seen = NULL, unprotected_alert_sent_at = NULL
             WHERE id = :id'
        );
        $stmt->execute([
            ':tp_price' => $newTakeProfitPrice,
            ':sl_price' => $newStopLossPrice,
            ':oco_id' => $ocoOrderListId,
            ':tp_order_id' => $takeProfitOrderId,
            ':sl_order_id' => $stopLossOrderId,
            ':id' => $tradeId,
        ]);
    }

    // DCA alimi gerceklesti ama eski OCO iptal EDILEMEDI durumunda kullanilir: eski OCO hala
    // gecerli ve ESKI miktari korumaya devam ediyor, bu yuzden entry_price/quantity/oco alanlarina
    // DOKUNULMAZ (yanlislikla degistirmek eski korumayi da bozardi) - sadece DCA hakkinin
    // tuketildigini isaretler (bu pozisyon icin bir daha DCA denenmez)
    public static function markDcaRoundConsumed(int $tradeId): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('UPDATE active_trades SET dca_rounds_used = 1 WHERE id = :id');
        $stmt->execute([':id' => $tradeId]);
    }

    // Kademeli Kar Alma: pozisyon +%X kara ulastiginda miktarin yarisi MARKET satilip gercek kar
    // cebe indirilir, KALAN yari icin (Kar Al artik pratik bir tavan olmayan, cok uzak) yeni bir
    // OCO kurulur - gercek "trend bitene kadar sur" davranisi applyContinuousTrailing()'in zaten
    // var olan Zarar Kes yukseltme mekanizmasindan gelir. partial_tp_executed=1 kalici olarak
    // isaretlenir, bir pozisyonda SADECE BIR KEZ tetiklenir. $newTakeProfitPrice/$newStopLossPrice
    // null verilirse (yeni OCO girilemedi) mevcut degerlere DOKUNULMAZ - applyTrailingStop()'taki
    // AYNI "yeni OCO basarisizsa eski hedef degerlerini bozma" ilkesi. $trailingStopStage,
    // applyDiscreteTrailingStage()'in bir sonraki turda BU ANDA kurulmus (zaten iyi) Zarar Kes'i
    // eski/dusuk bir discrete hedefe GERI CEKMEYE calismasini onlemek icin PESINEN maksimum
    // discrete asamaya ilerletilir - AutoTradeController::applyPartialTakeProfitIfEligible() bunu
    // sniper/normal pozisyon ayrimina gore hesaplayip gecer
    public static function applyPartialTakeProfit(
        int $tradeId,
        float $remainingQuantity,
        ?float $newTakeProfitPrice,
        ?float $newStopLossPrice,
        ?int $ocoListId,
        ?int $takeProfitOrderId,
        ?int $stopLossOrderId,
        int $trailingStopStage,
        float $highestPriceSeen
    ): void {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE active_trades
             SET quantity = :quantity, take_profit_price = COALESCE(:tp_price, take_profit_price),
                 stop_loss_price = COALESCE(:sl_price, stop_loss_price), oco_order_list_id = :oco_list_id,
                 take_profit_order_id = :tp_order_id, stop_loss_order_id = :sl_order_id,
                 partial_tp_executed = 1, trailing_stop_stage = :stage,
                 highest_price_seen = GREATEST(COALESCE(highest_price_seen, 0), :highest_price)
             WHERE id = :id'
        );

        $stmt->execute([
            ':quantity' => $remainingQuantity,
            ':tp_price' => $newTakeProfitPrice,
            ':sl_price' => $newStopLossPrice,
            ':oco_list_id' => $ocoListId,
            ':tp_order_id' => $takeProfitOrderId,
            ':sl_order_id' => $stopLossOrderId,
            ':stage' => $trailingStopStage,
            ':highest_price' => $highestPriceSeen,
            ':id' => $tradeId,
        ]);
    }

    // Bir pozisyona ait her alim fill'ini (ilk alim + DCA alimi) denetim amaciyla kaydeder.
    // active_trades.buy_order_id sadece ILK fill'e isaret etmeye devam eder - bu tablo
    // coklu-fill gecmisinin tek dogru kaynagidir
    public static function addFillRecord(int $tradeId, int $orderId, float $quantity, float $price, string $fillType): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'INSERT INTO active_trade_fills (active_trade_id, order_id, quantity, price, fill_type)
             VALUES (:active_trade_id, :order_id, :quantity, :price, :fill_type)'
        );

        $stmt->execute([
            ':active_trade_id' => $tradeId,
            ':order_id' => $orderId,
            ':quantity' => $quantity,
            ':price' => $price,
            ':fill_type' => $fillType,
        ]);
    }
}
