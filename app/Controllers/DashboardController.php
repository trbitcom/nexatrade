<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Session;
use App\Core\Url;
use App\Models\ActiveFuturesTrade;
use App\Models\ActiveTrade;
use App\Models\AiIntervention;
use App\Models\ApiKey;
use App\Models\BotLog;
use App\Models\KnownSymbol;
use App\Models\Order;
use App\Models\Setting;
use App\Models\SymbolCooldown;
use App\Models\User;
use App\Services\BinanceFuturesService;
use App\Services\BinanceService;
use App\Services\ChangelogService;
use App\Services\MarketScanner;
use App\Services\NewsService;
use App\Services\RiskProfileService;
use App\Services\SentimentService;
use App\Services\ServerInfoService;
use Throwable;

final class DashboardController
{
    // AI Radar, her biri icin ayri bir OpenAI cagrisi gerektirdiginden (10 aday = 10 sirali istek,
    // 10-25+ saniye surebilir) her sayfa yuklemesinde/odak kazanmada yeniden hesaplanirsa dashboard
    // yavas hissettirir. generateMarketPulse'daki ile ayni onbellekleme deseni burada da kullanilir -
    // tum kullanicilar arasinda PAYLASILAN (global) bir onbellek, gereksiz tekrar cagrilari onler
    private const RADAR_CACHE_KEY = 'dashboard_radar_scan_cache';
    private const RADAR_CACHE_SECONDS = 120;

    public function index(): void
    {
        AuthMiddleware::handle();

        $userId = (int) Session::get('user_id');
        $user = User::findById($userId);
        $isAdmin = Session::get('user_role') === 'admin';

        $existingKey = ApiKey::findByUser($userId)[0] ?? null;
        $maskedApiKey = $existingKey !== null ? $this->maskApiKey($existingKey['api_key']) : null;
        $existingExchange = $existingKey['exchange_name'] ?? null;

        $autoTradeEnabled = (bool) ($existingKey['auto_trade_enabled'] ?? false);
        $autoTradeBudgetPercent = (float) ($existingKey['auto_trade_budget_percent'] ?? 10.00);
        $maxPortfolioRiskPercent = (float) ($existingKey['max_portfolio_risk_percent'] ?? 30.00);
        $takeProfitPercent = (float) ($existingKey['take_profit_percent'] ?? 20.00);
        $stopLossPercent = (float) ($existingKey['stop_loss_percent'] ?? 10.00);
        $maxDailyLossPercent = (float) ($existingKey['max_daily_loss_percent'] ?? 15.00);
        $trailingStopEnabled = (bool) ($existingKey['trailing_stop_enabled'] ?? true);
        $trailingTriggerPercent = (float) ($existingKey['trailing_trigger_percent'] ?? 1.50);
        $trailingLockPercent = (float) ($existingKey['trailing_lock_percent'] ?? 0.30);
        $trailingDistancePercent = (float) ($existingKey['trailing_distance_percent'] ?? 1.00);
        $socialRadarEnabled = (bool) ($existingKey['social_radar_enabled'] ?? false);
        $listingSniperEnabled = (bool) ($existingKey['listing_sniper_enabled'] ?? false);
        // Duyuru Avcisi'nin auto_trade_budget_percent'ten TAMAMEN BAGIMSIZ kendi butce yuzdesi -
        // varsayilan %5 (AI Avci'nin varsayilan %10'undan bilincli olarak dusuk, sniper korlemesine
        // alim yaptigi icin)
        $sniperBudgetPercent = (float) ($existingKey['sniper_budget_percent'] ?? 5.00);
        $smartMoneyEnabled = (bool) ($existingKey['smart_money_enabled'] ?? false);
        $dcaEnabled = (bool) ($existingKey['dca_enabled'] ?? false);
        $futuresTradingEnabled = (bool) ($existingKey['futures_trading_enabled'] ?? false);
        $futuresLeverage = (int) ($existingKey['futures_leverage'] ?? 3);
        $futuresTrailingStopEnabled = (bool) ($existingKey['futures_trailing_stop_enabled'] ?? true);
        $futuresTrailingTriggerPercent = (float) ($existingKey['futures_trailing_trigger_percent'] ?? 1.00);
        $futuresTrailingLockPercent = (float) ($existingKey['futures_trailing_lock_percent'] ?? 0.20);
        $futuresTrailingDistancePercent = (float) ($existingKey['futures_trailing_distance_percent'] ?? 1.00);

        // PERFORMANS: Binance bakiyesi, AI Radar (Binance tarama + OpenAI puanlama), piyasa nabzı,
        // haber akışı ve açık pozisyonların anlık fiyatı gibi DIŞ API'lere bağlı veriler artık
        // burada senkron çekilmiyor - sayfa anında açılır, bu veriler sayfa yüklendikten SONRA
        // fetch/AJAX ile ayrı uç noktalardan gelir (bkz. dataBalance/dataRadar/dataNews/dataActiveTrades
        // ve dashboard/index.php'nin sonundaki JS). Aşağıdakiler yalnızca yerel veritabanı okumaları
        // olduğu için (ağ çağrısı yok) senkron kalmaları sayfa açılışını yavaşlatmaz.
        // 20 Temmuz'da tespit edildi: countByUserAndStatus()/calculateDailyPNL() ham orders satir
        // sayisi/tutari uzerinden calisiyordu - 1 ALIS + 1 SATIS'tan olusan TEK bir kapanmis
        // pozisyonu "TAMAMLANAN" sayacinda 2 ayri islem gibi gosteriyordu, gunluk PNL de henuz
        // KAPANMAMIS bir pozisyonun alis tutarini zarar gibi sayabiliyordu (calculateRolling24hPNL/
        // calculatePnlSummary'deki 10 Temmuz'da duzeltilen AYNI hata sinifi, burada duzeltilmemis
        // kalmisti). Ikisi de artik ZATEN dogru olan calculatePnlSummary()'nin tek kaynagini kullaniyor -
        // "TAMAMLANAN" artik gercekten KAPANMIS POZISYON sayisi (1 alis + 1(+) satis = 1), ham emir sayisi degil
        $pnlSummary = Order::calculatePnlSummary($userId);
        $completedOrders = $pnlSummary['total_trades'];
        $recentOrders = Order::findRecentByUser($userId, 10);
        $dailyPnl = $pnlSummary['daily_profit'];

        $activeTrades = ActiveTrade::findOpenForUser($userId);
        $activeFuturesTrades = ActiveFuturesTrade::findOpenForUser($userId);
        $recentListings = KnownSymbol::findRecentlyListed(3, 8);

        $successMessage = Session::get('api_key_success');
        $errorMessage = Session::get('api_key_error');
        Session::remove('api_key_success');
        Session::remove('api_key_error');

        $autoTradeSuccess = Session::get('auto_trade_success');
        $autoTradeError = Session::get('auto_trade_error');
        Session::remove('auto_trade_success');
        Session::remove('auto_trade_error');

        $advancedModulesSuccess = Session::get('advanced_modules_success');
        $advancedModulesError = Session::get('advanced_modules_error');
        Session::remove('advanced_modules_success');
        Session::remove('advanced_modules_error');

        $accountSuccess = Session::get('account_success');
        $accountError = Session::get('account_error');
        Session::remove('account_success');
        Session::remove('account_error');

        $telegramVerifyToken = User::ensureTelegramVerifyToken($userId);
        $telegramConnected = !empty($user['telegram_chat_id']);
        $telegramBotUsername = $this->getTelegramBotUsername();

        $riskProfile = $existingKey['risk_profile'] ?? 'balanced';
        $riskAccepted = (bool) ($user['is_risk_accepted'] ?? false);

        // Devre kesici soguma suresi: NULL/gecmis tarihse aktif degil, gelecekteki bir tarihse
        // kullaniciya UI'da net bir uyari gosterilir (bkz. RiskManagerService::checkCooldown -
        // backend zaten bunu bagimsiz olarak engelliyor, bu sadece kullanicinin NEDEN islem
        // yapilmadigini gormesi icin - 9 Temmuz TIAUSDT RCA'sindan sonra eklendi). "Aktif mi"
        // karsilastirmasi ApiKey::getCircuitBreakerStatus() icinde MySQL'in kendi saatiyle yapilir -
        // PHP tarafinda ayrica strtotime()/time() KARSILASTIRMASI YAPILMAZ (bkz. o metodun yorumu)
        $circuitBreakerUntil = ApiKey::getCircuitBreakerStatus($userId);
        $circuitBreakerActive = $circuitBreakerUntil !== null;

        // API Kurulum Rehberi'nde musteriye gosterilecek, Binance'in IP kisitlamasi icin
        // kopyalayabilecegi sunucu disa-donuk IP adresi (bkz. ServerInfoService - 24s onbellekli)
        $serverPublicIp = ServerInfoService::getPublicIp();

        // Surum BILEREK sadece config/app.php'den okunur (DB override YOK) - CHANGELOG.md'nin en ust
        // kaydiyla TEK bir dogru kaynaktan senkron kalmasi icin (iki farkli yerde surum tutulursa
        // biri guncellenip digeri unutulabilir)
        $appVersionConfig = require __DIR__ . '/../../config/app.php';
        $appVersion = (string) ($appVersionConfig['app_version'] ?? '1.0.0');
        $changelogEntries = ChangelogService::getEntries(5);

        require __DIR__ . '/../Views/dashboard/index.php';
    }

    // Hos Geldiniz/Risk Bildirimi modalindaki zorunlu onay kutusu isaretlenip form gonderildiginde
    // cagrilir - bir daha geri donmez, kullanici bundan sonra dashboard'u tum ozellikleriyle kullanabilir
    public function acceptRisk(): void
    {
        AuthMiddleware::handle();

        $userId = (int) Session::get('user_id');
        User::acceptRisk($userId);

        header('Location: ' . Url::to('/dashboard'));
    }

    // Risk bildirimini henuz kabul etmemis kullanicilarin borsa/otomasyon ayarlarini degistirmesini
    // engeller (API anahtari, AI Avci, gelismis moduller) - onaylamadan gercek para ile islem
    // acabilecek hicbir ayar kaydedilemesin diye. Hesap bilgileri (ad/e-posta/sifre) bu kisitlamaya dahil DEGIL.
    private function requireRiskAccepted(int $userId, string $errorSessionKey = 'api_key_error'): bool
    {
        $user = User::findById($userId);

        if ($user !== false && (bool) $user['is_risk_accepted']) {
            return true;
        }

        $this->respondFormResult(false, 'Devam etmeden önce Risk Bildirimi\'ni okuyup onaylamalısınız.', $errorSessionKey);

        return false;
    }

    // fetch() ile gonderilen AJAX form istekleri bu ozel header'i tasir (JS'teki submitJsonForm
    // helper'i tarafindan eklenir) - klasik <form> tam sayfa POST'undan bu sekilde ayirt edilir
    private function isAjaxRequest(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    // Ayni form-kaydetme sonucunu iki farkli sekilde iletir: AJAX istekse JSON doner (sayfa hic
    // yenilenmez, JS toast/inline mesaj gosterir), degilse ESKI Session-flash + redirect davranisina
    // duser (JS devre disi kalirsa bile form calismaya devam etsin diye - progressive enhancement)
    private function respondFormResult(bool $success, string $message, string $sessionKey): void
    {
        if ($this->isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);

            return;
        }

        Session::set($sessionKey, $message);
        header('Location: ' . Url::to('/dashboard'));
    }

    public function saveApiKeys(): void
    {
        AuthMiddleware::handle();

        $userId = (int) Session::get('user_id');

        if (!$this->requireRiskAccepted($userId)) {
            return;
        }

        $exchangeName = trim((string) ($_POST['exchange_name'] ?? ''));
        $apiKey = trim((string) ($_POST['api_key'] ?? ''));
        $secretKey = trim((string) ($_POST['secret_key'] ?? ''));

        if ($exchangeName === '' || $apiKey === '' || $secretKey === '') {
            Session::set('api_key_error', 'Lütfen tüm alanları doldurun.');
            header('Location: ' . Url::to('/dashboard'));
            return;
        }

        try {
            ApiKey::save($userId, $exchangeName, $apiKey, $secretKey);
            Session::set('api_key_success', 'API anahtarları başarıyla kaydedildi.');
        } catch (Throwable $e) {
            Session::set('api_key_error', 'API anahtarları kaydedilirken bir hata oluştu.');
        }

        header('Location: ' . Url::to('/dashboard'));
    }

    // AI Avci (otonom tarama/alim) ayarlarini kaydeder: acik/kapali, sabit butce, kar hedefi ve zarar kes yuzdeleri
    public function saveAutoTradeSettings(): void
    {
        AuthMiddleware::handle();

        $userId = (int) Session::get('user_id');

        if (!$this->requireRiskAccepted($userId, 'auto_trade_error')) {
            return;
        }

        if (ApiKey::findByUser($userId) === []) {
            $this->respondFormResult(false, 'Önce bir borsa API anahtarı kaydetmelisiniz.', 'auto_trade_error');
            return;
        }

        $enabled = isset($_POST['auto_trade_enabled']);
        $budgetPercent = (float) ($_POST['auto_trade_budget_percent'] ?? 10);
        $maxPortfolioRiskPercent = (float) ($_POST['max_portfolio_risk_percent'] ?? 30);
        $takeProfitPercent = (float) ($_POST['take_profit_percent'] ?? 20);
        $stopLossPercent = (float) ($_POST['stop_loss_percent'] ?? 10);
        $maxDailyLossPercent = (float) ($_POST['max_daily_loss_percent'] ?? 15);
        $trailingStopEnabled = isset($_POST['trailing_stop_enabled']);
        $trailingTriggerPercent = (float) ($_POST['trailing_trigger_percent'] ?? 1.5);
        $trailingLockPercent = (float) ($_POST['trailing_lock_percent'] ?? 0.3);
        $trailingDistancePercent = (float) ($_POST['trailing_distance_percent'] ?? 1.0);

        if ($budgetPercent < 1 || $budgetPercent > 100) {
            $this->respondFormResult(false, 'İşlem başına bütçe, bakiyenin %1 ile %100\'ü arasında olmalıdır.', 'auto_trade_error');
            return;
        }

        if ($maxPortfolioRiskPercent < 5 || $maxPortfolioRiskPercent > 100) {
            $this->respondFormResult(false, 'Maksimum toplam portföy riski %5 ile %100 arasında olmalıdır.', 'auto_trade_error');
            return;
        }

        if ($takeProfitPercent < 1 || $takeProfitPercent > 500) {
            $this->respondFormResult(false, 'Kâr hedefi %1 ile %500 arasında olmalıdır.', 'auto_trade_error');
            return;
        }

        if ($stopLossPercent < 1 || $stopLossPercent > 90) {
            $this->respondFormResult(false, 'Zarar kes %1 ile %90 arasında olmalıdır.', 'auto_trade_error');
            return;
        }

        if ($maxDailyLossPercent < 1 || $maxDailyLossPercent > 50) {
            // Ust sinir kasitli olarak 100 degil 50 - devre kesicinin gercek bir koruma saglamasi
            // icin (canlida bir kullanicinin bu degeri %100'e cektigi, boylece devre kesicinin
            // pratikte hicbir zaman devreye giremedigi tespit edildi)
            $this->respondFormResult(false, 'Günlük maksimum zarar limiti %1 ile %50 arasında olmalıdır.', 'auto_trade_error');
            return;
        }

        if ($trailingTriggerPercent < 0.1 || $trailingTriggerPercent > 50) {
            $this->respondFormResult(false, 'İzleyen Stop tetik yüzdesi %0.1 ile %50 arasında olmalıdır.', 'auto_trade_error');
            return;
        }

        // Gercek Finans Matematigi: Kilit seviyesi, borsa komisyonu (Order::FEE_RATE_PER_LEG - alis+satis
        // ~%0.2) dusuldukten SONRA bile pozisyonu kesinlikle NET KARDA birakmali - aksi halde "kar
        // kilitlendi" bildirimi musteriyi yaniltir, gercekte komisyon sonrasi zarar/basabas olabilir.
        // 26 Temmuz'da eklendi - MIN_SAFE_LOCK_PERCENT, %0.2 komisyona 0.1 puanlik guvenlik payi eklenmis
        // hali (%0.3) - mevcut varsayilan (0.3) zaten BU esigi karsiliyor, geriye donuk kirici degil
        $minSafeLockPercent = (Order::FEE_RATE_PER_LEG * 2 * 100) + 0.1;

        if ($trailingLockPercent < $minSafeLockPercent || $trailingLockPercent > 50) {
            $this->respondFormResult(false, sprintf(
                'İzleyen Stop kilit yüzdesi en az %%%.1f olmalıdır (borsa komisyonu sonrası net kârda kalmak için), en fazla %%50.',
                $minSafeLockPercent
            ), 'auto_trade_error');
            return;
        }

        if ($trailingDistancePercent < 0.1 || $trailingDistancePercent > 50) {
            $this->respondFormResult(false, 'İzleyen Stop izleme mesafesi %0.1 ile %50 arasında olmalıdır.', 'auto_trade_error');
            return;
        }

        try {
            ApiKey::updateAutoTradeSettings($userId, $enabled, $budgetPercent, $maxPortfolioRiskPercent, $takeProfitPercent, $stopLossPercent, $maxDailyLossPercent);
            ApiKey::updateTrailingSettings($userId, $trailingStopEnabled, $trailingTriggerPercent, $trailingLockPercent, $trailingDistancePercent);
            $this->respondFormResult(true, 'AI Avcı ayarları güncellendi.', 'auto_trade_success');
        } catch (Throwable $e) {
            $this->respondFormResult(false, 'Ayarlar kaydedilirken bir hata oluştu.', 'auto_trade_error');
        }
    }

    // Kullanicinin kendi ad/e-posta/sifre bilgilerini gunceller. Guvenlik geregi: HERHANGI bir
    // degisiklik icin mevcut sifre dogrulanmalidir (oturum acik unutulmus bir cihazdan yanlislikla
    // hesap ele gecirilmesine karsi). Yeni sifre alani BOS birakilirsa sadece ad/e-posta guncellenir
    public function saveAccountSettings(): void
    {
        AuthMiddleware::handle();

        $userId = (int) Session::get('user_id');
        $user = User::findById($userId);

        $name = trim((string) ($_POST['account_name'] ?? ''));
        $email = trim((string) ($_POST['account_email'] ?? ''));
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

        if ($name === '' || $email === '') {
            Session::set('account_error', 'Lütfen ad soyad ve e-posta alanlarını doldurun.');
            header('Location: ' . Url::to('/dashboard'));
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::set('account_error', 'Geçerli bir e-posta adresi girin.');
            header('Location: ' . Url::to('/dashboard'));
            return;
        }

        if ($user === false || !User::verifyPassword($currentPassword, $user['password'])) {
            Session::set('account_error', 'Mevcut şifreniz hatalı - değişiklik için mevcut şifrenizi doğru girmelisiniz.');
            header('Location: ' . Url::to('/dashboard'));
            return;
        }

        if ($email !== $user['email']) {
            $existing = User::findByEmail($email);

            if ($existing !== false && (int) $existing['id'] !== $userId) {
                Session::set('account_error', 'Bu e-posta adresi başka bir hesap tarafından kullanılıyor.');
                header('Location: ' . Url::to('/dashboard'));
                return;
            }
        }

        if ($newPassword !== '') {
            if (strlen($newPassword) < 8) {
                Session::set('account_error', 'Yeni şifre en az 8 karakter olmalıdır.');
                header('Location: ' . Url::to('/dashboard'));
                return;
            }

            if ($newPassword !== $newPasswordConfirm) {
                Session::set('account_error', 'Yeni şifreler eşleşmiyor.');
                header('Location: ' . Url::to('/dashboard'));
                return;
            }
        }

        try {
            User::updateProfile($userId, $name, $email);

            if ($newPassword !== '') {
                User::updatePassword($userId, $newPassword);
            }

            Session::set('user_name', $name);
            Session::set('account_success', 'Hesap bilgileriniz güncellendi.');
        } catch (Throwable $e) {
            Session::set('account_error', 'Hesap bilgileri güncellenirken bir hata oluştu.');
        }

        header('Location: ' . Url::to('/dashboard'));
    }

    // Hedge-fund seviyesi gelismis modullerin (Sosyal Radar, Duyuru Avcisi, Akilli Para, DCA) opt-in
    // anahtarlarini kaydeder. Bunlar auto_trade_enabled'dan BAGIMSIZDIR - kullanici her birini
    // ayri ayri acip kapatabilir, hicbiri varsayilan olarak acik gelmez
    public function saveAdvancedModules(): void
    {
        AuthMiddleware::handle();

        $userId = (int) Session::get('user_id');

        if (!$this->requireRiskAccepted($userId, 'advanced_modules_error')) {
            return;
        }

        if (ApiKey::findByUser($userId) === []) {
            Session::set('advanced_modules_error', 'Önce bir borsa API anahtarı kaydetmelisiniz.');
            header('Location: ' . Url::to('/dashboard'));
            return;
        }

        $socialRadarEnabled = isset($_POST['social_radar_enabled']);
        $listingSniperEnabled = isset($_POST['listing_sniper_enabled']);
        $smartMoneyEnabled = isset($_POST['smart_money_enabled']);
        $dcaEnabled = isset($_POST['dca_enabled']);
        // Diger 4 alanin aksine bu artik bir checkbox degil, "İşlem Modu" radyo grubu (her zaman
        // POST edilir - value'ya bakilmali, isset() burada HER ZAMAN true doner ve yanlis olur
        $futuresTradingEnabled = ($_POST['futures_trading_enabled'] ?? '0') === '1';

        // Futures Izleyen Stop alanlari SADECE "Spot + Futures" modu secili oldugunda formda
        // render edilir (bkz. view) - futures kapaliyken bu alanlar POST'ta HIC BULUNMAZ. sniper_budget_percent
        // ile AYNI korumayi tekrarliyoruz: alanlar POST edilmemisse mevcut kayitli deger dokunulmadan
        // korunur, aksi halde her "Gelismis Moduller" kaydedildiginde futures ayarlari sessizce
        // varsayilana sifirlanirdi
        $futuresTrailingSettingsSubmitted = isset($_POST['futures_trailing_trigger_percent']);
        $futuresTrailingStopEnabled = isset($_POST['futures_trailing_stop_enabled']);
        $futuresTrailingTriggerPercent = (float) ($_POST['futures_trailing_trigger_percent'] ?? 1.0);
        $futuresTrailingLockPercent = (float) ($_POST['futures_trailing_lock_percent'] ?? 0.2);
        $futuresTrailingDistancePercent = (float) ($_POST['futures_trailing_distance_percent'] ?? 1.0);

        if ($futuresTrailingSettingsSubmitted && ($futuresTrailingTriggerPercent < 0.1 || $futuresTrailingTriggerPercent > 50)) {
            Session::set('advanced_modules_error', 'Futures İzleyen Stop tetik yüzdesi %0.1 ile %50 arasında olmalıdır.');
            header('Location: ' . Url::to('/dashboard'));
            return;
        }

        if ($futuresTrailingSettingsSubmitted && ($futuresTrailingLockPercent < 0 || $futuresTrailingLockPercent > 50)) {
            Session::set('advanced_modules_error', 'Futures İzleyen Stop kilit yüzdesi %0 ile %50 arasında olmalıdır.');
            header('Location: ' . Url::to('/dashboard'));
            return;
        }

        if ($futuresTrailingSettingsSubmitted && ($futuresTrailingDistancePercent < 0.1 || $futuresTrailingDistancePercent > 50)) {
            Session::set('advanced_modules_error', 'Futures İzleyen Stop izleme mesafesi %0.1 ile %50 arasında olmalıdır.');
            header('Location: ' . Url::to('/dashboard'));
            return;
        }

        // Duyuru Avcısı ("Otonom Sniper") kendi BAGIMSIZ butce sutununu (sniper_budget_percent)
        // kullanir - AI Avci'nin auto_trade_budget_percent'i ile ARTIK PAYLASILMAZ (13 Temmuz'da
        // ayni sutunu paylasmalari "kritik mantik hatasi" olarak raporlandi). POST edilmemisse
        // (eski/onbellekteki bir form gonderimi) mevcut deger dokunulmadan korunur
        if (isset($_POST['sniper_budget_percent'])) {
            $sniperBudgetPercent = (float) $_POST['sniper_budget_percent'];

            if ($sniperBudgetPercent < 1 || $sniperBudgetPercent > 100) {
                Session::set('advanced_modules_error', 'Sniper bütçesi, bakiyenin %1 ile %100\'ü arasında olmalıdır.');
                header('Location: ' . Url::to('/dashboard'));
                return;
            }

            ApiKey::updateSniperBudgetPercent($userId, $sniperBudgetPercent);
        }

        try {
            ApiKey::updateModuleToggles($userId, $socialRadarEnabled, $listingSniperEnabled, $smartMoneyEnabled, $dcaEnabled, $futuresTradingEnabled);

            if ($futuresTrailingSettingsSubmitted) {
                ApiKey::updateFuturesTrailingSettings($userId, $futuresTrailingStopEnabled, $futuresTrailingTriggerPercent, $futuresTrailingLockPercent, $futuresTrailingDistancePercent);
            }

            Session::set('advanced_modules_success', 'Gelişmiş modül ayarları güncellendi.');
        } catch (Throwable $e) {
            Session::set('advanced_modules_error', 'Ayarlar kaydedilirken bir hata oluştu.');
        }

        header('Location: ' . Url::to('/dashboard'));
    }

    // --- PERFORMANS: Dashboard'un ilk yüklemesini hızlandırmak için dış API'lere bağlı verileri
    // sayfa render edildikten SONRA, tarayıcının fetch() ile çektiği ayrı JSON uç noktaları ---

    // Cüzdan bakiyesi (Binance imzalı istek) - navbar'daki istatistik şeridine JS ile yazılır
    public function dataBalance(): void
    {
        $userId = $this->requireAjaxAuth();

        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        $existingKey = ApiKey::findByUser($userId)[0] ?? null;
        $usdtBalance = $existingKey !== null
            ? $this->fetchUsdtBalance($existingKey['api_key'], $existingKey['secret_key'])
            : null;

        echo json_encode([
            'connected' => $usdtBalance !== null,
            'balance' => number_format($usdtBalance ?? 0.0, 2),
        ], JSON_UNESCAPED_UNICODE);
    }

    // AI Radar taraması + piyasa nabzı yorumu (Binance tarama + OpenAI puanlama) - en yavaş dış
    // çağrı zinciri burada olduğu icin sayfa acilisindan tamamen ayrildi
    public function dataRadar(): void
    {
        $userId = $this->requireAjaxAuth();

        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        $radarScan = $this->fetchAiRadar();
        $marketPulse = $this->fetchMarketPulse($radarScan);

        echo json_encode([
            'radar' => $radarScan,
            'market_pulse' => $marketPulse,
        ], JSON_UNESCAPED_UNICODE);
    }

    // Kripto haber akışı (RSS + gerekirse OpenAI çevirisi)
    public function dataNews(): void
    {
        $userId = $this->requireAjaxAuth();

        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        echo json_encode(['items' => $this->fetchNews()], JSON_UNESCAPED_UNICODE);
    }

    // Açık pozisyonların anlık fiyat/ilerleme yüzdesi (Binance fiyat sorgusu, pozisyon başına 1 çağrı).
    // Pozisyon listesinin kendisi (parite/giriş/TP/SL) zaten sayfa ilk açıldığında yerel DB'den
    // senkron geldi - burada sadece "current_price"/"progress_percent" değerleri döner, JS bunlarla
    // ilgili pozisyon kartını trade id üzerinden eşleştirip günceller
    public function dataActiveTrades(): void
    {
        $userId = $this->requireAjaxAuth();

        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        $existingKey = ApiKey::findByUser($userId)[0] ?? null;
        $trades = $this->fetchActiveTrades($userId, $existingKey);

        $result = [];
        foreach ($trades as $trade) {
            $result[(int) $trade['id']] = [
                'current_price' => $trade['current_price'],
                'progress_percent' => $trade['progress_percent'],
            ];
        }

        // JSON_FORCE_OBJECT: trade id'leri (ör. 0,1,2) tesadufen ardisik tam sayi olursa PHP bunu
        // JS dizisiyle karistirmasin diye "trades" HER ZAMAN bir JS nesnesi ({}) olarak kodlanir,
        // boylece JS tarafinda trades[tradeId] seklinde guvenle anahtar bazli erisilebilir
        echo json_encode(['trades' => $result], JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
    }

    // Acik KISA (futures) pozisyonlarin anlik mark fiyati/likidasyon/gerceklesmemis PNL'ini doner -
    // dataActiveTrades'in (spot) futures karsiligi. getPositionRisk() Binance'ten TEK cagriyla
    // hem mark fiyatini hem likidasyon fiyatini hem de gerceklesmemis PNL'i birlikte getirir
    public function apiFuturesPositions(): void
    {
        $userId = $this->requireAjaxAuth();

        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        $existingKey = ApiKey::findByUser($userId)[0] ?? null;
        $trades = ActiveFuturesTrade::findOpenForUser($userId);
        $result = [];

        if ($existingKey !== null && $trades !== []) {
            try {
                $futures = new BinanceFuturesService($existingKey['api_key'], $existingKey['secret_key']);

                foreach ($trades as $trade) {
                    try {
                        $risk = $futures->getPositionRisk((string) $trade['pair']);
                        $entryPrice = (float) $trade['entry_price'];
                        $markPrice = $risk['mark_price'];

                        $result[(int) $trade['id']] = [
                            'mark_price' => $markPrice > 0 ? $markPrice : null,
                            'liquidation_price' => $risk['liquidation_price'] > 0 ? $risk['liquidation_price'] : null,
                            // SHORT: kar fiyat DUSTUKCE olusur - unrealized_profit alani zaten
                            // Binance'ten dogru yonde (short icin negatif/pozitif) geliyor
                            'unrealized_pnl_usdt' => round($risk['unrealized_profit'], 2),
                            'unrealized_pnl_pct' => $entryPrice > 0 && $markPrice > 0
                                ? round((($entryPrice - $markPrice) / $entryPrice) * 100, 2)
                                : null,
                        ];
                    } catch (Throwable $e) {
                        $result[(int) $trade['id']] = ['mark_price' => null, 'liquidation_price' => null, 'unrealized_pnl_usdt' => null, 'unrealized_pnl_pct' => null];
                    }
                }
            } catch (Throwable $e) {
                error_log('[apiFuturesPositions] ' . $e->getMessage());
            }
        }

        echo json_encode(['success' => true, 'trades' => $result], JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
    }

    // AJAX uc noktalari icin: oturum yoksa AuthMiddleware::handle() gibi HTML sayfasina yonlendirmez
    // (fetch() bunu JSON olarak ayristirmaya calisip sessizce bozulurdu) - bunun yerine duzgun bir
    // JSON 401 doner, boylece istemci tarafinda "oturum sonlandi" durumu net sekilde ele alinabilir
    private function requireAjaxAuth(): ?int
    {
        Session::start();

        if (!Session::has('user_id')) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Oturum sonlanmış.'], JSON_UNESCAPED_UNICODE);

            return null;
        }

        // 16 Temmuz'da tespit edildi: AuthMiddleware::handle() ile AYNI "banlanmis/pasife alinmis
        // kullanicinin acik oturumu gecerli kalmaya devam ediyor" acigi burada da vardi - bu metod
        // handle()'i hic cagirmadan KENDI (daha zayif) session-varligi kontrolunu yapiyordu. API
        // anahtari kaydetme/otomatik islem ayarlarini degistirme gibi hassas TUM AJAX uc noktalari
        // bu fonksiyondan geciyor, o yuzden AYNI taze durum kontrolu burada da uygulanir
        $userId = (int) Session::get('user_id');
        $user = User::findById($userId);

        if ($user === false || $user['status'] !== 'active') {
            Session::destroy();
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Oturum sonlanmış.'], JSON_UNESCAPED_UNICODE);

            return null;
        }

        return $userId;
    }

    // "Telegram'ı Bağla" deep link'inin gideceği bot kullanıcı adını (varsa) döner
    private function getTelegramBotUsername(): string
    {
        $dbValue = Setting::get('telegram_bot_username');

        if ($dbValue !== null) {
            return $dbValue;
        }

        $config = require __DIR__ . '/../../config/app.php';

        return (string) ($config['telegram_bot_username'] ?? '');
    }

    // Ekranda gosterilmeden once anahtarin sadece ilk ve son 5 hanesini birakip gerisini gizler
    private function maskApiKey(string $key): string
    {
        $length = strlen($key);

        if ($length <= 10) {
            return str_repeat('*', $length);
        }

        return substr($key, 0, 5) . '...' . substr($key, -5);
    }

    // Baglanti veya anahtar hatasinda null doner; view bunu "API Bagli Degil" olarak gosterir
    private function fetchUsdtBalance(string $apiKey, string $secretKey): ?float
    {
        try {
            $binance = new BinanceService($apiKey, $secretKey);
            $balances = $binance->getBalances();

            foreach ($balances as $balance) {
                if ($balance['asset'] === 'USDT') {
                    return $balance['free'];
                }
            }

            return 0.0;
        } catch (Throwable $e) {
            return null;
        }
    }

    // "AI Radar Analizi" bolumu icin canli tarama + gercek OpenAI duyarlilik skorlarini getirir
    // Salt okunurdur, hicbir islem acmaz; borsa erisilemezse null doner ve arayuz "cevrimdisi" gosterir
    private function fetchAiRadar(): ?array
    {
        $cached = Setting::getMeta(self::RADAR_CACHE_KEY);

        if ($cached !== null && strtotime((string) $cached['updated_at']) > time() - self::RADAR_CACHE_SECONDS) {
            $decoded = json_decode((string) $cached['value'], true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        try {
            // Tarama kapasitesi 10'dan 25'e cikarilinca, adaylar icin ardisik OpenAI cagrilarinin
            // toplam suresi (~25 x 1-3sn + throttle) paylasimli hostinglerin varsayilan PHP
            // max_execution_time'ini (genelde 30sn) asabiliyor - istek yarida kesilip dashboard'da
            // "Bağlantı Yok" gibi gorunuyordu. Bu istek ozelinde ust siniri guvenli bir paya cikarir
            set_time_limit(120);

            $scanner = new MarketScanner();
            $topMovers = $scanner->scanTopMovers();

            // 27 Temmuz'da degisti: GPT/OpenAI yerine AutoTradeController'daki asil alim kararini
            // veren AYNI Deterministik Motor (TechnicalScoreEngine RSI/MACD/hacim formulu) burada
            // sadece GORUNTULEME icin kullanilir - maliyet/gecikme kaldirildi. Esik degerleri
            // (skor>=70, MACD olumlu, hacim>=1.0) AutoTradeController::DETERMINISTIC_MOTOR_MIN_SCORE/
            // DETERMINISTIC_MOTOR_MIN_VOLUME_DELTA ile SENKRON tutulmali - biri degisirse digeri de
            // guncellenmeli, aksi halde Radar'in "AL" isareti asil botun karariyla uyusmaz
            $analyses = [];

            foreach ($topMovers as $mover) {
                $symbol = $mover['symbol'];

                try {
                    $rsi1h = $scanner->calculateRsi($symbol);
                    $technicalScore = $scanner->calculateTechnicalScore($symbol, (float) $mover['priceChangePercent'], $rsi1h);

                    if ($technicalScore === null) {
                        $analyses[] = ['score' => 0, 'is_buy_signal' => false, 'reason' => 'Yetersiz mum verisi'];
                        continue;
                    }

                    $analyses[] = [
                        'score' => (int) round($technicalScore['score']),
                        'is_buy_signal' => $technicalScore['score'] >= 70
                            && $technicalScore['macd_positive'] === true
                            && ($technicalScore['volume_delta'] === null || $technicalScore['volume_delta'] >= 1.0),
                        'reason' => $technicalScore['reason'],
                    ];
                } catch (Throwable $e) {
                    // Tek bir sembolun Binance/hesaplama hatasi TUM Radar'i iptal etmesin -
                    // notr (SentimentService'in eski fail-open davranisiyla ayni sekilli) sonuc dondurulur
                    $analyses[] = ['score' => 0, 'is_buy_signal' => false, 'reason' => ''];
                }
            }

            $merged = [];

            foreach ($topMovers as $index => $mover) {
                $merged[] = $mover + ($analyses[$index] ?? ['score' => 0, 'is_buy_signal' => false]);
            }

            usort($merged, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

            Setting::set(self::RADAR_CACHE_KEY, json_encode($merged, JSON_UNESCAPED_UNICODE));

            return $merged;
        } catch (Throwable $e) {
            return null;
        }
    }

    // AI Radar verisine dayanarak 2-3 cumlelik "genel piyasa nabzi" yorumu getirir (Haberler panelinde gosterilir)
    // SentimentService kendi icinde 15 dakika onbellekliyor, bu yuzden her sayfa yuklemesi ekstra OpenAI cagrisi yapmaz
    private function fetchMarketPulse(?array $radarScan): ?string
    {
        if ($radarScan === null || $radarScan === []) {
            return null;
        }

        try {
            $sentiment = new SentimentService();

            return $sentiment->generateMarketPulse($radarScan);
        } catch (Throwable $e) {
            return null;
        }
    }

    // "Haberler" panelindeki akan ticker icin gercek kripto haber basliklerini getirir
    private function fetchNews(): array
    {
        try {
            $news = new NewsService();

            return $news->fetchLatest();
        } catch (Throwable $e) {
            return [];
        }
    }

    // Bot log akışı: son N satırı döndürür (AI Monolog widget'i için) — eski compat endpoint
    public function dataBotLogs(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');
        echo json_encode(['logs' => BotLog::findRecent(5)], JSON_UNESCAPED_UNICODE);
    }

    // ==========================================================
    // /api/dashboard/* — Yeni, güçlendirilmiş JSON uç noktaları
    // Her biri: başarısızlıkta 500 yerine {success:false, message, data:[]} döner
    // ==========================================================

    public function apiBalance(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        try {
            $existingKey = ApiKey::findByUser($userId)[0] ?? null;

            if ($existingKey === null) {
                echo json_encode(['success' => false, 'message' => 'API Anahtarı Eksik', 'data' => []], JSON_UNESCAPED_UNICODE);
                return;
            }

            $balance = $this->fetchUsdtBalance($existingKey['api_key'], $existingKey['secret_key']);

            if ($balance === null) {
                echo json_encode(['success' => false, 'message' => 'Borsa Bağlantısı Başarısız', 'data' => []], JSON_UNESCAPED_UNICODE);
                return;
            }

            echo json_encode([
                'success'   => true,
                'connected' => true,
                'balance'   => number_format($balance, 2),
                'currency'  => 'USDT',
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[apiBalance] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Sistem Hatası', 'data' => []], JSON_UNESCAPED_UNICODE);
        }
    }

    public function apiRadar(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        try {
            $radarScan   = $this->fetchAiRadar();
            $marketPulse = $this->fetchMarketPulse($radarScan);

            if ($radarScan === null) {
                echo json_encode(['success' => false, 'message' => 'Piyasa verisi alınamadı', 'data' => []], JSON_UNESCAPED_UNICODE);
                return;
            }

            echo json_encode([
                'success'      => true,
                'radar'        => $radarScan,
                'market_pulse' => $marketPulse,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[apiRadar] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Radar Taraması Başarısız', 'data' => []], JSON_UNESCAPED_UNICODE);
        }
    }

    public function apiNews(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        try {
            echo json_encode(['success' => true, 'items' => $this->fetchNews()], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[apiNews] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Haber akışı alınamadı', 'data' => []], JSON_UNESCAPED_UNICODE);
        }
    }

    // Canli Savas Radari: modal ILK acildiginda BIR KEZ cagirilir - mum gecmisi + pozisyon
    // hedefleri doner. Sonraki canli guncellemeler (anlik fiyat/SL) icin AYRI bir "tick" ucnoktasi/
    // interval YOK - zaten var olan apiHunts() 5 saniyede bir poll edildigi icin (bkz. dashboard/
    // index.php fetchActiveTrades()), grafik o AYNI donguye "biniyor" - Binance'e ekstra yuk
    // BINDIRILMEZ, "API bani yememek" kaygisi zaten kurulu mekanizmayla karsilanmis olur
    public function apiLiveChart(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        $tradeId = (int) ($_GET['trade_id'] ?? 0);

        if ($tradeId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $trade = ActiveTrade::findById($tradeId);

            // Sahiplik kontrolu: baska bir kullanicinin pozisyon ID'sini tahmin ederek onun
            // grafigini/hedeflerini gormesi KESINLIKLE engellenir
            if ($trade === null || (int) $trade['user_id'] !== $userId || $trade['status'] !== 'open') {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Pozisyon bulunamadı.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Public (imzasiz) istemci - sadece genel piyasa mumu cekilecek, hesaba ozel hicbir
            // veri gerekmiyor, kullanicinin gercek API anahtarini cozmeye/kullanmaya GEREK yok
            $publicClient = new BinanceService('', '');
            $klines = $publicClient->getKlines((string) $trade['pair'], '1m', 50);

            // Izleyen Stop Tetik Seviyesi: fiyat bu noktaya ulasirsa Zarar Kes yukari cekilmeye
            // baslar (bkz. AutoTradeController::applyDiscreteTrailingStage). Duyuru Avcisi (sniper)
            // pozisyonlari SABIT %10 tetik kullanir (SNIPER_TRAILING_STOP_STAGES), digerleri
            // kullanicinin KENDI dashboard'dan ayarladigi trailing_trigger_percent'i kullanir - bkz.
            // AutoTradeController::applyTrailingStopIfEligible() ile AYNI ayrim. trailing_stop_stage
            // zaten >= 1 ise (tetik ZATEN gecildi) bu artik "gelecek" bir seviye degildir, gosterilmez
            $trailingTriggerPrice = null;

            if ((int) ($trade['trailing_stop_stage'] ?? 0) === 0) {
                $parentOrder = $trade['buy_order_id'] !== null ? Order::findById((int) $trade['buy_order_id']) : null;
                $isSniper = ($parentOrder['strategy_bucket'] ?? null) === 'announcement_hunter';
                $triggerPercent = $isSniper ? 10.0 : ApiKey::getTrailingSettings($userId)['trailing_trigger_percent'];
                $trailingTriggerPrice = (float) $trade['entry_price'] * (1 + $triggerPercent / 100);
            }

            echo json_encode([
                'success' => true,
                'pair' => $trade['pair'],
                'entry_price' => (float) $trade['entry_price'],
                'take_profit_price' => (float) $trade['take_profit_price'],
                'stop_loss_price' => (float) $trade['stop_loss_price'],
                'take_profit_removed' => (int) ($trade['take_profit_removed'] ?? 0) === 1,
                'trailing_trigger_price' => $trailingTriggerPrice,
                'klines' => $klines,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('NexaTrade apiLiveChart: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Grafik verisi alınamadı.'], JSON_UNESCAPED_UNICODE);
        }
    }

    public function apiHunts(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        try {
            $existingKey = ApiKey::findByUser($userId)[0] ?? null;
            $trades      = $this->fetchActiveTrades($userId, $existingKey);

            $result = [];
            foreach ($trades as $trade) {
                $result[(int) $trade['id']] = [
                    // Kart iskeletini (parite/giris/TP) sayfa yenilenmeden JS ile insa edebilmek icin -
                    // bkz. dashboard/index.php'deki syncHuntCards() - eskiden bu alanlar SADECE ilk
                    // sayfa yuklemesinde PHP tarafinda render ediliyordu, sonradan acilan bir pozisyon
                    // tam sayfa yenilemesi olmadan HICBIR ZAMAN listede gorunmuyordu
                    'pair'                => $trade['pair'],
                    'entry_price'         => $trade['entry_price'],
                    'take_profit_price'   => $trade['take_profit_price'],
                    'current_price'       => $trade['current_price'],
                    'progress_percent'    => $trade['progress_percent'],
                    'unrealized_pnl_usdt' => $trade['unrealized_pnl_usdt'] ?? null,
                    'unrealized_pnl_pct'  => $trade['unrealized_pnl_pct']  ?? null,
                    // Izleyen Zirh durumu: 0=henuz pasif (+%1.5 esigi gorulmedi), >=1=aktif
                    // (kar kilitlendi, artik surekli izlemede) - bkz. AutoTradeController::TRAILING_STOP_STAGES
                    'trailing_stop_stage' => (int) ($trade['trailing_stop_stage'] ?? 0),
                    'stop_loss_price'     => $trade['stop_loss_price'] ?? null,
                    // Kar Al Tavanini Kaldirma: sayfa acikken Sinirsiz Izleme'ye gecen bir pozisyonun
                    // JS tarafinda sayfa yenilenmeden "Sinirsiz (∞)" rozetine donusebilmesi icin -
                    // bkz. dashboard/index.php'deki updateTakeProfitBadge()
                    'take_profit_removed' => (int) ($trade['take_profit_removed'] ?? 0) === 1,
                ];
            }

            echo json_encode(['success' => true, 'trades' => $result], JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
        } catch (Throwable $e) {
            error_log('[apiHunts] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Pozisyon verisi alınamadı', 'data' => []], JSON_UNESCAPED_UNICODE);
        }
    }

    // "Son İşlemler" panelinin JS ile sayfa yenilenmeden guncellenebilmesi icin - bu panel eskiden
    // SADECE ilk sayfa yuklemesinde $recentOrders ile PHP tarafinda render ediliyordu, manuel kapatma
    // gibi sayfa acikken olusan yeni bir siparis F5 atilmadan asla gorunmuyordu
    public function apiRecentOrders(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        try {
            $orders = Order::findRecentByUser($userId, 10);
            echo json_encode(['success' => true, 'orders' => $orders], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[apiRecentOrders] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'İşlem geçmişi alınamadı', 'orders' => []], JSON_UNESCAPED_UNICODE);
        }
    }

    public function apiLogs(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        try {
            $logs = BotLog::findRecent(5);

            // bot_logs TUM kullanicilar arasinda PAYLASILAN tek bir tablo (her satir bir tarama
            // turunu temsil eder, bir kullaniciyi degil) - positions_opened SADECE o turde pozisyon
            // acilan TOPLAM kullanici sayisidir, hangi kullanici(lar) oldugunu belirtmez. "POZİSYON
            // AÇILDI" yazisinin SADECE gercekten bu kullanici icin gecerliyken gorunmesi icin, her
            // satir bu kullanicinin KENDI order gecmisiyle capraz kontrol edilir (bkz.
            // Order::hasFilledBuyNear) - 10 Temmuz'da bulunan capraz kullanici veri sizintisi duzeltmesi
            foreach ($logs as &$log) {
                $selectedSymbol = $log['selected_symbol'] ?? null;

                $log['opened_for_me'] = $selectedSymbol !== null
                    && (int) ($log['positions_opened'] ?? 0) > 0
                    && Order::hasFilledBuyNear($userId, (string) $selectedSymbol, (string) $log['run_at']);
            }
            unset($log);

            echo json_encode(['success' => true, 'logs' => $logs], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[apiLogs] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Log verisi alınamadı', 'data' => []], JSON_UNESCAPED_UNICODE);
        }
    }

    // "Görünmez Kalkan" (AI Kalkanı) widget'ı için: botun MTF/Emir Defteri gibi sert filtrelerle
    // engellediği tuzakların son 5 kaydını döner. ai_interventions TUM kullanicilar arasinda
    // PAYLASILAN, global (piyasa capinda) bir tablo - AI Monolog'daki cross-user sizinti bug'indan
    // (bkz. apiLogs) FARKLI olarak burada bir "musteriye ozel eylem" iddiasi YOK, sadece "piyasada
    // su tuzak tespit edildi" bilgisi paylasiliyor - dolayisiyla ekstra bir capraz kontrole gerek yok
    public function apiInterventions(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        try {
            echo json_encode(['success' => true, 'interventions' => AiIntervention::findRecent(5)], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[apiInterventions] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Veri alınamadı', 'interventions' => []], JSON_UNESCAPED_UNICODE);
        }
    }

    public function apiPnl(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        try {
            $summary = Order::calculatePnlSummary($userId);

            // Realized P&L (yukarida) sadece KAPANMIS (satilmis) islemleri sayar; hala acik olan
            // pozisyonlarin kagit uzerindeki kar/zarari eklenmezse gercek durumu yanlis yansitir
            // (ör. yeni acilan bir pozisyon, henuz satilmadigi icin "tam zarar" gibi gorunur).
            // Bu yuzden acik pozisyonlarin guncel kar/zararini da ayni pencerelere ekliyoruz.
            $unrealizedPnl = $this->calculateUnrealizedPnl($userId);

            $summary['daily_profit']   = round($summary['daily_profit'] + $unrealizedPnl, 2);
            $summary['weekly_profit']  = round($summary['weekly_profit'] + $unrealizedPnl, 2);
            $summary['monthly_profit'] = round($summary['monthly_profit'] + $unrealizedPnl, 2);

            echo json_encode(['success' => true] + $summary, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[apiPnl] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'P&L verisi alınamadı', 'data' => []], JSON_UNESCAPED_UNICODE);
        }
    }

    // "Geçmiş İşlemler" modalı: gunluk/haftalik/aylik secime gore ozet seridi (Order::calculatePeriodSummary)
    // + ayni pencerede kalan ham islem listesi (Order::findByUserInPeriod) - "Son İşlemler" tablosunun
    // sadece 10 kayitla sinirli goruntusunun aksine, secilen donemin TAMAMINI (500 kayda kadar) gosterir
    public function apiOrderHistory(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        try {
            $daysMap = ['daily' => 1, 'weekly' => 7, 'monthly' => 30];
            $period = (string) ($_GET['period'] ?? 'daily');
            $days = $daysMap[$period] ?? 1;

            $summary = Order::calculatePeriodSummary($userId, $days);
            $orders = Order::findByUserInPeriod($userId, $days);

            echo json_encode([
                'success' => true,
                'period'  => $period,
                'summary' => $summary,
                'orders'  => $orders,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[apiOrderHistory] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Geçmiş işlemler alınamadı'], JSON_UNESCAPED_UNICODE);
        }
    }

    // Acik pozisyonlarin (henuz satilmamis) guncel fiyatla kagit uzerindeki toplam kar/zarari -
    // apiPortfolio'daki ile ayni hesap mantigi, PNL ozetinin gercek durumu yansitmasi icin
    private function calculateUnrealizedPnl(int $userId): float
    {
        $existingKey = ApiKey::findByUser($userId)[0] ?? null;
        $trades = ActiveTrade::findOpenForUser($userId);

        if ($existingKey === null || $trades === []) {
            return 0.0;
        }

        $unrealizedPnl = 0.0;

        try {
            $binance = new BinanceService($existingKey['api_key'], $existingKey['secret_key']);

            foreach ($trades as $trade) {
                try {
                    $price = $binance->getPrice((string) $trade['pair']);
                    $qty = (float) $trade['quantity'];
                    $unrealizedPnl += ($price - (float) $trade['entry_price']) * $qty;
                } catch (Throwable) {
                }
            }
        } catch (Throwable) {
        }

        return $unrealizedPnl;
    }

    public function apiRiskProfile(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        $profile = trim((string) ($_POST['profile'] ?? ''));
        // '' = henuz belirtilmedi, 'overwrite' = kullanici ozel SL'in profil degerine sifirlanmasini
        // onayladi, 'preserve' = kullanici ozel SL'ine DOKUNULMAMASINI istedi (sadece diger alanlar guncellensin)
        $stopLossAction = trim((string) ($_POST['stop_loss_action'] ?? ''));

        if (!RiskProfileService::isValid($profile)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Geçersiz risk profili.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            // 12 Temmuz'da tespit edilen "sessiz ezme" bug'ı: bu kart, kullanıcının "AI Avcı
            // Ayarları" formundan ELLE girdiği bir Zarar Kes değerini hiçbir uyarı olmadan
            // profilin sabit değerine sıfırlıyordu. Artık mevcut değer 3 standart profil
            // değerinden hiçbirine denk gelmiyorsa (yani özelleştirilmişse) VE kullanıcı henüz
            // bir tercih belirtmediyse, HİÇBİR ŞEY DEĞİŞTİRİLMEDEN önce onay istenir
            $existingKey = ApiKey::findByUser($userId)[0] ?? null;
            $currentStopLoss = $existingKey !== null ? (float) $existingKey['stop_loss_percent'] : null;
            $hasCustomStopLoss = $currentStopLoss !== null && RiskProfileService::isCustomStopLoss($currentStopLoss);

            if ($hasCustomStopLoss && $stopLossAction === '') {
                echo json_encode([
                    'success'            => false,
                    'needs_confirmation' => true,
                    'current_stop_loss'  => $currentStopLoss,
                    'profile_stop_loss'  => RiskProfileService::get($profile)['stop_loss_percent'],
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $preserveCustomStopLoss = $hasCustomStopLoss && $stopLossAction === 'preserve';

            ApiKey::updateRiskProfile($userId, $profile, $preserveCustomStopLoss);
            $meta = RiskProfileService::get($profile);
            echo json_encode([
                'success'                     => true,
                'profile'                     => $profile,
                'ai_score_threshold'          => $meta['ai_score_threshold'],
                'stop_loss_percent'           => $preserveCustomStopLoss ? $currentStopLoss : $meta['stop_loss_percent'],
                'max_active_trades'           => $meta['max_active_trades'],
                'preserved_custom_stop_loss'  => $preserveCustomStopLoss,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[apiRiskProfile] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Risk profili kaydedilemedi.'], JSON_UNESCAPED_UNICODE);
        }
    }

    public function apiPortfolio(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        try {
            $existingKey = ApiKey::findByUser($userId)[0] ?? null;
            if ($existingKey === null) {
                echo json_encode(['success' => false, 'message' => 'API anahtarı yok'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $usdtBalance = $this->fetchUsdtBalance($existingKey['api_key'], $existingKey['secret_key']);
            $trades      = ActiveTrade::findOpenForUser($userId);

            // KRITIK DUZELTME (10 Temmuz): $usdtBalance null ise (Binance erisilemez/IP ban) bu blok
            // atlanir ve $positionsValue/$unrealizedPnl 0.0'da KALIRDI - panel bunu gercek "$0.00"
            // gibi gosterip, borsaya hic erisilemedigi halde yaniltici bir sekilde "kar/zarar yok"
            // izlenimi veriyordu. Artik API erisilemezken bu iki alan de NULL doner (bilinmiyor),
            // "0" (biliniyor ve gercekten sifir) ile "?" (bilinmiyor) ayrimi JS tarafinda yapilabilsin
            $positionsValue = null;
            $unrealizedPnl  = null;

            if ($trades !== [] && $usdtBalance !== null) {
                $positionsValue = 0.0;
                $unrealizedPnl  = 0.0;

                try {
                    $binance = new BinanceService($existingKey['api_key'], $existingKey['secret_key']);
                    foreach ($trades as $trade) {
                        try {
                            $price          = $binance->getPrice((string) $trade['pair']);
                            $qty            = (float) $trade['quantity'];
                            $positionsValue += $price * $qty;
                            $unrealizedPnl  += ($price - (float) $trade['entry_price']) * $qty;
                        } catch (Throwable) {}
                    }
                } catch (Throwable) {}
            } elseif ($trades === []) {
                // Acik pozisyon gercekten yok (DB'den, Binance'ten bagimsiz dogrulandi) - bu durumda
                // 0.0 GERCEKTEN dogru bir deger, "bilinmiyor" degil
                $positionsValue = 0.0;
                $unrealizedPnl  = 0.0;
            }

            // 22 Temmuz'da eklendi: PORTFÖY toplami eskiden SADECE spot'u yansitiyordu, futures
            // cuzdanindaki para ve acik futures pozisyonunun anlik kar/zarari hic sayilmiyordu.
            // BILINCLI TASARIM: bu blok spot'un aksine BASARISIZ OLURSA sessizce 0 katkida bulunur
            // (null DEGIL) - cunku futures spot'a "eklenen" opsiyonel bir bonus, spot'un KENDI
            // bakiyesi gibi "olmazsa hicbir sey bilinmiyor" durumu degil. Boylece futures API'sinde
            // bir sorun (kullanici futures acmamis, izin yok vb.) olursa panel EN KOTU ihtimalle
            // bugunku (spot-only) haline doner, asla "?" gosterip mevcut davranisi BOZMAZ
            $futuresValue = 0.0;

            try {
                $futuresTrades = ActiveFuturesTrade::findOpenForUser($userId);
                $futuresApi    = new BinanceFuturesService($existingKey['api_key'], $existingKey['secret_key']);
                $futuresValue  = $futuresApi->getWalletBalance();

                foreach ($futuresTrades as $futuresTrade) {
                    try {
                        $risk         = $futuresApi->getPositionRisk((string) $futuresTrade['pair']);
                        $futuresValue += $risk['unrealized_profit'];
                    } catch (Throwable) {}
                }
            } catch (Throwable) {}

            echo json_encode([
                'success'         => true,
                'api_connected'   => $usdtBalance !== null,
                'usdt_balance'    => $usdtBalance !== null ? round($usdtBalance, 2) : null,
                'positions_value' => $positionsValue !== null ? round($positionsValue, 2) : null,
                'futures_value'   => round($futuresValue, 2),
                'total_value'     => $usdtBalance !== null ? round($usdtBalance + $positionsValue + $futuresValue, 2) : null,
                'unrealized_pnl'  => $unrealizedPnl !== null ? round($unrealizedPnl, 2) : null,
                'open_count'      => count($trades),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[apiPortfolio] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Portföy verisi alınamadı'], JSON_UNESCAPED_UNICODE);
        }
    }

    public function apiScanStatus(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        try {
            $latest = BotLog::findLatest();
            if ($latest === null) {
                echo json_encode(['success' => true, 'has_data' => false], JSON_UNESCAPED_UNICODE);
                return;
            }

            $aiScores = json_decode((string) ($latest['ai_scores'] ?? '[]'), true) ?? [];
            usort($aiScores, static fn (array $a, array $b): int => (int) $b['score'] <=> (int) $a['score']);

            echo json_encode([
                'success'          => true,
                'has_data'         => true,
                'run_at'           => $latest['run_at'],
                'selected_symbol'  => $latest['selected_symbol'],
                'selected_score'   => $latest['selected_score'],
                'positions_opened' => (int) $latest['positions_opened'],
                'top_scores'       => array_slice($aiScores, 0, 5),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[apiScanStatus] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Tarama durumu alınamadı'], JSON_UNESCAPED_UNICODE);
        }
    }

    // "Performans Analizi" modalı: hangi stratejinin ve hangi paritenin gercekten kazandirdigini
    // gosterir - Order::calculateStrategyBreakdown() / calculateSymbolBreakdown() tek istekte
    public function apiPerformanceBreakdown(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        try {
            echo json_encode([
                'success'   => true,
                'strategies' => Order::calculateStrategyBreakdown($userId),
                'symbols'    => Order::calculateSymbolBreakdown($userId),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[apiPerformanceBreakdown] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Performans verisi alınamadı'], JSON_UNESCAPED_UNICODE);
        }
    }

    // Manuel Kill Switch: Sistem Durumu modalindaki acil durum anahtari - kullanici/yonetici HICBIR
    // zarari beklemeden istedigi an TUM otonom modulleri (spot+futures) durdurabilir/tekrar acabilir.
    // ApiKey::setManualKillSwitch() sadece bu bayragi yazar - RiskManagerService::checkCircuitBreaker()/
    // checkFuturesCircuitBreaker() bunu HER yeni-islem denemesinden ONCE kontrol eder (bkz. o metodun
    // yorumu), acik pozisyon izleme/koruma bu kontrolden GECMEDIGI icin ETKILENMEZ
    public function apiToggleKillSwitch(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        try {
            $enabled = ($_POST['enabled'] ?? '0') === '1';

            ApiKey::setManualKillSwitch($userId, $enabled);

            echo json_encode(['success' => true, 'enabled' => $enabled], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[apiToggleKillSwitch] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Kill Switch güncellenemedi'], JSON_UNESCAPED_UNICODE);
        }
    }

    // Sistem Durumu modalindaki "Sembol Soğuması" listesinden musterinin KENDI istegiyle bir kilidi
    // manuel kaldirmasi icin (POST - durum degistiren bir eylem). SymbolCooldown::clear() sadece o
    // (kullanici, sembol) kilidini siler - gecmis islem kayitlarina HIC dokunmaz, botun kendi
    // otomatik soguma mantigina (Zarar Kes/Erken Kacis) da dokunmaz, bir sonraki zararda kilit
    // normal sekilde yeniden kurulur
    public function apiClearSymbolCooldown(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        try {
            $pair = trim((string) ($_POST['pair'] ?? ''));

            if ($pair === '') {
                echo json_encode(['success' => false, 'message' => 'Parite belirtilmedi'], JSON_UNESCAPED_UNICODE);
                return;
            }

            SymbolCooldown::clear($userId, $pair);

            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[apiClearSymbolCooldown] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Soğuma kaldırılamadı'], JSON_UNESCAPED_UNICODE);
        }
    }

    // "Aktif Avlar" panelindeki manuel "Şimdi Kapat" butonu (30 Temmuz'da eklendi) - müşteri
    // yüksek bir kâr görüp otomatik hedefi (İzleyen Stop/Kâr Al) beklemeden anında piyasadan
    // satıp kilitlemek isteyebilir. Mevcut koruma emrini (OCO veya Kâr Al Tavanı Kaldırılmışsa
    // tekil Zarar Kes) iptal edip piyasadan satar, ardından AutoTradeController::finalizeSpotClose()
    // ile AYNI mekanizmayla (gerçek PNL/loglama/bildirim/soğuma) kapatır - koddaki TEK kapanış
    // yolu burada TEKRAR YAZILMAZ, doğrudan çağrılır
    public function apiClosePosition(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        try {
            $tradeId = (int) ($_POST['trade_id'] ?? 0);
            $trade = ActiveTrade::findById($tradeId);

            if ($trade === null || (int) $trade['user_id'] !== $userId || $trade['status'] !== 'open') {
                echo json_encode(['success' => false, 'message' => 'Pozisyon bulunamadı veya zaten kapalı'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $apiKeys = ApiKey::findByUser($userId);

            if ($apiKeys === []) {
                echo json_encode(['success' => false, 'message' => 'API anahtarı bulunamadı'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $binance = new BinanceService($apiKeys[0]['api_key'], $apiKeys[0]['secret_key']);
            $pair = (string) $trade['pair'];
            $quantity = (float) $trade['quantity'];

            // Mevcut korumayi iptal et - OCO ya da (Kar Al Tavani Kaldirilmissa) tekil Zarar Kes.
            // Ikisi de yoksa (zaten korumasiz kalmis bir pozisyon) dogrudan satisa gecilir
            if ($trade['oco_order_list_id'] !== null) {
                $binance->cancelOcoOrder($pair, (int) $trade['oco_order_list_id']);
            } elseif ($trade['stop_loss_order_id'] !== null) {
                $binance->cancelOrder($pair, (int) $trade['stop_loss_order_id']);
            }

            $marketSellResult = $binance->placeOrder($pair, 'SELL', 'MARKET', $quantity);

            if (!$marketSellResult['success']) {
                echo json_encode(['success' => false, 'message' => 'Piyasa satışı başarısız: ' . $marketSellResult['error']], JSON_UNESCAPED_UNICODE);
                return;
            }

            $raw = $marketSellResult['raw'] ?? [];
            $executedQty = (float) ($raw['executedQty'] ?? $quantity);
            $cumulativeQuote = (float) ($raw['cummulativeQuoteQty'] ?? 0);
            $exitPrice = $executedQty > 0 ? $cumulativeQuote / $executedQty : 0.0;
            $exitTotal = $cumulativeQuote > 0 ? $cumulativeQuote : $exitPrice * $executedQty;

            // PERFORMANS (31 Temmuz, musteri talebi): satis Binance'te GERCEKLESTIKTEN hemen sonra
            // tarayiciya aninda cevap donulur - finalizeSpotClose()'un geri kalani (komisyon sorgusu,
            // Trade Post-Mortem analizi, Telegram bildirimi - hepsi birer ag cagrisi) fastcgi_finish_
            // request() SONRASINDA, kullanicinin "Simdi Kapat" butonuna tikladigi anla arasinda hicbir
            // katkisi yok. Asil kapanis (Binance market satisi) zaten YUKARIDA tamamlandi - burasi
            // sadece kayit/bildirim, gecikmesi kullaniciyi bekletmemeli. function_exists() korumasi:
            // bu fonksiyon sadece PHP-FPM altinda var, yoksa senkron devam eder (davranis DEGISMEZ,
            // sadece hizlanma olmaz)
            echo json_encode(['success' => true, 'exit_price' => $exitPrice], JSON_UNESCAPED_UNICODE);

            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            (new AutoTradeController())->finalizeSpotClose(
                $binance,
                $trade,
                $exitPrice,
                $executedQty,
                $exitTotal,
                $marketSellResult['order_id'] !== null ? (int) $marketSellResult['order_id'] : null,
                'manual_close'
            );
        } catch (Throwable $e) {
            error_log('[apiClosePosition] ' . $e->getMessage());
            // fastcgi_finish_request() zaten cagrildiysa tarayici bu echo'yu asla gormez (baglanti
            // zaten kapandi) - ama Binance market satisindan SONRAKI bir adimda (finalizeSpotClose
            // ici) hata olursa bu artik sessiz kalmamali: criticalPersist() zaten kendi notifyAdmin
            // AndCustomer'ini tetikler, burasi sadece error_log icin ek bir guvenlik agi
            echo json_encode(['success' => false, 'message' => 'Pozisyon kapatılamadı'], JSON_UNESCAPED_UNICODE);
        }
    }

    // "Sistem Durumu" widget'i: her otonom modulun gercekten canli olup olmadigini (son calisma
    // zamani), bu kullanicinin devre kesici durumunu ve log dosyalarindaki son kritik hatalari tek
    // istekte doner - onceden bunlarin hepsi cPanel Terminal'e girip elle log/DB sorgulamayi
    // gerektiriyordu. Modul tazeligi: spot icin bot_logs (zaten her turda kosulsuz yazilir),
    // digerleri icin run()'in en basina eklenen kosulsuz Setting damgalari (bkz. ListingSniperService/
    // SmartMoneyTracker/FuturesTradingService::run())
    public function apiSystemStatus(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        try {
            $pdo = Database::getInstance();
            $now = time();

            $lastSpotRunAt = $pdo->query("SELECT UNIX_TIMESTAMP(MAX(run_at)) FROM bot_logs WHERE trade_type = 'spot'")->fetchColumn();

            $moduleDefs = [
                'spot'           => ['label' => 'AI Avcı (Spot)', 'last_run_at' => $lastSpotRunAt !== false ? $lastSpotRunAt : null, 'stale_after_seconds' => 600],
                'listing_sniper' => ['label' => 'Duyuru Avcısı', 'last_run_at' => Setting::get('listing_sniper_last_run_at'), 'stale_after_seconds' => 300],
                'smart_money'    => ['label' => 'Akıllı Para Kopyalayıcı', 'last_run_at' => Setting::get('smart_money_last_run_at'), 'stale_after_seconds' => 900],
                'futures'        => ['label' => 'Futures (Kısa)', 'last_run_at' => Setting::get('futures_last_run_at'), 'stale_after_seconds' => 300],
            ];

            $modules = [];
            foreach ($moduleDefs as $key => $def) {
                $lastRunAt = $def['last_run_at'] !== null ? (int) $def['last_run_at'] : null;
                $secondsAgo = $lastRunAt !== null ? ($now - $lastRunAt) : null;

                $modules[$key] = [
                    'label'        => $def['label'],
                    'last_run_at'  => $lastRunAt,
                    'seconds_ago'  => $secondsAgo,
                    // null (hic calismamis) VEYA beklenen araligin 2 katindan fazla gecikmis ise "durgun"
                    'healthy'      => $secondsAgo !== null && $secondsAgo <= $def['stale_after_seconds'],
                ];
            }

            $circuitBreakerUntil = ApiKey::getCircuitBreakerStatus($userId);

            $criticalErrors = $this->collectRecentCriticalErrors();

            $existingKey = ApiKey::findByUser($userId)[0] ?? null;
            $riskLimit = $existingKey !== null ? $this->calculateRiskLimitUsage($userId, $existingKey) : null;

            $symbolCooldowns = SymbolCooldown::findActiveForUser($userId);
            $manualKillSwitch = $existingKey !== null && (int) ($existingKey['manual_kill_switch'] ?? 0) === 1;

            echo json_encode([
                'success'                 => true,
                'modules'                 => $modules,
                'circuit_breaker_until'   => $circuitBreakerUntil,
                'recent_critical_errors'  => $criticalErrors,
                'risk_limit'              => $riskLimit,
                'symbol_cooldowns'        => $symbolCooldowns,
                'manual_kill_switch'      => $manualKillSwitch,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[apiSystemStatus] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Sistem durumu alınamadı'], JSON_UNESCAPED_UNICODE);
        }
    }

    // Her log dosyasinin TAMAMINI degil, sadece son birkaç KB'ini okur - auto_trade.log gibi birkaç
    // MB'lik dosyalar HER dashboard pollinginde (60sn) tam yuklenip performansi/RAM'i zorlamasin diye
    private function tailFile(string $path, int $maxBytes = 6000): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $size = filesize($path);
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        fseek($handle, max(0, $size - $maxBytes));
        $chunk = fread($handle, $maxBytes);
        fclose($handle);

        $lines = explode("\n", (string) $chunk);
        // Okumaya dosyanin ortasindan basladigimiz icin ilk satir kesik olabilir, atilir
        array_shift($lines);

        return array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));
    }

    // Sistem Durumu widget'i icin "Risk Limiti" gostergesi: RiskManagerService::checkCircuitBreaker()/
    // checkFuturesCircuitBreaker()'daki AYNI formul (kayan 24sn zarar / toplam ozkaynak * max_daily_loss_percent)
    // ama GORUNTULEME icin - hicbir engelleme kararini BURADAN vermez, sadece kullaniciya "ne kadar
    // yaklastin" gosterir. Ozkaynak spot+futures BIRLESTIRILMIS (iki modulun kendi ayri check'lerinde
    // KULLANDIGI taban farkli olabilir - bkz. AutoTradeController/FuturesTradingService'teki cagri
    // noktalari - burada TEK bir birlesik sayi ureterek "toplam ne kadar riskim var" sorusuna cevap verilir)
    private function calculateRiskLimitUsage(int $userId, array $existingKey): ?array
    {
        $maxDailyLossPercent = (float) $existingKey['max_daily_loss_percent'];

        if ($maxDailyLossPercent <= 0) {
            return null;
        }

        $spotBalance = $this->fetchUsdtBalance($existingKey['api_key'], $existingKey['secret_key']);
        if ($spotBalance === null) {
            return null;
        }

        $spotOpenCost = 0.0;
        foreach (ActiveTrade::findOpenForUser($userId) as $trade) {
            $spotOpenCost += (float) $trade['entry_price'] * (float) $trade['quantity'];
        }

        $futuresEquity = 0.0;
        try {
            $futures = new BinanceFuturesService($existingKey['api_key'], $existingKey['secret_key']);
            $futuresMargin = 0.0;
            foreach (ActiveFuturesTrade::findOpenForUser($userId) as $trade) {
                $leverage = max(1, (int) $trade['leverage']);
                $notional = (float) $trade['entry_price'] * (float) $trade['quantity'];
                $futuresMargin += $notional / $leverage;
            }
            $futuresEquity = $futures->getWalletBalance() + $futuresMargin;
        } catch (Throwable) {
            // Futures izni yok/erisilemedi - sessizce spot-only'e duser (fail-open, sadece goruntuleme)
        }

        $totalEquity = $spotBalance + $spotOpenCost + $futuresEquity;
        $rolling24hPnl = Order::calculateRolling24hPNL($userId);
        $lossAmount = $rolling24hPnl < 0 ? abs($rolling24hPnl) : 0.0;
        $maxLossAmount = $totalEquity * ($maxDailyLossPercent / 100);
        $usedPercent = $maxLossAmount > 0 ? min(100.0, ($lossAmount / $maxLossAmount) * 100) : 0.0;

        return [
            'loss_amount'            => round($lossAmount, 2),
            'max_loss_amount'        => round($maxLossAmount, 2),
            'max_daily_loss_percent' => $maxDailyLossPercent,
            'used_percent'           => round($usedPercent, 1),
            'total_equity'           => round($totalEquity, 2),
        ];
    }

    // Sistem Durumu widget'i icin: tum otonom modullerin log dosyalarindaki son "KRİTİK"/"başarısız"
    // satirlarini birlestirip zaman damgasina gore siralar - full_system_check.php tanilama betiginde
    // elle yapilan taramanin AYNISI, artik dashboard'da otomatik
    private function collectRecentCriticalErrors(): array
    {
        $logDir = __DIR__ . '/../../storage/logs';
        $logFiles = ['auto_trade.log', 'listing_sniper.log', 'futures_trading.log', 'binance_errors.log', 'market_scanner_errors.log'];

        $matches = [];
        foreach ($logFiles as $file) {
            $lines = $this->tailFile($logDir . '/' . $file);
            foreach ($lines as $line) {
                // "PRE_TRADING/BREAK taraması başarısız" (ListingSniperService) borsanın kendi
                // rutin PRE_TRADING/BREAK dongusunden kaynaklanan, zaten ListingSniperController'da
                // BinanceApiTimeoutException ile zarif sekilde yakalanip bir sonraki cron turunde
                // kendiliginden duzelen GECICI bir durum - "basarisiz" kelimesi gecince buraya
                // gercek bir kritik hata gibi dusuyordu, gurultu olarak disarida birakilir
                if (stripos($line, 'PRE_TRADING/BREAK') !== false) {
                    continue;
                }

                if (stripos($line, 'KRİTİK') !== false || stripos($line, 'başarısız') !== false
                    || stripos($line, 'Undefined') !== false || stripos($line, 'Fatal') !== false) {
                    $matches[] = $line;
                }
            }
        }

        sort($matches);

        return array_slice($matches, -5);
    }

    // "Son İşlemler" tablosundaki "👁" (detay) butonuna tiklandiginda cagrilir - tek bir siparisin
    // tam hassasiyetli fiyat/tutar bilgisini, (varsa) PNL'ini ve (bulunabiliyorsa) hangi Karma Radar
    // stratejisinden geldigini doner. Sayfa ilk yuklendiginde HESAPLANMAZ (sadece tiklaninca, lazy) -
    // 10 siparisin hepsi icin gereksiz sorgu yapip sayfa acilisini yavaslatmasin diye
    public function apiOrderDetail(): void
    {
        $userId = $this->requireAjaxAuth();
        if ($userId === null) {
            return;
        }

        header('Content-Type: application/json');

        try {
            $orderId = (int) ($_GET['id'] ?? 0);
            $order = Order::findByIdForUser($orderId, $userId);

            if ($order === null) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'İşlem bulunamadı'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pnlUsdt = null;
            $pnlPercent = null;

            // PNL sadece SAT (kapanis) siparisleri icin anlamlidir, ve sadece bir ALIS'i
            // (parent_order_id) dogurduysa hesaplanabilir - ör. baglantisiz eski kayitlarda olmayabilir
            if (strtolower((string) $order['side']) === 'sell' && $order['parent_order_id'] !== null) {
                $parent = Order::findById((int) $order['parent_order_id']);

                if ($parent !== null) {
                    $sellTotal = (float) $order['total'];
                    $buyTotal = (float) $parent['total'];
                    $pnlUsdt = round($sellTotal - $buyTotal, 2);
                    $pnlPercent = $buyTotal > 0 ? round((($sellTotal - $buyTotal) / $buyTotal) * 100, 2) : null;
                }
            }

            // Strateji etiketi artik dogrudan siparisin KENDI kaydindan okunur (orders.strategy_bucket) -
            // eskiden bot_logs'u JSON parse ederek zaman-yakinligina gore TAHMIN ediyordu (kirilgan,
            // ayni pozisyonun Alis/Satis kayitlarinda farkli sonuc verebiliyordu). Artik SAT/kapanis
            // siparisleri olusturulurken KENDI ALIS siparisinin etiketini kalici olarak miras aliyor
            // (bkz. AutoTradeController/FuturesTradingService/ListingSniperService) - burada tahmine
            // hic gerek kalmadi, tek bir sutun okumasi yeterli
            $strategyBucket = $order['strategy_bucket'] ?? null;

            echo json_encode([
                'success' => true,
                'order' => [
                    'pair' => $order['pair'],
                    'side' => $order['side'],
                    'status' => $order['status'],
                    'price' => (float) $order['price'],
                    'quantity' => (float) $order['quantity'],
                    'total' => (float) $order['total'],
                    'created_at' => $order['created_at'],
                    'error_message' => $order['error_message'],
                    'loss_reason' => $order['loss_reason'] ?? null,
                ],
                'pnl_usdt' => $pnlUsdt,
                'pnl_percent' => $pnlPercent,
                'strategy_bucket' => $strategyBucket,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[apiOrderDetail] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'İşlem detayı alınamadı'], JSON_UNESCAPED_UNICODE);
        }
    }

    // "Aktif Avlar" bolumu icin acik pozisyonlari + Kar Al hedefine gore anlik ilerleme yuzdesini getirir
    // İlerleme %0 = giris fiyati, %100 = Kar Al hedefi; negatife dusmesi Zarar Kes'e yaklasildigini gosterir
    private function fetchActiveTrades(int $userId, ?array $existingKey): array
    {
        $trades = ActiveTrade::findOpenForUser($userId);

        if ($trades === [] || $existingKey === null) {
            return [];
        }

        try {
            $binance = new BinanceService($existingKey['api_key'], $existingKey['secret_key']);
        } catch (Throwable $e) {
            return $trades;
        }

        foreach ($trades as &$trade) {
            $entryPrice = (float) $trade['entry_price'];
            $targetPrice = (float) $trade['take_profit_price'];

            try {
                $currentPrice = $binance->getPrice((string) $trade['pair']);
            } catch (Throwable $e) {
                $currentPrice = null;
            }

            $trade['current_price'] = $currentPrice;

            if ($currentPrice !== null && $targetPrice > $entryPrice) {
                $progress = (($currentPrice - $entryPrice) / ($targetPrice - $entryPrice)) * 100;
                $trade['progress_percent'] = max(0.0, min(100.0, $progress));
            } else {
                $trade['progress_percent'] = null;
            }

            $quantity = (float) $trade['quantity'];
            if ($currentPrice !== null && $entryPrice > 0) {
                $trade['unrealized_pnl_usdt'] = round(($currentPrice - $entryPrice) * $quantity, 2);
                $trade['unrealized_pnl_pct']  = round((($currentPrice - $entryPrice) / $entryPrice) * 100, 2);
            } else {
                $trade['unrealized_pnl_usdt'] = null;
                $trade['unrealized_pnl_pct']  = null;
            }
        }
        unset($trade);

        return $trades;
    }
}
