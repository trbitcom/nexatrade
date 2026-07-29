-- NexaTrade Veritabani Semasi
-- phpMyAdmin uzerinden dogrudan ice aktarilabilir

CREATE DATABASE IF NOT EXISTS `nexatrade` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `nexatrade`;

-- --------------------------------------------------------
-- Tablo: users
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    `status` ENUM('active', 'passive', 'banned') NOT NULL DEFAULT 'active' COMMENT 'Yeni kayitlar passive ile baslar; admin onayiyla active olur',
    `telegram_chat_id` VARCHAR(64) NULL COMMENT 'Kullanicinin kendi Telegram sohbet kimligi (deep link ile baglanir)',
    `telegram_verify_token` VARCHAR(64) NULL COMMENT 'Telegram baglama linki icin tek kullanimlik dogrulama tokeni',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    UNIQUE KEY `uq_users_telegram_verify_token` (`telegram_verify_token`),
    KEY `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Migrasyon: Musteri Onboarding / Risk Onay Modali icin - kullanici "Okudum, anladim ve
-- kabul ediyorum" onay kutusunu isaretleyene kadar 0 kalir, dashboard'da zorunlu modal gosterilir
-- --------------------------------------------------------
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `is_risk_accepted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Hos Geldiniz/Risk Bildirimi modalini onaylayana kadar 0; onaylandiktan sonra bir daha gosterilmez',
    ADD COLUMN IF NOT EXISTS `risk_accepted_at` DATETIME NULL COMMENT 'Onay kutusunun isaretlendigi tam an - admin panelinde kim ne zaman onayladi gormek icin';

-- --------------------------------------------------------
-- Tablo: user_api_keys
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_api_keys` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `exchange_name` VARCHAR(50) NOT NULL,
    `api_key` TEXT NOT NULL,
    `secret_key` TEXT NOT NULL,
    `auto_trade_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `auto_trade_budget_usdt` DECIMAL(10,2) NOT NULL DEFAULT 20.00 COMMENT 'Eski sabit USDT butcesi - auto_trade_budget_percent eklendikten sonra artik islem acarken kullanilmiyor, geriye donuk kayit icin tutulur',
    `auto_trade_budget_percent` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT 'Her islemde kullanilacak bakiyenin yuzdesi - hesap buyudukce/kucul dukce otomatik olcekler, sabit tutar gibi bakiyeyi kazara yutmaz',
    `sniper_budget_percent` DECIMAL(5,2) NOT NULL DEFAULT 5.00 COMMENT 'Duyuru Avcisi (Otonom Sniper) icin AYRI ve BAGIMSIZ butce yuzdesi - auto_trade_budget_percent ile PAYLASILMAZ. Sniper AI onayi beklemeden korlemesine alim yaptigi icin cok daha dusuk tutulmasi onerilir (varsayilan %5)',
    `max_portfolio_risk_percent` DECIMAL(5,2) NOT NULL DEFAULT 30.00 COMMENT 'Ayni anda ACIK olan TUM pozisyonlarin toplam maliyetinin bakiyeye orani ust siniri - tek tek pozisyon butcesi kucuk olsa bile, cok sayida esolan pozisyon toplamda hesabi asiri riske atamasin diye',
    `take_profit_percent` DECIMAL(5,2) NOT NULL DEFAULT 20.00,
    `stop_loss_percent` DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    `max_daily_loss_percent` DECIMAL(5,2) NOT NULL DEFAULT 15.00 COMMENT 'Devre kesici: son 24 saatte butcenin bu yuzdesi kadar zarar edilirse islem durur',
    `social_radar_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Sosyal medya hype spike tespitini AI onay havuzuna dahil eder (opt-in)',
    `listing_sniper_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Yeni listelemeye AI onayi beklemeden aninda alim (opt-in, yuksek risk)',
    `smart_money_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Izlenen balina cuzdan hareketlerini kopyalar (opt-in)',
    `dca_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Zarardaki pozisyona maks 1 tur maliyet dusurme alimi (opt-in)',
    `risk_profile` ENUM('safe','balanced','aggressive') NOT NULL DEFAULT 'balanced' COMMENT 'Kullanicinin secili risk profili',
    `ai_score_threshold` TINYINT UNSIGNED NOT NULL DEFAULT 80 COMMENT 'Al sinyali icin gereken minimum AI skoru (safe:65, balanced:55, aggressive:45 - bkz. RiskProfileService::PROFILES, TEK dogru kaynak orasi)',
    `max_active_trades` TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'Eslezamanli acik tutulabilecek maksimum pozisyon sayisi',
    `futures_trading_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Binance Futures uzerinden KISA (short) pozisyon acmayi acar (opt-in, yuksek risk - kaldirac/likidasyon)',
    `futures_leverage` TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'v1: sabit/dusuk kaldirac, kullanici arayuzden degistiremez - likidasyon riskini sinirli tutmak icin',
    `circuit_breaker_until` DATETIME NULL COMMENT 'Ardisik zarar devre kesicisi tetiklendiginde NOW()+24 saat olarak set edilir - auto_trade_enabled formdan/tarayici onbellek hatasiyla erken 1 yapilsa BILE bu sure dolmadan islem yapilmaz (bagimsiz ikinci guvenlik katmani)',
    `last_cooldown_notif` DATE NULL COMMENT 'Devre kesici/soguma uyarisinin Telegram''dan EN SON hangi gun gonderildigi - kullanici kilitli kaldigi surece cron her dondugunde ayni mesaji tekrar tekrar atmasin diye (gunde en fazla 1 bildirim). Kilit acilinca NULL''a sifirlanir',
    `trailing_stop_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Spot Izleyen Stop (Trailing Stop) zirhini acar/kapatir - kapaliysa applyTrailingStopIfEligible() hicbir sey yapmadan doner, pozisyon sadece sabit TP/SL OCOsuyla korunur',
    `trailing_trigger_percent` DECIMAL(5,2) NOT NULL DEFAULT 1.50 COMMENT 'Spot: Asama 1in tetiklenmesi icin gereken asgari kar yuzdesi (entryya gore)',
    `trailing_lock_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.30 COMMENT 'Spot: Asama 1 tetiklenince Zarar Kesin entryin bu kadar USTUNE cekilecegi kilit yuzdesi',
    `trailing_distance_percent` DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT 'Spot: Asama 2 (Sinirsiz Izleme) - Zarar Kesin goruledigi zirvenin bu kadar ALTINDA tutulacagi izleme mesafesi',
    `futures_trailing_stop_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Futures Izleyen Stop zirhini acar/kapatir - spottan BAGIMSIZ, kaldirac riski nedeniyle ayri tutulur',
    `futures_trailing_trigger_percent` DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT 'Futures (SHORT): Asama 1in tetiklenmesi icin gereken asgari kar yuzdesi - spottan daha siki varsayilan',
    `futures_trailing_lock_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.20 COMMENT 'Futures (SHORT): Asama 1 tetiklenince Zarar Kesin entryin bu kadar ALTINA cekilecegi kilit yuzdesi',
    `futures_trailing_distance_percent` DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT 'Futures (SHORT): Asama 2 - Zarar Kesin goruledigi dibin bu kadar USTUNDE tutulacagi izleme mesafesi',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_api_keys_user_id` (`user_id`),
    KEY `idx_uak_auto_trade_enabled` (`auto_trade_enabled`),
    KEY `idx_uak_social_radar_enabled` (`social_radar_enabled`),
    KEY `idx_uak_listing_sniper_enabled` (`listing_sniper_enabled`),
    KEY `idx_uak_smart_money_enabled` (`smart_money_enabled`),
    CONSTRAINT `fk_user_api_keys_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tablo: webhook_logs
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `webhook_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pair` VARCHAR(20) NOT NULL,
    `action` VARCHAR(20) NOT NULL,
    `payload` TEXT NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tablo: orders
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `orders` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `pair` VARCHAR(20) NOT NULL,
    `side` ENUM('buy', 'sell') NOT NULL,
    `type` ENUM('market', 'limit', 'oco') NOT NULL DEFAULT 'market',
    `quantity` DECIMAL(20,8) NOT NULL,
    `price` DECIMAL(20,8) NOT NULL,
    `total` DECIMAL(20,8) NOT NULL,
    `binance_order_id` BIGINT UNSIGNED NULL,
    `parent_order_id` INT UNSIGNED NULL COMMENT 'Kar Al (Take Profit) emrini, onu doguran ALIS emrine baglar',
    `status` ENUM('pending', 'filled', 'cancelled', 'failed') NOT NULL DEFAULT 'pending',
    `error_message` TEXT NULL COMMENT 'status=failed oldugunda Binance API''sinin dondurdugu gercek hata metni (ör. "Filter failure: LOT_SIZE") - dashboard/Son Islemler''de seffaflik icin',
    `strategy_bucket` VARCHAR(50) NULL COMMENT 'Hangi stratejiden geldigi (golge_hacim/dipten_donus/erken_momentum/whitelist/announcement_hunter) - SAT/kapanis siparisleri kendi ALIS siparisinden miras alir, P&L-strateji raporlari icin TEK dogru kaynak',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_orders_user_id` (`user_id`),
    KEY `idx_orders_user_status_created` (`user_id`, `status`, `created_at`),
    KEY `idx_orders_pair` (`pair`),
    KEY `idx_orders_strategy_bucket` (`strategy_bucket`),
    CONSTRAINT `fk_orders_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_orders_parent`
        FOREIGN KEY (`parent_order_id`) REFERENCES `orders` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tablo: known_symbols
-- Binance'te su ana kadar gorulmus tum USDT paritelerini tutar; cron her calistiginda
-- canli listeyle karsilastirilip yeni cikan pariteler "yeni listelenen" olarak yakalanir
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `known_symbols` (
    `symbol` VARCHAR(20) NOT NULL,
    `first_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_bootstrap` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ilk kurulumda taban olarak kaydedildi mi (gercek yeni listeleme degil)',
    `status` VARCHAR(20) NULL COMMENT 'Binance exchangeInfo son bilinen durumu (TRADING/PRE_TRADING/BREAK vb.)',
    `expected_start_time` TIMESTAMP NULL COMMENT 'Duyuru Avcisi''nin bu sembolu ILK KEZ PRE_TRADING/BREAK durumunda gordugu an - Binance ileri tarihli kesin bir listelenme saati sunmadigi icin bu, "izlemeye ne zaman basladik" anlamina gelir, kesin tahmin degil',
    `sniper_status` VARCHAR(20) NULL COMMENT 'Duyuru Avcisi is akisi: pending (TRADING olmasi bekleniyor) | executed (alim yapildi) | failed (suresi doldu/basarisiz)',
    PRIMARY KEY (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Migrasyon: Mevcut veritabanlarinda (known_symbols tablosu CREATE TABLE IF NOT EXISTS yuzunden
-- zaten var oldugu icin yukaridaki tanim islemez) PRE_TRADING/BREAK erken tespit sutunlarini ekler
-- --------------------------------------------------------
ALTER TABLE `known_symbols`
    ADD COLUMN IF NOT EXISTS `status` VARCHAR(20) NULL COMMENT 'Binance exchangeInfo son bilinen durumu (TRADING/PRE_TRADING/BREAK vb.)',
    ADD COLUMN IF NOT EXISTS `expected_start_time` TIMESTAMP NULL COMMENT 'Duyuru Avcisi''nin bu sembolu ILK KEZ PRE_TRADING/BREAK durumunda gordugu an - kesin tahmin degil',
    ADD COLUMN IF NOT EXISTS `sniper_status` VARCHAR(20) NULL COMMENT 'Duyuru Avcisi is akisi: pending (TRADING olmasi bekleniyor) | executed (alim yapildi) | failed (suresi doldu/basarisiz)';

-- --------------------------------------------------------
-- Tablo: active_trades
-- Acik pozisyonlarin TEK dogru kaynagi: AI Avci bir OCO (Kar Al + Zarar Kes) korumasiyla
-- pozisyon actiginda buraya bir satir eklenir, OCO gerceklestiginde status guncellenir
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `active_trades` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `pair` VARCHAR(20) NOT NULL,
    `buy_order_id` INT UNSIGNED NOT NULL,
    `quantity` DECIMAL(20,8) NOT NULL,
    `entry_price` DECIMAL(20,8) NOT NULL,
    `take_profit_price` DECIMAL(20,8) NOT NULL,
    `stop_loss_price` DECIMAL(20,8) NOT NULL,
    `oco_order_list_id` BIGINT UNSIGNED NULL,
    `take_profit_order_id` BIGINT UNSIGNED NULL,
    `stop_loss_order_id` BIGINT UNSIGNED NULL,
    `status` ENUM('open', 'closed_profit', 'closed_loss', 'closed_manual', 'failed') NOT NULL DEFAULT 'open',
    `dca_rounds_used` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Bu pozisyonda kac DCA (maliyet dusurme) turu kullanildi (maks 1)',
    `breakeven_triggered` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ESKI tek-asamali break-even bayragi - trailing_stop_stage tarafindan yerini aldi, geriye donuk uyumluluk icin sutun korunuyor',
    `trailing_stop_stage` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Kademeli Izleyen Stop asamasi: 0=hicbiri, 1=+%2.5 karda SL +%0.5e cekildi, 2=+%4.0 karda SL +%2.0ye cekildi (2 asamadan sonra Asama 3 - Sinirsiz Izleme - surekli calisir)',
    `highest_price_seen` DECIMAL(20,8) NULL COMMENT 'Asama 3 (Sinirsiz Izleme) icin: Asama 2ye ulasildiktan sonra gorulen en yuksek fiyat - Zarar Kes bunun daima altinda tutulur',
    `loss_reason` VARCHAR(255) NULL COMMENT 'Trade Post-Mortem: zararla kapanan pozisyon icin tespit edilen kok neden (hiz/BTC etkisi/zirh-slippage kurali veya AI yedegi)',
    `partial_tp_executed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Kademeli Kar Alma: pozisyonun yarisi erken karla satildi mi (bir pozisyonda SADECE BIR KEZ tetiklenir) - quantity/take_profit_price/stop_loss_price bu tetiklemeden sonra KALAN yariyi yansitir',
    `ai_entry_score` TINYINT UNSIGNED NULL COMMENT 'Girisi onaylayan GPT/AI skoru (0-100) - oncesinde SADECE bot_logs''a (sembol+zaman yaklasik eslesmesiyle) yaziliyordu, artik pozisyonun KENDI satirinda kalici/kesin olarak tutulur - "hangi skorla girilen islemler kar/zarar getirdi" analizini kesinlestirir. Sosyal Radar/Pusu kurtarma gibi yollardan gelen adaylarda da GPT skoru (varsa telafi puani dahil) budur',
    `opened_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `closed_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `idx_active_trades_user` (`user_id`),
    KEY `idx_active_trades_status` (`status`),
    KEY `idx_active_trades_user_pair_status` (`user_id`, `pair`, `status`),
    CONSTRAINT `fk_active_trades_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_active_trades_buy_order`
        FOREIGN KEY (`buy_order_id`) REFERENCES `orders` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tablo: active_trade_fills
-- DCA (maliyet dusurme) sonrasi ayni pozisyon icin yapilan her alim fill'inin denetim kaydi.
-- active_trades.buy_order_id sadece ILK alima isaret etmeye devam eder (geriye donuk uyumluluk);
-- bu tablo coklu-fill gecmisinin tek dogru kaynagidir
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `active_trade_fills` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `active_trade_id` INT UNSIGNED NOT NULL,
    `order_id` INT UNSIGNED NOT NULL,
    `quantity` DECIMAL(20,8) NOT NULL,
    `price` DECIMAL(20,8) NOT NULL,
    `fill_type` ENUM('initial', 'dca') NOT NULL DEFAULT 'initial',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_active_trade_fills_trade` (`active_trade_id`),
    CONSTRAINT `fk_active_trade_fills_trade`
        FOREIGN KEY (`active_trade_id`) REFERENCES `active_trades` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_active_trade_fills_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tablo: smart_money_seen_txs
-- Akilli Para Kopyalayici'nin ayni on-chain islemi birden fazla cron turunda tekrar islememesi
-- icin dedupe kaydi (known_symbols ile ayni basit desen)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `smart_money_seen_txs` (
    `tx_hash` VARCHAR(80) NOT NULL,
    `seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`tx_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tablo: bot_logs
-- Her cron calistirmasinin yapisal ozeti: taranan coinler, AI skorlari, secilen coin, acilan pozisyon sayisi
-- (Ayrintili hata/baglanti loglari halen storage/logs/*.log dosyalarinda tutulur)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bot_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `run_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `trade_type` ENUM('spot','futures') NOT NULL DEFAULT 'spot' COMMENT 'Bu taramanin AI Avci (spot/UZUN) mu yoksa Futures (KISA) cronundan mi geldigi',
    `scanned_symbols` TEXT NOT NULL COMMENT 'JSON dizi',
    `ai_scores` TEXT NOT NULL COMMENT 'JSON dizi: [{symbol,score,reason,is_buy_signal}]',
    `input_data` TEXT NULL COMMENT 'JSON: sembol => {priceChangePercent, quoteVolume, high_90d, low_90d, position_percent_90d} - GPT''ye gonderilen ham piyasa verisi, ileride backtest/analiz icin',
    `selected_symbol` VARCHAR(20) NULL,
    `selected_score` TINYINT UNSIGNED NULL,
    `positions_opened` INT UNSIGNED NOT NULL DEFAULT 0,
    `notes` TEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_bot_logs_run_at` (`run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Migrasyon: Mevcut veritabanlarinda bot_logs'a trade_type + input_data ekler
-- --------------------------------------------------------
ALTER TABLE `bot_logs`
    ADD COLUMN IF NOT EXISTS `trade_type` ENUM('spot','futures') NOT NULL DEFAULT 'spot' AFTER `run_at`,
    ADD COLUMN IF NOT EXISTS `input_data` TEXT NULL AFTER `ai_scores`;

-- --------------------------------------------------------
-- Tablo: app_settings
-- Admin panelinden degistirilebilen genel ayarlar (OpenAI anahtari, webhook/cron token'lari).
-- Burada bir kayit yoksa kod config/app.php'deki degere geri duser (bkz. Setting modeli)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_settings` (
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Migrasyon: Mevcut veritabanlarinda risk profili sutunlarini ekler (CREATE TABLE IF NOT EXISTS ile kurulmamis DB'ler icin)
-- --------------------------------------------------------
ALTER TABLE `user_api_keys`
    ADD COLUMN IF NOT EXISTS `risk_profile` ENUM('safe','balanced','aggressive') NOT NULL DEFAULT 'balanced',
    ADD COLUMN IF NOT EXISTS `ai_score_threshold` TINYINT UNSIGNED NOT NULL DEFAULT 80,
    ADD COLUMN IF NOT EXISTS `max_active_trades` TINYINT UNSIGNED NOT NULL DEFAULT 3;

-- --------------------------------------------------------
-- Migrasyon: Sabit USDT butcesi yerine bakiye yuzdesi + toplam portfoy risk tavani ekler
-- --------------------------------------------------------
ALTER TABLE `user_api_keys`
    ADD COLUMN IF NOT EXISTS `auto_trade_budget_percent` DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    ADD COLUMN IF NOT EXISTS `max_portfolio_risk_percent` DECIMAL(5,2) NOT NULL DEFAULT 30.00;

-- --------------------------------------------------------
-- Migrasyon: Duyuru Avcisi (Otonom Sniper) icin auto_trade_budget_percent'ten TAMAMEN BAGIMSIZ,
-- ayri bir butce yuzdesi ekler - sniper korlemesine (AI onayi beklemeden) alim yaptigi icin normal
-- AI Avci'dan cok daha dusuk bir oranla sinirlanabilmesi gerekir (varsayilan %5)
-- --------------------------------------------------------
ALTER TABLE `user_api_keys`
    ADD COLUMN IF NOT EXISTS `sniper_budget_percent` DECIMAL(5,2) NOT NULL DEFAULT 5.00;

-- --------------------------------------------------------
-- Migrasyon: Futures (kaldiracli KISA pozisyon) modulu icin opt-in bayrak + sabit kaldirac
-- --------------------------------------------------------
ALTER TABLE `user_api_keys`
    ADD COLUMN IF NOT EXISTS `futures_trading_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `futures_leverage` TINYINT UNSIGNED NOT NULL DEFAULT 3;

-- --------------------------------------------------------
-- Migrasyon: Devre kesici icin zaman bazli soguma (cooldown) - 9 Temmuz'daki TIAUSDT RCA'sinda,
-- auto_trade_enabled bayraginin (tarayici onbellegi/tekrarlanan form kaydi gibi bilinmeyen bir
-- yolla) devre kesici tetiklendikten SONRA bile tekrar 1'e donebildigi tespit edildi. Bu sutun,
-- bayragin durumundan TAMAMEN BAGIMSIZ ikinci bir kilit saglar - bkz. RiskManagerService
-- --------------------------------------------------------
ALTER TABLE `user_api_keys`
    ADD COLUMN IF NOT EXISTS `circuit_breaker_until` DATETIME NULL;

-- --------------------------------------------------------
-- Migrasyon: Devre kesici Telegram bildirim susturucusu - kullanici kilitli kaldigi surece cron
-- her dondugunde (15 dk'da bir) ayni "Devre Kesici Tetiklendi" mesaji tekrar tekrar atiliyordu.
-- Bu sutun, gunde en fazla 1 bildirim gonderilmesini saglar (bkz. RiskManagerService::checkCooldown)
-- --------------------------------------------------------
ALTER TABLE `user_api_keys`
    ADD COLUMN IF NOT EXISTS `last_cooldown_notif` DATE NULL;

-- --------------------------------------------------------
-- Migrasyon: Basarisiz (failed) emirlerde Binance'in gercek hata metnini saklamak icin
-- --------------------------------------------------------
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `error_message` TEXT NULL;

-- --------------------------------------------------------
-- Migrasyon: Strateji atifini (hangi Karma Radar stratejisinden/Duyuru Avcisi'ndan geldigi) gecici
-- bot_logs loglarindan tahmin etmek yerine, kalici siparis kaydinin kendisine TEK dogru kaynak
-- olarak tasir - P&L-strateji raporlarinin milisaniyeler surmesi icin indexlenir
-- --------------------------------------------------------
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `strategy_bucket` VARCHAR(50) NULL,
    ADD INDEX IF NOT EXISTS `idx_orders_strategy_bucket` (`strategy_bucket`);

-- --------------------------------------------------------
-- Migrasyon: Dinamik Kaçış Protokolü - açık pozisyonun Zarar Kes seviyesi giriş fiyatına
-- (break-even) taşındığında bunu işaretler, boylece ayni pozisyon icin OCO degistirme islemi
-- tekrar tekrar denenmez (hem gereksiz Binance cagrisi hem de fiyat dususunde SL'in yanlislikla
-- tekrar asagi cekilmesi onlenir)
-- --------------------------------------------------------
ALTER TABLE `active_trades`
    ADD COLUMN IF NOT EXISTS `breakeven_triggered` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0;

-- --------------------------------------------------------
-- Migrasyon: Kademeli Izleyen Stop (Dinamik Kar Kilitleme) - tek-asamali break-even'in yerini alan
-- 2 asamali kar kilitleme mekanizmasi icin. 0=hicbiri, 1=+%2.5 karda SL +%0.5'e, 2=+%4.0 karda SL +%2.0'ye
-- --------------------------------------------------------
ALTER TABLE `active_trades`
    ADD COLUMN IF NOT EXISTS `trailing_stop_stage` TINYINT UNSIGNED NOT NULL DEFAULT 0;

-- --------------------------------------------------------
-- Migrasyon: Asama 3 (Sinirsiz Izleme) - Asama 2'ye (+%4.0) ulastiktan sonra, fiyat yukselmeye
-- devam ettikce Zarar Kes'i en yuksek gorulen fiyatin daima %2 altinda tutan surekli izleme icin
-- --------------------------------------------------------
ALTER TABLE `active_trades`
    ADD COLUMN IF NOT EXISTS `highest_price_seen` DECIMAL(20,8) NULL;

-- --------------------------------------------------------
-- Migrasyon: Trade Post-Mortem (Islem Otopsisi) - bir pozisyon zararla kapandiginda, sadece PNL
-- yazip gecmek yerine kok nedeni (hiz/BTC etkisi/zirh-slippage kural zincirinden veya AI yedeginden)
-- tespit edip saklar. TradePostMortemService::analyze() tarafindan doldurulur, dashboard'da "Son
-- Islemler" tablosundaki zararli satirlarin tooltip'inde gosterilir
-- --------------------------------------------------------
ALTER TABLE `active_trades`
    ADD COLUMN IF NOT EXISTS `loss_reason` VARCHAR(255) NULL;

-- --------------------------------------------------------
-- Migrasyon: Kademeli Kar Alma (Partial Take Profit) - pozisyon erken bir kar esigine ulastiginda
-- yarisi MARKET satilip gercek kar cebe indirilir, kalan yari Izleyen Zirh ile suruculur. Bir
-- pozisyonda SADECE BIR KEZ tetiklenir (ikinci bir kademeli satis yapilmaz)
-- --------------------------------------------------------
ALTER TABLE `active_trades`
    ADD COLUMN IF NOT EXISTS `partial_tp_executed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0;

-- --------------------------------------------------------
-- Migrasyon: Girisi onaylayan AI skorunu pozisyonun KENDI satirinda kalici olarak saklar (oncesinde
-- SADECE bot_logs'a, sembol+zaman YAKLASIK eslesmesiyle yaziliyordu - kesin bir foreign key yoktu)
-- --------------------------------------------------------
ALTER TABLE `active_trades`
    ADD COLUMN IF NOT EXISTS `ai_entry_score` TINYINT UNSIGNED NULL;

-- --------------------------------------------------------
-- Migrasyon: Futures (KISA) pozisyonlar icin Izleyen Stop - spot active_trades'teki AYNI
-- trailing_stop_stage semantigi, lowest_price_seen ise SHORT icin highest_price_seen'in TERSI
-- --------------------------------------------------------
ALTER TABLE `active_futures_trades`
    ADD COLUMN IF NOT EXISTS `trailing_stop_stage` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `lowest_price_seen` DECIMAL(20,8) NULL;

-- --------------------------------------------------------
-- Migrasyon: Izleyen Stop parametrelerini (spot + futures, AYRI kolon setleri) sabit kodlanmis
-- class const'lardan veritabanina tasir - kullanici artik Dashboard'dan kendi tetik/kilit/izleme
-- yuzdelerini duzenleyebilir. Varsayilanlar, eski AutoTradeController::TRAILING_STOP_STAGES /
-- FuturesTradingService::FUTURES_TRAILING_* sabitleriyle BIREBIR AYNI - mevcut kullanicilarin
-- davranisi migration aninda degismez
-- --------------------------------------------------------
ALTER TABLE `user_api_keys`
    ADD COLUMN IF NOT EXISTS `trailing_stop_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS `trailing_trigger_percent` DECIMAL(5,2) NOT NULL DEFAULT 1.50,
    ADD COLUMN IF NOT EXISTS `trailing_lock_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.30,
    ADD COLUMN IF NOT EXISTS `trailing_distance_percent` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    ADD COLUMN IF NOT EXISTS `futures_trailing_stop_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS `futures_trailing_trigger_percent` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    ADD COLUMN IF NOT EXISTS `futures_trailing_lock_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.20,
    ADD COLUMN IF NOT EXISTS `futures_trailing_distance_percent` DECIMAL(5,2) NOT NULL DEFAULT 1.00;

-- --------------------------------------------------------
-- Migrasyon: Ardisik Cift Onay'i zamana dayali (first_seen_at + sabit saniye) mimariden tur
-- tabanli (pass_count) mimariye gecirir - bkz. pending_signals tablo yorumu (21 Temmuz).
-- first_seen_at/last_validated_at SUTUNLARI KALDIRILMAZ (guvenlik agi/GC icin hala kullanilir)
-- --------------------------------------------------------
ALTER TABLE `pending_signals`
    ADD COLUMN IF NOT EXISTS `pass_count` TINYINT UNSIGNED NOT NULL DEFAULT 1;

-- --------------------------------------------------------
-- Migrasyon: Pullback Kalkani'nin (Anti-FOMO Freni #2) kor noktasi icin kacis supabi - canli
-- veride (25 Temmuz, BANKUSDT) kesintisiz/duz yukselen bir coinin 5+ ardisik turda teknik skoru
-- 100'e ragmen HICBIR ZAMAN gerilememesi gozlemlendi. pass_count'tan (Ardisik Cift Onay) TAMAMEN
-- AYRI bir sayac - bkz. PendingSignal::recordPullbackFailure() yorumu
-- --------------------------------------------------------
ALTER TABLE `pending_signals`
    ADD COLUMN IF NOT EXISTS `pullback_fail_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Ayni sembolun art arda kac kez Pullback Kalkani''na takildigi - esigi asinca kacis supabi tetiklenir';

-- --------------------------------------------------------
-- Tablo: symbol_cooldowns
-- Sembol bazli soguma (kara liste): bir kullanicinin ZARARLA (Zarar Kes) veya Dinamik Erken Kacis
-- ile kapattigi bir coine, AI skoru ne kadar hizli toparlanirsa toparlansin belirli bir sure tekrar
-- giris yapmasini engeller ("intikam islemi" / revenge trading onlemi). Devre kesiciden FARKLI
-- olarak SADECE o (kullanici, sembol) ciftini etkiler - botun geri kalanini/diger kullanicilari durdurmaz.
-- UNIQUE(user_id, pair): ayni coin tekrar zararla kapanirsa soguma suresi BASTAN baslar (yeni satir
-- eklenmez, mevcut satir guncellenir)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `symbol_cooldowns` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `pair` VARCHAR(20) NOT NULL,
    `cooldown_until` DATETIME NOT NULL,
    `reason` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_symbol_cooldowns_user_pair` (`user_id`, `pair`),
    KEY `idx_symbol_cooldowns_until` (`cooldown_until`),
    CONSTRAINT `fk_symbol_cooldowns_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tablo: ai_interventions
-- "Görünmez Kalkan" raporu: botun arka planda MTF/Emir Defteri gibi sert filtrelerle engellediği
-- tuzakların musteri-yuzlu bir ozeti - "AI Kalkani" dashboard widget'ini besler. user_id NULL
-- birakilabilir - MTF/Tahta gibi filtreler GLOBAL (piyasa capinda, kullanicidan bagimsiz) karar
-- noktalarinda calisir, RSI/BTC-dususu filtreleriyle AYNI mimaride. Sutun yine de ileride per-user
-- bir mudahale (ör. sembol sogumasi) eklenmek istenirse kullanilabilsin diye NULLABLE tutulur.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_interventions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NULL COMMENT 'NULL ise global (piyasa capinda) bir mudahale',
    `symbol` VARCHAR(20) NOT NULL,
    `intervention_type` VARCHAR(50) NOT NULL COMMENT 'ör. MTF_TUZAK, SATIS_DUVARI',
    `description` VARCHAR(500) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_interventions_created` (`created_at`),
    KEY `idx_ai_interventions_symbol_type` (`symbol`, `intervention_type`),
    CONSTRAINT `fk_ai_interventions_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tablo: pending_signals
-- Ardisik Cift Onay (Double Scan Approval): bir sembol agir teknik filtrelerin (RSI/Hacim/MTF/
-- Tahta) hepsinden gectiginde ANINDA alinmaz - once burada "gorüldu" olarak isaretlenir. 21 Temmuz'da
-- ZAMANA DAYALI (first_seen_at + sabit saniye) tasarimdan TUR TABANLI (pass_count) tasarima
-- gecirildi - canli loglarda dogrulandi: cron'lar arasi gercek boslugun (API gecikmesi/CronLock
-- cakismasi yuzunden) 60-185sn arasinda TUTARSIZ oldugu tekrar tekrar gozlemlendi, sabit bir saniye
-- esigi (60->120->...) surekli yetersiz kalip ayni semptomu tekrar tekrar yamaliyordu. Artik "ardisik"
-- tanimi SANIYEYE degil, cron'un KENDI dogal ritmine gore art arda İKİ BASARILI TARAMA turuna
-- dayanir - sunucu/API ne kadar yavaslarsa yavassin kendini otomatik ayarlar. pass_count=1 ilk
-- gorulmede, 2'ye ulasinca (bkz. AutoTradeController::PENDING_SIGNAL_REQUIRED_PASSES) alim onaylanir
-- ve satir silinir; ayni sembol bir SONRAKI turda herhangi bir sert filtreden GECEMEZSE (trend
-- bozuldu) satir ANINDA silinir (bkz. 6 ret noktasindaki PendingSignal::delete() cagrilari).
-- first_seen_at artik bir ONAY tetikleyicisi DEGIL, SADECE bir GUVENLIK AGI: cron cokmesi/hic
-- yeniden degerlendirilmeme gibi (aday havuzundan tamamen dusme) durumlarda tabloyu SISMEMESI icin
-- 15 dakikadan eski satirlar PendingSignal::pruneStale() ile temizlenir. Kullanicidan BAGIMSIZ,
-- GLOBAL bir tablodur - ai_interventions'daki user_id=null deseniyle AYNI mantik. UNIQUE(symbol):
-- bir sembolun ayni anda sadece TEK bir bekleyen sinyali olabilir
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pending_signals` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `symbol` VARCHAR(20) NOT NULL,
    `first_seen_score` TINYINT UNSIGNED NOT NULL,
    `pass_count` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Art arda kac BASARILI tur (tum sert filtrelerden gecti) sayildi - 2ye ulasinca alim onaylanir. Kullanicinin istedigi INT yerine TINYINT: deger pratikte hep 1 veya gecici olarak 2 (aninda tuketilip silinir), daha genis bir kolon gereksiz',
    `first_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'ARTIK onay tetikleyicisi degil - sadece guvenlik agi (GC) icin, bkz. tablo yorumu',
    `last_validated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pending_signals_symbol` (`symbol`),
    KEY `idx_pending_signals_first_seen` (`first_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tablo: pending_limit_orders
-- 27 Temmuz'da eklendi: eski "aktif bekleme" (12sn polling) tabanlı Pullback Kalkanı'nın yerini
-- alır - canlı veride (PENGUUSDT, ZECUSDT) kesintisiz/duz yukselen coinlerin bu kisa pencerede
-- HICBIR ZAMAN yeterli gerileme gostermeyip alim yapamamasi sorununu cozer. Artik piyasa emriyle
-- ANINDA alinmaz - sinyal fiyatinin biraz altina GERCEK bir LIMIT ALIS emri konur, bu tabloda
-- "beklemede" kaydedilir, Binance'in kendi order book'unda dogal zamaninda dolar. Fast Tracker
-- (1dk) her turda bu tabloyu tarar: dolduysa gercek pozisyon (active_trades) olusturulur, dolmadan
-- PENDING_LIMIT_ORDER_TIMEOUT_MINUTES (15) gecerse emir iptal edilip satir silinir. PendingSignal
-- (Ardisik Cift Onay - "aday kac tur ust uste sinyal verdi") ile KARISTIRILMAMALI, bu tablo bir
-- adim SONRASINI (onaylanmis sinyal GERCEK bir borsa emrine donusunce) temsil eder. UNIQUE(user_id,
-- pair): ayni kullanici+parite icin ayni anda sadece TEK bir bekleyen emir olabilir
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pending_limit_orders` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `pair` VARCHAR(20) NOT NULL,
    `binance_order_id` BIGINT UNSIGNED NOT NULL,
    `limit_price` DECIMAL(20,8) NOT NULL,
    `quantity` DECIMAL(20,8) NOT NULL,
    `budget` DECIMAL(20,8) NOT NULL COMMENT 'Emir konulurken hesaplanan USDT butcesi - dolunca gercek quote tutariyla karsilastirmak icin',
    `budget_percent` DECIMAL(5,2) NOT NULL COMMENT 'Emir konulurken kullanicinin kasa yuzdesi ayari - sadece dolunca musteriye gidecek bildirim metninde gosterilir',
    `take_profit_percent` DECIMAL(5,2) NOT NULL COMMENT 'Emir konulurken kullanicinin ayari - dolana kadar kullanici ayarini degistirirse bile TUTARLI kalsin diye anlik deger degil, o andaki deger dondurulur',
    `stop_loss_percent` DECIMAL(5,2) NOT NULL,
    `strategy_bucket` VARCHAR(50) NULL,
    `score` TINYINT UNSIGNED NULL COMMENT 'Giris ani AI/Deterministik Motor skoru - dolunca ActiveTrade.ai_entry_score''a aktarilir',
    `rsi_1h` DECIMAL(5,2) NULL,
    `rsi_15m` DECIMAL(5,2) NULL,
    `placed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pending_limit_orders_user_pair` (`user_id`, `pair`),
    KEY `idx_pending_limit_orders_placed_at` (`placed_at`),
    CONSTRAINT `fk_pending_limit_orders_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tablo: cron_locks
-- 21 Temmuz'da eklendi: AI Avci'nin tarama sikligi 300sn'den 60sn'ye dusurulunce (Ardisik Cift
-- Onay + Agresif Momentum Baypasi), bir taramanin (ozellikle Pullback beklemeleri yuzunden) 60
-- saniyeden uzun surmesi halinde bir sonraki cron isteginin AYNI anda calisip cakismasi
-- (ayni coine cift islem acilmasi dahil) riski dogdu. Bu tablo basit ama ATOMIK bir kilit
-- mekanizmasi saglar - gercek atomiklik PRIMARY KEY(lock_name) UNIQUE kisitlamasindan gelir:
-- iki es zamanli istek AYNI ANDA INSERT denerse, InnoDB'nin satir kilidi sayesinde SADECE biri
-- basarili olur (bkz. CronLock::acquire()). Sadece auto_trade icin degil, TUM otonom cron
-- modulleri (futures_trade, listing_sniper, smart_money) icin ORTAK, paylasilan bir tablo -
-- her modul kendi lock_name'iyle BAGIMSIZ kilitlenir, biri digerini bloklamaz
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cron_locks` (
    `lock_name` VARCHAR(50) NOT NULL,
    `locked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`lock_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tablo: active_futures_trades
-- Binance Futures uzerinde acilan KISA (short) pozisyonlarin TEK dogru kaynagi. active_trades'ten
-- (spot) BILINCLI olarak AYRI tutulur - kaldirac/likidasyon riski tasiyan bu modulun bir hatasi,
-- spot pozisyon takibini/mutabakatini asla etkilememelidir
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `active_futures_trades` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `pair` VARCHAR(20) NOT NULL,
    `side` ENUM('short') NOT NULL DEFAULT 'short' COMMENT 'v1 sadece KISA destekler - UZUN zaten spot alim uzerinden aciliyor',
    `leverage` TINYINT UNSIGNED NOT NULL,
    `margin_type` ENUM('isolated','cross') NOT NULL DEFAULT 'isolated',
    `open_order_id` INT UNSIGNED NOT NULL COMMENT 'Pozisyonu acan SATIS emrine (orders.id) isaret eder',
    `quantity` DECIMAL(20,8) NOT NULL,
    `entry_price` DECIMAL(20,8) NOT NULL,
    `liquidation_price` DECIMAL(20,8) NULL COMMENT 'Binance pozisyon riskinden okunan tahmini likidasyon fiyati (bilgi/uyari amacli)',
    `take_profit_price` DECIMAL(20,8) NOT NULL,
    `stop_loss_price` DECIMAL(20,8) NOT NULL,
    `take_profit_order_id` BIGINT UNSIGNED NULL COMMENT 'Binance emir ID (TAKE_PROFIT_MARKET, closePosition)',
    `stop_loss_order_id` BIGINT UNSIGNED NULL COMMENT 'Binance emir ID (STOP_MARKET, closePosition)',
    `trailing_stop_stage` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Izleyen Stop asamasi (spot active_trades.trailing_stop_stage ile AYNI semantik): 0=hicbiri, 1=+%FUTURES_TRAILING_TRIGGER_PERCENT karda SL +%FUTURES_TRAILING_LOCK_PERCENTe cekildi (sonrasi Asama 2 - Sinirsiz Izleme)',
    `lowest_price_seen` DECIMAL(20,8) NULL COMMENT 'Asama 2 (Sinirsiz Izleme) icin: SHORT pozisyonda kar fiyat DUSTUKCE olustugu icin (spot''un highest_price_seen''inin TERSI) Asama 1e ulasildiktan sonra gorulen en DUSUK fiyat - Zarar Kes bunun daima USTUNDE tutulur',
    `status` ENUM('open', 'closed_profit', 'closed_loss', 'closed_manual', 'failed') NOT NULL DEFAULT 'open',
    `opened_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `closed_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `idx_active_futures_trades_user` (`user_id`),
    KEY `idx_active_futures_trades_status` (`status`),
    KEY `idx_active_futures_trades_user_pair_status` (`user_id`, `pair`, `status`),
    CONSTRAINT `fk_active_futures_trades_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_active_futures_trades_open_order`
        FOREIGN KEY (`open_order_id`) REFERENCES `orders` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Migrasyon: Giris anindaki Kar Al/Zarar Kes fiyatlarini KALICI/DEGISTIRILEMEZ olarak saklar.
-- Sorun: take_profit_price/stop_loss_price kolonlari Izleyen Stop calistikca UPDATE ile UZERINE
-- YAZILIYOR - bir pozisyon kapandiktan SONRA "hangi SL% ile girilmisti" sorusuna bu kolonlardan
-- dogru cevap ALINAMAZ (geriye donuk analiz denendiginde negatif/anlamsiz SL% degerleri cikmasinin
-- kok nedeni buydu). Bu iki yeni kolon SADECE create() aninda yazilir, hicbir UPDATE sorgusu
-- (applyTrailingStop / applyDcaFill / applyPartialTakeProfit) bunlara DOKUNMAZ - ai_entry_score ile
-- AYNI "kalici, degismez giris anligi" deseni. NULL birakilir (ai_entry_score'daki gibi): mevcut
-- acik/kapali eski satirlar icin geriye donuk doldurma YAPILMAZ, sadece bu migrasyondan SONRA
-- acilan pozisyonlarda dolu olur
-- --------------------------------------------------------
ALTER TABLE `active_trades`
    ADD COLUMN IF NOT EXISTS `initial_take_profit_price` DECIMAL(20,8) NULL COMMENT 'Giris aninda belirlenen Kar Al fiyati - Izleyen Stop/DCA/Kademeli Kar Alma NE OLURSA OLSUN asla degismez',
    ADD COLUMN IF NOT EXISTS `initial_stop_loss_price` DECIMAL(20,8) NULL COMMENT 'Giris aninda belirlenen Zarar Kes fiyati - Izleyen Stop/DCA/Kademeli Kar Alma NE OLURSA OLSUN asla degismez';

ALTER TABLE `active_futures_trades`
    ADD COLUMN IF NOT EXISTS `initial_take_profit_price` DECIMAL(20,8) NULL COMMENT 'Giris aninda belirlenen Kar Al fiyati - Izleyen Stop asla degismez',
    ADD COLUMN IF NOT EXISTS `initial_stop_loss_price` DECIMAL(20,8) NULL COMMENT 'Giris aninda belirlenen Zarar Kes fiyati - Izleyen Stop asla degismez';

-- --------------------------------------------------------
-- Migrasyon: Komisyon Takibi (22 Temmuz) - bugune kadar hicbir PNL hesabi Binance islem
-- komisyonunu dusmuyordu, yani gosterilen "kar" rakamlari aslinda BRUT (komisyon oncesi) idi.
-- Bu iki kolon, orders.price/quantity ile AYNI kayit anda (fills[] veya myTrades'ten) doldurulur.
-- BILINCLI OLARAK active_trades/active_futures_trades'e AYRI bir "toplam komisyon" kolonu
-- EKLENMEDI - stop_loss_price'in Izleyen Stop tarafindan ezilmesiyle YASADIGIMIZ AYNI "ikinci
-- kaynak" riskini tekrar yaratirdi. Bir pozisyonun toplam komisyonu, o pozisyona bagli TUM
-- orders satirlarinin (ilk alis + varsa DCA + kismi/nihai satis) commission'i TOPLANARAK
-- hesaplanir - orders TEK dogru kaynak olarak kalir (Order::calculateRolling24hPNL'in ayni
-- ilkeyi kar/zarar icin zaten kullandigi desenle tutarli)
-- --------------------------------------------------------
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `commission` DECIMAL(20,8) NULL COMMENT 'Bu emrin fills[] (giris) veya myTrades (cikis) uzerinden toplanan gercek Binance komisyonu - NULL: bu satirdan ONCEKI eski kayitlar (geriye donuk doldurma yok, ai_entry_score ile ayni desen)',
    ADD COLUMN IF NOT EXISTS `commission_asset` VARCHAR(10) NULL COMMENT 'Komisyonun kesildigi varlik (ör. USDT veya BNB-indirimli hesaplarda BNB)';

-- --------------------------------------------------------
-- Migrasyon: Kar Al Tavanini Kaldirma (22 Temmuz) - kazanan islemlerin analizi, Sinirsiz Izleme
-- asamasina ulasan pozisyonlarin bile HEP ayni sabit Kar Al fiyatinda (~%5) tavan yaptigini
-- ortaya cikardi: replaceOcoWithNewStop() Zarar Kes'i yukseltirken Kar Al'a HIC dokunmuyordu.
-- Artik Sinirsiz Izleme ILK KEZ aktiflesince mevcut OCO tamamen iptal edilip YERINE SADECE tek
-- yonlu bir Zarar Kes emri konuyor - sabit tavan devreden cikiyor, pozisyon trendi Zarar Kes'in
-- kendisi yukselerek sonsuza kadar surebiliyor. take_profit_price KOLONU degistirilmez/silinmez
-- (dashboard'da "eski/artik gecerli olmayan hedef" olarak bilgi amacli kalir), sadece
-- take_profit_removed=1 iken artik ENFORCE edilmedigini gosterir
-- --------------------------------------------------------
ALTER TABLE `active_trades`
    ADD COLUMN IF NOT EXISTS `take_profit_removed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Sinirsiz Izleme (Asama 2) aktiflesince sabit Kar Al iptal edilip SADECE Zarar Kes emriyle mi izleniyor - 1 ise oco_order_list_id/take_profit_order_id NULL kalir, stop_loss_order_id TEKIL bir emri isaret eder';

-- orders.type ENUM'una 'stop_loss' eklenir - Kar Al tavani kaldirildiktan sonraki tekil
-- (OCO'suz) Zarar Kes emirlerinin gercek Binance emir tipini yansitmasi icin
ALTER TABLE `orders`
    MODIFY COLUMN `type` ENUM('market', 'limit', 'oco', 'stop_loss') NOT NULL DEFAULT 'market';

-- --------------------------------------------------------
-- Veri migrasyonu: Risk Profili AI esiklerinin geriye donuk BACKFILL'i (22 Temmuz, v1.44.1) -
-- SentimentService "prop-trader" promptuna (v1.44.0) gecince RiskProfileService::PROFILES'taki
-- ai_score_threshold sabitleri (safe:70->65, balanced:60->55, aggressive:50->45) asagi cekildi,
-- ANCAK bu sabitler SADECE bir kullanici profilini YENIDEN SECTIGINDE (ApiKey::updateRiskProfile)
-- okunup DB'ye yazilir - zaten aktif kullanicilarin user_api_keys.ai_score_threshold kolonu ESKI
-- degerde DONUK kalirdi ve motoru fiilen susturmaya devam ederdi. ai_score_threshold'un
-- risk_profile'in KATI bir fonksiyonu oldugu (dogrudan duzenlenebilir bir form alani OLMADIGI,
-- bkz. ApiKey.php yorum: "Profil degistiginde ... otomatik ayarlanir") dogrulanip, TUM satirlar
-- risk_profile'a gore kosulsuz senkronlandi
-- --------------------------------------------------------
UPDATE `user_api_keys` SET `ai_score_threshold` = 65 WHERE `risk_profile` = 'safe' AND `ai_score_threshold` <> 65;
UPDATE `user_api_keys` SET `ai_score_threshold` = 55 WHERE `risk_profile` = 'balanced' AND `ai_score_threshold` <> 55;
UPDATE `user_api_keys` SET `ai_score_threshold` = 45 WHERE `risk_profile` = 'aggressive' AND `ai_score_threshold` <> 45;

-- --------------------------------------------------------
-- Migrasyon: Ani Fitil (Wick) Korumasi (23 Temmuz) - LISTAUSDT/BANKUSDT/ZAMAUSDT gibi pek cok
-- pozisyon acilistan sadece 1.5-13.9 dakika sonra "Ani Volatilite/igne" ile Zarar Kes'e carpip
-- kapaniyordu (gercek bir trend donusu degil, anlik bir fiyat sicramasi). Ilk OCO artik kullanicinin
-- GERCEK Zarar Kes'inden HER ZAMAN daha genis bir "Genis Kalkan" ile kuruluyor, AutoTradeController'daki
-- WICK_SHIELD_MINUTES (15 dk) sonra tightenStopLossIfEligible() bunu asil hedefe sikilastiriyor -
-- TABII Ki Izleyen Stop/Kismi Kar Alma o ana kadar SL'e zaten dokunmamissa. DEFAULT 1 BILINCLI:
-- mevcut acik pozisyonlar (fitil korumasindan ONCE, GERCEK SL ile acilmis) yanlislikla "sikilastirma
-- bekliyor" sayilmasin diye - SADECE protectPositionWithOco() ile YENI acilan pozisyonlar 0 ile baslar
-- --------------------------------------------------------
ALTER TABLE `active_trades`
    ADD COLUMN IF NOT EXISTS `is_sl_tightened` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Ani Fitil Korumasi: 0 = gecici Genis Kalkan SL ile acildi, henuz asil hedefe sikilastirilmadi; 1 = zaten sikilastirilmis (veya bu ozellikten ONCE acilmis eski bir satir)';

-- --------------------------------------------------------
-- Migrasyon: Ardisik Zarar Serisi Sifirlama (23 Temmuz) - canli bir olayda devre kesici, admin
-- circuit_breaker_until'i manuel temizleyip auto_trade_enabled=1 yaptiktan HEMEN SONRA, bot YENI
-- bir islem denemeden ONCE ayni eski "son 3 kapanan hepsi zarar" gercegini gorup ANINDA yeniden
-- kilitleniyordu (3 kez ust uste yasandi) - kisir dongu, cunku yeni bir islemin denenmesine hic
-- FIRSAT verilmiyordu. ApiKey::resetLossStreak() artik bunu, GECMIS kapanis kayitlarina (loss_reason/
-- status) DOKUNMADAN, admin'in BILINCLI onayiyla cozer - bkz. RiskManagerService::checkCircuitBreaker()
-- --------------------------------------------------------
ALTER TABLE `user_api_keys`
    ADD COLUMN IF NOT EXISTS `loss_streak_reset_at` DATETIME NULL COMMENT 'Ardisik zarar devre kesicisi icin admin tarafindan bilincli sifirlama noktasi - NULL ise devre kesici TUM 24 saatlik pencereyi dikkate alir, dolu ise SADECE bu tarihten SONRA kapanan islemler ardisik-zarar sayimina girer';

-- --------------------------------------------------------
-- Migrasyon: Manuel Kill Switch (24 Temmuz) - kullanici/yonetici 3 zarari (ardisik zarar devre
-- kesicisi) veya gunluk zarar limitini BEKLEMEDEN, istedigi an TUM otonom modulleri (spot+futures)
-- durdurabilsin diye. circuit_breaker_until'den (otomatik, 24 saat sonra kendiliginden acilir)
-- BILINCLI olarak AYRI ve SURESIZDIR - bkz. ApiKey::setManualKillSwitch() /
-- RiskManagerService::checkCircuitBreaker()
-- --------------------------------------------------------
ALTER TABLE `user_api_keys`
    ADD COLUMN IF NOT EXISTS `manual_kill_switch` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Kullanici/yonetici tarafindan elle aktif edilen, suresiz devre kesici - 1 iken TUM otonom yeni-islem giris noktalari (spot+futures) durur, acik pozisyon izleme/koruma ETKILENMEZ';

-- --------------------------------------------------------
-- Migrasyon: Islem Ici Zirve/Dip Izleme - Trade Diagnostics (25 Temmuz) - "neden Zarar Kes ile
-- kapandi" sorusuna GERCEK fiyat verisiyle cevap vermek icin. highest_price_seen'den KASITLI olarak
-- AYRI: o sutun SADECE Izleyen Stop Asama 2ye (Sinirsiz Izleme) ulasildiktan SONRA dolar (bkz. o
-- sutunun yorumu) - BANKUSDT #131 canli orneginde pozisyon +%1.99'a kadar cikip trailing tetigini
-- (%2.0) kil payi kacirdigi icin highest_price_seen HIC dolmadi. Bu iki yeni sutun ise pozisyon
-- ACILDIGI ANDAN itibaren HER cron turunda, trailing/kismi kar alma durumundan TAMAMEN BAGIMSIZ
-- guncellenir - bkz. AutoTradeController::reconcileActiveTrades() ve ActiveTrade::updatePriceExtremes()
-- --------------------------------------------------------
ALTER TABLE `active_trades`
    ADD COLUMN IF NOT EXISTS `highest_price_reached` DECIMAL(20,8) NULL COMMENT 'Pozisyon acikken (trailing durumundan bagimsiz, HER cron turunda) gorulen en yuksek fiyat - Trade Diagnostics icin',
    ADD COLUMN IF NOT EXISTS `lowest_price_reached` DECIMAL(20,8) NULL COMMENT 'Pozisyon acikken (trailing durumundan bagimsiz, HER cron turunda) gorulen en dusuk fiyat - Trade Diagnostics icin';

-- --------------------------------------------------------
-- Migrasyon: Giris Ani RSI Kaydi (26 Temmuz) - ESPUSDT #180 post-mortem'inde 15dk RSI soguyup
-- filtreyi gecerken 1 saatlik RSI'in hala esige (75) yakin (73) oldugu, ama bunun sadece
-- TechnicalScoreEngine'in bilgilendirme metninde "dusuk agirlik" ile belirtildigi tespit edildi.
-- Bu iki sutun HICBIR filtre/karar mantigini DEGISTIRMEZ - sadece giris anindaki 1 saatlik/15
-- dakikalik RSI degerlerini kalici olarak saklar, ileride ("esige yakin giren islemler daha mi
-- cok kaybediyor?" gibi sorulara) GERCEK veriyle cevap verebilmek icin. Doldurulmasi/kullanimi
-- icin bkz. AutoTradeController::protectPositionWithOco() ve ActiveTrade::create()
-- --------------------------------------------------------
ALTER TABLE `active_trades`
    ADD COLUMN IF NOT EXISTS `entry_rsi_1h` DECIMAL(5,2) NULL COMMENT 'Giris anindaki 1 saatlik RSI (MarketScanner::calculateRsi) - sadece kayit, karar mantigini etkilemez',
    ADD COLUMN IF NOT EXISTS `entry_rsi_15m` DECIMAL(5,2) NULL COMMENT 'Giris anindaki 15 dakikalik RSI (TechnicalScoreEngine rsi_15m) - sadece kayit, karar mantigini etkilemez';

-- --------------------------------------------------------
-- Test kullanicisi (login akisini denemek icin) - admin yetkisiyle
-- E-posta : test@nexatrade.com
-- Sifre   : Test1234!
-- Hash asagida password_hash(PASSWORD_DEFAULT) ile uretilmistir, dogrudan kopyalanabilir
-- --------------------------------------------------------
INSERT INTO `users` (`name`, `email`, `password`, `role`, `status`) VALUES
('Test Kullanıcı', 'test@nexatrade.com', '$2y$10$gEoZX10KbkVxjrmV3Zyp3O2nCX2Ia6LEeBsBZvMh0o1J8lm/fEM6m', 'admin', 'active');
