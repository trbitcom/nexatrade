<?php

declare(strict_types=1);

// Uygulama giris noktasi (front controller)

// Basit PSR-4 benzeri otomatik yukleyici: App\ namespace'ini app/ dizinine baglar
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../app/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\AutoFuturesTradeController;
use App\Controllers\AutoTradeController;
use App\Controllers\BacktestController;
use App\Controllers\DailySummaryController;
use App\Controllers\DashboardController;
use App\Controllers\ListingSniperController;
use App\Controllers\SmartMoneyController;
use App\Controllers\TelegramWebhookController;
use App\Controllers\WebhookController;
use App\Core\CliKernel;
use App\Core\Router;

// VPS/crontab gecisi: "php public/index.php cli:komut" seklinde CLI'dan cagrilirsa, Router'a hic
// girmeden burada ayri bir yoldan islenir - bkz. CliKernel yorumu. HTTP istekleri bu bloktan
// ETKILENMEZ (php_sapi_name() bir web sunucusu altinda asla 'cli' donmez)
if (PHP_SAPI === 'cli') {
    (new CliKernel())->handle($argv);
    return;
}

$router = new Router();

// Kok URL: oturum acikken /dashboard'a, kapaliyken /login'e yonlendirir (showLogin icinde kontrol edilir)
$router->get('/', [AuthController::class, 'showLogin']);

// TradingView (veya benzeri) servislerin sinyal gonderecegi uc nokta
$router->post('/api/webhook', [WebhookController::class, 'handle']);

// Otonom AI avcisi: cPanel Cron Job tarafindan periyodik olarak tetiklenir (GET, ?token=... ile)
$router->get('/api/auto-trade/run', [AutoTradeController::class, 'run']);

// Telegram Bot API'sinin dogrudan pingledigi webhook: musteri /start {token} yazdiginda tetiklenir
// (bkz. TelegramWebhookController - yalnizca gercek bir HTTPS alan adinda calisir, localhost'ta degil)
$router->post('/api/telegram/webhook', [TelegramWebhookController::class, 'handle']);

// Duyuru Avcisi: ana auto-trade dongusunden BAGIMSIZ, cok daha sik (ör. her 1 dakikada bir)
// cPanel Cron Job ile tetiklenmesi onerilir - yeni listelemeye milisaniyeler icinde tepki verir
$router->get('/api/listing-sniper/run', [ListingSniperController::class, 'run']);

// Akilli Para Kopyalayici: orta sikte (ör. her 5 dakikada bir) cPanel Cron Job ile tetiklenmesi onerilir
$router->get('/api/smart-money/run', [SmartMoneyController::class, 'run']);

// Futures (kaldiracli KISA/short) modulu: ana auto-trade dongusunden BAGIMSIZ, ayni sikta (ör. 15 dk)
// cPanel Cron Job ile tetiklenmesi onerilir - sadece futures_trading_enabled=1 olan kullanicilari etkiler
$router->get('/api/futures-trade/run', [AutoFuturesTradeController::class, 'run']);

// Gece Yarisi Hesap Ozeti: digerlerinden TAMAMEN FARKLI sikta - cPanel Cron Job ile GUNDE BIR KEZ
// (00:00, sunucu saatine gore) tetiklenmesi icin tasarlandi, her musteriye kendi Telegram'indan gunluk ozet gonderir
$router->get('/api/daily-summary/run', [DailySummaryController::class, 'run']);

// Hizli Pozisyon Takipcisi: ana auto-trade dongusunden (GPT'li tarama, ~90sn+ surebiliyor) TAMAMEN
// BAGIMSIZ kilit kullanir - SADECE acik pozisyonlarin Izleyen Stop/Kar Al kontrolunu yapar, GPT
// cagirmaz. cPanel Cron Job ile 1 dakikada bir (cPanel'in izin verdigi en sik aralik) tetiklenmesi
// onerilir - ana tarama devam ederken bile bu kontrol asla bloklanmaz
$router->get('/api/fast-tracker/run', [AutoTradeController::class, 'runFastTracker']);

// Kimlik dogrulama
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register']);
$router->get('/logout', [AuthController::class, 'logout']);

// Korumali panel (AuthMiddleware kontrolu controller icinde yapilir)
$router->get('/dashboard', [DashboardController::class, 'index']);
$router->post('/dashboard/api-keys', [DashboardController::class, 'saveApiKeys']);
$router->post('/dashboard/auto-trade-settings', [DashboardController::class, 'saveAutoTradeSettings']);
$router->post('/dashboard/advanced-modules', [DashboardController::class, 'saveAdvancedModules']);
$router->post('/dashboard/account-settings', [DashboardController::class, 'saveAccountSettings']);
$router->post('/dashboard/accept-risk', [DashboardController::class, 'acceptRisk']);

// Performans: dis API'lere bagli veriler sayfa yuklendikten SONRA bu uc noktalardan fetch/AJAX ile gelir
$router->get('/dashboard/data/balance', [DashboardController::class, 'dataBalance']);
$router->get('/dashboard/data/radar', [DashboardController::class, 'dataRadar']);
$router->get('/dashboard/data/news', [DashboardController::class, 'dataNews']);
$router->get('/dashboard/data/active-trades', [DashboardController::class, 'dataActiveTrades']);
$router->get('/dashboard/data/bot-logs', [DashboardController::class, 'dataBotLogs']);

// Yeni, güçlendirilmiş uç noktalar — her biri {success, ...} formatında saf JSON döner
$router->get('/api/dashboard/balance', [DashboardController::class, 'apiBalance']);
$router->get('/api/dashboard/radar',   [DashboardController::class, 'apiRadar']);
$router->get('/api/dashboard/news',    [DashboardController::class, 'apiNews']);
$router->get('/api/dashboard/hunts',   [DashboardController::class, 'apiHunts']);
$router->get('/api/dashboard/live-chart', [DashboardController::class, 'apiLiveChart']);
$router->get('/api/dashboard/logs',    [DashboardController::class, 'apiLogs']);
$router->get('/api/dashboard/interventions', [DashboardController::class, 'apiInterventions']);
$router->get('/api/dashboard/pnl',          [DashboardController::class, 'apiPnl']);
$router->get('/api/dashboard/portfolio',    [DashboardController::class, 'apiPortfolio']);
$router->get('/api/dashboard/scan-status',  [DashboardController::class, 'apiScanStatus']);
$router->get('/api/dashboard/futures-positions', [DashboardController::class, 'apiFuturesPositions']);
$router->get('/api/dashboard/order-detail', [DashboardController::class, 'apiOrderDetail']);
$router->get('/api/dashboard/order-history', [DashboardController::class, 'apiOrderHistory']);
$router->get('/api/dashboard/system-status', [DashboardController::class, 'apiSystemStatus']);
$router->get('/api/dashboard/performance-breakdown', [DashboardController::class, 'apiPerformanceBreakdown']);
$router->post('/api/dashboard/clear-cooldown', [DashboardController::class, 'apiClearSymbolCooldown']);
$router->post('/api/dashboard/toggle-kill-switch', [DashboardController::class, 'apiToggleKillSwitch']);
$router->post('/api/settings/risk-profile', [DashboardController::class, 'apiRiskProfile']);

// Admin paneli (AuthMiddleware::requireAdmin ile korunur, sadece role='admin')
$router->get('/admin', [AdminController::class, 'index']);
$router->post('/admin/settings', [AdminController::class, 'saveSettings']);
$router->post('/admin/users/status', [AdminController::class, 'updateUserStatus']);
$router->post('/admin/users/role', [AdminController::class, 'updateUserRole']);
$router->post('/admin/users/delete', [AdminController::class, 'deleteUser']);
$router->post('/admin/users/reset-password', [AdminController::class, 'resetUserPassword']);
$router->post('/admin/users/reset-circuit-breaker', [AdminController::class, 'resetCircuitBreaker']);

// Backtest: gecmis fiyat verisiyle mekanik filtreleri test eder - salt okunur, gercek islem yapmaz
$router->get('/admin/backtest', [BacktestController::class, 'index']);
$router->post('/admin/backtest', [BacktestController::class, 'index']);

$router->dispatch();
