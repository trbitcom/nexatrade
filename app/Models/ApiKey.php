<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\Encryption;
use App\Services\RiskProfileService;
use InvalidArgumentException;
use PDO;

final class ApiKey
{
    // Anahtarlari kaydetmeden once otomatik olarak sifreler
    // Kullanicinin zaten bir kaydi varsa UPDATE, yoksa INSERT yapar (upsert)
    public static function save(int $userId, string $exchangeName, string $apiKey, string $secretKey): int
    {
        $pdo = Database::getInstance();

        $existingId = self::findIdByUser($pdo, $userId);

        if ($existingId !== null) {
            $stmt = $pdo->prepare(
                'UPDATE user_api_keys SET exchange_name = :exchange_name, api_key = :api_key, secret_key = :secret_key WHERE id = :id'
            );

            $stmt->execute([
                ':exchange_name' => $exchangeName,
                ':api_key' => Encryption::encrypt($apiKey),
                ':secret_key' => Encryption::encrypt($secretKey),
                ':id' => $existingId,
            ]);

            return $existingId;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO user_api_keys (user_id, exchange_name, api_key, secret_key) VALUES (:user_id, :exchange_name, :api_key, :secret_key)'
        );

        $stmt->execute([
            ':user_id' => $userId,
            ':exchange_name' => $exchangeName,
            ':api_key' => Encryption::encrypt($apiKey),
            ':secret_key' => Encryption::encrypt($secretKey),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private static function findIdByUser(PDO $pdo, int $userId): ?int
    {
        $stmt = $pdo->prepare('SELECT id FROM user_api_keys WHERE user_id = :user_id LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    // Kullanicinin anahtarlarini cekip otomatik olarak cozer (decrypt)
    public static function findByUser(int $userId): array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT * FROM user_api_keys WHERE user_id = :user_id ORDER BY created_at DESC');
        $stmt->execute([':user_id' => $userId]);

        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['api_key'] = Encryption::decrypt($row['api_key']);
            $row['secret_key'] = Encryption::decrypt($row['secret_key']);
        }
        unset($row);

        return $rows;
    }

    // Aktif (status='active') kullanicilardan API anahtari kaydetmis olanlarin tamamini, cozulmus halde getirir
    // Webhook otomasyonu bu listeyi kullanarak tum musterilerin hesabinda ayni anda islem acar
    public static function findAllForActiveUsers(): array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->query(
            "SELECT uak.id, uak.user_id, uak.exchange_name, uak.api_key, uak.secret_key
             FROM user_api_keys uak
             INNER JOIN users u ON u.id = uak.user_id
             WHERE u.status = 'active'"
        );

        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['api_key'] = Encryption::decrypt($row['api_key']);
            $row['secret_key'] = Encryption::decrypt($row['secret_key']);
        }
        unset($row);

        return $rows;
    }

    // Otomatik alim/satim (AI avci) ozelligini acmis, aktif kullanicilarin anahtar ve butce ayarlarini getirir
    public static function findAllForAutoTrade(): array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->query(
            "SELECT uak.id, uak.user_id, uak.exchange_name, uak.api_key, uak.secret_key,
                    uak.auto_trade_budget_usdt, uak.auto_trade_budget_percent, uak.max_portfolio_risk_percent,
                    uak.take_profit_percent, uak.stop_loss_percent,
                    uak.max_daily_loss_percent, uak.social_radar_enabled, uak.listing_sniper_enabled,
                    uak.smart_money_enabled, uak.dca_enabled,
                    uak.risk_profile, uak.ai_score_threshold, uak.max_active_trades, uak.manual_kill_switch
             FROM user_api_keys uak
             INNER JOIN users u ON u.id = uak.user_id
             WHERE u.status = 'active' AND uak.auto_trade_enabled = 1"
        );

        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['api_key'] = Encryption::decrypt($row['api_key']);
            $row['secret_key'] = Encryption::decrypt($row['secret_key']);
        }
        unset($row);

        return $rows;
    }

    // Duyuru Avcisi'ni acmis, aktif kullanicilarin anahtar/butce ayarlarini getirir
    // auto_trade_enabled'dan BAGIMSIZDIR - kullanici bu modulu AI Avci'yi acmadan da kullanabilir
    public static function findAllForListingSniper(): array
    {
        return self::findAllForModule('listing_sniper_enabled');
    }

    // Akilli Para Kopyalayici'yi acmis, aktif kullanicilarin anahtar/butce ayarlarini getirir
    public static function findAllForSmartMoney(): array
    {
        return self::findAllForModule('smart_money_enabled');
    }

    // Futures KISA (short) modulunu acmis, aktif kullanicilarin anahtar/kaldirac ayarlarini getirir.
    // Dashboard'daki "İşlem Modu" seçicisi (Sadece Spot / Spot + Futures) DOGRUDAN bu bayragi
    // (futures_trading_enabled) yazar - FuturesTradingService::shortForAllUsers() YENİ pozisyon
    // acarken SADECE bu sorgudan donen kullanicilari dikkate alir, bu yuzden "Sadece Spot" secen
    // bir kullanici icin asla yeni short acilmaz (mevcut ACIK pozisyonlar ise bu filtreden
    // BAGIMSIZ, ActiveFuturesTrade::findAllOpen() ile HER ZAMAN mutabakat/kapatma islemine tabidir)
    public static function findAllForFuturesTrade(): array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->query(
            "SELECT uak.id, uak.user_id, uak.exchange_name, uak.api_key, uak.secret_key,
                    uak.auto_trade_budget_percent, uak.max_portfolio_risk_percent,
                    uak.take_profit_percent, uak.stop_loss_percent, uak.max_daily_loss_percent,
                    uak.futures_leverage, uak.ai_score_threshold, uak.manual_kill_switch
             FROM user_api_keys uak
             INNER JOIN users u ON u.id = uak.user_id
             WHERE u.status = 'active' AND uak.futures_trading_enabled = 1"
        );

        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['api_key'] = Encryption::decrypt($row['api_key']);
            $row['secret_key'] = Encryption::decrypt($row['secret_key']);
        }
        unset($row);

        return $rows;
    }

    // Kullanicinin risk profilini ve buna bagli motor esiklerini tek sorguda gunceller
    // Profil degistiginde stop_loss_percent, ai_score_threshold ve max_active_trades otomatik ayarlanir.
    // $preserveCustomStopLoss=true ise stop_loss_percent'e HIC DOKUNULMAZ - 12 Temmuz'da tespit
    // edilen "sessiz ezme" bug'ina karsi: kullanicinin "AI Avci Ayarlari" formundan ELLE girdigi
    // ozel bir Zarar Kes degeri varsa, DashboardController onay almadan bu degeri asla profilin
    // sabit degerine sifirlamamali (bkz. RiskProfileService::isCustomStopLoss)
    public static function updateRiskProfile(int $userId, string $profile, bool $preserveCustomStopLoss = false): void
    {
        $settings = RiskProfileService::get($profile);
        $pdo      = Database::getInstance();

        if ($preserveCustomStopLoss) {
            $stmt = $pdo->prepare(
                'UPDATE user_api_keys
                 SET risk_profile       = :risk_profile,
                     ai_score_threshold = :ai_score_threshold,
                     max_active_trades  = :max_active_trades
                 WHERE user_id = :user_id'
            );

            $stmt->execute([
                ':risk_profile'       => $profile,
                ':ai_score_threshold' => $settings['ai_score_threshold'],
                ':max_active_trades'  => $settings['max_active_trades'],
                ':user_id'            => $userId,
            ]);

            return;
        }

        $stmt = $pdo->prepare(
            'UPDATE user_api_keys
             SET risk_profile       = :risk_profile,
                 ai_score_threshold = :ai_score_threshold,
                 stop_loss_percent  = :stop_loss_percent,
                 max_active_trades  = :max_active_trades
             WHERE user_id = :user_id'
        );

        $stmt->execute([
            ':risk_profile'       => $profile,
            ':ai_score_threshold' => $settings['ai_score_threshold'],
            ':stop_loss_percent'  => $settings['stop_loss_percent'],
            ':max_active_trades'  => $settings['max_active_trades'],
            ':user_id'            => $userId,
        ]);
    }

    // Kolon adi PDO ile parametrelenemedigi icin (sadece degerler parametrelenebilir), bu private
    // metod HER ZAMAN sabit bir izin listesinden cagrilir (bkz. findAllForListingSniper/findAllForSmartMoney) -
    // asla disaridan/kullanici girdisinden gelen bir deger buraya ulasmaz
    private static function findAllForModule(string $flagColumn): array
    {
        if (!in_array($flagColumn, ['listing_sniper_enabled', 'smart_money_enabled'], true)) {
            throw new InvalidArgumentException("Geçersiz modül bayrağı: {$flagColumn}");
        }

        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "SELECT uak.id, uak.user_id, uak.exchange_name, uak.api_key, uak.secret_key,
                    uak.auto_trade_budget_usdt, uak.auto_trade_budget_percent, uak.sniper_budget_percent,
                    uak.max_portfolio_risk_percent,
                    uak.take_profit_percent, uak.stop_loss_percent,
                    uak.max_daily_loss_percent
             FROM user_api_keys uak
             INNER JOIN users u ON u.id = uak.user_id
             WHERE u.status = 'active' AND uak.`{$flagColumn}` = 1"
        );
        $stmt->execute();

        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['api_key'] = Encryption::decrypt($row['api_key']);
            $row['secret_key'] = Encryption::decrypt($row['secret_key']);
        }
        unset($row);

        return $rows;
    }

    // Admin paneli istatistigi: AI Avci'yi acmis toplam kullanici sayisi
    public static function countAutoTradeEnabled(): int
    {
        $pdo = Database::getInstance();

        return (int) $pdo->query('SELECT COUNT(*) FROM user_api_keys WHERE auto_trade_enabled = 1')->fetchColumn();
    }

    // Otomatik islem ayarlarini gunceller. API anahtarlarindan bagimsizdir; save() bu alanlara dokunmaz
    // (aksi halde kullanici anahtarini her guncelledi­ginde otomatik islem ayarlari sessizce sifirlanirdi)
    public static function updateAutoTradeSettings(
        int $userId,
        bool $enabled,
        float $budgetPercent,
        float $maxPortfolioRiskPercent,
        float $takeProfitPercent,
        float $stopLossPercent,
        float $maxDailyLossPercent
    ): void {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE user_api_keys
             SET auto_trade_enabled = :enabled, auto_trade_budget_percent = :budget_percent,
                 max_portfolio_risk_percent = :max_portfolio_risk,
                 take_profit_percent = :take_profit, stop_loss_percent = :stop_loss,
                 max_daily_loss_percent = :max_daily_loss
             WHERE user_id = :user_id'
        );

        $stmt->execute([
            ':enabled' => $enabled ? 1 : 0,
            ':budget_percent' => $budgetPercent,
            ':max_portfolio_risk' => $maxPortfolioRiskPercent,
            ':take_profit' => $takeProfitPercent,
            ':stop_loss' => $stopLossPercent,
            ':max_daily_loss' => $maxDailyLossPercent,
            ':user_id' => $userId,
        ]);
    }

    // Devre kesici (ardisik zarar limiti) tetiklendiginde, digerlerine dokunmadan
    // otomatik islemi VE 4 gelismis modulun tamamini kapatir - kullanicinin butce/oran ayarlari
    // korunur (manuel olarak tekrar acabilir), ama "tum otomasyonu durdur" niyeti tam olarak yerine gelir
    public static function disableAutoTrade(int $userId): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE user_api_keys
             SET auto_trade_enabled = 0, social_radar_enabled = 0, listing_sniper_enabled = 0,
                 smart_money_enabled = 0, dca_enabled = 0, futures_trading_enabled = 0
             WHERE user_id = :user_id'
        );
        $stmt->execute([':user_id' => $userId]);
    }

    // Devre kesici SOGUMA suresini baslatir - disableAutoTrade() ile BIRLIKTE cagrilir. 9 Temmuz
    // TIAUSDT RCA'sinda, auto_trade_enabled bayraginin (tarayici onbellegi/tekrarlanan form kaydi
    // gibi bilinmeyen bir yolla) devre kesiciden HEMEN SONRA tekrar 1'e donebildigi tespit edildi -
    // bu sutun, bayragin durumundan TAMAMEN BAGIMSIZ, atlanamayan ikinci bir kilit saglar
    public static function setCircuitBreakerCooldown(int $userId, int $cooldownHours): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE user_api_keys SET circuit_breaker_until = DATE_ADD(NOW(), INTERVAL :hours HOUR) WHERE user_id = :user_id'
        );
        $stmt->execute([':hours' => $cooldownHours, ':user_id' => $userId]);
    }

    // Kullanicinin devre kesici sogumasi HALA aktifse (circuit_breaker_until gelecekte bir tarihse)
    // bicimlendirilmis tarihi doner, degilse (NULL veya gecmis) null doner - "aktif mi" karsilastirmasi
    // BILEREK MySQL'in KENDI saatiyle (NOW()) yapilir, PHP tarafinda strtotime()/time() ile DEGIL:
    // bu sunucuda PHP (UTC) ile MySQL (Europe/Berlin) saat dilimleri farkli oldugu icin, deger DB'den
    // cekilip PHP'de karsilastirilirsa saatler dolmus bir sogumayi bile "hala aktif" gibi gosterebiliyordu
    // (canli test sirasinda tam bu sekilde yakalandi)
    public static function getCircuitBreakerStatus(int $userId): ?string
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(circuit_breaker_until, '%d.%m.%Y %H:%i')
             FROM user_api_keys
             WHERE user_id = :user_id AND circuit_breaker_until IS NOT NULL AND circuit_breaker_until > NOW()
             LIMIT 1"
        );
        $stmt->execute([':user_id' => $userId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    // 23 Temmuz'da eklendi: devre kesici "son N kapanan islem hepsi zarar mi" diye BAKIYOR, bir
    // zamanlayici degil - sadece circuit_breaker_until'i temizlemek (setCircuitBreakerCooldown'un
    // tersi) bu ALTINDAKI gercegi degistirmiyordu, bu yuzden auto_trade_enabled=1 yapildiginda bot
    // YENI bir islem denemeden ONCE ayni eski seriyi gorup ANINDA yeniden kilitleniyordu - kisir
    // dongu (canli olayda 3 kez ust uste yasandi). resetLossStreak() bunu, admin'in BILINCLI onayiyla,
    // GECMIS kapanis kayitlarina (loss_reason/status) DOKUNMADAN cozer: sadece "bu tarihten ONCEKI
    // kapanislari ardisik-zarar sayimina KATMA" isareti koyar (bkz. ActiveTrade::findRecentClosed)
    public static function resetLossStreak(int $userId): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE user_api_keys
             SET loss_streak_reset_at = NOW(), circuit_breaker_until = NULL, auto_trade_enabled = 1,
                 last_cooldown_notif = NULL
             WHERE user_id = :user_id'
        );
        $stmt->execute([':user_id' => $userId]);
    }

    // Manuel Kill Switch: yonetici/musteri 3 zarari BEKLEMEDEN, istedigi an botu durdurabilsin diye.
    // circuit_breaker_until'den (otomatik, 24 saat sonra kendiliginden acilir) BILINCLI olarak AYRI
    // ve SURESIZ - sadece kullanicinin kendisi TEKRAR kapatana kadar aktif kalir. RiskManagerService
    // bunu checkCircuitBreaker()/checkFuturesCircuitBreaker()'in EN BASINDA kontrol eder, bu yuzden
    // TUM otonom giris noktalarini (spot+futures) TEK bir bayrakla durdurur - acik pozisyonlarin
    // izlenmesi (reconcileActiveTrades) bu kontrolden GECMEZ, dokunulmadan devam eder
    public static function setManualKillSwitch(int $userId, bool $enabled): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('UPDATE user_api_keys SET manual_kill_switch = :enabled WHERE user_id = :user_id');
        $stmt->execute([':enabled' => $enabled ? 1 : 0, ':user_id' => $userId]);
    }

    // bkz. resetLossStreak() yorumu - NULL ise (hic sifirlanmamissa) devre kesici normal davranisina
    // (TUM 24 saatlik pencereyi dikkate alir) doner, RiskManagerService bunu boyle yorumlar
    public static function getLossStreakResetAt(int $userId): ?string
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT loss_streak_reset_at FROM user_api_keys WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
        $value = $stmt->fetchColumn();

        return ($value === false || $value === null) ? null : (string) $value;
    }

    // Devre kesici/soguma bildirimi BUGUN zaten gonderilmis mi? Kullanici kilitli kaldigi surece
    // cron her dondugunde (15 dk'da bir) ayni Telegram mesajinin tekrar tekrar atilmasini onlemek
    // icin - gunde en fazla 1 bildirim. Tarih karsilastirmasi BILEREK MySQL'in kendi CURDATE()'iyle
    // yapilir (PHP tarafinda degil) - ayni PHP/MySQL saat dilimi farki riski icin
    public static function hasSentCooldownNotifToday(int $userId): bool
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'SELECT (last_cooldown_notif IS NOT NULL AND last_cooldown_notif = CURDATE())
             FROM user_api_keys WHERE user_id = :user_id LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId]);

        return (bool) $stmt->fetchColumn();
    }

    // Bildirim gonderildikten HEMEN SONRA cagrilir - susturucuyu bugunun tarihiyle isaretler
    public static function markCooldownNotifSent(int $userId): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('UPDATE user_api_keys SET last_cooldown_notif = CURDATE() WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
    }

    // Kilit tamamen actildiginda (soguma bitti) cagrilir - bir sonraki ceza doneminde sistem
    // tekrar (sadece 1 kez/gun) bildirim atabilsin diye susturucuyu sifirlar. WHERE kosulundaki
    // "zaten NULL degilse" sarti, temiz/kilitli-olmayan kullanicilar icin gereksiz yazma islemini
    // (her cron turunda) onler - sadece gercekten sifirlanmasi GEREKEN satirlar guncellenir
    public static function resetCooldownNotif(int $userId): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE user_api_keys SET last_cooldown_notif = NULL
             WHERE user_id = :user_id AND last_cooldown_notif IS NOT NULL'
        );
        $stmt->execute([':user_id' => $userId]);
    }

    // Duyuru Avcisi'nin (Otonom Sniper) butce yuzdesini gunceller - auto_trade_budget_percent'ten
    // TAMAMEN BAGIMSIZ, kendi sutununa (sniper_budget_percent) yazar. Sniper AI onayi beklemeden
    // korlemesine alim yaptigi icin normal AI Avci butcesinden cok daha dusuk tutulmasi onerilir -
    // bu ayrim BILINCLI: 13 Temmuz'da ayni sutunu paylasmalari "kritik mantik hatasi" olarak
    // raporlandi (bkz. CHANGELOG), iki modulun risk profili birbirinden tamamen bagimsiz olmali
    public static function updateSniperBudgetPercent(int $userId, float $budgetPercent): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('UPDATE user_api_keys SET sniper_budget_percent = :budget_percent WHERE user_id = :user_id');
        $stmt->execute([':budget_percent' => $budgetPercent, ':user_id' => $userId]);
    }

    // Kullanicinin Dashboard'daki "Gelişmiş Modüller" bolumunden tek tek actigi/kapattigi opt-in bayraklar
    // Izleyen Stop (Trailing Stop) parametrelerini getirir - eskiden AutoTradeController/
    // FuturesTradingService icinde sabit class const olarak tutuluyordu, artik kullanici bazli DB
    // ayarlari. findByUser()'in AKSINE api_key/secret_key sifre COZME yuku YOK - bu metod cron'un
    // HER acik pozisyon icin her turda cagirdigi hafif bir sorgu olmali. Kullanicinin hicbir
    // user_api_keys satiri yoksa (teorik olarak imkansiz, cagiran taraf zaten aktif bir trade'e
    // sahip) eski sabit degerlerle AYNI varsayilanlara duser
    public static function getTrailingSettings(int $userId): array
    {
        $defaults = [
            'trailing_stop_enabled' => true,
            'trailing_trigger_percent' => 1.5,
            'trailing_lock_percent' => 0.3,
            'trailing_distance_percent' => 1.0,
            'futures_trailing_stop_enabled' => true,
            'futures_trailing_trigger_percent' => 1.0,
            'futures_trailing_lock_percent' => 0.2,
            'futures_trailing_distance_percent' => 1.0,
        ];

        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'SELECT trailing_stop_enabled, trailing_trigger_percent, trailing_lock_percent, trailing_distance_percent,
                    futures_trailing_stop_enabled, futures_trailing_trigger_percent, futures_trailing_lock_percent, futures_trailing_distance_percent
             FROM user_api_keys WHERE user_id = :user_id LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return $defaults;
        }

        return [
            'trailing_stop_enabled' => (bool) $row['trailing_stop_enabled'],
            'trailing_trigger_percent' => (float) $row['trailing_trigger_percent'],
            'trailing_lock_percent' => (float) $row['trailing_lock_percent'],
            'trailing_distance_percent' => (float) $row['trailing_distance_percent'],
            'futures_trailing_stop_enabled' => (bool) $row['futures_trailing_stop_enabled'],
            'futures_trailing_trigger_percent' => (float) $row['futures_trailing_trigger_percent'],
            'futures_trailing_lock_percent' => (float) $row['futures_trailing_lock_percent'],
            'futures_trailing_distance_percent' => (float) $row['futures_trailing_distance_percent'],
        ];
    }

    public static function updateTrailingSettings(
        int $userId,
        bool $enabled,
        float $triggerPercent,
        float $lockPercent,
        float $distancePercent
    ): void {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE user_api_keys
             SET trailing_stop_enabled = :enabled, trailing_trigger_percent = :trigger_percent,
                 trailing_lock_percent = :lock_percent, trailing_distance_percent = :distance_percent
             WHERE user_id = :user_id'
        );

        $stmt->execute([
            ':enabled' => $enabled ? 1 : 0,
            ':trigger_percent' => $triggerPercent,
            ':lock_percent' => $lockPercent,
            ':distance_percent' => $distancePercent,
            ':user_id' => $userId,
        ]);
    }

    public static function updateFuturesTrailingSettings(
        int $userId,
        bool $enabled,
        float $triggerPercent,
        float $lockPercent,
        float $distancePercent
    ): void {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE user_api_keys
             SET futures_trailing_stop_enabled = :enabled, futures_trailing_trigger_percent = :trigger_percent,
                 futures_trailing_lock_percent = :lock_percent, futures_trailing_distance_percent = :distance_percent
             WHERE user_id = :user_id'
        );

        $stmt->execute([
            ':enabled' => $enabled ? 1 : 0,
            ':trigger_percent' => $triggerPercent,
            ':lock_percent' => $lockPercent,
            ':distance_percent' => $distancePercent,
            ':user_id' => $userId,
        ]);
    }

    public static function updateModuleToggles(
        int $userId,
        bool $socialRadarEnabled,
        bool $listingSniperEnabled,
        bool $smartMoneyEnabled,
        bool $dcaEnabled,
        bool $futuresTradingEnabled
    ): void {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE user_api_keys
             SET social_radar_enabled = :social, listing_sniper_enabled = :sniper,
                 smart_money_enabled = :smart_money, dca_enabled = :dca,
                 futures_trading_enabled = :futures
             WHERE user_id = :user_id'
        );

        $stmt->execute([
            ':social' => $socialRadarEnabled ? 1 : 0,
            ':sniper' => $listingSniperEnabled ? 1 : 0,
            ':smart_money' => $smartMoneyEnabled ? 1 : 0,
            ':dca' => $dcaEnabled ? 1 : 0,
            ':futures' => $futuresTradingEnabled ? 1 : 0,
            ':user_id' => $userId,
        ]);
    }
}
