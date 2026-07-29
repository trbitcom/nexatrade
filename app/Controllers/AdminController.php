<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Session;
use App\Core\Url;
use App\Models\ActiveTrade;
use App\Models\ApiKey;
use App\Models\BotLog;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Throwable;

// Sadece role='admin' olan kullanicilar erisebilir (AuthMiddleware::requireAdmin ile korunur)
final class AdminController
{
    private const VALID_STATUSES = ['active', 'passive', 'banned'];
    private const VALID_ROLES = ['admin', 'user'];

    public function index(): void
    {
        AuthMiddleware::requireAdmin();

        $stats = [
            'total_users' => User::countAll(),
            'auto_trade_users' => ApiKey::countAutoTradeEnabled(),
            'open_positions' => ActiveTrade::countAllOpen(),
            'trades_today' => Order::countFilledToday(),
        ];

        $users = User::findAllWithStats();
        $recentLogs = BotLog::findRecent(15);
        $scoreBandBreakdown = Order::calculateScoreBandBreakdown();

        // Anahtarlarin kendisini degil, sadece "DB'den mi geliyor" bilgisini ve maskelenmis onizlemesini gosteririz
        $settings = [
            'openai_api_key' => $this->maskedSettingPreview('openai_api_key'),
            'groq_api_key' => $this->maskedSettingPreview('groq_api_key'),
            'gemini_api_key' => $this->maskedSettingPreview('gemini_api_key'),
            'webhook_token' => $this->maskedSettingPreview('webhook_token'),
            'auto_trade_token' => $this->maskedSettingPreview('auto_trade_token'),
            'telegram_bot_token' => $this->maskedSettingPreview('telegram_bot_token'),
            'telegram_admin_chat_id' => $this->maskedSettingPreview('telegram_admin_chat_id'),
            'telegram_bot_username' => $this->maskedSettingPreview('telegram_bot_username'),
            'telegram_webhook_secret' => $this->maskedSettingPreview('telegram_webhook_secret'),
            'coingecko_api_key' => $this->maskedSettingPreview('coingecko_api_key'),
            'etherscan_api_key' => $this->maskedSettingPreview('etherscan_api_key'),
            'listing_sniper_token' => $this->maskedSettingPreview('listing_sniper_token'),
            'smart_money_token' => $this->maskedSettingPreview('smart_money_token'),
            'daily_summary_token' => $this->maskedSettingPreview('daily_summary_token'),
        ];

        // Bunlar gizli anahtar degil, sadece sayisal esik degerleri - maskelemeden gercek degeri gosteririz
        $listingSniperMinVolume = $this->getNumericSettingValue('listing_sniper_min_quote_volume', 10000.0);
        $smartMoneyMinTransfer = $this->getNumericSettingValue('smart_money_min_transfer_usd', 50000.0);
        $scannerCoinLimit = (int) $this->getNumericSettingValue('scanner_coin_limit', 25.0);

        // Gizli degil, sadece virgulle ayrilmis sembol listesi - bos ise MarketScanner tum piyasayi tarar
        $marketScannerWhitelist = (string) (Setting::get('market_scanner_whitelist') ?? '');

        // DB'de hic yoksa config/app.php'deki varsayilana (BANKUSDT,DEXEUSDT,BTCUSDT,ETHUSDT) duser -
        // MarketScanner::getBlacklistedSymbols() ile AYNI oncelik sirasi, panelde varsayilan gorunsun
        $marketScannerBlacklist = Setting::get('market_scanner_blacklist');
        if ($marketScannerBlacklist === null) {
            $appConfig = require __DIR__ . '/../../config/app.php';
            $marketScannerBlacklist = (string) ($appConfig['market_scanner_blacklist'] ?? '');
        }

        // Karar Motoru: 'ai' (varsayilan, GPT/AI Karar Skoru asil karari verir) veya 'deterministic'
        // (TechnicalScoreEngine'in RSI/MACD/hacim formulu asil karari verir, GPT Golge Modda calismaya
        // devam eder). Bkz. AutoTradeController::getDecisionMotor() AYNI Setting-first-then-config deseni
        $decisionMotor = Setting::get('decision_motor');
        if ($decisionMotor === null) {
            $appConfig = $appConfig ?? require __DIR__ . '/../../config/app.php';
            $decisionMotor = (string) ($appConfig['decision_motor'] ?? 'ai');
        }

        $watchedWalletsText = $this->watchedWalletsToTextarea();

        $successMessage = Session::get('admin_success');
        $errorMessage = Session::get('admin_error');
        Session::remove('admin_success');
        Session::remove('admin_error');

        require __DIR__ . '/../Views/admin/index.php';
    }

    // OpenAI anahtari / webhook token / cron token: admin panelinden girilirse DB'ye (app_settings) yazilir
    // ve o andan itibaren config/app.php'deki degerin onune gecer. Bos birakilan alanlar degistirilmez.
    public function saveSettings(): void
    {
        AuthMiddleware::requireAdmin();

        $fields = [
            'openai_api_key', 'groq_api_key', 'gemini_api_key', 'webhook_token', 'auto_trade_token',
            'telegram_bot_token', 'telegram_admin_chat_id', 'telegram_bot_username', 'telegram_webhook_secret',
            'coingecko_api_key', 'etherscan_api_key', 'listing_sniper_token', 'smart_money_token',
            'daily_summary_token',
            'listing_sniper_min_quote_volume', 'smart_money_min_transfer_usd',
            'scanner_coin_limit',
        ];
        $updated = 0;

        try {
            foreach ($fields as $field) {
                $value = trim((string) ($_POST[$field] ?? ''));

                if ($value !== '') {
                    Setting::set($field, $value);
                    $updated++;
                }
            }

            // Piyasa tarama beyaz listesi: DIGER alanlarin aksine BOS deger de KASITLI olarak
            // kaydedilir (yukaridaki dongude "bos deger = dokunma" atlanirdi). Alanin kendi
            // placeholder metni ("Boş = kısıtlama yok, tüm piyasa taranır") admin'e bunu bos
            // birakip kaydederek beyaz listeyi TAMAMEN kaldirabilecegini vaat ediyor - eskiden
            // form bos gonderildiginde DB'deki eski deger SESSIZCE degismeden kalip, admin
            // listeyi "temizledigini" sandigi halde sistem hala eski sabit listeye takili kaliyordu
            if (isset($_POST['market_scanner_whitelist'])) {
                Setting::set('market_scanner_whitelist', trim((string) $_POST['market_scanner_whitelist']));
                $updated++;
            }

            // Kara liste: whitelist ile AYNI "bos deger de kasitli kaydedilir" istisnasi - admin
            // istedigi zaman TUM kara listeyi bos birakip kaydederek kaldirabilmeli
            if (isset($_POST['market_scanner_blacklist'])) {
                Setting::set('market_scanner_blacklist', trim((string) $_POST['market_scanner_blacklist']));
                $updated++;
            }

            // Karar Motoru: bir <select> alani, serbest metin degil - beklenmeyen bir deger
            // gelirse (ör. form manipulasyonu) GUVENLI VARSAYILANA (ai) sessizce duser, asla
            // taninmayan bir string DB'ye yazilmaz
            if (isset($_POST['decision_motor'])) {
                $decisionMotorInput = (string) $_POST['decision_motor'];
                Setting::set('decision_motor', $decisionMotorInput === 'deterministic' ? 'deterministic' : 'ai');
                $updated++;
            }

            // Izlenen balina cuzdanlari: textarea her satirda "adres|etiket" formatinda gelir,
            // JSON dizisine cevrilip Setting'e kaydedilir. Bos birakilirsa liste temizlenir (kasitli:
            // admin butun cuzdanlari tek seferde kaldirabilmeli)
            if (isset($_POST['smart_money_watched_wallets'])) {
                $this->saveWatchedWallets((string) $_POST['smart_money_watched_wallets']);
                $updated++;
            }

            Session::set('admin_success', $updated > 0
                ? "{$updated} ayar güncellendi."
                : 'Değişiklik yapılmadı (tüm alanlar boş bırakıldı).');
        } catch (Throwable $e) {
            Session::set('admin_error', 'Ayarlar kaydedilirken bir hata oluştu.');
        }

        header('Location: ' . Url::to('/admin'));
    }

    // "0xADRES|Etiket" seklinde, her satirda bir cuzdan olacak sekilde girilen metni
    // SmartMoneyTracker'in okudugu JSON dizisine cevirir
    private function saveWatchedWallets(string $rawText): void
    {
        $lines = preg_split('/\r?\n/', trim($rawText)) ?: [];
        $wallets = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = explode('|', $line, 2);
            $address = trim($parts[0]);

            if ($address === '') {
                continue;
            }

            $wallets[] = [
                'address' => $address,
                'label' => isset($parts[1]) ? trim($parts[1]) : $address,
            ];
        }

        Setting::set('smart_money_watched_wallets', json_encode($wallets, JSON_UNESCAPED_UNICODE));
    }

    // Kayitli cuzdan listesini, textarea'da duzenlenebilir "adres|etiket" satirlarina cevirir
    private function watchedWalletsToTextarea(): string
    {
        $cached = Setting::get('smart_money_watched_wallets');

        if ($cached === null) {
            return '';
        }

        $decoded = json_decode($cached, true);

        if (!is_array($decoded)) {
            return '';
        }

        $lines = [];

        foreach ($decoded as $wallet) {
            $address = (string) ($wallet['address'] ?? '');
            $label = (string) ($wallet['label'] ?? '');

            if ($address === '') {
                continue;
            }

            $lines[] = $label !== '' && $label !== $address ? "{$address}|{$label}" : $address;
        }

        return implode("\n", $lines);
    }

    public function updateUserStatus(): void
    {
        AuthMiddleware::requireAdmin();

        $userId = (int) ($_POST['user_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');

        if ($userId === (int) Session::get('user_id') && $status !== 'active') {
            Session::set('admin_error', 'Kendi hesabınızın durumunu pasif/banlı yapamazsınız.');
            header('Location: ' . Url::to('/admin'));
            return;
        }

        if ($userId <= 0 || !in_array($status, self::VALID_STATUSES, true)) {
            Session::set('admin_error', 'Geçersiz kullanıcı veya durum.');
            header('Location: ' . Url::to('/admin'));
            return;
        }

        try {
            User::updateStatus($userId, $status);
            Session::set('admin_success', "Kullanıcı #{$userId} durumu güncellendi: {$status}");
        } catch (Throwable $e) {
            Session::set('admin_error', 'Durum güncellenirken bir hata oluştu.');
        }

        header('Location: ' . Url::to('/admin'));
    }

    public function updateUserRole(): void
    {
        AuthMiddleware::requireAdmin();

        $userId = (int) ($_POST['user_id'] ?? 0);
        $role = (string) ($_POST['role'] ?? '');

        if ($userId === (int) Session::get('user_id')) {
            Session::set('admin_error', 'Kendi rolünüzü buradan değiştiremezsiniz.');
            header('Location: ' . Url::to('/admin'));
            return;
        }

        if ($userId <= 0 || !in_array($role, self::VALID_ROLES, true)) {
            Session::set('admin_error', 'Geçersiz kullanıcı veya rol.');
            header('Location: ' . Url::to('/admin'));
            return;
        }

        try {
            User::updateRole($userId, $role);
            Session::set('admin_success', "Kullanıcı #{$userId} rolü güncellendi: {$role}");
        } catch (Throwable $e) {
            Session::set('admin_error', 'Rol güncellenirken bir hata oluştu.');
        }

        header('Location: ' . Url::to('/admin'));
    }

    // Kullaniciyi ve ON DELETE CASCADE ile bagli TUM verilerini (API anahtarlari, siparisler,
    // acik pozisyonlar) kalici olarak siler - geri alinamaz. Kendi hesabini silme ENGELLENIR
    // (updateUserStatus/updateUserRole'deki ayni self-koruma deseni)
    public function deleteUser(): void
    {
        AuthMiddleware::requireAdmin();

        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($userId === (int) Session::get('user_id')) {
            Session::set('admin_error', 'Kendi hesabınızı silemezsiniz.');
            header('Location: ' . Url::to('/admin'));
            return;
        }

        if ($userId <= 0) {
            Session::set('admin_error', 'Geçersiz kullanıcı.');
            header('Location: ' . Url::to('/admin'));
            return;
        }

        try {
            User::delete($userId);
            Session::set('admin_success', "Kullanıcı #{$userId} kalıcı olarak silindi.");
        } catch (Throwable $e) {
            Session::set('admin_error', 'Kullanıcı silinirken bir hata oluştu.');
        }

        header('Location: ' . Url::to('/admin'));
    }

    // Devre Kesici'yi (ApiKey::resetLossStreak() - bkz. o yorumdaki kisir donguru RCA'si) manuel
    // olarak acar: circuit_breaker_until temizlenir, auto_trade_enabled=1 yapilir VE "ardisik zarar"
    // sayimi bu andan ITIBAREN sifirlanir (GECMIS kapanis kayitlarina DOKUNULMAZ, sadece ileriye
    // donuk sayima katilmazlar) - boylece bot yeniden acildiginda AYNI eski seriyi gorup ANINDA
    // tekrar kilitlenmez. 25 Temmuz'da eklendi - onceden bu metod (ApiKey::resetLossStreak) VARDI
    // ama hicbir UI'dan cagirilamiyordu, sadece atilabilir CLI betikleriyle tetiklenebiliyordu
    public function resetCircuitBreaker(): void
    {
        AuthMiddleware::requireAdmin();

        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($userId <= 0) {
            Session::set('admin_error', 'Geçersiz kullanıcı.');
            header('Location: ' . Url::to('/admin'));
            return;
        }

        try {
            ApiKey::resetLossStreak($userId);
            Session::set('admin_success', "Kullanıcı #{$userId} için Devre Kesici manuel olarak açıldı.");
        } catch (Throwable $e) {
            Session::set('admin_error', 'Devre Kesici sıfırlanırken bir hata oluştu.');
        }

        header('Location: ' . Url::to('/admin'));
    }

    // Bir kullanicinin sifresini GORMEDEN yeni bir sifreyle sifirlar - sifreler tek yonlu hash
    // olarak saklandigi icin mevcut sifreyi "gormek" kriptografik olarak imkansizdir, admin sadece
    // yeni bir sifre BELIRLEYEBILIR
    public function resetUserPassword(): void
    {
        AuthMiddleware::requireAdmin();

        $userId = (int) ($_POST['user_id'] ?? 0);
        $newPassword = (string) ($_POST['new_password'] ?? '');

        if ($userId <= 0) {
            Session::set('admin_error', 'Geçersiz kullanıcı.');
            header('Location: ' . Url::to('/admin'));
            return;
        }

        if (strlen($newPassword) < 8) {
            Session::set('admin_error', 'Yeni şifre en az 8 karakter olmalıdır.');
            header('Location: ' . Url::to('/admin'));
            return;
        }

        try {
            User::updatePassword($userId, $newPassword);
            Session::set('admin_success', "Kullanıcı #{$userId} şifresi güncellendi.");
        } catch (Throwable $e) {
            Session::set('admin_error', 'Şifre güncellenirken bir hata oluştu.');
        }

        header('Location: ' . Url::to('/admin'));
    }

    // Gizli olmayan sayisal esik ayarlari icin: DB'de kayitliysa onu, yoksa config/app.php'deki
    // varsayilani doner - maskedSettingPreview'in aksine gercek degeri gosterir (secret degildir)
    private function getNumericSettingValue(string $key, float $default): float
    {
        $dbValue = Setting::get($key);

        if ($dbValue !== null) {
            return (float) $dbValue;
        }

        $config = require __DIR__ . '/../../config/app.php';

        return (float) ($config[$key] ?? $default);
    }

    // Ayarın DB'de kayıtlı olup olmadığını ve varsa ilk/son birkaç karakterini gösterir; tam değeri asla döndürmez
    private function maskedSettingPreview(string $key): array
    {
        $value = Setting::get($key);

        if ($value === null) {
            return ['source' => 'config/app.php (dosya)', 'preview' => null];
        }

        $length = strlen($value);
        $preview = $length <= 8 ? str_repeat('*', $length) : substr($value, 0, 4) . '...' . substr($value, -4);

        return ['source' => 'Admin panelinden (DB)', 'preview' => $preview];
    }
}
