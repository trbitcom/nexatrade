<?php

declare(strict_types=1);

use App\Core\Url;
use App\Services\RiskProfileService;

/** @var array|false $user */
/** @var bool $isAdmin */
/** @var string|null $maskedApiKey */
/** @var string|null $existingExchange */
/** @var int $completedOrders */
/** @var array $recentOrders */
/** @var float $dailyPnl */
/** @var array $activeTrades */
/** @var array $activeFuturesTrades */
/** @var array $recentListings */
/** @var bool $autoTradeEnabled */
/** @var float $autoTradeBudget */
/** @var float $takeProfitPercent */
/** @var float $stopLossPercent */
/** @var float $maxDailyLossPercent */
/** @var string $telegramVerifyToken */
/** @var bool $telegramConnected */
/** @var string $telegramBotUsername */
/** @var bool $socialRadarEnabled */
/** @var bool $listingSniperEnabled */
/** @var bool $smartMoneyEnabled */
/** @var bool $dcaEnabled */
/** @var bool $futuresTradingEnabled */
/** @var int $futuresLeverage */
/** @var string|null $successMessage */
/** @var string|null $errorMessage */
/** @var string|null $autoTradeSuccess */
/** @var string|null $autoTradeError */
/** @var string|null $advancedModulesSuccess */
/** @var string|null $advancedModulesError */
/** @var string|null $accountSuccess */
/** @var string|null $accountError */
/** @var string $riskProfile */
/** @var bool $riskAccepted */
/** @var bool $circuitBreakerActive */
/** @var string|null $circuitBreakerUntil */
/** @var string $serverPublicIp */
/** @var string $appVersion */
/** @var array $changelogEntries */

// PERFORMANS: Bakiye, AI Radar, Piyasa Nabzı ve Haberler artık burada PHP degiskeni olarak
// GELMIYOR - bunlar dis API'lere bagli oldugu icin sayfa aninda acilsin diye kaldirildi,
// sayfa yuklendikten SONRA JS (fetch) ile /dashboard/data/* uc noktalarindan cekilip
// ilgili panellere yaziliyor (bkz. bu dosyanin sonundaki <script> blogu)

$userName = htmlspecialchars((string) ($user['name'] ?? 'Kullanıcı'), ENT_QUOTES, 'UTF-8');
$userEmail = htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8');

$pnlValue = ($dailyPnl >= 0 ? '+' : '-') . '$' . number_format(abs($dailyPnl), 2);
$pnlClass = $dailyPnl >= 0 ? 'text-emerald-600' : 'text-rose-600';

// Miktar gibi ondalikli degerlerdeki gereksiz sifirlari temizler (ör. 0.00150000 -> 0.0015)
$trimQty = static fn (float $value): string => rtrim(rtrim(sprintf('%.8f', $value), '0'), '.') ?: '0';

// Fiyat gosterimi icin dinamik hassasiyet - number_format($x, 2) HMSTR/TLM gibi kurusun altindaki
// (ör. $0.00030285) mikro-cap coin fiyatlarini "$0.00" olarak gosterip yanlislikla "fiyat sifir"
// izlenimi veriyordu. 1$ ve uzerindeki fiyatlarda gereksiz hassasiyet gurultu yaratmasin diye
// SADECE gercekten 2 haneden fazla anlamli basamagi olan fiyatlarda 4 haneye cikilir
// (ör. 63450.25 -> "63,450.25" ama 1.2345 -> "1.2345")
$formatTradePrice = static function (float $price): string {
    if ($price >= 1) {
        // 2 haneye yuvarlamak degeri degistirmiyorsa (ör. 63450.25) 2 hane yeterlidir;
        // degistiriyorsa (ör. 1.2345 -> 1.23 anlam kaybettirir) 4 haneye cikilir
        $decimals = (round($price, 2) === round($price, 8)) ? 2 : 4;

        return number_format($price, $decimals);
    }

    $formatted = rtrim(rtrim(sprintf('%.8f', $price), '0'), '.');

    return $formatted === '' ? '0' : $formatted;
};

// Not: fiyat/hacim bicimlendirme (eskiden $formatPrice/$formatVolume) artik burada degil,
// asagidaki <script> blogunda JS karsiligiyla (formatPrice/formatVolume) yapiliyor - AI Radar
// verisi artik PHP tarafinda degil, sayfa yuklendikten sonra JS ile render ediliyor

// Ayar formlarindan biri az once bir sonuc mesaji urettiyse, sayfa acilinca ayarlar modal'i otomatik acilsin
$openSettingsModal = !empty($successMessage) || !empty($errorMessage) || !empty($autoTradeSuccess) || !empty($autoTradeError) || !empty($accountSuccess) || !empty($accountError);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>NexaTrade | Terminal</title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(Url::to('/assets/img/favicon.png'), ENT_QUOTES, 'UTF-8') ?>">
    <!-- Ana ekrana eklendiginde (iOS/Android) uygulama ikonu ve tam ekran (standalone) acilis icin -->
    <link rel="apple-touch-icon" href="<?= htmlspecialchars(Url::to('/assets/img/favicon.png'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="manifest" href="<?= htmlspecialchars(Url::to('/assets/manifest.webmanifest'), ENT_QUOTES, 'UTF-8') ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="NexaTrade">
    <meta name="theme-color" content="#f3f5fb">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- cdn.tailwindcss.com (calisma zamaninda JS ile CSS ureten, uretim icin onerilmeyen yontem)
         yerine derleme zamaninda uretilmis, kucuk ve statik bir CSS dosyasi - sayfa acilisini hizlandirir -->
    <!-- ?v=dosya-degisim-zamani onbellek kirma (cache-busting) - 31 Temmuz'da eklendi: derlenmiş
         CSS'te (coin ikonu boyutu icin) yapilan bir degisiklik, versiyon parametresi olmadigi icin
         musterinin mobil tarayicisinda ESKİ onbellekten okunmaya devam ediyordu (masaustunde/yerelde
         dogru gorunuyordu, cunku o tarayicilarda onbellek zaten temizdi/farkli davraniyordu). Dosyanin
         KENDI mtime'i kullanilir (app_version'a BAGIMLI degil) - CSS her degistiginde (npm run
         build:css) URL otomatik degisir, ayni desen tum <link> etiketlerinde (bkz. auth/admin
         gorunumleri) TEKRARLANIR -->
    <link rel="stylesheet" href="<?= htmlspecialchars(Url::to('/assets/css/tailwind.css'), ENT_QUOTES, 'UTF-8') ?>?v=<?= @filemtime(__DIR__ . '/../../../assets/css/tailwind.css') ?: '1' ?>">
    <!-- Canli Savas Radari icin: hafif/performansli mum grafigi kutuphanesi (TradingView Lightweight
         Charts) - projede zaten Google Fonts CDN kullanildigi icin (yukarida) tek bir grafik
         kutuphanesi icin CDN kullanmak mevcut yaklasimla tutarli, ayrica derleme adimi gerektirmez -->
    <script defer src="https://unpkg.com/lightweight-charts@4.1.3/dist/lightweight-charts.standalone.production.js"></script>
    <style>
        html, body {
            height: 100%;
            overflow: hidden;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'Space Grotesk', 'Inter', sans-serif;
            background-color: #f5f5f7;
            background-image: radial-gradient(circle at 20% 0%, rgba(139, 92, 246, 0.05), transparent 55%);
            -webkit-tap-highlight-color: transparent;
        }

        /* ---- Dokunmatik cihazlarda buton/kart tepkisi: gercek bir uygulama gibi hissettirsin ---- */
        button, a {
            -webkit-tap-highlight-color: transparent;
        }
        button:active, a:active {
            transform: scale(0.97);
        }
        button, a {
            transition: transform 0.15s ease;
        }

        .font-mono-tech {
            font-family: 'JetBrains Mono', monospace;
        }

        .glass-panel {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(0, 0, 0, 0.06);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04), 0 16px 32px -16px rgba(0, 0, 0, 0.10);
        }

        .thin-scroll::-webkit-scrollbar {
            width: 5px;
        }

        .thin-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        .thin-scroll::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.15);
            border-radius: 3px;
        }

        /* ---- Ana widget grid'i: tum ekrani doldurur, sayfa hic kaymaz ---- */
        .terminal-grid {
            display: grid;
            grid-template-columns: 1.15fr 1fr 1fr 1fr;
            grid-template-rows: 1fr 1fr;
            grid-template-areas:
                "chart radar news listings"
                "technical orders hunts hunts";
        }

        .area-chart { grid-area: chart; }
        .area-technical { grid-area: technical; }
        .area-radar { grid-area: radar; }
        .area-news { grid-area: news; }
        .area-listings { grid-area: listings; }
        .area-hunts { grid-area: hunts; }
        .area-orders { grid-area: orders; }

        .signal-row {
            animation: row-pulse 1.8s ease-in-out infinite;
            background: rgba(52, 211, 153, 0.06);
        }

        @keyframes row-pulse {
            0%, 100% { box-shadow: inset 0 0 0 1px rgba(52, 211, 153, 0.4); }
            50% { box-shadow: inset 0 0 0 1px rgba(52, 211, 153, 0.08); }
        }

        .hunt-progress-track {
            background: rgba(0, 0, 0, 0.05);
        }

        /* ---- Ozel Kayan Fiyat Bandi: iki ozdes kopya art arda dizilip %50 kaydirilir, boylece
           dikişsiz (seamless) sonsuz dongu olusur - klasik "marquee" teknigi ---- */
        @keyframes ticker-scroll {
            from { transform: translateX(0); }
            to   { transform: translateX(-50%); }
        }
        .animate-ticker-scroll {
            width: max-content;
            animation: ticker-scroll 32s linear infinite;
        }
        .animate-ticker-scroll:hover {
            animation-play-state: paused;
        }
        @media (prefers-reduced-motion: reduce) {
            .animate-ticker-scroll { animation: none; }
        }

        /* ---- Genel giris animasyonu: paneller acilista hafifce yukari kayarak belirir ---- */
        @keyframes panel-in {
            from { opacity: 0; transform: translateY(10px) scale(0.995); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        .glass-panel {
            animation: panel-in 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        }
        /* Sadece gercek fare/trackpad'i olan cihazlarda hover kaldirma efekti uygulanir - aksi halde
           dokunmatik ekranlarda dokunulan panel, parmak cekilene kadar "takili" hover halinde kalirdi */
        @media (hover: hover) and (pointer: fine) {
            .glass-panel:hover {
                transform: translateY(-1px);
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05), 0 20px 40px -18px rgba(0, 0, 0, 0.14);
                border-color: rgba(0, 0, 0, 0.09);
            }
        }
        @media (prefers-reduced-motion: reduce) {
            .glass-panel { animation: none; }
        }

        /* ---- Rakam guncellemesi: yenilenen bir deger kisaca yesile/kirmiziya "flash" olur ---- */
        @keyframes value-flash-up {
            0%   { background-color: rgba(52, 211, 153, 0.32); border-radius: 4px; }
            100% { background-color: rgba(52, 211, 153, 0); border-radius: 4px; }
        }
        @keyframes value-flash-down {
            0%   { background-color: rgba(244, 63, 94, 0.26); border-radius: 4px; }
            100% { background-color: rgba(244, 63, 94, 0); border-radius: 4px; }
        }
        .value-flash-up   { animation: value-flash-up 0.9s ease-out; }
        .value-flash-down { animation: value-flash-down 0.9s ease-out; }

        /* ---- Toast bildirimleri: sag alt kosede yumusakca beliren/kaybolan form-kaydetme geri bildirimi ---- */
        @keyframes toast-in {
            from { opacity: 0; transform: translateY(8px) scale(0.98); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes toast-out {
            from { opacity: 1; transform: translateY(0) scale(1); }
            to   { opacity: 0; transform: translateY(8px) scale(0.98); }
        }
        .toast-in  { animation: toast-in 0.25s ease-out forwards; }
        .toast-out { animation: toast-out 0.25s ease-in forwards; }

        /* ---- Haberler: kendi RSS akisimizdan gelen basliklarin yavasca yukari akmasi ---- */
        .news-ticker-viewport {
            -webkit-mask-image: linear-gradient(to bottom, transparent 0, black 24px, black calc(100% - 12px), transparent 100%);
            mask-image: linear-gradient(to bottom, transparent 0, black 24px, black calc(100% - 12px), transparent 100%);
        }

        @keyframes news-scroll {
            0% { transform: translateY(0); }
            100% { transform: translateY(-50%); }
        }

        .news-ticker-track {
            animation: news-scroll 55s linear infinite;
        }

        .news-ticker-track:hover {
            animation-play-state: paused;
        }

        /* ---- Sistem Kalp Atışı ---- */
        @keyframes pulse-ring {
            0%   { transform: scale(0.9); opacity: 0.9; }
            100% { transform: scale(2.2); opacity: 0; }
        }
        .pulse-ring { animation: pulse-ring 1.8s cubic-bezier(0.2, 0.8, 0.2, 1) infinite; }

        /* ---- AI Monolog terminal satırı ---- */
        @keyframes monolog-in {
            from { opacity: 0; transform: translateY(3px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .monolog-line { animation: monolog-in 0.3s ease forwards; }

        /* ---- Sermaye Kadranı donut geçiş ---- */
        .donut-segment { transition: stroke-dasharray 0.8s cubic-bezier(0.4, 0, 0.2, 1); }

        .toggle-dot {
            transition: transform 0.2s ease, background-color 0.2s ease;
        }

        input:checked ~ .toggle-track {
            background-color: rgba(139, 92, 246, 0.85);
        }

        /* toggle-dot, toggle-track'in ICINDE degil YANINDA (sibling) bir eleman - bu yuzden
           ".toggle-track .toggle-dot" (descendant) degil "~ .toggle-dot" (sibling) secici kullanilmali */
        input:checked ~ .toggle-dot {
            transform: translateX(18px);
            background-color: #fff;
        }

        /* ---- Islem Modu (Spot / Spot+Futures) radyo kartlari ---- */
        input:checked ~ .trading-mode-card {
            border-color: rgba(139, 92, 246, 0.5);
            background-color: rgba(139, 92, 246, 0.05);
        }
        input:checked ~ .trading-mode-card .trading-mode-badge {
            display: inline-flex;
        }

        /* ---- Genis ekran altinda (tablet/telefon) grid'i normal, kaydirilabilir akisa cevir ---- */
        @media (max-width: 1023px) {
            html, body {
                height: auto;
                overflow: auto;
            }

            /* display:flex + flex-direction:column, display:block'un YERINE - tek sutunlu dikey
               istifleme davranisi AYNI kalir, ama order ile ozel bir sirlama YAPILABILIR hale gelir
               (bkz. .area-hunts kurali, 31 Temmuz - musteri talebi: mobilde Aktif Avlar grafigin USTUNE) */
            .terminal-grid {
                display: flex;
                flex-direction: column;
                height: auto;
            }

            .terminal-grid > div {
                margin-bottom: 0.75rem;
                max-height: 420px;
            }

            /* Musteri talebi (31 Temmuz): mobilde acik pozisyonlari HEMEN gormek istiyor, grafigi
               kaydirmadan once - HTML sirasini (masaustunde grid-area ile bagimsiz konumlanan
               duzeni) degistirmeden SADECE mobilde en basa alinir */
            .terminal-grid > .area-hunts {
                order: -1;
            }

            /* Grafik (mum + fiyat ekseni + zaman butonlari), diger kucuk liste panellerinden
               (Haberler, Son Islemler vb.) cok daha fazla gorsel alana ihtiyac duyar - hepsini
               ayni 420px'e sikistirmak grafigi "kucuk/okunaksiz" gosteriyordu.
               31 Temmuz'da tespit edildi: SADECE max-height verilmesi mobilde grafigin TAMAMEN
               gorunmemesine sebep oluyordu - .area-chart icindeki #chartWidgetContainer'in
               (flex-1 min-h-0) buyuyecegi somut bir yukseklik yoktu (max-height bir UST SINIR
               tanimlar, gercek bir yukseklik VERMEZ), TradingView/lightweight-charts otomatik
               boyutlandirma (autosize) 0 yukseklikli bir container'da SESSIZCE hicbir sey cizmiyordu -
               ayni "container hidden iken createChart() cagrilmasin" ilkesinin (bkz. Canli Savas
               Radari modali, 27 Temmuz) baska bir varyanti. Somut height eklenince duzeldi */
            .terminal-grid > .area-chart {
                height: 80vh;
                max-height: 80vh;
            }

            /* Teknik Analiz Ozeti (kendi hesapladigimiz RSI/SMA/MACD gauge'u): AL/SAT/NOTR ibresi +
               detay satirlariyla birlikte genel liste panellerinden (420px) daha fazla dikey
               alana ihtiyac duyuyor, aksi halde mobilde kirpiliyor/gorunmuyordu */
            .terminal-grid > .area-technical {
                max-height: 560px;
            }

            /* Masaustunde siki bir arayuz icin bilerek kucuk tutulan yazi boyutlari (9-11px),
               mobilde okunurlugu ciddi sekilde zorluyordu - sadece dar ekranda buyutulur */
            .text-\[9px\]  { font-size: 11px !important; }
            .text-\[10px\] { font-size: 12px !important; }
            .text-\[11px\] { font-size: 12px !important; }

            /* iOS Safari, font-size'i 16px'in altinda olan bir input'a odaklanildiginda sayfayi
               otomatik yakinlastirir (istenmeyen "zip" efekti) - ayarlar formundaki tum alanlar
               bu yuzden mobilde 16px'e sabitlenir */
            input, select, textarea {
                font-size: 16px !important;
            }
        }

        /* ---- Notch/Dynamic Island ve alt tutamaç (home indicator) icin guvenli alan ----
           Ana ekrana eklenmis (standalone) PWA modunda status bar/gesture bar icerigin
           uzerine binmesin diye - normal tarayicida env() zaten 0 doner, zararsizdir */
        @supports (padding: max(0px)) {
            nav.flex-none {
                padding-top: max(0.5rem, env(safe-area-inset-top));
                padding-left: max(1.25rem, env(safe-area-inset-left));
                padding-right: max(1.25rem, env(safe-area-inset-right));
            }
        }
    </style>
</head>
<body class="flex flex-col text-gray-800">
    <!-- Hos Geldiniz ve Risk Bildirimi Modali: is_risk_accepted=0 oldugu surece EN USTTE, kapatilamaz
         sekilde gosterilir (backdrop'ta onclick YOK, X butonu YOK) - kullanici checkbox'i isaretleyip
         formu gondermeden ne bu sayfadaki ne de baska hicbir sayfadaki ayari degistiremez
         (bkz. DashboardController::requireRiskAccepted) -->
    <div id="riskModal" class="fixed inset-0 z-[100] <?= $riskAccepted ? 'hidden' : '' ?>">
        <div class="absolute inset-0 bg-black/80 backdrop-blur-sm"></div>
        <div class="relative h-full flex items-center justify-center p-4">
            <div class="glass-panel relative rounded-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto thin-scroll">
                <div class="px-6 py-5 border-b border-black/5">
                    <h2 class="font-display text-xl sm:text-2xl font-bold text-gray-900">🚀 NexaTrade'e Hoş Geldiniz</h2>
                    <p class="text-xs text-gray-500 mt-1">Yeni Nesil Yapay Zeka Fon Yönetimi</p>
                </div>
                <div class="px-6 py-5 space-y-5 text-sm text-gray-700 leading-relaxed">
                    <div>
                        <h3 class="font-display font-semibold text-gray-900 mb-1.5">NexaTrade Nedir?</h3>
                        <p>NexaTrade, kripto para piyasalarındaki fırsatları sizin yerinize saniye saniye izleyen, duygulardan arındırılmış ve tamamen yapay zeka (AI) destekli kantitatif bir al-sat otomasyonudur. Piyasada uyumayan, yorulmayan ve sadece matematiğe güvenen dijital bir fon yöneticisidir.</p>
                    </div>
                    <div>
                        <h3 class="font-display font-semibold text-gray-900 mb-1.5">Sistem Nasıl Çalışır?</h3>
                        <p class="mb-2">Sıradan botların aksine NexaTrade at gözlüğü takmaz.</p>
                        <ul class="space-y-2">
                            <li class="flex items-start gap-2">
                                <span class="text-violet-500 mt-0.5">•</span>
                                <span><strong class="text-gray-900">Makro Hafıza:</strong> Bir coini almadan önce son 90 günlük geçmişine bakar. Zirveye (dirence) ulaşmış, çoktan patlama yapmış (FOMO) coinlere asla girmez.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="text-violet-500 mt-0.5">•</span>
                                <span><strong class="text-gray-900">Keskin Nişancı Mantığı:</strong> Her gün yüzlerce işlem açarak kasanızı komisyonda eritmez. Sadece hacim artışının ve doğru fiyatlanmanın eşleştiği o nadir anlarda tetiği çeker.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="text-violet-500 mt-0.5">•</span>
                                <span><strong class="text-gray-900">Otonom Risk Yönetimi:</strong> Belirlediğiniz "Kâr Al" ve "Zarar Kes" (Stop-Loss) seviyelerine harfiyen uyar.</span>
                            </li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-display font-semibold text-gray-900 mb-1.5">Nasıl Kazanç Sağlanır?</h3>
                        <p>NexaTrade "bir gecede zengin olma" vaadi sunmaz; düzenli, disiplinli ve birleşik getiri (bileşik faiz) mantığıyla kasayı büyütmeyi hedefler. Sistemin kâr edebilmesi için piyasada sağlıklı bir hacim ve dalgalanma (volatilite) olması gerekir. Piyasa tamamen yatay veya sert bir düşüş trendindeyken sistem bütçenizi korumak için günlerce işlem açmayıp nakitte (USDT) bekleyebilir. Sabır, bu sistemin en büyük kazanç anahtarıdır.</p>
                    </div>
                    <div class="rounded-xl border border-rose-500/20 bg-rose-500/5 p-4">
                        <h3 class="font-display font-semibold text-rose-700 mb-2">⚠️ Risk Bildirimi ve Kullanıcı Sorumlulukları (Lütfen Dikkatle Okuyunuz)</h3>
                        <ul class="space-y-2">
                            <li class="flex items-start gap-2">
                                <span class="text-rose-500 mt-0.5">•</span>
                                <span><strong class="text-gray-900">Sermaye Riski:</strong> Kripto para piyasaları dünyanın en riskli ve en hareketli finansal pazarıdır. Sisteme sadece kaybettiğinizde hayat standardınızı etkilemeyecek miktarda bütçe ayırınız.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="text-rose-500 mt-0.5">•</span>
                                <span><strong class="text-gray-900">Garanti Yoktur:</strong> Yapay zeka geçmiş verileri analiz ederek en yüksek kazanma ihtimalini hesaplar, ancak geleceği kesin olarak bilemez. Hiçbir işlemde %100 kâr garantisi yoktur. Piyasaya aniden düşen küresel bir haber, botun tüm analizlerini geçersiz kılabilir ve sistem "Zarar Kes" (Stop) yaparak işlemi ekside kapatabilir.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="text-rose-500 mt-0.5">•</span>
                                <span><strong class="text-gray-900">Kişisel Sorumluluk:</strong> NexaTrade sadece bir yazılım aracıdır. API anahtarlarınızın güvenliği, belirlenen risk ayarları ve borsada uğranabilecek olası finansal kayıplar tamamen kullanıcının kendi sorumluluğundadır.</span>
                            </li>
                        </ul>
                    </div>
                </div>
                <form action="<?= htmlspecialchars(Url::to('/dashboard/accept-risk'), ENT_QUOTES, 'UTF-8') ?>" method="POST" class="px-6 py-5 border-t border-black/5 bg-black/[0.02] rounded-b-2xl">
                    <label class="flex items-start gap-2.5 cursor-pointer select-none mb-4">
                        <input type="checkbox" id="riskAcceptCheckbox" required class="mt-0.5 h-4 w-4 rounded border-black/20 text-violet-600 focus:ring-violet-400/40">
                        <span class="text-sm text-gray-800">Okudum, anladım ve NexaTrade'in risklerini bilerek sistemi kullanmayı kabul ediyorum.</span>
                    </label>
                    <button type="submit" id="riskAcceptSubmit" disabled class="w-full sm:w-auto px-6 py-2.5 rounded-lg bg-violet-600 text-white font-medium text-sm transition-colors hover:bg-violet-500 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-violet-600">
                        Kabul Ediyorum ve Devam Et
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Navbar -->
    <nav class="flex-none flex flex-wrap items-center justify-between px-5 py-2 border-b border-violet-500/10 bg-white/85 backdrop-blur-md gap-4">
        <div class="flex items-center gap-2 flex-none">
            <img src="<?= htmlspecialchars(Url::to('/assets/img/logo.png'), ENT_QUOTES, 'UTF-8') ?>" alt="NexaTrade" class="h-11 w-auto">
            <button type="button" onclick="openChangelogModal()" title="Neler Yeni? (sürüm notları)" class="hidden sm:inline-block font-mono-tech text-[10px] text-gray-500 hover:text-violet-600 hover:border-violet-400/50 tracking-widest border border-black/10 rounded px-1.5 py-0.5 ml-1 transition-colors">v<?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" onclick="openSystemStatusModal()" title="Sistem Durumu" class="inline-flex items-center gap-1 font-mono-tech text-[10px] text-gray-500 hover:text-violet-600 hover:border-violet-400/50 tracking-widest border border-black/10 rounded px-1.5 py-0.5 ml-1 transition-colors">
                <span id="systemStatusDot" class="w-1.5 h-1.5 rounded-full bg-gray-300 animate-pulse flex-none"></span>
                <span class="hidden sm:inline">SİSTEM</span>
            </button>
        </div>

        <!-- Cuzdan & istatistik: yatay dizilim, ortalanmis, belirgin bir arka plan kutusu icinde
             Mobilde (nav flex-wrap sayesinde) logo+butonlarin ALTINDA, tam genislikte AYRI bir
             satira duser (order-3 + w-full); dar ekranda tasan kisim overflow-x-auto ile yana kaydirilir.
             md+ ekranda eskisi gibi tek satirda, ortada kalir (order-none + w-auto) -->
        <div class="order-3 w-full mt-2 md:mt-0 md:w-auto md:order-none flex justify-center md:flex-1 min-w-0">
            <!-- Mobilde (md altinda) 3 sutunluk bir izgaraya donusur - 6 istatistigin TAMAMI, yatay
                 kaydirmaya gerek kalmadan tek bakista gorunur. md+ ekranda eskisi gibi tek satirda,
                 ayirici cizgilerle bolunmus halde kalir (ayiricilar mobilde gizlenir) -->
            <div class="grid grid-cols-3 gap-x-3 gap-y-2 md:flex md:items-center md:gap-3.5 font-mono-tech text-[11px] bg-black/[0.03] border border-black/10 rounded-lg px-4 py-2 md:py-1.5 max-w-full md:overflow-x-auto">
                <div class="flex flex-col md:flex-row md:items-center gap-0.5 md:gap-1.5 md:whitespace-nowrap">
                    <span class="text-gray-500 tracking-widest">BAKİYE</span>
                    <span id="navBalanceValue" class="text-gray-900 font-bold animate-pulse">…</span>
                    <span id="navBalanceSubtext" class="text-[9px] text-gray-500">yükleniyor</span>
                </div>
                <div class="hidden md:block w-px h-4 bg-black/5 flex-none"></div>
                <div class="flex flex-col md:flex-row md:items-center gap-0.5 md:gap-1.5 md:whitespace-nowrap">
                    <span class="text-gray-500 tracking-widest">GÜNLÜK PNL</span>
                    <span id="navDailyPnl" class="font-bold <?= $pnlClass ?>"><?= htmlspecialchars($pnlValue, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="hidden md:block w-px h-4 bg-black/5 flex-none"></div>
                <div class="flex flex-col md:flex-row md:items-center gap-0.5 md:gap-1.5 md:whitespace-nowrap">
                    <span class="text-gray-500 tracking-widest">TAMAMLANAN</span>
                    <span id="navCompletedOrders" class="text-gray-900 font-bold"><?= (int) $completedOrders ?></span>
                </div>
                <div class="hidden md:block w-px h-4 bg-black/5 flex-none"></div>
                <div class="flex flex-col md:flex-row md:items-center gap-0.5 md:gap-1.5 md:whitespace-nowrap">
                    <span class="text-gray-500 tracking-widest">AÇIK POZİSYON</span>
                    <span id="navOpenPositions" class="text-gray-900 font-bold"><?= count($activeTrades) + count($activeFuturesTrades) ?></span>
                </div>
                <div class="hidden md:block w-px h-4 bg-black/5 flex-none"></div>
                <div class="flex flex-col md:flex-row md:items-center gap-0.5 md:gap-1.5 md:whitespace-nowrap">
                    <span class="text-gray-500 tracking-widest">PORTFÖY</span>
                    <span id="navPortfolioValue" class="text-gray-900 font-bold animate-pulse">…</span>
                </div>
                <div class="hidden md:block w-px h-4 bg-black/5 flex-none"></div>
                <div class="flex flex-col md:flex-row md:items-center gap-0.5 md:gap-1.5 md:whitespace-nowrap">
                    <span class="text-gray-500 tracking-widest">GR.DIŞI</span>
                    <span id="navUnrealized" class="text-gray-400 font-bold animate-pulse">…</span>
                </div>
            </div>
        </div>

        <div class="flex items-center flex-wrap justify-end gap-x-3 gap-y-1.5 max-w-full flex-none">
            <span class="hidden lg:inline font-mono-tech text-xs text-gray-500"><?= $userEmail ?></span>
            <?php if ($isAdmin): ?>
                <a href="<?= htmlspecialchars(Url::to('/admin'), ENT_QUOTES, 'UTF-8') ?>" class="font-mono-tech text-xs px-3 py-1.5 rounded-lg border border-cyan-400/30 text-cyan-600 hover:bg-cyan-400/10 hover:border-cyan-400/50 transition-colors">◆ ADMIN</a>
            <?php endif; ?>
            <button type="button" onclick="openSettingsModal()" class="font-mono-tech text-xs px-3 py-1.5 rounded-lg border border-violet-500/30 text-violet-600 hover:bg-violet-500/10 hover:border-violet-400/50 transition-colors">⚙ AYARLAR</button>
            <a href="<?= htmlspecialchars(Url::to('/logout'), ENT_QUOTES, 'UTF-8') ?>" class="font-mono-tech text-xs px-3 py-1.5 rounded-lg border border-black/10 text-gray-600 hover:bg-black/5 hover:text-gray-900 transition-colors">ÇIKIŞ</a>
            <!-- Sistem Kalp Atışı: yanindaki yazi sabit "ÇEVRİMİÇİ" degil, sistemin su anki risk
                 profilini (Guvenli/Dengeli/Agresif) gosterir - mobilde de gorunur (bkz. yorum
                 gecmisi), boylece tek bakista hem "sistem calisiyor" hem "hangi modda calisiyor"
                 bilgisi alinir. Once dar ekranda tasmaya sebep oldugu icin mobilde tamamen
                 gizlenmisti - dogru duzeltme metni gizlemek degil, ust grubun flex-wrap olmasi:
                 sigmadiginda ADMIN/AYARLAR/ÇIKIŞ'in ALTINA kendi satirina kayar, kaybolmaz.
                 Ayirici cizgi (border-l) SADECE sm+ ekranda gosterilir - mobilde kendi satirina
                 kayınca bir onceki elemandan ayiran dikey cizginin anlami kalmiyor -->
            <?php $navModeMeta = RiskProfileService::get($riskProfile); ?>
            <div class="flex items-center gap-1.5 sm:border-l sm:border-black/10 sm:pl-3 sm:ml-1">
                <span class="relative flex w-2.5 h-2.5 flex-none">
                    <span class="pulse-ring absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full w-2.5 h-2.5 bg-emerald-400 shadow-[0_0_6px_2px_rgba(52,211,153,0.5)]"></span>
                </span>
                <span class="font-mono-tech text-[10px] text-emerald-600 tracking-widest whitespace-nowrap">
                    <?= htmlspecialchars($navModeMeta['emoji'], ENT_QUOTES, 'UTF-8') ?>
                    <?= htmlspecialchars(mb_strtoupper($navModeMeta['label'], 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
        </div>
    </nav>

    <!-- Kayan Fiyat Bandı: 31 Temmuz'da TradingView'in resmi Ticker Tape widget'ına geri dönüldü -
         musteri talebi: "ikonlar kayan seritte tradingview olsun" (coin logolari). Ana Grafik'teki AYNI
         gerekce (bkz. o panelin yorumu, 22 Temmuz "bad auth token" artik gecerli degil - ozel VPS IP'si).
         Eski Binance-REST-tabanli ozel serit (initTickerTape/refreshTickerTape) kod tabaninda BOZULMADAN
         duruyor (kullanilmiyor) - tekrar sorun cikarsa hizli geri donus icin.
         displayMode: "adaptive" (ilk deneme) mobilde surekli kayan TEK satir yerine 2 satirlik bir
         izgaraya donusup sabit 46px yukseklikli container'da kirpiliyordu (yuzde degisim satiri hic
         gorunmuyordu, son sembol yatay kesiliyordu) - "regular" sabitlendi, genislik ne olursa olsun
         TEK satir kayan bant garanti eder (Playwright ile mobil viewport'ta dogrulandi) -->
    <div class="flex-none h-[46px] overflow-hidden border-b border-violet-500/10 bg-white/70 relative">
        <div class="tradingview-widget-container" style="height:100%;width:100%">
            <div class="tradingview-widget-container__widget"></div>
            <script type="text/javascript" src="https://s3.tradingview.com/external-embedding/embed-widget-ticker-tape.js" async>
            {
                "symbols": [
                    { "proName": "BINANCE:BTCUSDT", "title": "BTC/USDT" },
                    { "proName": "BINANCE:ETHUSDT", "title": "ETH/USDT" },
                    { "proName": "BINANCE:BNBUSDT", "title": "BNB/USDT" },
                    { "proName": "BINANCE:SOLUSDT", "title": "SOL/USDT" },
                    { "proName": "BINANCE:XRPUSDT", "title": "XRP/USDT" }
                ],
                "showSymbolLogo": true,
                "isTransparent": true,
                "displayMode": "regular",
                "colorTheme": "light",
                "locale": "tr"
            }
            </script>
        </div>
    </div>

    <!-- FİNANSAL ÖZET -->
    <div class="flex-none px-2.5 pt-2 pb-0">
        <div class="glass-panel rounded-2xl border border-black/5 px-5 py-3 flex flex-wrap items-center gap-x-8 gap-y-2.5 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-r from-violet-500/[0.03] to-transparent pointer-events-none"></div>

            <!-- Günlük -->
            <div class="flex flex-col min-w-[90px]">
                <span class="font-mono-tech text-[9px] text-gray-500 tracking-widest uppercase mb-0.5">24 SAAT P&L</span>
                <span id="pnlDaily" class="font-mono-tech text-sm font-bold text-gray-400 animate-pulse">···</span>
            </div>

            <div class="w-px h-7 bg-black/[0.03] flex-none hidden sm:block"></div>

            <!-- Haftalık -->
            <div class="flex flex-col min-w-[90px]">
                <span class="font-mono-tech text-[9px] text-gray-500 tracking-widest uppercase mb-0.5">7 GÜN P&L</span>
                <span id="pnlWeekly" class="font-mono-tech text-sm font-bold text-gray-400 animate-pulse">···</span>
            </div>

            <div class="w-px h-7 bg-black/[0.03] flex-none hidden sm:block"></div>

            <!-- Aylık -->
            <div class="flex flex-col min-w-[90px]">
                <span class="font-mono-tech text-[9px] text-gray-500 tracking-widest uppercase mb-0.5">30 GÜN P&L</span>
                <span id="pnlMonthly" class="font-mono-tech text-sm font-bold text-gray-400 animate-pulse">···</span>
            </div>

            <div class="w-px h-7 bg-black/[0.03] flex-none hidden sm:block"></div>

            <!-- Kazanma Oranı -->
            <div class="flex items-center gap-3">
                <svg class="flex-none" width="40" height="40" viewBox="0 0 40 40">
                    <circle cx="20" cy="20" r="15" fill="none" stroke="rgba(255,255,255,0.05)" stroke-width="4"/>
                    <circle id="winRateArc" cx="20" cy="20" r="15" fill="none"
                            stroke="rgba(139,92,246,0.6)" stroke-width="4"
                            stroke-dasharray="0 94.25" stroke-linecap="round"
                            transform="rotate(-90 20 20)" class="transition-all duration-700"/>
                </svg>
                <div class="flex flex-col">
                    <span class="font-mono-tech text-[9px] text-gray-500 tracking-widest uppercase mb-0.5">KAZANMA ORANI</span>
                    <span id="pnlWinRate" class="font-mono-tech text-sm font-bold text-gray-400 animate-pulse">···</span>
                    <span id="pnlWinDetail" class="font-mono-tech text-[9px] text-gray-400"></span>
                </div>
            </div>

            <!-- Sağ köşe: Risk Profili etiketi -->
            <?php
                $rpMeta = RiskProfileService::get($riskProfile);
                $rpBadgeColor = match($riskProfile) {
                    'safe'       => 'border-sky-400/40 text-sky-600 bg-sky-400/10',
                    'aggressive' => 'border-rose-400/40 text-rose-600 bg-rose-400/10',
                    default      => 'border-violet-400/40 text-violet-600 bg-violet-400/10',
                };
                $rpDotColor = match($riskProfile) {
                    'safe'       => 'bg-sky-400',
                    'aggressive' => 'bg-rose-400',
                    default      => 'bg-violet-400',
                };
            ?>
            <div class="ml-auto flex-none hidden md:flex flex-col items-end gap-1">
                <span class="font-mono-tech text-[9px] text-gray-400 tracking-widest">RİSK PROFİLİ</span>
                <span class="inline-flex items-center gap-1.5 border rounded-lg px-2 py-0.5 font-mono-tech text-[10px] font-semibold tracking-wide <?= $rpBadgeColor ?>">
                    <span class="w-1.5 h-1.5 rounded-full <?= $rpDotColor ?> shadow-[0_0_6px_2px_currentColor] opacity-80"></span>
                    <?= $rpMeta['emoji'] ?> <?= htmlspecialchars(mb_strtoupper($rpMeta['label']), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Ana terminal grid -->
    <div class="terminal-grid flex-1 min-h-0 gap-2.5 p-2.5 box-border">

        <!-- Grafik -->
        <div class="area-chart relative glass-panel rounded-2xl flex flex-col min-h-0 overflow-hidden">
            <div class="flex-none flex items-center justify-between px-4 py-2 border-b border-black/5">
                <div class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-rose-400 shadow-[0_0_6px_2px_rgba(251,113,133,0.6)] animate-blink"></span>
                    <select id="chartSymbolSelect" onchange="loadChart(this.value)" class="bg-gray-100 border border-black/10 rounded-lg px-2 py-1 text-xs font-mono-tech text-gray-800 focus:outline-none focus:border-violet-400/60 focus:ring-1 focus:ring-violet-400/40 cursor-pointer">
                        <option value="BINANCE:BTCUSDT">BTC/USDT</option>
                        <option value="BINANCE:ETHUSDT">ETH/USDT</option>
                        <option value="BINANCE:BNBUSDT">BNB/USDT</option>
                        <option value="BINANCE:SOLUSDT">SOL/USDT</option>
                        <option value="BINANCE:XRPUSDT">XRP/USDT</option>
                        <option value="BINANCE:DOGEUSDT">DOGE/USDT</option>
                        <option value="BINANCE:ADAUSDT">ADA/USDT</option>
                        <option value="BINANCE:AVAXUSDT">AVAX/USDT</option>
                        <option value="BINANCE:DOTUSDT">DOT/USDT</option>
                        <option value="BINANCE:LINKUSDT">LINK/USDT</option>
                        <option value="BINANCE:TRXUSDT">TRX/USDT</option>
                        <option value="BINANCE:LTCUSDT">LTC/USDT</option>
                    </select>
                </div>
                <!-- Sermaye Kadranı -->
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-1.5" title="Sermaye Kadranı">
                        <svg id="donutChart" width="34" height="34" viewBox="0 0 34 34" style="transform:rotate(-90deg)">
                            <circle cx="17" cy="17" r="11" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="5"/>
                            <circle id="donutUsed" cx="17" cy="17" r="11" fill="none" stroke="rgba(139,92,246,0.75)" stroke-width="5" stroke-dasharray="0 69.1" stroke-linecap="round" class="donut-segment"/>
                        </svg>
                        <div class="font-mono-tech text-[9px] leading-tight">
                            <div><span id="donutPct" class="text-violet-600 font-bold">—</span></div>
                            <div id="donutFree" class="text-gray-400 text-[8px]">USDT</div>
                        </div>
                    </div>
                    <span class="font-mono-tech text-[10px] text-gray-500 tracking-widest">MERHABA, <?= mb_strtoupper($userName) ?></span>
                </div>
            </div>
            <!-- Ana Grafik: 31 Temmuz'da TradingView Advanced Chart widget'ına geri dönüldü (VPS'e
                 geçtiğimiz için "bad auth token" sorununun artık geçerli olmaması bekleniyor - bkz.
                 yukarıdaki <script> yorumu). #lwChart/#lwChartStatus KASITLI olarak hâlâ DOM'da
                 duruyor (hidden) - loadChart()'taki eski initLightweightChart() yolu bozulmadan
                 kalsın diye, TradingView tekrar sorun çıkarırsa tek fonksiyon değişikliğiyle geri dönülür -->
            <div id="chartWidgetContainer" class="flex-1 min-h-0 relative">
                <div id="tvChartContainer" class="absolute inset-0"></div>
                <div id="lwChart" class="absolute inset-0 hidden"></div>
                <p id="lwChartStatus" class="absolute inset-0 hidden items-center justify-center font-mono-tech text-xs text-gray-400">Yükleniyor...</p>
            </div>
        </div>

        <!-- Teknik Analiz Ozeti: TradingView widget'i yerine, ayni Binance mum verisinden istemci
             tarafinda hesaplanan RSI(14)/SMA(20,50)/MACD(12,26,9) oy birligiyle AL-SAT-NOTR gostergesi -->
        <div class="area-technical relative glass-panel rounded-2xl flex flex-col min-h-0 overflow-hidden">
            <div class="flex-none flex items-center justify-between px-4 py-2 border-b border-black/5">
                <span class="font-mono-tech text-[10px] tracking-widest text-gray-500">TEKNİK ANALİZ ÖZETİ</span>
                <span id="technicalWidgetSymbol" class="font-mono-tech text-[10px] text-violet-600">BTCUSDT</span>
            </div>
            <div id="technicalWidgetContainer" class="flex-1 min-h-0 flex flex-col items-center justify-center px-3 py-2 gap-1.5">
                <p class="font-mono-tech text-xs text-gray-400">Hesaplanıyor...</p>
            </div>
        </div>

        <!-- AI Radar -->
        <div class="area-radar relative glass-panel rounded-2xl flex flex-col min-h-0 overflow-hidden">
            <div class="flex-none flex items-center justify-between px-4 py-2.5 border-b border-black/5">
                <div class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 shadow-[0_0_6px_2px_rgba(52,211,153,0.6)] animate-blink"></span>
                    <span class="font-mono-tech text-xs tracking-wider text-gray-800">AI RADAR</span>
                </div>
                <div class="flex items-center gap-2">
                    <span id="radarUpdatedAt" class="font-mono-tech text-[9px] text-gray-400">—</span>
                    <span class="font-mono-tech text-[10px] text-gray-500 tracking-widest">EŞİK &gt; <?= (int) $rpMeta['ai_score_threshold'] ?></span>
                </div>
            </div>
            <div class="relative flex-1 min-h-0 overflow-y-auto thin-scroll px-3 py-2" id="radarContainer">
                <p class="font-mono-tech text-xs text-gray-500">Taranıyor...</p>
            </div>
            <!-- Canlı AI Monologu -->
            <div class="flex-none border-t border-black/5">
                <div class="flex items-center justify-between px-4 py-1.5">
                    <span class="font-mono-tech text-[9px] tracking-widest text-violet-600">⬡ AI MONOLOG</span>
                    <span class="font-mono-tech text-[9px] text-gray-400">SON 5 KAYIT</span>
                </div>
                <div id="monologContainer" class="px-3 pb-2.5 max-h-28 overflow-y-auto thin-scroll space-y-0.5 font-mono-tech text-[10px]">
                    <p class="text-gray-400">Yükleniyor...</p>
                </div>
            </div>
            <!-- Görünmez Kalkan Raporu: botun MTF/Emir Defteri sert filtreleriyle engellediği
                 tuzakların müşteri-yüzlü özeti (bkz. AiIntervention modeli) -->
            <div class="flex-none border-t border-black/5">
                <div class="flex items-center justify-between px-4 py-1.5">
                    <span class="font-mono-tech text-[9px] tracking-widest text-sky-600">🛡️ AI KALKANI</span>
                    <span class="font-mono-tech text-[9px] text-gray-400">SON 5 KAYIT</span>
                </div>
                <div id="shieldContainer" class="px-3 pb-2.5 max-h-20 overflow-y-auto thin-scroll space-y-0.5 font-mono-tech text-[10px]">
                    <p class="text-gray-400">Yükleniyor...</p>
                </div>
            </div>
        </div>

        <!-- Haberler -->
        <div class="area-news relative glass-panel rounded-2xl flex flex-col min-h-0 overflow-hidden">
            <div class="flex-none flex items-center justify-between px-4 py-2.5 border-b border-black/5">
                <div class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 shadow-[0_0_6px_2px_rgba(34,211,238,0.6)] animate-blink"></span>
                    <span class="font-mono-tech text-xs tracking-wider text-gray-800">HABERLER</span>
                </div>
                <div class="flex items-center gap-2">
                    <span id="newsUpdatedAt" class="font-mono-tech text-[9px] text-gray-400">—</span>
                    <span class="font-mono-tech text-[10px] text-gray-500 tracking-widest">CANLI AKIŞ</span>
                </div>
            </div>
            <div id="marketPulseBox" class="hidden flex-none px-4 py-2 border-b border-black/5 bg-violet-500/5">
                <span class="text-[9px] font-bold text-violet-600 tracking-widest">AI PİYASA NABZI</span>
                <p id="marketPulseText" class="font-mono-tech text-[11px] text-gray-700 leading-relaxed mt-0.5"></p>
            </div>
            <div class="news-ticker-viewport flex-1 min-h-0 overflow-hidden relative" id="newsContainer">
                <p class="font-mono-tech text-xs text-gray-500 px-4 py-3">Akış yükleniyor...</p>
            </div>
        </div>

        <!-- Yeni Listelenenler -->
        <div class="area-listings relative glass-panel rounded-2xl flex flex-col min-h-0 overflow-hidden">
            <div class="flex-none flex items-center justify-between px-4 py-2.5 border-b border-black/5">
                <div class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400 shadow-[0_0_6px_2px_rgba(251,191,36,0.6)] animate-blink"></span>
                    <span class="font-mono-tech text-xs tracking-wider text-gray-800">YENİ LİSTELENEN</span>
                </div>
                <span class="font-mono-tech text-[10px] text-gray-500 tracking-widest">SON 3 GÜN</span>
            </div>
            <!-- Korku ve Açgözlülük Barı -->
            <div class="flex-none px-4 py-2 border-b border-black/5">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="font-mono-tech text-[9px] tracking-widest text-gray-500">PİYASA DUYARLILIĞI</span>
                    <span id="fearGreedLabel" class="font-mono-tech text-[9px] font-bold text-gray-400">—</span>
                </div>
                <div class="w-full h-1.5 rounded-full bg-black/[0.03] overflow-hidden">
                    <div id="fearGreedBar" class="h-full rounded-full bg-gray-600 transition-all duration-700" style="width:0%"></div>
                </div>
            </div>
            <div class="flex-1 min-h-0 overflow-y-auto thin-scroll px-3 py-2">
                <?php if (empty($recentListings)): ?>
                    <p class="font-mono-tech text-xs text-gray-500">Yeni listeleme yok</p>
                <?php else: ?>
                    <div class="font-mono-tech text-[11px] space-y-1">
                        <?php foreach ($recentListings as $listing): ?>
                            <?php
                            $listingSymbol = (string) $listing['symbol'];
                            $hoursAgo = (int) floor((time() - strtotime((string) $listing['first_seen_at'])) / 3600);
                            $agoLabel = $hoursAgo < 1 ? 'az önce' : ($hoursAgo < 24 ? $hoursAgo . 's önce' : floor($hoursAgo / 24) . 'g önce');
                            ?>
                            <div onclick="loadChart('BINANCE:<?= htmlspecialchars($listingSymbol, ENT_QUOTES, 'UTF-8') ?>')" title="Grafikte aç" class="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 cursor-pointer hover:bg-black/5 transition-colors">
                                <span class="flex items-center gap-1.5">
                                    <span class="text-[8px] font-bold text-amber-600 border border-amber-400/40 rounded px-1 py-0.5">YENİ</span>
                                    <span class="font-semibold text-gray-800"><?= htmlspecialchars($listingSymbol, ENT_QUOTES, 'UTF-8') ?></span>
                                </span>
                                <span class="text-gray-500"><?= htmlspecialchars($agoLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Son islemler -->
        <div class="area-orders relative glass-panel rounded-2xl flex flex-col min-h-0 overflow-hidden">
            <div class="flex-none flex items-center justify-between px-4 py-2.5 border-b border-black/5">
                <span class="font-mono-tech text-xs tracking-wider text-gray-800">SON İŞLEMLER</span>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="openOrderHistoryModal()" class="font-mono-tech text-[9px] tracking-widest text-violet-600 border border-violet-400/30 hover:bg-violet-400/10 rounded px-1.5 py-0.5 transition-colors">GEÇMİŞ</button>
                    <button type="button" onclick="openPerformanceModal()" class="font-mono-tech text-[9px] tracking-widest text-violet-600 border border-violet-400/30 hover:bg-violet-400/10 rounded px-1.5 py-0.5 transition-colors">ANALİZ</button>
                    <span id="recentOrdersCount" class="font-mono-tech text-[10px] text-gray-500 tracking-widest"><?= count($recentOrders) ?> KAYIT</span>
                </div>
            </div>
            <!-- SON BOT TARAMASI — JS ile doldurulur -->
            <div class="flex-none border-b border-black/5 bg-violet-500/[0.03]">
                <div class="flex items-center justify-between px-4 py-1.5">
                    <span class="font-mono-tech text-[9px] tracking-widest text-violet-600">⬡ SON BOT TARAMASI</span>
                    <span id="scanRunAt" class="font-mono-tech text-[9px] text-gray-400">yükleniyor…</span>
                </div>
                <div id="scanScores" class="flex flex-wrap gap-x-3 gap-y-0.5 px-4 pb-1.5 font-mono-tech text-[9px] text-gray-400">
                    <span>Bekleniyor</span>
                </div>
            </div>
            <div id="recentOrdersContainer" class="flex-1 min-h-0 overflow-y-auto thin-scroll">
                <?php if (empty($recentOrders)): ?>
                    <p class="font-mono-tech text-xs text-gray-500 px-4 py-3">Henüz işlem yok</p>
                <?php else: ?>
                    <table class="w-full font-mono-tech text-[11px]">
                        <thead>
                            <tr class="text-left text-gray-500 text-[9px] tracking-widest sticky top-0 bg-white">
                                <th class="font-medium px-4 py-1.5">PARİTE</th>
                                <th class="font-medium px-2 py-1.5">YÖN</th>
                                <th class="font-medium px-2 py-1.5">MİKTAR</th>
                                <th class="font-medium px-2 py-1.5">FİYAT</th>
                                <th class="font-medium px-4 py-1.5">DURUM</th>
                                <th class="font-medium px-2 py-1.5 text-right"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentOrders as $order): ?>
                                <?php
                                $side = strtoupper((string) $order['side']);
                                $status = strtoupper((string) $order['status']);

                                $sideLabel = $side === 'BUY' ? 'AL' : 'SAT';
                                $sideClasses = $side === 'BUY' ? 'bg-emerald-400/10 text-emerald-600' : 'bg-rose-400/10 text-rose-600';

                                $statusMap = [
                                    'FILLED' => ['OK', 'bg-emerald-400/10 text-emerald-600'],
                                    'FAILED' => ['HATA', 'bg-rose-400/10 text-rose-600'],
                                    'PENDING' => ['BEKLİYOR', 'bg-amber-400/10 text-amber-600'],
                                    'CANCELLED' => ['İPTAL', 'bg-gray-400/10 text-gray-600'],
                                ];
                                [$statusLabel, $statusClasses] = $statusMap[$status] ?? [$status, 'bg-gray-400/10 text-gray-600'];
                                $errorMessage = (string) ($order['error_message'] ?? '');
                                $statusTitle = $status === 'FAILED' && $errorMessage !== '' ? $errorMessage : '';
                                ?>
                                <?php $lossReason = (string) ($order['loss_reason'] ?? ''); ?>
                                <tr class="border-t border-black/5 hover:bg-black/[0.015] cursor-pointer transition-colors" onclick="openOrderDetail(<?= (int) $order['id'] ?>)">
                                    <td data-coin-name="<?= htmlspecialchars((string) $order['pair'], ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-1.5 font-semibold text-gray-800"><?= htmlspecialchars((string) $order['pair'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-2 py-1.5">
                                        <span class="rounded px-1.5 py-0.5 <?= $sideClasses ?>"><?= $sideLabel ?></span>
                                        <?php if ($lossReason !== ''): ?>
                                            <!-- Trade Post-Mortem: zararla kapanan pozisyonun kok nedeni, mouse-over tooltip ile -->
                                            <span class="ml-0.5 cursor-help" title="<?= htmlspecialchars($lossReason, ENT_QUOTES, 'UTF-8') ?>">ℹ️</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-2 py-1.5 text-gray-600"><?= htmlspecialchars($trimQty((float) $order['quantity']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-2 py-1.5 text-gray-600">$<?= htmlspecialchars($formatTradePrice((float) $order['price']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-4 py-1.5">
                                        <span class="rounded px-1.5 py-0.5 <?= $statusClasses ?> <?= $statusTitle !== '' ? 'cursor-help border-b border-dashed border-rose-400/50' : '' ?>"
                                              <?= $statusTitle !== '' ? 'title="' . htmlspecialchars($statusTitle, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                                            <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td class="px-2 py-1.5 text-right text-gray-400">👁</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Aktif Avlar -->
        <div class="area-hunts relative glass-panel rounded-2xl flex flex-col min-h-0 overflow-hidden">
            <div class="flex-none flex items-center justify-between px-4 py-2.5 border-b border-black/5">
                <span class="font-mono-tech text-xs tracking-wider text-gray-800">AKTİF AVLAR</span>
                <div class="flex items-center gap-2">
                    <span id="huntsUpdatedAt" class="font-mono-tech text-[9px] text-gray-400">—</span>
                    <span id="huntsPositionCount" class="font-mono-tech text-[10px] text-gray-500 tracking-widest"><?= count($activeTrades) + count($activeFuturesTrades) ?> POZİSYON</span>
                </div>
            </div>
            <!-- Bekleyen Emirler: tum filtrelerden gecip GERCEK bir Binance limit emri konulmus ama
                 henuz DOLMAMIS adaylar - musteri talebi (31 Temmuz): "kactan/ne kadarlik alacagini
                 ONCEDEN gormek istiyorum". huntsContainer'dan AYRI (o acik POZISYONLARI temsil eder,
                 bu henuz gerceklesmemis bir DENEME) - JS syncHuntCards()'ı bozmamak icin ayri kart tipi -->
            <div id="pendingOrdersContainer" class="flex-none <?= empty($pendingOrders) ? 'hidden' : '' ?> px-3 pt-2 space-y-1.5 border-b border-black/5 pb-2">
                <?php foreach ($pendingOrders as $po): ?>
                    <div data-pending-card="<?= (int) $po['id'] ?>" class="rounded-lg border border-dashed border-amber-400/40 bg-amber-400/[0.04] px-3 py-1.5">
                        <div class="flex justify-between items-center">
                            <span class="flex items-center gap-1.5">
                                <span class="font-mono-tech text-[9px] font-bold text-amber-600 border border-amber-400/40 rounded px-1 py-0.5">⏳ BEKLİYOR</span>
                                <span data-coin-name="<?= htmlspecialchars((string) $po['pair'], ENT_QUOTES, 'UTF-8') ?>" class="font-mono-tech text-xs font-semibold text-gray-800"><?= htmlspecialchars((string) $po['pair'], ENT_QUOTES, 'UTF-8') ?></span>
                            </span>
                            <span data-pending-remaining="<?= (int) $po['id'] ?>" class="font-mono-tech text-[9px] text-amber-600"><?= (int) round(((int) $po['remaining_seconds']) / 60) ?> dk kaldı</span>
                        </div>
                        <div class="flex justify-between items-center mt-0.5">
                            <span class="font-mono-tech text-[10px] text-gray-500">Fiyat: $<?= $formatTradePrice((float) $po['limit_price']) ?> · Miktar: <?= $trimQty((float) $po['quantity']) ?></span>
                            <span class="font-mono-tech text-[10px] text-gray-500">~$<?= number_format((float) $po['budget'], 2) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div id="huntsContainer" class="flex-1 min-h-0 overflow-y-auto thin-scroll px-3 py-2 space-y-2">
                <?php if (empty($activeTrades) && empty($activeFuturesTrades)): ?>
                    <p data-hunts-empty="1" class="font-mono-tech text-xs text-gray-500">Açık pozisyon yok</p>
                <?php else: ?>
                    <?php foreach ($activeTrades as $trade): ?>
                        <?php
                        $entryPrice = (float) $trade['entry_price'];
                        $targetPrice = (float) $trade['take_profit_price'];
                        $stopPrice = (float) $trade['stop_loss_price'];
                        $tradeId = (int) $trade['id'];
                        $trailingStage = (int) ($trade['trailing_stop_stage'] ?? 0);
                        // Kar Al Tavanini Kaldirma: Sinirsiz Izleme'de artik sabit bir TP hedefi yok -
                        // bkz. AutoTradeController::removeTakeProfitCeiling()
                        $takeProfitRemoved = (int) ($trade['take_profit_removed'] ?? 0) === 1;
                        ?>
                        <div data-hunt-card="<?= $tradeId ?>" class="rounded-lg border border-black/5 bg-black/[0.02] px-3 py-2">
                            <div class="flex justify-between items-center mb-1">
                                <span class="flex items-center gap-1.5">
                                    <span class="font-mono-tech text-[9px] font-bold text-emerald-600 border border-emerald-400/40 rounded px-1 py-0.5">UZUN</span>
                                    <span data-coin-name="<?= htmlspecialchars((string) $trade['pair'], ENT_QUOTES, 'UTF-8') ?>" class="font-mono-tech text-xs font-semibold text-gray-800"><?= htmlspecialchars((string) $trade['pair'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span data-trade-shield="<?= $tradeId ?>" class="font-mono-tech text-[9px] font-bold rounded px-1 py-0.5 border <?= $trailingStage >= 1 ? 'text-violet-600 border-violet-400/40' : 'text-gray-400 border-black/10' ?>"><?= $trailingStage >= 1 ? '🛡️ AKTİF' : '🛡️ PASİF' ?></span>
                                </span>
                                <span class="font-mono-tech text-[10px] text-gray-500">Giriş: $<?= $formatTradePrice($entryPrice) ?></span>
                            </div>
                            <div class="flex justify-between items-center mb-1.5">
                                <span class="font-mono-tech text-[10px] text-rose-600">SL $<span data-trade-sl="<?= $tradeId ?>"><?= $formatTradePrice($stopPrice) ?></span></span>
                                <span data-trade-tp="<?= $tradeId ?>" data-tp-removed="<?= $takeProfitRemoved ? '1' : '0' ?>">
                                <?php if ($takeProfitRemoved): ?>
                                    <span class="font-mono-tech text-[9px] font-bold text-violet-600 border border-violet-400/40 rounded px-1.5 py-0.5 cursor-help" title="Sabit tavan kaldırıldı, trend izleniyor">🚀 Sınırsız (∞)</span>
                                <?php else: ?>
                                    <span class="font-mono-tech text-[10px] text-emerald-600">TP $<?= $formatTradePrice($targetPrice) ?></span>
                                <?php endif; ?>
                                </span>
                            </div>
                            <div class="flex gap-1 mt-1">
                                <button type="button" onclick="openLiveChart(<?= $tradeId ?>)" class="flex-1 font-mono-tech text-[9px] text-cyan-600 border border-cyan-400/30 rounded px-1.5 py-0.5 hover:bg-cyan-400/10 transition-colors">📈 Canlı İzle</button>
                                <button type="button" onclick="closePositionNow(<?= $tradeId ?>, '<?= htmlspecialchars((string) $trade['pair'], ENT_QUOTES, 'UTF-8') ?>')" class="flex-1 font-mono-tech text-[9px] text-rose-600 border border-rose-400/30 rounded px-1.5 py-0.5 hover:bg-rose-400/10 transition-colors">✕ Şimdi Kapat</button>
                            </div>
                            <!-- Anlik fiyat/ilerleme/Izleyen Zirh durumu (Binance sorgusu gerektirir) sayfa
                                 acildiktan SONRA JS ile /api/dashboard/hunts uzerinden doldurulur/guncellenir -->
                            <div data-trade-progress="<?= $tradeId ?>">
                                <p class="font-mono-tech text-[10px] text-gray-500 mt-1">fiyat yükleniyor...</p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($activeFuturesTrades as $trade): ?>
                        <?php
                        $entryPrice = (float) $trade['entry_price'];
                        $targetPrice = (float) $trade['take_profit_price'];
                        $stopPrice = (float) $trade['stop_loss_price'];
                        $leverage = (int) $trade['leverage'];
                        ?>
                        <div class="rounded-lg border border-rose-500/20 bg-rose-500/5 px-3 py-2">
                            <div class="flex justify-between items-center mb-1">
                                <span class="flex items-center gap-1.5">
                                    <span class="font-mono-tech text-[9px] font-bold text-rose-600 border border-rose-400/40 rounded px-1 py-0.5">KISA <?= $leverage ?>x</span>
                                    <span class="font-mono-tech text-xs font-semibold text-gray-800"><?= htmlspecialchars((string) $trade['pair'], ENT_QUOTES, 'UTF-8') ?></span>
                                </span>
                                <span class="font-mono-tech text-[10px] text-gray-500">Giriş: $<?= $formatTradePrice($entryPrice) ?></span>
                            </div>
                            <div class="flex justify-between items-center mb-1.5">
                                <span class="font-mono-tech text-[10px] text-rose-600">SL $<?= $formatTradePrice($stopPrice) ?></span>
                                <span class="font-mono-tech text-[10px] text-emerald-600">TP $<?= $formatTradePrice($targetPrice) ?></span>
                            </div>
                            <!-- Anlik mark fiyati/likidasyon/PNL sayfa acildiktan SONRA JS ile
                                 /api/dashboard/futures-positions uzerinden doldurulur -->
                            <div data-futures-progress="<?= (int) $trade['id'] ?>">
                                <p class="font-mono-tech text-[10px] text-gray-500 mt-1">fiyat yükleniyor...</p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Canli Savas Radari Modal - "Kesiciyi Ac" gibi diger butonlarin AYNI acilis/kapanis
         desenini (opacity/scale transition) kullanir, bkz. openSettingsModal()/closeSettingsModal() -->
    <div id="liveChartModal" class="fixed inset-0 z-50 hidden">
        <div id="liveChartBackdrop" onclick="closeLiveChart()" class="absolute inset-0 bg-black/70 backdrop-blur-sm opacity-0 transition-opacity duration-200"></div>
        <div class="relative h-full flex items-center justify-center p-4">
            <div id="liveChartPanel" class="glass-panel relative rounded-2xl w-full max-w-2xl opacity-0 scale-95 transition-all duration-200">
                <div class="flex items-center justify-between px-5 py-4 border-b border-black/5">
                    <span class="font-display font-semibold text-gray-900">📈 Canlı Savaş Radarı — <span id="liveChartPair">—</span></span>
                    <div class="flex items-center gap-3">
                        <button type="button" id="liveChartZoomToggle" onclick="toggleLiveChartZoom()" class="hidden font-mono-tech text-[9px] text-cyan-600 border border-cyan-400/30 rounded px-1.5 py-0.5 hover:bg-cyan-400/10 transition-colors">🔎 Tüm Seviyeler</button>
                        <button type="button" onclick="closeLiveChart()" class="text-gray-500 hover:text-gray-900 transition-colors text-xl leading-none">&times;</button>
                    </div>
                </div>
                <div class="p-5">
                    <div id="liveChartLoading" class="font-mono-tech text-xs text-gray-500 text-center py-10">Grafik yükleniyor...</div>
                    <div id="liveChartContainer" class="hidden" style="height: 480px;"></div>
                    <div class="flex items-center justify-center gap-4 mt-3">
                        <span class="font-mono-tech text-[10px] text-gray-400">— — Giriş: <span id="liveChartEntryValue">—</span></span>
                        <span id="liveChartTpValueWrap" class="font-mono-tech text-[10px] text-emerald-600">— Hedef (TP): <span id="liveChartTpValue">—</span></span>
                        <span class="font-mono-tech text-[10px] text-rose-600">— Zırh (SL): <span id="liveChartSlValue">—</span></span>
                        <span id="liveChartTriggerWrap" class="font-mono-tech text-[10px] text-violet-600 hidden">┄ İzleyen Stop Tetik: <span id="liveChartTriggerValue">—</span></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Ayarlar Modal -->
    <div id="settingsModal" class="fixed inset-0 z-50 hidden">
        <div id="settingsBackdrop" onclick="closeSettingsModal()" class="absolute inset-0 bg-black/70 backdrop-blur-sm opacity-0 transition-opacity duration-200"></div>
        <div class="relative h-full flex items-center justify-center p-4">
            <div id="settingsPanel" class="glass-panel relative rounded-2xl w-full max-w-3xl max-h-[85vh] overflow-y-auto thin-scroll opacity-0 scale-95 transition-all duration-200">
                <div class="flex items-center justify-between px-5 py-4 border-b border-black/5">
                    <span class="font-display font-semibold text-gray-900">Ayarlar</span>
                    <button type="button" onclick="closeSettingsModal()" class="text-gray-500 hover:text-gray-900 transition-colors text-xl leading-none">&times;</button>
                </div>
                <div class="grid md:grid-cols-2 gap-6 p-5">
                    <div class="md:col-span-2 border-b border-black/5 pb-5">
                        <div class="font-mono-tech text-xs tracking-widest text-gray-700 mb-3">HESAP BİLGİLERİ</div>

                        <?php if (!empty($accountSuccess)): ?>
                            <div class="rounded-lg border border-emerald-400/30 bg-emerald-400/10 text-emerald-600 text-xs px-3 py-2 mb-3"><?= htmlspecialchars($accountSuccess, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>

                        <?php if (!empty($accountError)): ?>
                            <div class="rounded-lg border border-rose-400/30 bg-rose-400/10 text-rose-600 text-xs px-3 py-2 mb-3"><?= htmlspecialchars($accountError, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>

                        <form method="POST" action="<?= htmlspecialchars(Url::to('/dashboard/account-settings'), ENT_QUOTES, 'UTF-8') ?>" class="grid sm:grid-cols-2 gap-3">
                            <div>
                                <label for="account_name" class="block text-[10px] tracking-widest text-gray-500 mb-1">AD SOYAD</label>
                                <input type="text" id="account_name" name="account_name" value="<?= htmlspecialchars((string) ($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 focus:outline-none focus:border-violet-400/60 focus:ring-1 focus:ring-violet-400/40">
                            </div>
                            <div>
                                <label for="account_email" class="block text-[10px] tracking-widest text-gray-500 mb-1">E-POSTA</label>
                                <input type="email" id="account_email" name="account_email" value="<?= htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-violet-400/60 focus:ring-1 focus:ring-violet-400/40">
                            </div>
                            <div>
                                <label for="new_password" class="block text-[10px] tracking-widest text-gray-500 mb-1">YENİ ŞİFRE (İSTEĞE BAĞLI)</label>
                                <input type="password" id="new_password" name="new_password" placeholder="Değiştirmek istemiyorsan boş bırak" class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech placeholder:text-gray-400 focus:outline-none focus:border-violet-400/60 focus:ring-1 focus:ring-violet-400/40">
                            </div>
                            <div>
                                <label for="new_password_confirm" class="block text-[10px] tracking-widest text-gray-500 mb-1">YENİ ŞİFRE (TEKRAR)</label>
                                <input type="password" id="new_password_confirm" name="new_password_confirm" class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-violet-400/60 focus:ring-1 focus:ring-violet-400/40">
                            </div>
                            <div class="sm:col-span-2">
                                <label for="current_password" class="block text-[10px] tracking-widest text-amber-600 mb-1">MEVCUT ŞİFRE (DEĞİŞİKLİK İÇİN ZORUNLU)</label>
                                <input type="password" id="current_password" name="current_password" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-amber-400/60 focus:ring-1 focus:ring-amber-400/40">
                            </div>
                            <div class="sm:col-span-2">
                                <button type="submit" class="w-full rounded-lg bg-gray-800 hover:bg-gray-700 transition-colors text-white text-sm font-semibold py-2">Hesap Bilgilerini Güncelle</button>
                            </div>
                        </form>
                    </div>

                    <div>
                        <div class="font-mono-tech text-xs tracking-widest text-violet-600 mb-3">BORSA API AYARLARI</div>

                        <!-- API Kurulum Rehberi: varsayilan kapali akordeon, tiklaninca acilir -->
                        <div class="rounded-lg border border-violet-400/30 bg-violet-400/5 mb-3 overflow-hidden">
                            <button type="button" onclick="toggleApiGuide()" class="w-full flex items-center justify-between gap-2 px-3 py-2.5 text-left">
                                <span class="text-xs font-semibold text-violet-700">🛈 Yardıma mı ihtiyacınız var? API Nasıl Oluşturulur?</span>
                                <span id="apiGuideArrow" class="text-violet-500 text-xs shrink-0">▸</span>
                            </button>
                            <div id="apiGuideContent" class="hidden px-3 pb-3 text-xs text-gray-700 leading-relaxed">
                                <ol class="space-y-2.5">
                                    <li><strong class="text-gray-900">Adım 1:</strong> Binance hesabınıza giriş yapın ve profil menüsünden <strong>API Yönetimi</strong>'ne tıklayın.</li>
                                    <li><strong class="text-gray-900">Adım 2:</strong> "API Oluştur" butonuna basıp <strong>"Sistem Tarafından Üretilen"</strong> seçeneğiyle devam edin ve API'nize bir isim verin (Örn: <span class="font-mono-tech text-gray-600">NexaTrade Bot</span>).</li>
                                    <li><strong class="text-gray-900">Adım 3:</strong> Oluşturulan API'nin altındaki <strong>"Kısıtlamaları Düzenle"</strong> butonuna tıklayın.</li>
                                    <li>
                                        <strong class="text-gray-900">Adım 4 (Çok Önemli):</strong> İzinler kısmından <strong>"Spot ve Marjin Alım Satımını Etkinleştir"</strong> ile <strong>"Vadeli İşlemleri Etkinleştir"</strong> kutucuklarını işaretleyin.
                                        <div class="mt-1.5 rounded border border-rose-400/30 bg-rose-400/10 text-rose-600 px-2.5 py-1.5 font-medium">⚠️ Çekme (Withdraw) işlemine ASLA izin vermeyin.</div>
                                    </li>
                                    <li>
                                        <strong class="text-gray-900">Adım 5 (IP Güvenliği):</strong> Sayfanın alt kısmındaki <strong>"Sadece güvenilir IP'lere erişimi kısıtla"</strong> seçeneğini seçin ve aşağıdaki IP adresini kopyalayıp kutucuğa yapıştırın:
                                        <div class="mt-2 flex items-center gap-2">
                                            <code id="serverIpValue" class="flex-1 min-w-0 truncate font-mono-tech text-sm text-gray-900 bg-white border border-black/10 rounded px-2.5 py-1.5"><?= htmlspecialchars($serverPublicIp, ENT_QUOTES, 'UTF-8') ?></code>
                                            <button type="button" onclick="copyServerIp()" id="copyIpButton" class="shrink-0 text-[11px] font-medium text-violet-600 border border-violet-400/40 rounded px-2.5 py-1.5 hover:bg-violet-400/10 transition-colors">Kopyala</button>
                                        </div>
                                    </li>
                                    <li><strong class="text-gray-900">Adım 6:</strong> <strong>Kaydet</strong> butonuna basın. Ekranda beliren <strong>API Key</strong> ve <strong>Secret Key</strong> bilgilerini kopyalayıp yandaki forma yapıştırın.</li>
                                </ol>
                            </div>
                        </div>

                        <?php if (!empty($successMessage)): ?>
                            <div class="rounded-lg border border-emerald-400/30 bg-emerald-400/10 text-emerald-600 text-xs px-3 py-2 mb-3"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>

                        <?php if (!empty($errorMessage)): ?>
                            <div class="rounded-lg border border-rose-400/30 bg-rose-400/10 text-rose-600 text-xs px-3 py-2 mb-3"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>

                        <?php if ($maskedApiKey !== null): ?>
                            <p class="text-xs text-gray-500 mb-3">
                                Kayıtlı anahtar (<?= htmlspecialchars((string) $existingExchange, ENT_QUOTES, 'UTF-8') ?>):
                                <span class="font-mono-tech text-gray-700 border border-black/10 rounded px-1.5 py-0.5"><?= htmlspecialchars($maskedApiKey, ENT_QUOTES, 'UTF-8') ?></span>
                            </p>
                        <?php endif; ?>

                        <form method="POST" action="<?= htmlspecialchars(Url::to('/dashboard/api-keys'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-3">
                            <div>
                                <label for="exchange_name" class="block text-[10px] tracking-widest text-gray-500 mb-1">BORSA</label>
                                <select id="exchange_name" name="exchange_name" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 focus:outline-none focus:border-violet-400/60 focus:ring-1 focus:ring-violet-400/40">
                                    <option value="Binance" <?= $existingExchange === 'Binance' ? 'selected' : '' ?>>Binance</option>
                                </select>
                            </div>
                            <div>
                                <label for="api_key" class="block text-[10px] tracking-widest text-gray-500 mb-1">API KEY</label>
                                <input type="text" id="api_key" name="api_key" placeholder="API anahtarınızı girin" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech placeholder:text-gray-400 focus:outline-none focus:border-violet-400/60 focus:ring-1 focus:ring-violet-400/40">
                            </div>
                            <div>
                                <label for="secret_key" class="block text-[10px] tracking-widest text-gray-500 mb-1">SECRET KEY</label>
                                <input type="password" id="secret_key" name="secret_key" placeholder="Secret anahtarınızı girin" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech placeholder:text-gray-400 focus:outline-none focus:border-violet-400/60 focus:ring-1 focus:ring-violet-400/40">
                            </div>
                            <button type="submit" class="w-full rounded-lg bg-violet-500 hover:bg-violet-400 transition-colors text-white text-sm font-semibold py-2">Kaydet / Güncelle</button>
                        </form>
                    </div>

                    <div>
                        <div class="font-mono-tech text-xs tracking-widest text-cyan-600 mb-3">AI AVCI AYARLARI</div>

                        <?php if ($circuitBreakerActive): ?>
                            <div class="rounded-lg border border-rose-400/30 bg-rose-400/10 text-rose-600 text-xs px-3 py-2 mb-3 flex items-start gap-2">
                                <span>🔒</span>
                                <span>Devre kesici aktif. <strong><?= htmlspecialchars($circuitBreakerUntil, ENT_QUOTES, 'UTF-8') ?></strong> tarihine kadar otonom işlem yapılamaz (ardışık zarar kes sonrası güvenlik soğuması). Şalteri açsanız bile bu süre dolmadan işlem gerçekleşmez.</span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($autoTradeSuccess)): ?>
                            <div class="rounded-lg border border-emerald-400/30 bg-emerald-400/10 text-emerald-600 text-xs px-3 py-2 mb-3"><?= htmlspecialchars($autoTradeSuccess, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>

                        <?php if (!empty($autoTradeError)): ?>
                            <div class="rounded-lg border border-rose-400/30 bg-rose-400/10 text-rose-600 text-xs px-3 py-2 mb-3"><?= htmlspecialchars($autoTradeError, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>

                        <form id="autoTradeSettingsForm" method="POST" action="<?= htmlspecialchars(Url::to('/dashboard/auto-trade-settings'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-3">
                            <label class="flex items-center gap-3 cursor-pointer select-none">
                                <span class="relative inline-block w-9 h-5">
                                    <input type="checkbox" id="auto_trade_enabled" name="auto_trade_enabled" <?= $autoTradeEnabled ? 'checked' : '' ?> class="peer sr-only">
                                    <span class="toggle-track absolute inset-0 rounded-full bg-black/5 transition-colors"></span>
                                    <span class="toggle-dot absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-gray-400"></span>
                                </span>
                                <span class="text-xs text-gray-700">Otonom taramayı etkinleştir</span>
                            </label>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label for="auto_trade_budget_percent" class="block text-[10px] tracking-widest text-gray-500 mb-1">İŞLEM BAŞINA BÜTÇE (BAKİYENİN %'Sİ)</label>
                                    <input type="number" step="0.1" min="1" max="100" id="auto_trade_budget_percent" name="auto_trade_budget_percent" value="<?= htmlspecialchars(number_format($autoTradeBudgetPercent, 1, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-cyan-400/60 focus:ring-1 focus:ring-cyan-400/40">
                                </div>
                                <div>
                                    <label for="max_portfolio_risk_percent" class="block text-[10px] tracking-widest text-orange-600 mb-1">MAKS. TOPLAM PORTFÖY RİSKİ (%)</label>
                                    <input type="number" step="0.1" min="5" max="100" id="max_portfolio_risk_percent" name="max_portfolio_risk_percent" value="<?= htmlspecialchars(number_format($maxPortfolioRiskPercent, 1, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-orange-400/60 focus:ring-1 focus:ring-orange-400/40">
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label for="take_profit_percent" class="block text-[10px] tracking-widest text-emerald-600 mb-1">KÂR AL (%)</label>
                                    <input type="number" step="0.1" min="1" id="take_profit_percent" name="take_profit_percent" value="<?= htmlspecialchars(number_format($takeProfitPercent, 1, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-emerald-400/60 focus:ring-1 focus:ring-emerald-400/40">
                                </div>
                                <div>
                                    <label for="stop_loss_percent" class="block text-[10px] tracking-widest text-rose-600 mb-1">ZARAR KES (%)</label>
                                    <input type="number" step="0.1" min="1" id="stop_loss_percent" name="stop_loss_percent" value="<?= htmlspecialchars(number_format($stopLossPercent, 1, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-rose-400/60 focus:ring-1 focus:ring-rose-400/40">
                                </div>
                            </div>
                            <div>
                                <label for="max_daily_loss_percent" class="block text-[10px] tracking-widest text-amber-600 mb-1">GÜNLÜK MAKS. ZARAR LİMİTİ (%)</label>
                                <input type="number" step="0.1" min="1" max="50" id="max_daily_loss_percent" name="max_daily_loss_percent" value="<?= htmlspecialchars(number_format($maxDailyLossPercent, 1, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-amber-400/60 focus:ring-1 focus:ring-amber-400/40">
                            </div>

                            <div class="rounded-xl border border-black/10 bg-black/[0.02] p-4 mt-1">
                                <label class="flex items-center gap-3 cursor-pointer select-none mb-2">
                                    <span class="relative inline-block w-9 h-5">
                                        <input type="checkbox" id="trailing_stop_enabled" name="trailing_stop_enabled" <?= $trailingStopEnabled ? 'checked' : '' ?> class="peer sr-only">
                                        <span class="toggle-track absolute inset-0 rounded-full bg-black/5 transition-colors"></span>
                                        <span class="toggle-dot absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-gray-400"></span>
                                    </span>
                                    <span class="text-xs font-semibold text-gray-800">🔒 İzleyen Stop (Trailing Stop)</span>
                                </label>
                                <p class="text-[10px] text-gray-500 mb-3">Pozisyon belirlediğin tetik yüzdesine ulaştığında Zarar Kes otomatik olarak kâr bölgesine çekilir, sonrasında fiyatı sürekli takip eder.</p>
                                <div class="rounded-lg border border-violet-400/30 bg-violet-400/10 text-violet-700 text-[11px] leading-relaxed px-3 py-2.5 mb-3 flex items-start gap-2">
                                    <span class="shrink-0 text-sm leading-none">🚀</span>
                                    <span><strong class="font-semibold">Dinamik Trend Avcısı Aktif:</strong> Sistem, fiyat hedefleri aşıp Sınırsız İzleme (Aşama 2) moduna geçtiğinde, önceden belirlenmiş sabit kâr tavanını otomatik olarak yıkar ve maksimum kârı hedefler.</span>
                                </div>
                                <div class="grid grid-cols-3 gap-3">
                                    <div>
                                        <label for="trailing_trigger_percent" class="block text-[10px] tracking-widest text-gray-500 mb-1">TETİK (%)</label>
                                        <input type="number" step="0.1" min="0.1" max="50" id="trailing_trigger_percent" name="trailing_trigger_percent" value="<?= htmlspecialchars(number_format($trailingTriggerPercent, 1, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-cyan-400/60 focus:ring-1 focus:ring-cyan-400/40">
                                    </div>
                                    <div>
                                        <label for="trailing_lock_percent" class="block text-[10px] tracking-widest text-gray-500 mb-1">KİLİT (%)</label>
                                        <input type="number" step="0.1" min="0" max="50" id="trailing_lock_percent" name="trailing_lock_percent" value="<?= htmlspecialchars(number_format($trailingLockPercent, 1, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-cyan-400/60 focus:ring-1 focus:ring-cyan-400/40">
                                    </div>
                                    <div>
                                        <label for="trailing_distance_percent" class="block text-[10px] tracking-widest text-gray-500 mb-1">İZLEME (%)</label>
                                        <input type="number" step="0.1" min="0.1" max="50" id="trailing_distance_percent" name="trailing_distance_percent" value="<?= htmlspecialchars(number_format($trailingDistancePercent, 1, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-cyan-400/60 focus:ring-1 focus:ring-cyan-400/40">
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="w-full rounded-lg bg-cyan-500 hover:bg-cyan-400 transition-colors text-black text-sm font-semibold py-2">Ayarları Kaydet</button>
                            <p class="text-[11px] text-gray-500">Radar'da skoru esigin üzerine çıkan ilk coin bulunduğunda, o anki bakiyenin belirlediğin yüzdesi kadarıyla otomatik alım yapılır; Kâr Al ve Zarar Kes hedefleri TEK bir OCO emriyle korumaya alınır.</p>
                            <p class="text-[11px] text-orange-600/70">📊 Toplam Portföy Riski: Aynı anda açık TÜM pozisyonların toplam maliyeti bu oranı aşarsa, eşzamanlı pozisyon limiti dolmamış olsa bile yeni alım açılmaz.</p>
                            <p class="text-[11px] text-amber-600/70">🛡️ Devre Kesici: Son 24 saatteki zarar bu limiti (bütçenin %'si) aşarsa işlem geçici durur; ardışık 3 zarar kes tetiklenirse otonom tarama tamamen kapatılır.</p>
                        </form>
                    </div>
                </div>

                <div class="border-t border-black/5 px-5 py-5">
                    <div class="font-mono-tech text-xs tracking-widest text-sky-600 mb-3">TELEGRAM BİLDİRİMLERİ</div>

                    <?php if ($telegramConnected): ?>
                        <div class="flex items-center gap-2 text-xs text-emerald-600">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.8)]"></span>
                            Telegram hesabınız bağlı. Pozisyon açılış/kapanış bildirimleriniz buraya iletilecek.
                        </div>
                    <?php elseif ($telegramBotUsername !== ''): ?>
                        <p class="text-xs text-gray-500 mb-3">Pozisyon açılış/kapanış bildirimlerini kendi Telegram hesabınızda almak için aşağıdaki butona tıklayın ve açılan botta "Başlat / Start" deyin.</p>
                        <a
                            href="https://t.me/<?= htmlspecialchars($telegramBotUsername, ENT_QUOTES, 'UTF-8') ?>?start=<?= htmlspecialchars($telegramVerifyToken, ENT_QUOTES, 'UTF-8') ?>"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-2 rounded-lg bg-sky-500 hover:bg-sky-400 transition-colors text-black text-sm font-semibold px-4 py-2"
                        >📲 Telegram'ı Bağla</a>
                    <?php else: ?>
                        <p class="text-xs text-gray-500">Telegram entegrasyonu şu anda yapılandırılmamış. Yönetici admin panelinden Telegram Bot Token'ı ve Bot Kullanıcı Adını girmelidir.</p>
                    <?php endif; ?>
                </div>

                <div class="border-t border-black/5 px-5 py-5">
                    <div class="font-mono-tech text-xs tracking-widest text-fuchsia-600 mb-1">GELİŞMİŞ MODÜLLER</div>
                    <p class="text-[11px] text-gray-500 mb-4">Hepsi varsayılan olarak KAPALIDIR. Her biri "AI Avcı"dan bağımsız çalışır ve ayrı risk profili taşır — dikkatlice okuyup açın.</p>

                    <?php if (!empty($advancedModulesSuccess)): ?>
                        <div class="rounded-lg border border-emerald-400/30 bg-emerald-400/10 text-emerald-600 text-xs px-3 py-2 mb-3"><?= htmlspecialchars($advancedModulesSuccess, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <?php if (!empty($advancedModulesError)): ?>
                        <div class="rounded-lg border border-rose-400/30 bg-rose-400/10 text-rose-600 text-xs px-3 py-2 mb-3"><?= htmlspecialchars($advancedModulesError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <form method="POST" action="<?= htmlspecialchars(Url::to('/dashboard/advanced-modules'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-4">
                        <!-- İŞLEM MODU: Sadece Spot (Güvenli Liman) vs Spot + Futures (Agresif) - futures_trading_enabled
                             ile SENKRON çalışır (aynı alan, aşağıdaki checkbox grid'inde AYRICA gösterilmez) -->
                        <div class="rounded-xl border border-black/10 bg-black/[0.02] p-4 mb-1">
                            <div class="text-xs font-semibold text-gray-800 mb-1">⚙️ İŞLEM MODU</div>
                            <p class="text-[10px] text-gray-500 mb-3">Sisteminizin nasıl işlem açacağını seçin. Bu seçim anında "Kısa Pozisyon (Futures)" ayarınızı senkronize eder.</p>
                            <div class="grid sm:grid-cols-2 gap-3">
                                <label class="relative flex cursor-pointer">
                                    <input type="radio" name="futures_trading_enabled" value="0" <?= !$futuresTradingEnabled ? 'checked' : '' ?> class="peer sr-only">
                                    <div class="trading-mode-card w-full rounded-lg border border-black/10 bg-white p-3 transition-colors">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs font-semibold text-gray-800">🛡️ Sadece Spot</span>
                                            <span class="trading-mode-badge hidden items-center font-mono-tech text-[9px] text-violet-600 border border-violet-400/40 rounded px-1.5 py-0.5">SEÇİLİ</span>
                                        </div>
                                        <div class="text-[9px] tracking-widest text-emerald-600 font-mono-tech mb-1">GÜVENLİ LİMAN</div>
                                        <p class="text-[10px] text-gray-500">Sadece yükseliş yönlü (Long) spot varlık alımı yapar. Likidasyon (sıfırlanma) riski YOKTUR.</p>
                                    </div>
                                </label>
                                <label class="relative flex cursor-pointer">
                                    <input type="radio" name="futures_trading_enabled" value="1" <?= $futuresTradingEnabled ? 'checked' : '' ?> class="peer sr-only">
                                    <div class="trading-mode-card w-full rounded-lg border border-black/10 bg-white p-3 transition-colors">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs font-semibold text-gray-800">⚡ Spot + Futures</span>
                                            <span class="trading-mode-badge hidden items-center font-mono-tech text-[9px] text-violet-600 border border-violet-400/40 rounded px-1.5 py-0.5">SEÇİLİ</span>
                                        </div>
                                        <div class="text-[9px] tracking-widest text-rose-600 font-mono-tech mb-1">AGRESİF</div>
                                        <p class="text-[10px] text-gray-500">Hem spot alım yapar hem düşüş trendlerinde (Short) kaldıraçlı işlem açar. Likidasyon riski taşır.</p>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div class="grid md:grid-cols-2 gap-4">
                            <div class="rounded-lg border border-black/10 bg-white p-3">
                                <label class="flex items-center gap-3 cursor-pointer select-none mb-1.5">
                                    <span class="relative inline-block w-9 h-5">
                                        <input type="checkbox" id="social_radar_enabled" name="social_radar_enabled" <?= $socialRadarEnabled ? 'checked' : '' ?> class="peer sr-only">
                                        <span class="toggle-track absolute inset-0 rounded-full bg-black/5 transition-colors"></span>
                                        <span class="toggle-dot absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-gray-400"></span>
                                    </span>
                                    <span class="text-xs text-gray-800 font-semibold">📡 Sosyal Radar</span>
                                </label>
                                <p class="text-[10px] text-gray-500">Sosyal medyada anma sıklığı aniden fırlayan coinleri AI onayına ekler. Düşük risk — normal AI onay sürecinden geçer.</p>
                            </div>

                            <div class="rounded-lg border border-rose-500/20 bg-rose-500/5 p-3">
                                <label class="flex items-center gap-3 cursor-pointer select-none mb-1.5">
                                    <span class="relative inline-block w-9 h-5">
                                        <input type="checkbox" id="listing_sniper_enabled" name="listing_sniper_enabled" <?= $listingSniperEnabled ? 'checked' : '' ?> class="peer sr-only">
                                        <span class="toggle-track absolute inset-0 rounded-full bg-black/5 transition-colors"></span>
                                        <span class="toggle-dot absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-gray-400"></span>
                                    </span>
                                    <span class="text-xs text-gray-800 font-semibold">🎯 Duyuru Avcısı (Otonom Sniper)</span>
                                </label>
                                <p class="text-[10px] text-rose-600/80 mb-2">⚠️ YÜKSEK RİSK: Yeni listelemeye AI onayı BEKLEMEDEN anında alım yapar (sabit %20 Kâr Al / %2 Zarar Kes). Yeni pariteler çok oynak olabilir.</p>
                                <div>
                                    <label for="sniper_budget_percent" class="block text-[10px] tracking-widest text-rose-600 mb-1">SNIPER BÜTÇESİ (BAKİYENİN %'Sİ) — BAĞIMSIZ</label>
                                    <input type="number" step="0.1" min="1" max="100" id="sniper_budget_percent" name="sniper_budget_percent" value="<?= htmlspecialchars(number_format($sniperBudgetPercent, 1, '.', ''), ENT_QUOTES, 'UTF-8') ?>" class="w-full bg-white border border-rose-500/20 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-rose-400/60 focus:ring-1 focus:ring-rose-400/40">
                                    <p class="text-[9px] text-gray-500 mt-1">AI Avcı bütçesinden TAMAMEN BAĞIMSIZDIR, kendi ayrı sütununda tutulur. Sniper AI onayı BEKLEMEDEN körlemesine alım yaptığı için düşük tutulması (ör. %5) önerilir.</p>
                                </div>
                            </div>

                            <div class="rounded-lg border border-black/10 bg-white p-3">
                                <label class="flex items-center gap-3 cursor-pointer select-none mb-1.5">
                                    <span class="relative inline-block w-9 h-5">
                                        <input type="checkbox" id="smart_money_enabled" name="smart_money_enabled" <?= $smartMoneyEnabled ? 'checked' : '' ?> class="peer sr-only">
                                        <span class="toggle-track absolute inset-0 rounded-full bg-black/5 transition-colors"></span>
                                        <span class="toggle-dot absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-gray-400"></span>
                                    </span>
                                    <span class="text-xs text-gray-800 font-semibold">🐳 Akıllı Para Kopyalayıcı</span>
                                </label>
                                <p class="text-[10px] text-gray-500">İzlenen whale cüzdanları önemli bir token alımı yaptığında, Binance'te karşılığı varsa aynı yönde alım yapar. Kendi Kâr Al/Zarar Kes yüzdelerinizi kullanır.</p>
                            </div>

                            <div class="rounded-lg border border-amber-500/20 bg-amber-500/5 p-3">
                                <label class="flex items-center gap-3 cursor-pointer select-none mb-1.5">
                                    <span class="relative inline-block w-9 h-5">
                                        <input type="checkbox" id="dca_enabled" name="dca_enabled" <?= $dcaEnabled ? 'checked' : '' ?> class="peer sr-only">
                                        <span class="toggle-track absolute inset-0 rounded-full bg-black/5 transition-colors"></span>
                                        <span class="toggle-dot absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-gray-400"></span>
                                    </span>
                                    <span class="text-xs text-gray-800 font-semibold">📉 DCA (Maliyet Düşürme)</span>
                                </label>
                                <p class="text-[10px] text-amber-600/80">⚠️ Pozisyon zarara girdiğinde (AI hâlâ pozitifse) stop-loss yerine 1 kez daha aynı bütçeyle alım yapar. Toplam risk pozisyon başına 2 katına çıkar.</p>
                            </div>

                        </div>

                        <?php if ($futuresTradingEnabled): ?>
                            <p class="text-[10px] text-rose-600/80 -mt-2">⚠️ "Spot + Futures" modu seçili: Binance Futures üzerinden kaldıraçlı (sabit <?= (int) $futuresLeverage ?>x, isolated marj) KISA pozisyon açılabilir — likidasyon riski taşır. Kendi Kâr Al/Zarar Kes yüzdelerinizi kullanır, aynı anda en fazla 1 açık pozisyon.</p>

                            <div class="rounded-xl border border-rose-500/20 bg-rose-500/5 p-4">
                                <label class="flex items-center gap-3 cursor-pointer select-none mb-2">
                                    <span class="relative inline-block w-9 h-5">
                                        <input type="checkbox" id="futures_trailing_stop_enabled" name="futures_trailing_stop_enabled" <?= $futuresTrailingStopEnabled ? 'checked' : '' ?> class="peer sr-only">
                                        <span class="toggle-track absolute inset-0 rounded-full bg-black/5 transition-colors"></span>
                                        <span class="toggle-dot absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-gray-400"></span>
                                    </span>
                                    <span class="text-xs font-semibold text-gray-800">🔒 Futures İzleyen Stop (Trailing Stop)</span>
                                </label>
                                <p class="text-[10px] text-rose-600/80 mb-3">SHORT pozisyonda fiyat düştükçe kâr oluşur — Zarar Kes belirlediğin tetik yüzdesinde önce entry'nin altına kilitlenir, sonrasında dibi sürekli takip eder. Kaldıraç riski nedeniyle spottan bağımsız, ayrı bir ayardır.</p>
                                <div class="grid grid-cols-3 gap-3">
                                    <div>
                                        <label for="futures_trailing_trigger_percent" class="block text-[10px] tracking-widest text-rose-600 mb-1">TETİK (%)</label>
                                        <input type="number" step="0.1" min="0.1" max="50" id="futures_trailing_trigger_percent" name="futures_trailing_trigger_percent" value="<?= htmlspecialchars(number_format($futuresTrailingTriggerPercent, 1, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required class="w-full bg-white border border-rose-500/20 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-rose-400/60 focus:ring-1 focus:ring-rose-400/40">
                                    </div>
                                    <div>
                                        <label for="futures_trailing_lock_percent" class="block text-[10px] tracking-widest text-rose-600 mb-1">KİLİT (%)</label>
                                        <input type="number" step="0.1" min="0" max="50" id="futures_trailing_lock_percent" name="futures_trailing_lock_percent" value="<?= htmlspecialchars(number_format($futuresTrailingLockPercent, 1, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required class="w-full bg-white border border-rose-500/20 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-rose-400/60 focus:ring-1 focus:ring-rose-400/40">
                                    </div>
                                    <div>
                                        <label for="futures_trailing_distance_percent" class="block text-[10px] tracking-widest text-rose-600 mb-1">İZLEME (%)</label>
                                        <input type="number" step="0.1" min="0.1" max="50" id="futures_trailing_distance_percent" name="futures_trailing_distance_percent" value="<?= htmlspecialchars(number_format($futuresTrailingDistancePercent, 1, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required class="w-full bg-white border border-rose-500/20 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-rose-400/60 focus:ring-1 focus:ring-rose-400/40">
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <button type="submit" class="w-full rounded-lg bg-fuchsia-500 hover:bg-fuchsia-400 transition-colors text-black text-sm font-semibold py-2">Gelişmiş Modül Ayarlarını Kaydet</button>
                    </form>
                </div>

                <!-- RİSK PROFİLİ -->
                <div class="border-t border-black/5 px-5 py-5">
                    <div class="font-mono-tech text-xs tracking-widest text-emerald-600 mb-1">RİSK PROFİLİ</div>
                    <p class="text-[11px] text-gray-500 mb-4">Seçtiğiniz profil; AI skor eşiğini, zarar kes yüzdesini ve eş zamanlı maksimum açık pozisyon sayısını otomatik ayarlar.</p>

                    <div id="riskSaveMsg" class="hidden rounded-lg border border-emerald-400/30 bg-emerald-400/10 text-emerald-600 text-xs px-3 py-2 mb-3"></div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <?php foreach (RiskProfileService::PROFILES as $profileKey => $meta): ?>
                        <?php
                            $isActive  = ($riskProfile === $profileKey);
                            $cardBorder = $isActive
                                ? 'border-emerald-400/60 bg-emerald-400/5 shadow-[0_0_18px_rgba(52,211,153,0.15)]'
                                : 'border-black/10 bg-black/[0.02] hover:border-black/15';
                            $labelColor = $isActive ? 'text-emerald-600' : 'text-gray-800';
                        ?>
                        <button type="button"
                                data-profile="<?= htmlspecialchars($profileKey, ENT_QUOTES, 'UTF-8') ?>"
                                onclick="selectRiskProfile(this)"
                                class="risk-card text-left rounded-2xl border p-3.5 transition-all duration-200 cursor-pointer focus:outline-none <?= $cardBorder ?>">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-base"><?= $meta['emoji'] ?></span>
                                <?php if ($isActive): ?>
                                    <span class="font-mono-tech text-[9px] text-emerald-600 tracking-widest border border-emerald-400/40 rounded px-1.5 py-0.5">AKTİF</span>
                                <?php endif; ?>
                            </div>
                            <div class="font-semibold text-sm <?= $labelColor ?> mb-1"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="font-mono-tech text-[10px] text-gray-500 space-y-0.5">
                                <div>AI Eşik: <span class="text-gray-700"><?= $meta['ai_score_threshold'] ?></span></div>
                                <div>Stop-Loss: <span class="text-rose-600"><?= $meta['stop_loss_percent'] ?>%</span></div>
                                <div>Max Pozisyon: <span class="text-gray-700"><?= $meta['max_active_trades'] ?></span></div>
                            </div>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- İşlem Detay Modalı: "Son İşlemler" tablosunda bir satıra/👁 ikonuna tıklanınca açılır -->
    <div id="orderDetailModal" class="fixed inset-0 z-50 hidden">
        <div id="orderDetailBackdrop" onclick="closeOrderDetailModal()" class="absolute inset-0 bg-black/70 backdrop-blur-sm opacity-0 transition-opacity duration-200"></div>
        <div class="relative h-full flex items-center justify-center p-4">
            <div id="orderDetailPanel" class="glass-panel relative rounded-2xl w-full max-w-sm opacity-0 scale-95 transition-all duration-200">
                <div class="flex items-center justify-between px-5 py-4 border-b border-black/5">
                    <span class="font-display font-semibold text-gray-900">İşlem Detayı</span>
                    <button type="button" onclick="closeOrderDetailModal()" class="text-gray-500 hover:text-gray-900 transition-colors text-xl leading-none">&times;</button>
                </div>
                <div id="orderDetailBody" class="p-5 space-y-3">
                    <p class="font-mono-tech text-xs text-gray-500">Yükleniyor…</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Geçmiş İşlemler Modalı: "Son İşlemler" panelindeki "GEÇMİŞ" butonuna tıklanınca açılır -
         gunluk/haftalik/aylik secime gore ozet seridi + o donemin TAMAMINI listeler -->
    <div id="orderHistoryModal" class="fixed inset-0 z-50 hidden">
        <div id="orderHistoryBackdrop" onclick="closeOrderHistoryModal()" class="absolute inset-0 bg-black/70 backdrop-blur-sm opacity-0 transition-opacity duration-200"></div>
        <div class="relative h-full flex items-center justify-center p-4">
            <div id="orderHistoryPanel" class="glass-panel relative rounded-2xl w-full max-w-2xl max-h-[85vh] flex flex-col opacity-0 scale-95 transition-all duration-200">
                <div class="flex-none flex items-center justify-between px-5 py-4 border-b border-black/5">
                    <span class="font-display font-semibold text-gray-900">Geçmiş İşlemler</span>
                    <button type="button" onclick="closeOrderHistoryModal()" class="text-gray-500 hover:text-gray-900 transition-colors text-xl leading-none">&times;</button>
                </div>
                <div class="flex-none flex items-center gap-2 px-5 pt-4">
                    <button type="button" data-history-period="daily" onclick="fetchOrderHistory('daily')" class="history-period-btn font-mono-tech text-[10px] tracking-widest rounded-full px-3 py-1 border border-black/10 text-gray-500 transition-colors">GÜNLÜK</button>
                    <button type="button" data-history-period="weekly" onclick="fetchOrderHistory('weekly')" class="history-period-btn font-mono-tech text-[10px] tracking-widest rounded-full px-3 py-1 border border-black/10 text-gray-500 transition-colors">HAFTALIK</button>
                    <button type="button" data-history-period="monthly" onclick="fetchOrderHistory('monthly')" class="history-period-btn font-mono-tech text-[10px] tracking-widest rounded-full px-3 py-1 border border-black/10 text-gray-500 transition-colors">AYLIK</button>
                </div>
                <div id="orderHistorySummary" class="flex-none grid grid-cols-3 gap-2 px-5 pt-4">
                    <p class="font-mono-tech text-xs text-gray-500 col-span-3">Yükleniyor…</p>
                </div>
                <div id="orderHistoryList" class="flex-1 min-h-0 overflow-y-auto thin-scroll mt-4 px-5 pb-5">
                    <p class="font-mono-tech text-xs text-gray-500">Yükleniyor…</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Performans Analizi Modalı: "Son İşlemler" panelindeki "ANALİZ" butonuna tıklanınca açılır -
         hangi stratejinin ve hangi paritenin gerçekten kazandırdığını gösterir (tüm zamanlar) -->
    <div id="performanceModal" class="fixed inset-0 z-50 hidden">
        <div id="performanceBackdrop" onclick="closePerformanceModal()" class="absolute inset-0 bg-black/70 backdrop-blur-sm opacity-0 transition-opacity duration-200"></div>
        <div class="relative h-full flex items-center justify-center p-4">
            <div id="performancePanel" class="glass-panel relative rounded-2xl w-full max-w-2xl max-h-[85vh] flex flex-col opacity-0 scale-95 transition-all duration-200">
                <div class="flex-none flex items-center justify-between px-5 py-4 border-b border-black/5">
                    <span class="font-display font-semibold text-gray-900">Performans Analizi</span>
                    <button type="button" onclick="closePerformanceModal()" class="text-gray-500 hover:text-gray-900 transition-colors text-xl leading-none">&times;</button>
                </div>
                <div class="flex-1 min-h-0 overflow-y-auto thin-scroll p-5 space-y-5">
                    <div>
                        <div class="font-mono-tech text-[9px] tracking-widest text-gray-400 mb-2">STRATEJİ BAZLI</div>
                        <div id="performanceStrategies"><p class="font-mono-tech text-xs text-gray-500">Yükleniyor…</p></div>
                    </div>
                    <div>
                        <div class="font-mono-tech text-[9px] tracking-widest text-gray-400 mb-2">PARİTE BAZLI</div>
                        <div id="performanceSymbols"><p class="font-mono-tech text-xs text-gray-500">Yükleniyor…</p></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sistem Durumu Modalı: navbar'daki nokta/"SİSTEM" butonuna tıklanınca açılır - her otonom
         modulun canlı olup olmadığını, devre kesici durumunu ve son kritik hataları gösterir -->
    <div id="systemStatusModal" class="fixed inset-0 z-50 hidden">
        <div id="systemStatusBackdrop" onclick="closeSystemStatusModal()" class="absolute inset-0 bg-black/70 backdrop-blur-sm opacity-0 transition-opacity duration-200"></div>
        <div class="relative h-full flex items-center justify-center p-4">
            <div id="systemStatusPanel" class="glass-panel relative rounded-2xl w-full max-w-lg max-h-[85vh] overflow-y-auto thin-scroll opacity-0 scale-95 transition-all duration-200">
                <div class="flex items-center justify-between px-5 py-4 border-b border-black/5">
                    <span class="font-display font-semibold text-gray-900">Sistem Durumu</span>
                    <button type="button" onclick="closeSystemStatusModal()" class="text-gray-500 hover:text-gray-900 transition-colors text-xl leading-none">&times;</button>
                </div>
                <div id="systemStatusBody" class="p-5 space-y-4">
                    <p class="font-mono-tech text-xs text-gray-500">Yükleniyor…</p>
                </div>
            </div>
        </div>
    </div>

    <!-- "Neler Yeni?" (Changelog) Modal -->
    <div id="changelogModal" class="fixed inset-0 z-50 hidden">
        <div id="changelogBackdrop" onclick="closeChangelogModal()" class="absolute inset-0 bg-black/70 backdrop-blur-sm opacity-0 transition-opacity duration-200"></div>
        <div class="relative h-full flex items-center justify-center p-4">
            <div id="changelogPanel" class="glass-panel relative rounded-2xl w-full max-w-lg max-h-[80vh] overflow-y-auto thin-scroll opacity-0 scale-95 transition-all duration-200">
                <div class="flex items-center justify-between px-5 py-4 border-b border-black/5">
                    <span class="font-display font-semibold text-gray-900">🚀 Neler Yeni?</span>
                    <button type="button" onclick="closeChangelogModal()" class="text-gray-500 hover:text-gray-900 transition-colors text-xl leading-none">&times;</button>
                </div>
                <div class="p-5 space-y-6">
                    <?php if (empty($changelogEntries)): ?>
                        <p class="text-xs text-gray-500">Henüz bir sürüm notu bulunamadı.</p>
                    <?php endif; ?>
                    <?php foreach ($changelogEntries as $entry): ?>
                        <div>
                            <div class="flex items-center gap-2 mb-3">
                                <span class="font-mono-tech text-xs font-bold text-violet-600 border border-violet-400/30 bg-violet-400/10 rounded px-2 py-0.5">v<?= htmlspecialchars($entry['version'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="font-mono-tech text-[10px] text-gray-500"><?= htmlspecialchars($entry['date'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <?php foreach ($entry['sections'] as $sectionTitle => $items): ?>
                                <div class="mb-4">
                                    <div class="font-mono-tech text-[10px] tracking-widest text-gray-500 mb-1.5"><?= htmlspecialchars(mb_strtoupper($sectionTitle, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <ul class="space-y-1.5">
                                        <?php foreach ($items as $item): ?>
                                            <?php
                                            // Basit **kalin** isaretleyicisini <strong>'a cevirir - once HTML olarak
                                            // kacirilir (guvenlik), SONRA bold donusumu uygulanir
                                            $escaped = htmlspecialchars($item, ENT_QUOTES, 'UTF-8');
                                            $withBold = preg_replace('/\*\*(.+?)\*\*/', '<strong class="text-gray-800">$1</strong>', $escaped);
                                            ?>
                                            <li class="flex items-start gap-2 text-xs text-gray-600 leading-relaxed">
                                                <span class="text-violet-400 mt-0.5">•</span>
                                                <span><?= $withBold ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- "Canlı İzle" (pozisyon mini-grafik modalı) ve Teknik Analiz gostergesi icin: kendi
         barindirdigimiz, TradingView'a hicbir agla/auth'la bagli olmayan Apache 2.0 lisansli acik
         kaynak kutuphane (bkz. CHANGELOG, 22 Temmuz) - BU IKISI DEGISMEDI -->
    <script src="<?= htmlspecialchars(Url::to('/assets/js/lightweight-charts.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
    <!-- 31 Temmuz'da eklendi: SADECE Ana Grafik paneli icin TradingView'in tam ozellikli Advanced
         Chart widget'i (cizim araclari, onlarca indikator) TEKRAR denendi - eski "bad auth token"
         hatasi (22 Temmuz CHANGELOG) paylasimli hosting IP'sinin TradingView tarafinda bir kota/
         itibar sorununa takilmasindan kaynaklaniyordu, artik kendi ozel VPS IP'mizdeyiz. Ucuz ve
         GERI ALINABILIR bir deneme - calismazsa loadChart() icindeki eski lightweight-charts yolu
         (initLightweightChart) hala kod tabaninda duruyor, tek fonksiyon degisikligiyle geri donulur -->
    <script src="https://s3.tradingview.com/tv.js"></script>

    <script>
        // 16 Temmuz'da tespit edildi: PHP tarafinda tum sunucu-render edilmis (PHP kisa echo)
        // ciktilar htmlspecialchars() ile tutarli sekilde kaciriliyordu, ama /api/dashboard/* JSON uc
        // noktalarindan gelip bu script blogundaki fonksiyonlarla dogrudan innerHTML'e yazilan
        // degerler (AI'nin urettigi loss_reason/reason metinleri, ham Binance hata mesajlari, RSS
        // haber basligi/linki) HIC kacirilmiyordu - AYNI korumanin JS tarafindaki karsiligi
        // burada escapeHtml() ile saglaniyor
        function escapeHtml(str) {
            return String(str === null || str === undefined ? '' : str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        // href="..." icin: sadece http(s) ile baslayan degerlere izin verir, aksi halde
        // javascript: gibi tehlikeli semalari sessizce bosaltir (ör. bozuk/kotu niyetli bir RSS kaynagi)
        function safeHref(url) {
            var s = String(url === null || url === undefined ? '' : url);
            return /^https?:\/\//i.test(s) ? escapeHtml(s) : '#';
        }

        // Inline onclick="..." icine gomulen bir JS string literali icin: HTML-kacisi (escapeHtml)
        // TEK BASINA bu baglami korumaz - innerHTML atamasi once HTML'i ayristirip onclick
        // niteligindeki HTML entity'lerini DECODE eder, SONRA bu decode edilmis metni JS olarak
        // calistirir - yani &#39; gibi bir kacis JS degerlendirmesinden ONCE geri acilir. Once JS
        // string literali icin (\ ve ') kacilir, SONRA cevredeki cift tirnakli HTML niteligi icin (")
        function escapeJsAttr(str) {
            return String(str === null || str === undefined ? '' : str)
                .replace(/\\/g, '\\\\')
                .replace(/'/g, "\\'")
                .replace(/"/g, '&quot;');
        }

        // ============================================================
        // Ozel Grafik / Teknik Analiz / Kayan Bant Motoru (22 Temmuz)
        // TradingView embed widget'larinin (grafik/teknik analiz/kayan bant) yerine gecti - bkz.
        // CHANGELOG: TradingView'in kendi auth/protokol katmani bu paylasimli hosting IP'sinde
        // "ChartApi.Core: Protocol error. Reason=bad auth token" ile reddediyordu; genis capli bir
        // denetimde (config sadelestirme, cerez temizleme, farkli cihaz/tarayici, canli sunucuda
        // json_decode ile dogrulama) kod/tarayici tarafinda HICBIR neden bulunamadi - TradingView.com
        // dogrudan calisirken SADECE gomulu (embedded) baglamda basarisiz oluyordu. Artik dogrudan
        // Binance'in herkese acik (anahtarsiz, auth gerektirmeyen) REST/WebSocket API'sine baglaniyoruz
        // - ucuncu taraf oturum/auth katmani olmadigi icin ayni sinif hata bir daha yasanamaz.
        // Grafik: assets/js/lightweight-charts.min.js (kendi barindirdigimiz, Apache 2.0 lisansli,
        // TradingView, Inc.'in ayrica yayinladigi BAGIMSIZ acik kaynak render kutuphanesi - embed
        // widget'lardaki auth/oturum mekanizmasiyla ilgisi yok, sadece bize verilen veriyi cizer)
        // ============================================================

        var lwChart = null;
        var lwCandleSeries = null;
        var lwKlineSocket = null;
        var LW_INTERVAL = '1h';

        function initLightweightChart() {
            var container = document.getElementById('lwChart');
            if (!container || typeof LightweightCharts === 'undefined' || lwChart) { return; }

            lwChart = LightweightCharts.createChart(container, {
                layout: { background: { color: 'transparent' }, textColor: '#4b5563' },
                grid: {
                    vertLines: { color: 'rgba(139,92,246,0.08)' },
                    horzLines: { color: 'rgba(139,92,246,0.08)' },
                },
                timeScale: { timeVisible: true, secondsVisible: false, borderVisible: false },
                rightPriceScale: { borderVisible: false },
                width: container.clientWidth,
                height: container.clientHeight,
            });

            lwCandleSeries = lwChart.addCandlestickSeries({
                upColor: '#10b981',
                downColor: '#f43f5e',
                borderVisible: false,
                wickUpColor: '#10b981',
                wickDownColor: '#f43f5e',
            });

            new ResizeObserver(function () {
                if (lwChart) { lwChart.applyOptions({ width: container.clientWidth, height: container.clientHeight }); }
            }).observe(container);
        }

        // symbol 'BINANCE:BTCUSDT' formatinda gelir - TradingView'in KENDI bekledigi format zaten
        // bu, hicbir donusum gerekmez. Ana grafigi artik TradingView Advanced Chart widget'i cizer
        // (bkz. loadTradingViewChart) - Teknik Analiz Ozeti gostergesi ise HALA kendi Binance kline
        // verimizle hesaplaniyor (TradingView'in iframe'i icindeki veriye erisimimiz yok, bu yuzden
        // asagidaki fetch/renderTechnicalGauge cagrisi KALDI, sadece grafik cizimi degisti)
        function loadChart(symbol) {
            var pair = symbol.replace('BINANCE:', '').toUpperCase();

            var select = document.getElementById('chartSymbolSelect');
            if (select) {
                var hasOption = Array.prototype.some.call(select.options, function (opt) { return opt.value === symbol; });
                select.value = hasOption ? symbol : '';
            }

            var labelEl = document.getElementById('technicalWidgetSymbol');
            if (labelEl) { labelEl.textContent = pair; }

            loadTradingViewChart(symbol);

            fetch('https://api.binance.com/api/v3/klines?symbol=' + pair + '&interval=' + LW_INTERVAL + '&limit=300')
                .then(function (r) {
                    if (!r.ok) { throw new Error('HTTP ' + r.status); }
                    return r.json();
                })
                .then(function (raw) {
                    var candles = raw.map(function (k) {
                        return {
                            time: Math.floor(k[0] / 1000),
                            open: parseFloat(k[1]),
                            high: parseFloat(k[2]),
                            low: parseFloat(k[3]),
                            close: parseFloat(k[4]),
                        };
                    });

                    renderTechnicalGauge(candles);
                })
                .catch(function () { /* Teknik Analiz Ozeti sessizce eski degerinde kalir - Ana Grafik'i etkilemez */ });
        }

        // TradingView'in UCRETSIZ embed widget'i (tv.js) - ucretli "Charting Library"nin aksine
        // setSymbol()/onChartReady() gibi bir calisma-zamani JS API'si SUNMAZ, sembol degistirmenin
        // tek yolu container'i temizleyip widget'i SIFIRDAN yeniden olusturmak (ilk denemede
        // "tvWidget.onChartReady is not a function" hatasiyla tespit edildi, Playwright ile dogrulandi)
        function loadTradingViewChart(symbol) {
            if (typeof TradingView === 'undefined') { return; }

            var container = document.getElementById('tvChartContainer');
            if (container) { container.innerHTML = ''; }

            new TradingView.widget({
                autosize: true,
                symbol: symbol,
                interval: '15',
                timezone: 'Etc/UTC',
                theme: 'light',
                style: '1',
                locale: 'tr',
                toolbar_bg: '#ffffff',
                enable_publishing: false,
                allow_symbol_change: false,
                container_id: 'tvChartContainer'
            });
        }

        // Canli mum guncellemesi: Binance'in herkese acik kline WebSocket akisi - anahtar/oturum GEREKMEZ
        function connectKlineSocket(pair) {
            if (lwKlineSocket) {
                lwKlineSocket.onclose = null; // kasitli kapatmada tekrar baglanma denemesin
                lwKlineSocket.close();
            }

            var streamName = pair.toLowerCase() + '@kline_' + LW_INTERVAL;
            // Standart 443 portu kullanilir (9443 degil) - bazi kurumsal aglar/guvenlik duvarlari
            // standart olmayan portlarda cikis (egress) trafigini engelliyor, Binance ayni akisi
            // 443'te de resmi olarak sunuyor
            lwKlineSocket = new WebSocket('wss://stream.binance.com:443/ws/' + streamName);

            lwKlineSocket.onmessage = function (evt) {
                try {
                    var msg = JSON.parse(evt.data);
                    var k = msg.k;
                    if (!k || !lwCandleSeries) { return; }
                    lwCandleSeries.update({
                        time: Math.floor(k.t / 1000),
                        open: parseFloat(k.o),
                        high: parseFloat(k.h),
                        low: parseFloat(k.l),
                        close: parseFloat(k.c),
                    });
                } catch (e) { /* tekil bozuk bir mesaj tum akisi kesmesin */ }
            };
        }

        // --- Teknik gostergeler: kapanis fiyatlarindan (Binance klines) istemci tarafinda hesaplanir ---
        function calcSMA(closes, period) {
            if (closes.length < period) { return null; }
            var sum = 0;
            for (var i = closes.length - period; i < closes.length; i++) { sum += closes[i]; }
            return sum / period;
        }

        function calcEMASeries(values, period) {
            var k = 2 / (period + 1);
            var ema = [values[0]];
            for (var i = 1; i < values.length; i++) {
                ema.push(values[i] * k + ema[i - 1] * (1 - k));
            }
            return ema;
        }

        function calcRSI(closes, period) {
            if (closes.length < period + 1) { return null; }
            var gains = 0, losses = 0;
            for (var i = closes.length - period; i < closes.length; i++) {
                var diff = closes[i] - closes[i - 1];
                if (diff >= 0) { gains += diff; } else { losses -= diff; }
            }
            var avgGain = gains / period;
            var avgLoss = losses / period;
            if (avgLoss === 0) { return 100; }
            return 100 - (100 / (1 + (avgGain / avgLoss)));
        }

        function calcMACD(closes) {
            if (closes.length < 35) { return null; }
            var ema12 = calcEMASeries(closes, 12);
            var ema26 = calcEMASeries(closes, 26);
            var macdLine = ema12.map(function (v, i) { return v - ema26[i]; });
            var signalLine = calcEMASeries(macdLine, 9);
            var last = macdLine.length - 1;
            return { macd: macdLine[last], signal: signalLine[last] };
        }

        // TradingView'in "Osilatörler + Hareketli Ortalamalar" oy birligi mantiginin sadelestirilmis
        // kendi kontrolumuzdeki karsiligi - 4 gostergeden (RSI/SMA20/SMA50/MACD) AL/SAT oyu toplanir
        function renderTechnicalGauge(candles) {
            var container = document.getElementById('technicalWidgetContainer');
            if (!container) { return; }

            var closes = candles.map(function (c) { return c.close; });

            if (closes.length < 51) {
                container.innerHTML = '<p class="font-mono-tech text-xs text-gray-400">Yeterli veri yok.</p>';
                return;
            }

            var lastClose = closes[closes.length - 1];
            var rsi = calcRSI(closes, 14);
            var sma20 = calcSMA(closes, 20);
            var sma50 = calcSMA(closes, 50);
            var macd = calcMACD(closes);

            var buyVotes = 0, sellVotes = 0;
            if (rsi !== null) {
                if (rsi < 30) { buyVotes++; } else if (rsi > 70) { sellVotes++; }
            }
            if (sma20 !== null) { if (lastClose > sma20) { buyVotes++; } else { sellVotes++; } }
            if (sma50 !== null) { if (lastClose > sma50) { buyVotes++; } else { sellVotes++; } }
            if (macd !== null) { if (macd.macd > macd.signal) { buyVotes++; } else { sellVotes++; } }

            var net = buyVotes - sellVotes;
            var verdict, colorClass;
            if (net >= 3) { verdict = 'GÜÇLÜ AL'; colorClass = 'text-emerald-600'; }
            else if (net >= 1) { verdict = 'AL'; colorClass = 'text-emerald-500'; }
            else if (net <= -3) { verdict = 'GÜÇLÜ SAT'; colorClass = 'text-rose-600'; }
            else if (net <= -1) { verdict = 'SAT'; colorClass = 'text-rose-500'; }
            else { verdict = 'NÖTR'; colorClass = 'text-gray-500'; }

            // Ibre -90deg (tam SAT) .. +90deg (tam AL) arasinda, net -4..+4 araligina orantili doner
            var needleDeg = Math.max(-90, Math.min(90, net * 22.5));
            var macdIsBuy = macd !== null && macd.macd > macd.signal;
            var sma20IsAbove = sma20 !== null && lastClose > sma20;
            var sma50IsAbove = sma50 !== null && lastClose > sma50;

            container.innerHTML =
                '<svg width="118" height="64" viewBox="0 0 120 66">'
                + '<path d="M 10 60 A 50 50 0 0 1 44 12" fill="none" stroke="#f43f5e" stroke-width="10" stroke-linecap="round"/>'
                + '<path d="M 44 12 A 50 50 0 0 1 76 12" fill="none" stroke="#9ca3af" stroke-width="10" stroke-linecap="round"/>'
                + '<path d="M 76 12 A 50 50 0 0 1 110 60" fill="none" stroke="#10b981" stroke-width="10" stroke-linecap="round"/>'
                + '<line x1="60" y1="60" x2="60" y2="16" stroke="#374151" stroke-width="2.5" stroke-linecap="round" transform="rotate(' + needleDeg + ' 60 60)"/>'
                + '<circle cx="60" cy="60" r="4" fill="#374151"/>'
                + '</svg>'
                + '<div class="font-mono-tech text-sm font-bold ' + colorClass + '">' + verdict + '</div>'
                + '<div class="grid grid-cols-2 gap-x-3 gap-y-0.5 font-mono-tech text-[9px] text-gray-500 mt-1">'
                + '<span>RSI(14): <b class="text-gray-700">' + (rsi !== null ? rsi.toFixed(1) : '—') + '</b></span>'
                + '<span>MACD: <b class="' + (macd !== null ? (macdIsBuy ? 'text-emerald-600' : 'text-rose-600') : 'text-gray-400') + '">' + (macd !== null ? (macdIsBuy ? 'Al' : 'Sat') : '—') + '</b></span>'
                + '<span>SMA20: <b class="' + (sma20 !== null ? (sma20IsAbove ? 'text-emerald-600' : 'text-rose-600') : 'text-gray-400') + '">' + (sma20 !== null ? (sma20IsAbove ? 'Üstünde' : 'Altında') : '—') + '</b></span>'
                + '<span>SMA50: <b class="' + (sma50 !== null ? (sma50IsAbove ? 'text-emerald-600' : 'text-rose-600') : 'text-gray-400') + '">' + (sma50 !== null ? (sma50IsAbove ? 'Üstünde' : 'Altında') : '—') + '</b></span>'
                + '</div>';
        }

        // --- Kayan Fiyat Bandi: Binance public 24hr ticker'i periyodik cekilir ---
        var TICKER_SYMBOLS = [
            { symbol: 'BTCUSDT', title: 'BTC/USDT' },
            { symbol: 'ETHUSDT', title: 'ETH/USDT' },
            { symbol: 'BNBUSDT', title: 'BNB/USDT' },
            { symbol: 'SOLUSDT', title: 'SOL/USDT' },
            { symbol: 'XRPUSDT', title: 'XRP/USDT' },
        ];

        function renderTickerChip(item, priceText, pctText, isUp) {
            var pctColor = isUp === null ? 'text-gray-400' : (isUp ? 'text-emerald-600' : 'text-rose-600');
            var arrow = isUp === null ? '' : (isUp ? '▲' : '▼');
            return '<span class="flex items-center gap-1.5 shrink-0">'
                + '<b class="text-gray-800">' + item.title + '</b>'
                + '<span class="text-gray-600">' + priceText + '</span>'
                + '<span class="' + pctColor + '">' + arrow + ' ' + pctText + '</span>'
                + '</span>';
        }

        function initTickerTape() {
            var track = document.getElementById('customTickerTrack');
            if (!track) { return; }

            // Dikissiz sonsuz dongu icin ayni icerik IKI KEZ art arda (bkz. .animate-ticker-scroll'un
            // translateX(-50%) kurali) - refreshTickerTape() de HER ZAMAN bu ikili yapiyi korur
            var placeholder = TICKER_SYMBOLS.map(function (item) { return renderTickerChip(item, '—', '—', null); }).join('');
            track.innerHTML = placeholder + placeholder;

            refreshTickerTape();
        }

        function refreshTickerTape() {
            var track = document.getElementById('customTickerTrack');
            if (!track) { return; }

            var symbolsParam = encodeURIComponent(JSON.stringify(TICKER_SYMBOLS.map(function (s) { return s.symbol; })));

            fetch('https://api.binance.com/api/v3/ticker/24hr?symbols=' + symbolsParam)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!Array.isArray(data)) { return; }

                    var bySymbol = {};
                    data.forEach(function (d) { bySymbol[d.symbol] = d; });

                    var html = TICKER_SYMBOLS.map(function (item) {
                        var d = bySymbol[item.symbol];
                        if (!d) { return renderTickerChip(item, '—', '—', null); }
                        var price = parseFloat(d.lastPrice);
                        var pct = parseFloat(d.priceChangePercent);
                        var priceText = '$' + (price >= 1 ? price.toLocaleString('en-US', { maximumFractionDigits: 2 }) : price.toPrecision(4));
                        return renderTickerChip(item, priceText, Math.abs(pct).toFixed(2) + '%', pct >= 0);
                    }).join('');

                    track.innerHTML = html + html;
                })
                .catch(function () { /* fail-open: mevcut icerik degismeden kalir, bir sonraki turda tekrar dener */ });
        }

        function openSettingsModal() {
            var modal = document.getElementById('settingsModal');
            var backdrop = document.getElementById('settingsBackdrop');
            var panel = document.getElementById('settingsPanel');
            modal.classList.remove('hidden');
            requestAnimationFrame(function () {
                backdrop.classList.remove('opacity-0');
                panel.classList.remove('opacity-0', 'scale-95');
            });
        }

        function closeSettingsModal() {
            var modal = document.getElementById('settingsModal');
            var backdrop = document.getElementById('settingsBackdrop');
            var panel = document.getElementById('settingsPanel');
            backdrop.classList.add('opacity-0');
            panel.classList.add('opacity-0', 'scale-95');
            setTimeout(function () { modal.classList.add('hidden'); }, 200);
        }

        // API Kurulum Rehberi akordeonu - varsayilan kapali, tiklaninca acilir/kapanir
        function toggleApiGuide() {
            var content = document.getElementById('apiGuideContent');
            var arrow = document.getElementById('apiGuideArrow');
            var isHidden = content.classList.contains('hidden');
            content.classList.toggle('hidden');
            arrow.textContent = isHidden ? '▾' : '▸';
        }

        // Sunucu IP adresini panoya kopyalar (Binance'in "güvenilir IP" kutucuğuna yapıştırmak için)
        function copyServerIp() {
            var ip = document.getElementById('serverIpValue').textContent.trim();
            var button = document.getElementById('copyIpButton');

            var showCopied = function () {
                var original = button.textContent;
                button.textContent = 'Kopyalandı ✓';
                setTimeout(function () { button.textContent = original; }, 2000);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(ip).then(showCopied).catch(function () {
                    fallbackCopy(ip, showCopied);
                });
            } else {
                fallbackCopy(ip, showCopied);
            }
        }

        // navigator.clipboard'un desteklenmedigi (ör. eski tarayici/HTTP) durumlar icin yedek yontem
        function fallbackCopy(text, onSuccess) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            try {
                document.execCommand('copy');
                onSuccess();
            } catch (e) {
                // sessizce yut - kullanici manuel secip kopyalayabilir
            }
            document.body.removeChild(textarea);
        }

        // Risk Bildirimi modalindaki zorunlu checkbox isaretlenmeden "Kabul Ediyorum" butonu pasif kalir
        (function () {
            var riskCheckbox = document.getElementById('riskAcceptCheckbox');
            var riskSubmit = document.getElementById('riskAcceptSubmit');

            if (riskCheckbox && riskSubmit) {
                riskCheckbox.addEventListener('change', function () {
                    riskSubmit.disabled = !riskCheckbox.checked;
                });
            }
        })();

        // "Son İşlemler" tablosundaki bir satıra/👁 ikonuna tıklanınca çağrılır - detay lazy (sadece
        // tıklanınca) yüklenir, sayfa ilk açılışında her sipariş için gereksiz sorgu yapılmaz
        var STRATEGY_BUCKET_LABELS = {
            dinamik_hacim: 'Dinamik Hacim',
            // Asagidaki 3 etiket v1.9.0 "Vur-Kaç" gecisiyle artik uretilmiyor (bkz. MarketScanner.php),
            // ama GECMIS islem kayitlarinda hala var - "İşlem Detayı" panelinde dogru gorunmeye devam etsin diye korunuyor
            golge_hacim: 'Gölge Hacim',
            dipten_donus: 'Dipten Dönüş',
            erken_momentum: 'Erken Momentum',
            whitelist: 'Beyaz Liste',
            announcement_hunter: 'Duyuru Avcısı',
        };

        // Tam hassasiyetli fiyat gösterimi (İşlem Detay modalı için) - tablodaki 2/4 hane kuralından
        // farklı olarak, 1$ altı fiyatlarda 8 haneye kadar (sondaki gereksiz sıfırlar kırpılarak) gösterir
        function formatFullPrecisionPrice(price) {
            price = parseFloat(price);
            if (!isFinite(price)) { return '-'; }
            if (price >= 1) {
                return price.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
            }
            var fixed = price.toFixed(8).replace(/0+$/, '').replace(/\.$/, '');
            return fixed === '' ? '0' : fixed;
        }

        function openOrderDetail(orderId) {
            var modal = document.getElementById('orderDetailModal');
            var backdrop = document.getElementById('orderDetailBackdrop');
            var panel = document.getElementById('orderDetailPanel');
            var body = document.getElementById('orderDetailBody');

            body.innerHTML = '<p class="font-mono-tech text-xs text-gray-500">Yükleniyor…</p>';
            modal.classList.remove('hidden');
            requestAnimationFrame(function () {
                backdrop.classList.remove('opacity-0');
                panel.classList.remove('opacity-0', 'scale-95');
            });

            safeFetch('/api/dashboard/order-detail?id=' + orderId)
                .then(function (d) {
                    if (!d.success) {
                        body.innerHTML = '<p class="font-mono-tech text-xs text-rose-600">İşlem detayı alınamadı.</p>';
                        return;
                    }
                    renderOrderDetail(d);
                })
                .catch(function () {
                    body.innerHTML = '<p class="font-mono-tech text-xs text-rose-600">Bağlantı hatası.</p>';
                });
        }

        function closeOrderDetailModal() {
            var modal = document.getElementById('orderDetailModal');
            var backdrop = document.getElementById('orderDetailBackdrop');
            var panel = document.getElementById('orderDetailPanel');
            backdrop.classList.add('opacity-0');
            panel.classList.add('opacity-0', 'scale-95');
            setTimeout(function () { modal.classList.add('hidden'); }, 200);
        }

        function renderOrderDetail(data) {
            var order = data.order;
            var isBuy = order.side.toLowerCase() === 'buy';
            var sideLabel = isBuy ? 'Alış' : 'Satış';
            var sideClass = isBuy ? 'text-emerald-600' : 'text-rose-600';

            var html = '<div class="flex items-center justify-between mb-1">'
                + '<span class="font-display font-semibold text-gray-900">' + order.pair + '</span>'
                + '<span class="font-mono-tech text-xs font-bold ' + sideClass + '">' + sideLabel + '</span>'
                + '</div>';

            html += '<div class="grid grid-cols-2 gap-3 font-mono-tech text-xs bg-black/[0.02] rounded-lg p-3">'
                + '<div><div class="text-gray-400 text-[9px] tracking-widest mb-0.5">GERÇEKLEŞEN FİYAT</div><div class="text-gray-800">$' + formatFullPrecisionPrice(order.price) + '</div></div>'
                + '<div><div class="text-gray-400 text-[9px] tracking-widest mb-0.5">GERÇEKLEŞEN TUTAR</div><div class="text-gray-800">$' + parseFloat(order.total).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</div></div>'
                + '<div><div class="text-gray-400 text-[9px] tracking-widest mb-0.5">MİKTAR</div><div class="text-gray-800">' + order.quantity + '</div></div>'
                + '<div><div class="text-gray-400 text-[9px] tracking-widest mb-0.5">TARİH</div><div class="text-gray-800">' + order.created_at + '</div></div>'
                + '</div>';

            if (data.pnl_usdt !== null && data.pnl_usdt !== undefined) {
                var isProfit = data.pnl_usdt >= 0;
                var pnlClass = isProfit ? 'text-emerald-600' : 'text-rose-600';
                var boxClass = isProfit ? 'border-emerald-400/30 bg-emerald-400/5' : 'border-rose-400/30 bg-rose-400/5';
                var sign = isProfit ? '+' : '';
                var pctText = (data.pnl_percent !== null && data.pnl_percent !== undefined)
                    ? ' (' + sign + data.pnl_percent.toFixed(2) + '%)'
                    : '';

                html += '<div class="rounded-lg border ' + boxClass + ' px-3 py-2 mt-3">'
                    + '<div class="font-mono-tech text-[9px] tracking-widest text-gray-500 mb-0.5">NET KÂR/ZARAR (PNL)</div>'
                    + '<div class="font-mono-tech text-lg font-bold ' + pnlClass + '">' + sign + '$' + data.pnl_usdt.toFixed(2) + pctText + '</div>'
                    + '</div>';
            }

            if (data.strategy_bucket) {
                var label = STRATEGY_BUCKET_LABELS[data.strategy_bucket] || data.strategy_bucket;
                html += '<div class="flex items-center gap-1.5 mt-3">'
                    + '<span class="font-mono-tech text-[9px] tracking-widest text-gray-400">STRATEJİ:</span>'
                    + '<span class="font-mono-tech text-[10px] text-violet-600 border border-violet-400/30 bg-violet-400/10 rounded px-1.5 py-0.5">' + label + '</span>'
                    + '</div>';
            }

            if (order.error_message) {
                html += '<div class="rounded-lg border border-rose-400/30 bg-rose-400/5 px-3 py-2 mt-3 text-[10px] text-rose-600">' + escapeHtml(order.error_message) + '</div>';
            }

            // Trade Post-Mortem: zararla kapanan pozisyonun kok nedeni - satirdaki ℹ️ ikonuna
            // tiklandiginda (ya da satirin herhangi bir yerine) acilan bu modalda goruntulenir.
            // Hover'a dayali "title" tooltip'i tek basina yeterli degildi (dokunmatik cihazlarda
            // hic calismaz, satirin kendi onclick'i zaten bu modali actigi icin tiklama buraya duser)
            if (order.loss_reason) {
                html += '<div class="rounded-lg border border-amber-400/30 bg-amber-400/5 px-3 py-2 mt-3">'
                    + '<div class="font-mono-tech text-[9px] tracking-widest text-amber-600 mb-0.5">ℹ️ ZARAR ANALİZİ (POST-MORTEM)</div>'
                    + '<div class="text-[11px] text-gray-700 leading-snug">' + escapeHtml(order.loss_reason) + '</div>'
                    + '</div>';
            }

            document.getElementById('orderDetailBody').innerHTML = html;
        }

        var _historyCurrentPeriod = 'daily';

        function openOrderHistoryModal() {
            var modal = document.getElementById('orderHistoryModal');
            var backdrop = document.getElementById('orderHistoryBackdrop');
            var panel = document.getElementById('orderHistoryPanel');
            modal.classList.remove('hidden');
            requestAnimationFrame(function () {
                backdrop.classList.remove('opacity-0');
                panel.classList.remove('opacity-0', 'scale-95');
            });
            fetchOrderHistory(_historyCurrentPeriod);
        }

        function closeOrderHistoryModal() {
            var modal = document.getElementById('orderHistoryModal');
            var backdrop = document.getElementById('orderHistoryBackdrop');
            var panel = document.getElementById('orderHistoryPanel');
            backdrop.classList.add('opacity-0');
            panel.classList.add('opacity-0', 'scale-95');
            setTimeout(function () { modal.classList.add('hidden'); }, 200);
        }

        function fetchOrderHistory(period) {
            _historyCurrentPeriod = period;

            document.querySelectorAll('.history-period-btn').forEach(function (btn) {
                var active = btn.getAttribute('data-history-period') === period;
                btn.classList.toggle('bg-violet-500', active);
                btn.classList.toggle('text-white', active);
                btn.classList.toggle('border-violet-500', active);
                btn.classList.toggle('border-black/10', !active);
                btn.classList.toggle('text-gray-500', !active);
            });

            document.getElementById('orderHistorySummary').innerHTML = '<p class="font-mono-tech text-xs text-gray-500 col-span-3">Yükleniyor…</p>';
            document.getElementById('orderHistoryList').innerHTML = '<p class="font-mono-tech text-xs text-gray-500">Yükleniyor…</p>';

            safeFetch('/api/dashboard/order-history?period=' + period)
                .then(function (d) {
                    if (!d.success) {
                        document.getElementById('orderHistorySummary').innerHTML = '<p class="font-mono-tech text-xs text-rose-500 col-span-3">Veri alınamadı</p>';
                        document.getElementById('orderHistoryList').innerHTML = '';
                        return;
                    }
                    renderOrderHistorySummary(d.summary);
                    renderOrderHistoryRows(d.orders || []);
                })
                .catch(function () {
                    document.getElementById('orderHistorySummary').innerHTML = '<p class="font-mono-tech text-xs text-rose-500 col-span-3">Veri alınamadı</p>';
                    document.getElementById('orderHistoryList').innerHTML = '';
                });
        }

        function renderOrderHistorySummary(summary) {
            var netProfit = parseFloat(summary.net_profit || 0);
            var isProfit = netProfit >= 0;
            var pnlClass = isProfit ? 'text-emerald-600' : 'text-rose-600';
            var sign = isProfit ? '+' : '';
            var winRateText = (summary.win_rate === null || summary.win_rate === undefined) ? '—' : summary.win_rate.toFixed(1) + '%';

            var html =
                '<div class="rounded-lg border border-black/5 bg-black/[0.02] px-3 py-2">'
                + '<div class="font-mono-tech text-[9px] tracking-widest text-gray-400 mb-0.5">TOPLAM İŞLEM</div>'
                + '<div class="font-mono-tech text-sm font-bold text-gray-800">' + (summary.total_trades || 0) + '</div>'
                + '</div>'
                + '<div class="rounded-lg border border-black/5 bg-black/[0.02] px-3 py-2">'
                + '<div class="font-mono-tech text-[9px] tracking-widest text-gray-400 mb-0.5">KAZANMA ORANI</div>'
                + '<div class="font-mono-tech text-sm font-bold text-gray-800">' + winRateText + '</div>'
                + '</div>'
                + '<div class="rounded-lg border border-black/5 bg-black/[0.02] px-3 py-2">'
                + '<div class="font-mono-tech text-[9px] tracking-widest text-gray-400 mb-0.5">NET KÂR/ZARAR</div>'
                + '<div class="font-mono-tech text-sm font-bold ' + pnlClass + '">' + sign + '$' + Math.abs(netProfit).toFixed(2) + '</div>'
                + '</div>';

            document.getElementById('orderHistorySummary').innerHTML = html;
        }

        // "Son İşlemler" tablosundaki $statusMap (PHP) ile AYNI etiket/renk eslemesi - bu liste
        // sunucu render'ı değil JS ile dolduruldugu icin ayni haritanin JS karsiligi burada tutulur
        var HISTORY_STATUS_MAP = {
            FILLED:    ['OK', 'bg-emerald-400/10 text-emerald-600'],
            FAILED:    ['HATA', 'bg-rose-400/10 text-rose-600'],
            PENDING:   ['BEKLİYOR', 'bg-amber-400/10 text-amber-600'],
            CANCELLED: ['İPTAL', 'bg-gray-400/10 text-gray-600']
        };

        function renderOrderHistoryRows(orders) {
            var container = document.getElementById('orderHistoryList');

            if (!orders.length) {
                container.innerHTML = '<p class="font-mono-tech text-xs text-gray-500">Bu dönemde işlem yok</p>';
                return;
            }

            var rows = orders.map(function (order) {
                var side = String(order.side || '').toUpperCase();
                var status = String(order.status || '').toUpperCase();
                var sideLabel = side === 'BUY' ? 'AL' : 'SAT';
                var sideClass = side === 'BUY' ? 'bg-emerald-400/10 text-emerald-600' : 'bg-rose-400/10 text-rose-600';
                var statusInfo = HISTORY_STATUS_MAP[status] || [status, 'bg-gray-400/10 text-gray-600'];

                var lossBadge = order.loss_reason
                    ? '<span class="ml-0.5 cursor-help" title="' + escapeHtml(order.loss_reason) + '">ℹ️</span>'
                    : '';

                return '<tr class="border-t border-black/5 hover:bg-black/[0.015] cursor-pointer transition-colors" onclick="openOrderDetail(' + order.id + ')">'
                    + '<td data-coin-name="' + escapeHtml(order.pair) + '" class="px-4 py-1.5 font-semibold text-gray-800">' + coinIconHtml(order.pair) + escapeHtml(order.pair) + '</td>'
                    + '<td class="px-2 py-1.5"><span class="rounded px-1.5 py-0.5 ' + sideClass + '">' + sideLabel + '</span>' + lossBadge + '</td>'
                    + '<td class="px-2 py-1.5 text-gray-600">' + order.quantity + '</td>'
                    + '<td class="px-2 py-1.5 text-gray-600">$' + formatFullPrecisionPrice(order.price) + '</td>'
                    + '<td class="px-4 py-1.5"><span class="rounded px-1.5 py-0.5 ' + statusInfo[1] + '">' + statusInfo[0] + '</span></td>'
                    + '<td class="px-2 py-1.5 text-right text-gray-300">›</td>'
                    + '</tr>';
            }).join('');

            container.innerHTML =
                '<table class="w-full font-mono-tech text-[11px]">'
                + '<thead><tr class="text-left text-gray-500 text-[9px] tracking-widest sticky top-0 bg-white">'
                + '<th class="font-medium px-4 py-1.5">PARİTE</th>'
                + '<th class="font-medium px-2 py-1.5">YÖN</th>'
                + '<th class="font-medium px-2 py-1.5">MİKTAR</th>'
                + '<th class="font-medium px-2 py-1.5">FİYAT</th>'
                + '<th class="font-medium px-4 py-1.5">DURUM</th>'
                + '<th class="font-medium px-2 py-1.5 text-right"></th>'
                + '</tr></thead>'
                + '<tbody>' + rows + '</tbody>'
                + '</table>';
        }

        function openPerformanceModal() {
            var modal = document.getElementById('performanceModal');
            var backdrop = document.getElementById('performanceBackdrop');
            var panel = document.getElementById('performancePanel');
            modal.classList.remove('hidden');
            requestAnimationFrame(function () {
                backdrop.classList.remove('opacity-0');
                panel.classList.remove('opacity-0', 'scale-95');
            });

            document.getElementById('performanceStrategies').innerHTML = '<p class="font-mono-tech text-xs text-gray-500">Yükleniyor…</p>';
            document.getElementById('performanceSymbols').innerHTML = '<p class="font-mono-tech text-xs text-gray-500">Yükleniyor…</p>';

            safeFetch('/api/dashboard/performance-breakdown')
                .then(function (d) {
                    if (!d.success) {
                        document.getElementById('performanceStrategies').innerHTML = '<p class="font-mono-tech text-xs text-rose-500">Veri alınamadı</p>';
                        document.getElementById('performanceSymbols').innerHTML = '';
                        return;
                    }
                    renderPerformanceRows('performanceStrategies', d.strategies, function (row) {
                        return STRATEGY_BUCKET_LABELS[row.strategy_bucket] || row.strategy_bucket || 'Diğer / Webhook';
                    });
                    renderPerformanceRows('performanceSymbols', d.symbols, function (row) { return row.pair; });
                })
                .catch(function () {
                    document.getElementById('performanceStrategies').innerHTML = '<p class="font-mono-tech text-xs text-rose-500">Veri alınamadı</p>';
                    document.getElementById('performanceSymbols').innerHTML = '';
                });
        }

        function closePerformanceModal() {
            var modal = document.getElementById('performanceModal');
            var backdrop = document.getElementById('performanceBackdrop');
            var panel = document.getElementById('performancePanel');
            backdrop.classList.add('opacity-0');
            panel.classList.add('opacity-0', 'scale-95');
            setTimeout(function () { modal.classList.add('hidden'); }, 200);
        }

        function renderPerformanceRows(containerId, rows, labelFn) {
            var container = document.getElementById(containerId);

            if (!rows || !rows.length) {
                container.innerHTML = '<p class="font-mono-tech text-xs text-gray-500">Henüz kapanmış işlem yok</p>';
                return;
            }

            container.innerHTML = rows.map(function (row) {
                var netProfit = parseFloat(row.net_profit || 0);
                var isProfit = netProfit >= 0;
                var pnlClass = isProfit ? 'text-emerald-600' : 'text-rose-600';
                var sign = isProfit ? '+' : '';
                var winRateText = (row.win_rate === null || row.win_rate === undefined) ? '—' : row.win_rate.toFixed(1) + '%';

                return '<div class="flex items-center justify-between py-1.5 border-t border-black/5 first:border-t-0">'
                    + '<div class="font-mono-tech text-xs text-gray-700">' + escapeHtml(labelFn(row))
                    + '<span class="text-gray-400 ml-1.5">(' + row.total_trades + ' işlem, ' + winRateText + ' kazanma)</span></div>'
                    + '<div class="font-mono-tech text-xs font-bold ' + pnlClass + '">' + sign + '$' + Math.abs(netProfit).toFixed(2) + '</div>'
                    + '</div>';
            }).join('');
        }

        function openSystemStatusModal() {
            var modal = document.getElementById('systemStatusModal');
            var backdrop = document.getElementById('systemStatusBackdrop');
            var panel = document.getElementById('systemStatusPanel');
            modal.classList.remove('hidden');
            requestAnimationFrame(function () {
                backdrop.classList.remove('opacity-0');
                panel.classList.remove('opacity-0', 'scale-95');
            });
            fetchSystemStatus(true);
        }

        function closeSystemStatusModal() {
            var modal = document.getElementById('systemStatusModal');
            var backdrop = document.getElementById('systemStatusBackdrop');
            var panel = document.getElementById('systemStatusPanel');
            backdrop.classList.add('opacity-0');
            panel.classList.add('opacity-0', 'scale-95');
            setTimeout(function () { modal.classList.add('hidden'); }, 200);
        }

        // renderBody=true iken modal acikken cagrilir (govdeyi de doldurur); navbar'daki noktanin
        // periyodik tazelenmesinde (modal kapaliyken) sadece renderSystemStatusDot calisir, govde
        // gereksiz yere DOM'a yazilmaz
        function fetchSystemStatus(renderBody) {
            safeFetch('/api/dashboard/system-status')
                .then(function (d) {
                    if (!d.success) {
                        renderSystemStatusDot(null);
                        if (renderBody) {
                            document.getElementById('systemStatusBody').innerHTML = '<p class="font-mono-tech text-xs text-rose-500">Sistem durumu alınamadı</p>';
                        }
                        return;
                    }
                    renderSystemStatusDot(d);
                    if (renderBody) { renderSystemStatusBody(d); }
                })
                .catch(function () {
                    renderSystemStatusDot(null);
                });
        }

        function renderSystemStatusDot(d) {
            var dot = document.getElementById('systemStatusDot');
            if (!dot) return;

            dot.classList.remove('animate-pulse', 'bg-gray-300', 'bg-emerald-400', 'bg-amber-400', 'bg-rose-500');

            if (!d) {
                dot.classList.add('bg-gray-300', 'animate-pulse');
                return;
            }

            if (d.circuit_breaker_until) {
                dot.classList.add('bg-rose-500');
                return;
            }

            var allHealthy = Object.keys(d.modules).every(function (key) { return d.modules[key].healthy; });
            dot.classList.add(allHealthy ? 'bg-emerald-400' : 'bg-amber-400');
        }

        // Manuel Kill Switch: RiskManagerService::checkCircuitBreaker()'in EN BASINDA kontrol ettigi,
        // suresiz manuel bayrak - devre kesicinin otomatik (3 zarar / gunluk limit) mekanizmalarindan
        // BAGIMSIZ, kullanici/yonetici istedigi an TUM otonom yeni-islem girislerini durdurabilir
        function renderKillSwitchButton(enabled) {
            var activeClasses = 'border-rose-500 bg-rose-500/10 text-rose-600';
            var inactiveClasses = 'border-black/10 bg-black/[0.02] text-gray-600';
            var statusText = enabled
                ? '🔴 AKTİF - tüm otonom yeni işlem girişleri durduruldu (açık pozisyonlar izlenmeye devam ediyor)'
                : '⚪ Kapalı - otonom modüller normal çalışıyor';

            return '<div class="rounded-lg border ' + (enabled ? activeClasses : inactiveClasses) + ' px-3 py-2.5">'
                + '<div class="flex items-center justify-between gap-3">'
                + '<div>'
                + '<div class="font-mono-tech text-xs font-bold">🛑 Manuel Kill Switch</div>'
                + '<div class="font-mono-tech text-[10px] mt-0.5">' + statusText + '</div>'
                + '</div>'
                + '<button type="button" onclick="toggleKillSwitch(' + (enabled ? 'false' : 'true') + ')" class="flex-none font-mono-tech text-[10px] font-bold rounded-lg px-3 py-1.5 transition-colors '
                + (enabled ? 'bg-emerald-500 text-white hover:bg-emerald-600' : 'bg-rose-500 text-white hover:bg-rose-600') + '">'
                + (enabled ? 'AÇ' : 'DURDUR') + '</button>'
                + '</div></div>';
        }

        function toggleKillSwitch(enable) {
            var confirmText = enable
                ? 'TÜM otonom modülleri (spot + futures) şimdi durdurmak istediğine emin misin? Açık pozisyonlar izlenmeye devam eder, sadece yeni işlem girişleri durur.'
                : 'Manuel Kill Switch\'i kapatıp otonom modüllerin normal çalışmasına izin vermek istediğine emin misin?';

            if (!confirm(confirmText)) return;

            var formData = new URLSearchParams();
            formData.append('enabled', enable ? '1' : '0');

            fetch(_BASE + '/api/dashboard/toggle-kill-switch', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        showToast(enable ? 'Kill Switch aktif edildi' : 'Kill Switch kapatıldı', true);
                        fetchSystemStatus(true);
                    } else {
                        showToast('Kill Switch güncellenemedi', false);
                    }
                })
                .catch(function () {
                    showToast('Kill Switch güncellenemedi', false);
                });
        }

        // 30 Temmuz'da eklendi: "Aktif Avlar" karti uzerindeki manuel "Simdi Kapat" butonu -
        // musteri otomatik hedefi beklemeden ANINDA piyasadan satip kilitlemek istediginde
        function closePositionNow(tradeId, pair) {
            if (!confirm(pair + ' pozisyonunu ŞİMDİ piyasa fiyatından kapatmak istediğine emin misin? Bu işlem GERİ ALINAMAZ.')) return;

            var formData = new URLSearchParams();
            formData.append('trade_id', tradeId);

            fetch(_BASE + '/api/dashboard/close-position', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        showToast(pair + ' kapatıldı ($' + parseFloat(d.exit_price).toFixed(6) + ')', true);
                        // Sunucu artik satis gerceklesir gerceklesmez cevap donuyor (kayit/bildirim
                        // arka planda tamamlanir - bkz. apiClosePosition yorumu), bu yuzden kart
                        // hemen (arka plandaki DB yazimini beklemeden) client-side kaldirilir - aksi
                        // halde bir sonraki fetchActiveTrades() turu DB yazimindan once yetisip
                        // kapanan pozisyonu hala "acik" gosterebilirdi
                        var card = document.querySelector('[data-hunt-card="' + tradeId + '"]');
                        if (card) { card.remove(); }
                        fetchActiveTrades();
                        fetchPnl();
                        fetchBalance();
                    } else {
                        showToast(d.message || 'Pozisyon kapatılamadı', false);
                    }
                })
                .catch(function () {
                    showToast('Pozisyon kapatılamadı', false);
                });
        }

        function renderSystemStatusBody(d) {
            var moduleRows = Object.keys(d.modules).map(function (key) {
                var m = d.modules[key];
                var dotClass = m.healthy ? 'bg-emerald-400' : 'bg-rose-500';
                var timeText = m.last_run_at ? formatRelativeTime(m.last_run_at * 1000) : 'hiç çalışmadı';

                return '<div class="flex items-center justify-between py-1.5">'
                    + '<span class="flex items-center gap-2 font-mono-tech text-xs text-gray-700">'
                    + '<span class="w-2 h-2 rounded-full ' + dotClass + ' flex-none"></span>' + escapeHtml(m.label) + '</span>'
                    + '<span class="font-mono-tech text-[10px] text-gray-500">' + timeText + '</span>'
                    + '</div>';
            }).join('');

            var circuitHtml = d.circuit_breaker_until
                ? '<div class="rounded-lg border border-rose-400/30 bg-rose-400/5 px-3 py-2 text-xs text-rose-600 font-mono-tech">🔒 Devre kesici AKTİF - ' + escapeHtml(d.circuit_breaker_until) + '\'e kadar tüm otonom modüller durduruldu</div>'
                : '<div class="rounded-lg border border-emerald-400/30 bg-emerald-400/5 px-3 py-2 text-xs text-emerald-600 font-mono-tech">✓ Devre kesici kapalı</div>';

            var errorsHtml;
            if (!d.recent_critical_errors || !d.recent_critical_errors.length) {
                errorsHtml = '<p class="font-mono-tech text-[10px] text-gray-400">Son loglarda kritik hata yok</p>';
            } else {
                errorsHtml = d.recent_critical_errors.slice().reverse().map(function (line) {
                    return '<div class="font-mono-tech text-[10px] text-rose-600 border-l-2 border-rose-400/40 pl-2 py-0.5 leading-snug">' + escapeHtml(line) + '</div>';
                }).join('');
            }

            var riskHtml = renderRiskLimitGauge(d.risk_limit);
            var cooldownsHtml = renderSymbolCooldowns(d.symbol_cooldowns);
            var killSwitchHtml = renderKillSwitchButton(d.manual_kill_switch);

            document.getElementById('systemStatusBody').innerHTML =
                killSwitchHtml
                + circuitHtml
                + '<div><div class="font-mono-tech text-[9px] tracking-widest text-gray-400 mb-1">OTONOM MODÜLLER</div>' + moduleRows + '</div>'
                + '<div><div class="font-mono-tech text-[9px] tracking-widest text-gray-400 mb-1">GÜNLÜK RİSK LİMİTİ</div>' + riskHtml + '</div>'
                + '<div><div class="font-mono-tech text-[9px] tracking-widest text-gray-400 mb-1">SEMBOL SOĞUMASI</div>' + cooldownsHtml + '</div>'
                + '<div><div class="font-mono-tech text-[9px] tracking-widest text-gray-400 mb-1">SON KRİTİK HATALAR</div>' + errorsHtml + '</div>';
        }

        // RiskManagerService::checkCircuitBreaker()'daki kayan-24sn zarar / gunluk limit oranini
        // gorsellestirir - burasi HICBIR engelleme kararı VERMEZ, sadece o oranin ne kadara
        // yaklastigini gosterir (bkz. DashboardController::calculateRiskLimitUsage yorumu)
        function renderRiskLimitGauge(risk) {
            if (!risk) {
                return '<p class="font-mono-tech text-[10px] text-gray-400">Risk verisi alınamadı (API anahtarı/borsa erişimi gerekli)</p>';
            }

            var pct = risk.used_percent;
            var barClass = pct >= 80 ? 'bg-rose-500' : pct >= 50 ? 'bg-amber-400' : 'bg-emerald-400';
            var textClass = pct >= 80 ? 'text-rose-600' : pct >= 50 ? 'text-amber-600' : 'text-emerald-600';

            return '<div class="rounded-lg border border-black/5 bg-black/[0.02] px-3 py-2">'
                + '<div class="flex justify-between items-center mb-1.5">'
                + '<span class="font-mono-tech text-[10px] text-gray-600">-$' + risk.loss_amount.toFixed(2) + ' / -$' + risk.max_loss_amount.toFixed(2) + ' (%' + risk.max_daily_loss_percent + ' günlük limit)</span>'
                + '<span class="font-mono-tech text-xs font-bold ' + textClass + '">%' + pct.toFixed(1) + '</span>'
                + '</div>'
                + '<div class="hunt-progress-track w-full h-1.5 rounded-full">'
                + '<div class="h-full rounded-full ' + barClass + ' transition-all duration-500" style="width:' + Math.max(0, Math.min(100, pct)) + '%"></div>'
                + '</div></div>';
        }

        function renderSymbolCooldowns(cooldowns) {
            if (!cooldowns || !cooldowns.length) {
                return '<p class="font-mono-tech text-[10px] text-gray-400">Aktif soğuma yok</p>';
            }

            return cooldowns.map(function (c) {
                var minutes = c.minutes_remaining;
                var timeText = minutes >= 60 ? Math.round(minutes / 60) + ' sa' : minutes + ' dk';

                return '<div class="flex items-center justify-between py-1">'
                    + '<span class="font-mono-tech text-xs text-gray-700 cursor-help" title="' + escapeHtml(c.reason || '') + '">' + escapeHtml(c.pair) + '</span>'
                    + '<span class="flex items-center gap-2">'
                    + '<span class="font-mono-tech text-[10px] text-amber-600">' + timeText + ' kaldı</span>'
                    + '<button type="button" onclick="clearSymbolCooldown(\'' + escapeHtml(c.pair) + '\')" class="font-mono-tech text-[9px] text-gray-400 hover:text-rose-600 border border-black/10 hover:border-rose-400/40 rounded px-1 py-0.5 transition-colors">Atla</button>'
                    + '</span>'
                    + '</div>';
            }).join('');
        }

        // Musteri "Atla" butonuna bastiginda soguma manuel kaldirilir - onaydan sonra POST edilir,
        // basarili olursa Sistem Durumu govdesi taze veriyle yeniden cizilir (liste guncellenir)
        function clearSymbolCooldown(pair) {
            if (!confirm(pair + ' için soğumayı şimdi kaldırmak istediğine emin misin?')) return;

            var formData = new URLSearchParams();
            formData.append('pair', pair);

            fetch(_BASE + '/api/dashboard/clear-cooldown', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        showToast(pair + ' soğuması kaldırıldı', true);
                        fetchSystemStatus(true);
                    } else {
                        showToast('Soğuma kaldırılamadı', false);
                    }
                })
                .catch(function () {
                    showToast('Soğuma kaldırılamadı', false);
                });
        }

        function openChangelogModal() {
            var modal = document.getElementById('changelogModal');
            var backdrop = document.getElementById('changelogBackdrop');
            var panel = document.getElementById('changelogPanel');
            modal.classList.remove('hidden');
            requestAnimationFrame(function () {
                backdrop.classList.remove('opacity-0');
                panel.classList.remove('opacity-0', 'scale-95');
            });
        }

        function closeChangelogModal() {
            var modal = document.getElementById('changelogModal');
            var backdrop = document.getElementById('changelogBackdrop');
            var panel = document.getElementById('changelogPanel');
            backdrop.classList.add('opacity-0');
            panel.classList.add('opacity-0', 'scale-95');
            setTimeout(function () { modal.classList.add('hidden'); }, 200);
        }

        // =====================================================
        // NEXATRADE — ASYNC DATA FETCH & WIDGET RENDER ENGINE
        // Tüm istekler /api/dashboard/* — safeFetch ile HTTP
        // hataları yakalanır, panel "yükleniyor" donmaz
        // =====================================================

        // PHP'nin hesapladığı alt dizin ön eki — alt dizin kurulumunda (ör. XAMPP /nexatrade)
        // fetch URL'leri otomatik olarak doğru hedefe yönlenir
        var _BASE = '<?= \App\Core\Router::basePath() ?>';

        var _totalBalance   = 0;
        var _openTradeCount = 0;
        var _openFuturesTradeCount = 0;

        // 30 Temmuz'da eklendi: "AÇIK POZİSYON" ust bar sayaci hic id/JS guncellemesi almiyordu -
        // fetchActiveTrades() (spot, 3sn) ve fetchFuturesPositions() (futures, 3sn) ikisi de bu
        // ortak fonksiyonu cagirir, ikisinin toplamini yazar
        function updateNavOpenPositions() {
            var el = document.getElementById('navOpenPositions');
            if (el) el.textContent = _openTradeCount + _openFuturesTradeCount;
        }

        // Sayı biçimlendirici
        function fmt$(n) {
            var f = parseFloat(n) || 0;
            if (f >= 1000) return '$' + f.toLocaleString('en-US', { maximumFractionDigits: 2 });
            if (f >= 1)    return '$' + f.toFixed(4).replace(/\.?0+$/, '');
            return '$' + f.toFixed(8).replace(/\.?0+$/, '');
        }

        // HTTP hatasını da yakalar, JSON döndürür — her fetch() bunu kullanır
        // _BASE ile birleştirilir: XAMPP alt dizin kurulumlarında (/nexatrade) doğru URL üretir
        function safeFetch(url) {
            return fetch(_BASE + url).then(function(r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            });
        }

        // Coin İkonları (31 Temmuz, müşteri talebi) - bkz. CoinIconService/apiCoinIcons yorumu.
        // _coinIconCache: taban sembol (ör. 'BTC') -> logo URL'i, ya da null (bulunamadı, tekrar
        // İSTENMEZ - hasOwnProperty ile "hiç sorulmadı" / "soruldu, yok" ayrımı yapılır)
        var _coinIconCache = {};
        var _coinIconPending = {};
        var _coinIconFetchTimer = null;

        function baseAssetFromPair(pair) {
            return String(pair || '').toUpperCase().replace(/USDT$/, '');
        }

        // HTML dizgisi üreten render fonksiyonları (renderRadar/renderMonolog/renderRecentOrders/
        // renderOrderHistoryRows/syncHuntCards) içinde KULLANILIR - önbellekte varsa <img> HTML'i
        // döner, yoksa boş dizgi döner (bu turda ikonsuz görünür, arka planda getirilmesi kuyruğa
        // eklenir - bkz. backfillCoinIcons, ikon gelince sayfa YENİDEN ÇİZİLMEDEN eklenir)
        function coinIconHtml(pair) {
            var asset = baseAssetFromPair(pair);
            if (!asset) { return ''; }
            if (Object.prototype.hasOwnProperty.call(_coinIconCache, asset)) {
                var url = _coinIconCache[asset];
                return url ? '<img src="' + url + '" alt="" class="coin-icon w-3.5 h-3.5 rounded-full inline-block align-[-2px] mr-1" loading="lazy" onerror="this.remove()">' : '';
            }
            queueCoinIconFetch(asset);
            return '';
        }

        function queueCoinIconFetch(asset) {
            if (_coinIconPending[asset]) { return; }
            _coinIconPending[asset] = true;
            if (_coinIconFetchTimer) { return; }
            // Kisa bir gecikmeyle (250ms) biriktirilir - ayni render turunda istenen COK sayida
            // yeni sembol TEK bir /api/dashboard/coin-icons istegine toplanir, her biri icin ayri
            // ayri istek atilmaz (CoinGecko rate limit riskini azaltir)
            _coinIconFetchTimer = setTimeout(flushCoinIconFetch, 250);
        }

        function flushCoinIconFetch() {
            var assets = Object.keys(_coinIconPending);
            _coinIconPending = {};
            _coinIconFetchTimer = null;
            if (!assets.length) { return; }

            safeFetch('/api/dashboard/coin-icons?symbols=' + encodeURIComponent(assets.join(',')))
                .then(function (d) {
                    if (!d.success) { return; }
                    Object.keys(d.icons).forEach(function (sym) { _coinIconCache[sym] = d.icons[sym]; });
                    backfillCoinIcons();
                })
                .catch(function () {});
        }

        // Sayfa PHP tarafinda ilk render edildiginde (Aktif Avlar/Bekleyen Emirler/Son Islemler)
        // olusan [data-coin-name] elemanlari icin ikon istegini baslatir - bu elemanlar coinIconHtml()
        // UZERINDEN GECMEDIGI icin (PHP render'i, JS degil) kimse onlarin ikonunu kuyruga eklemez,
        // sayfa yuklenince BIR KEZ taranip eksik olanlar kuyruga alinir
        function queueVisibleCoinIcons() {
            document.querySelectorAll('[data-coin-name]').forEach(function (el) {
                var asset = baseAssetFromPair(el.getAttribute('data-coin-name'));
                if (asset && !Object.prototype.hasOwnProperty.call(_coinIconCache, asset)) {
                    queueCoinIconFetch(asset);
                }
            });
        }

        // Zaten DOM'da olan (ör. PHP ilk render'inde ikonsuz cizilmis) [data-coin-name] elemanlarina,
        // onbellek sonradan doldukca ikonu EKLER - kart/satiri YENIDEN OLUSTURMADAN. Hem PHP'nin
        // sunucu-render ettigi (Aktif Avlar/Son Islemler ilk yukleme) hem JS'in urettigi HTML'de
        // AYNI [data-coin-name] deseni kullanilir
        function backfillCoinIcons() {
            document.querySelectorAll('[data-coin-name]').forEach(function (el) {
                if (el.querySelector('img.coin-icon')) { return; }
                var asset = baseAssetFromPair(el.getAttribute('data-coin-name'));
                var url = _coinIconCache[asset];
                if (!url) { return; }
                var img = document.createElement('img');
                img.src = url;
                img.alt = '';
                img.className = 'coin-icon w-3.5 h-3.5 rounded-full inline-block align-[-2px] mr-1';
                img.loading = 'lazy';
                img.onerror = function () { img.remove(); };
                el.insertBefore(img, el.firstChild);
            });
        }

        // Rakamlar yenilendiginde (bakiye/portfoy/pnl) direkt zip diye degismek yerine
        // eski degerden yeniye dogru sayarak akar; degisim yonune gore kisa bir yesil/kirmizi
        // "flash" ile de vurgulanir - ilk yuklemede (henuz rawValue yoksa) 0'dan sayarak baslar.
        function animateNumber(el, newVal, opts) {
            if (!el || isNaN(newVal)) return;
            opts = opts || {};
            var decimals = opts.decimals !== undefined ? opts.decimals : 2;
            var prefix   = opts.prefix || '';
            var suffix   = opts.suffix || '';
            var showSign = !!opts.showSign;
            var hadPrev  = el.dataset.rawValue !== undefined;
            var oldVal   = hadPrev ? parseFloat(el.dataset.rawValue) : 0;
            if (isNaN(oldVal)) oldVal = 0;
            el.dataset.rawValue = String(newVal);

            if (hadPrev && opts.flash !== false && newVal !== oldVal) {
                var flashClass = newVal >= oldVal ? 'value-flash-up' : 'value-flash-down';
                el.classList.remove('value-flash-up', 'value-flash-down');
                void el.offsetWidth;
                el.classList.add(flashClass);
                setTimeout(function () { el.classList.remove(flashClass); }, 900);
            }

            var duration  = 650;
            var startTime = null;
            function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }
            function frame(ts) {
                if (!startTime) startTime = ts;
                var progress = Math.min(1, (ts - startTime) / duration);
                var current  = oldVal + (newVal - oldVal) * easeOutCubic(progress);
                var sign     = showSign ? (current >= 0 ? '+' : '-') : (current < 0 ? '-' : '');
                el.textContent = sign + prefix + Math.abs(current).toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + suffix;
                if (progress < 1) { requestAnimationFrame(frame); }
            }
            requestAnimationFrame(frame);
        }

        // Panel hata göstergesi — layout bozmayan, ortalanmış, Premium Terminal estetiği
        function panelErr(elId) {
            var el = document.getElementById(elId);
            if (el) {
                el.innerHTML = '<div class="flex flex-col items-center justify-center h-full w-full opacity-50 text-rose-600 text-xs tracking-widest uppercase">'
                    + '<span class="mb-2 text-lg">⊘</span>'
                    + '<span>Bağlantı Yok</span>'
                    + '</div>';
            }
        }

        // 22 Temmuz'da eklendi: "dashboard tam canlı değil" geri bildirimi üzerine - paneller
        // sessizce arka planda güncelleniyordu, kullanıcı bunu GÖREMİYORDU. Her fetch fonksiyonu
        // kendi anahtarıyla markUpdated() çağırır (BAŞARILI/BAŞARISIZ fark etmez - "en son ne
        // zaman senkronize olmaya çalıştık" bilgisi), panel başlığındaki "{key}UpdatedAt" id'li
        // span'a saniyede bir sayaç gibi işlenir - gerçekten canlı olduğu görsel olarak kanıtlanır
        var _lastUpdatedAt = {};

        function markUpdated(key) {
            _lastUpdatedAt[key] = Date.now();
        }

        function formatRelativeTime(ms) {
            var seconds = Math.floor((Date.now() - ms) / 1000);
            if (seconds < 5) { return 'az önce'; }
            if (seconds < 60) { return seconds + 'sn önce'; }
            var minutes = Math.floor(seconds / 60);
            if (minutes < 60) { return minutes + 'dk önce'; }
            var hours = Math.floor(minutes / 60);
            return hours + 'sa önce';
        }

        function tickFreshnessLabels() {
            Object.keys(_lastUpdatedAt).forEach(function (key) {
                var el = document.getElementById(key + 'UpdatedAt');
                if (el) { el.textContent = formatRelativeTime(_lastUpdatedAt[key]); }
            });
        }

        // Yumusak bildirim (toast): sag alt kosede belirir, ~3.5sn sonra kendiliginden kaybolur -
        // AJAX ile kaydedilen formların (ör. AI Avcı ayarları) basari/hata geri bildirimi icin
        function showToast(message, isSuccess) {
            var container = document.getElementById('toastContainer');
            if (!container) return;

            var toast = document.createElement('div');
            toast.className = 'toast-in glass-panel rounded-xl px-4 py-3 shadow-lg font-mono-tech text-xs max-w-xs border '
                + (isSuccess ? 'border-emerald-400/30 text-emerald-700' : 'border-rose-400/30 text-rose-700');
            toast.textContent = (isSuccess ? '✓ ' : '⊘ ') + message;
            container.appendChild(toast);

            setTimeout(function() {
                toast.classList.remove('toast-in');
                toast.classList.add('toast-out');
                setTimeout(function() { toast.remove(); }, 250);
            }, 3500);
        }

        // Klasik <form> tam sayfa POST'unu, sayfa hic yenilenmeden fetch() ile gonderen ortak yardimci -
        // X-Requested-With header'i sunucunun (DashboardController::isAjaxRequest) JSON mi yoksa
        // redirect mi donecegini ayirt etmesini saglar. Basarili/basarisiz sonuc showToast ile bildirilir
        function submitJsonForm(form, onSuccess) {
            var submitBtn = form.querySelector('button[type="submit"]');
            var originalText = submitBtn ? submitBtn.textContent : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Kaydediliyor...';
            }

            // form.action (DOM ozelligi, getAttribute('action') DEGIL) tarayici tarafindan zaten
            // TAM URL'e cozumlenir - action niteligi Url::to() ile _BASE'i ZATEN icerdigi icin
            // burada tekrar _BASE eklemek yolu ikiye katlardı
            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    showToast(d.message || (d.success ? 'Kaydedildi.' : 'Kaydedilemedi.'), !!d.success);
                    if (d.success && onSuccess) { onSuccess(d); }
                })
                .catch(function() {
                    showToast('Bağlantı hatası, ayarlar kaydedilemedi.', false);
                })
                .finally(function() {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalText;
                    }
                });
        }

        // --- Bakiye ---
        function fetchBalance() {
            safeFetch('/api/dashboard/balance')
                .then(function(d) {
                    var el  = document.getElementById('navBalanceValue');
                    var sub = document.getElementById('navBalanceSubtext');
                    if (el) el.classList.remove('animate-pulse');
                    if (d.success) {
                        _totalBalance = parseFloat((d.balance + '').replace(/,/g, '')) || 0;
                        if (el)  animateNumber(el, _totalBalance, { prefix: '$', decimals: 2 });
                        if (sub) sub.textContent = 'USDT';
                    } else {
                        if (el)  el.innerHTML = '<span class="text-gray-400 font-bold">---</span>';
                        if (sub) sub.innerHTML = '<span class="text-[9px] text-amber-600/80">API Bağlantı Bekleniyor</span>';
                    }
                    refreshDonut();
                })
                .catch(function() {
                    var el  = document.getElementById('navBalanceValue');
                    var sub = document.getElementById('navBalanceSubtext');
                    if (el)  el.classList.remove('animate-pulse');
                    if (el)  el.innerHTML  = '<span class="text-gray-400 font-bold">---</span>';
                    if (sub) sub.innerHTML = '<span class="text-[9px] text-rose-600/70">Bağlantı Yok</span>';
                });
        }

        function refreshDonut() {
            var seg    = document.getElementById('donutUsed');
            var pctEl  = document.getElementById('donutPct');
            var freeEl = document.getElementById('donutFree');
            if (!seg) return;
            var circ   = 2 * Math.PI * 11; // r=11 → ≈69.12
            var pct    = Math.min(100, _openTradeCount * 20);
            var filled = (circ * pct / 100).toFixed(1);
            seg.setAttribute('stroke-dasharray', filled + ' ' + circ.toFixed(1));
            if (pctEl)  pctEl.textContent  = _openTradeCount > 0 ? _openTradeCount + ' POZ' : '—';
            if (freeEl) freeEl.textContent = _totalBalance > 0
                ? '$' + _totalBalance.toLocaleString('en-US', { maximumFractionDigits: 0 })
                : 'USDT';
        }

        // --- AI Radar ---
        function fetchRadar() {
            safeFetch('/api/dashboard/radar')
                .then(function(d) {
                    markUpdated('radar');
                    if (!d.success) {
                        panelErr('radarContainer');
                        return;
                    }
                    renderRadar(d.radar || []);
                    if (d.market_pulse) { renderMarketPulse(d.market_pulse); }
                    renderFearGreed(d.radar || []);
                })
                .catch(function() {
                    markUpdated('radar');
                    panelErr('radarContainer');
                });
        }

        function renderRadar(items) {
            var c = document.getElementById('radarContainer');
            if (!c) return;
            if (!items.length) {
                c.innerHTML = '<p class="font-mono-tech text-xs text-gray-500">API anahtarı gerekli</p>';
                return;
            }
            var html = '<div class="space-y-1.5">';
            items.forEach(function(item) {
                var score  = item.score || 0;
                var change = item.priceChangePercent || 0;
                var isBuy  = item.is_buy_signal;
                var barW   = Math.max(0, Math.min(100, score));
                var barCls = score >= 80 ? 'bg-emerald-400' : score >= 60 ? 'bg-amber-400' : 'bg-gray-600';
                var chgCls = change >= 0 ? 'text-emerald-600' : 'text-rose-600';
                var badge  = isBuy
                    ? '<span class="ml-1 text-[8px] font-bold text-emerald-600 border border-emerald-400/40 rounded px-1 py-0.5">AL</span>'
                    : '';
                html += '<div class="rounded-lg px-2 py-1.5 ' + (isBuy ? 'signal-row' : 'hover:bg-black/5')
                    + ' cursor-pointer transition-colors" onclick="loadChart(\'BINANCE:' + escapeJsAttr(item.symbol) + '\')">'
                    + '<div class="flex justify-between items-center mb-0.5">'
                    + '<span data-coin-name="' + escapeHtml(item.symbol) + '" class="font-mono-tech text-xs font-semibold text-gray-800">' + coinIconHtml(item.symbol) + escapeHtml(item.symbol) + badge + '</span>'
                    + '<span class="font-mono-tech text-[10px] font-bold ' + chgCls + '">'
                    + (change >= 0 ? '+' : '') + change.toFixed(2) + '%</span>'
                    + '</div>'
                    + '<div class="flex items-center gap-2">'
                    + '<div class="flex-1 h-1 rounded-full bg-black/[0.03]">'
                    + '<div class="h-full rounded-full ' + barCls + ' transition-all duration-500" style="width:' + barW + '%"></div>'
                    + '</div>'
                    + '<span class="font-mono-tech text-[10px] text-gray-500 w-8 text-right">' + score + '</span>'
                    + '</div>';
                // item.reason bos olabilir (ör. skor hesaplamasi basarisiz olup varsayilan
                // {score:0} ile gelen adaylar - bkz. DashboardController::fetchAiRadar()) - satirlarin
                // esit yukseklikte kalmasi icin gorsel bir yer tutucu metin gosterilir
                html += '<p class="font-mono-tech text-[9px] text-gray-400 mt-0.5 truncate">'
                    + (item.reason ? escapeHtml(item.reason) : 'AI gerekçe eklemedi')
                    + '</p>';
                html += '</div>';
            });
            html += '</div>';
            c.innerHTML = html;
        }

        function renderMarketPulse(text) {
            var box = document.getElementById('marketPulseBox');
            var el  = document.getElementById('marketPulseText');
            if (!box || !el || !text) return;
            el.textContent = text;
            box.classList.remove('hidden');
            box.classList.add('flex');
        }

        function renderFearGreed(items) {
            var bar   = document.getElementById('fearGreedBar');
            var label = document.getElementById('fearGreedLabel');
            if (!bar || !label || !items.length) return;
            var avg   = items.reduce(function(s, i) { return s + (i.score || 0); }, 0) / items.length;
            var score = Math.round(avg);
            var color, txt;
            if      (score >= 75) { color = 'bg-emerald-400'; txt = 'AÇGÖZLÜLÜK';    }
            else if (score >= 55) { color = 'bg-lime-400';    txt = 'NÖTRİN ÜZERİ'; }
            else if (score >= 45) { color = 'bg-amber-400';   txt = 'NÖTR';          }
            else if (score >= 30) { color = 'bg-orange-400';  txt = 'KORKU';         }
            else                  { color = 'bg-rose-400';    txt = 'AŞIRI KORKU';   }
            bar.style.width = score + '%';
            bar.className   = 'h-full rounded-full transition-all duration-700 ' + color;
            var lbCls = score >= 55 ? 'text-emerald-600' : score >= 45 ? 'text-amber-600' : 'text-rose-600';
            label.textContent = score + ' — ' + txt;
            label.className   = 'font-mono-tech text-[9px] font-bold ' + lbCls;
        }

        // --- Haberler ---
        function fetchNews() {
            safeFetch('/api/dashboard/news')
                .then(function(d) {
                    markUpdated('news');
                    if (!d.success) {
                        panelErr('newsContainer');
                        return;
                    }
                    renderNews(d.items || []);
                })
                .catch(function() {
                    markUpdated('news');
                    panelErr('newsContainer');
                });
        }

        function renderNews(items) {
            var c = document.getElementById('newsContainer');
            if (!c) return;
            if (!items.length) {
                c.innerHTML = '<p class="font-mono-tech text-xs text-gray-500 px-4 py-3">Haber bulunamadı</p>';
                return;
            }
            var rows = items.map(function(item) {
                return '<div class="flex items-start gap-2 py-2 border-b border-black/5 px-4">'
                    + '<span class="mt-1 w-1.5 h-1.5 rounded-full bg-cyan-400/50 flex-none shrink-0"></span>'
                    + '<a href="' + safeHref(item.link) + '" target="_blank" rel="noopener"'
                    + ' class="font-mono-tech text-[11px] text-gray-700 hover:text-cyan-600 transition-colors leading-snug">'
                    + escapeHtml(item.title) + '</a></div>';
            }).join('');
            c.innerHTML = '<div class="news-ticker-track">' + rows + rows + '</div>';
        }

        // --- Aktif Avlar ---
        function fetchActiveTrades() {
            safeFetch('/api/dashboard/hunts')
                .then(function(d) {
                    markUpdated('hunts');
                    if (!d.success) {
                        markTradePricesUnavailable();
                        return;
                    }
                    var trades = d.trades || {};
                    _openTradeCount = Object.keys(trades).length;
                    syncHuntCards(trades);
                    updateTradeProgress(trades);
                    updateLiveChartFromTrades(trades);
                    refreshDonut();
                    updateNavOpenPositions();
                })
                .catch(function() {
                    markUpdated('hunts');
                    markTradePricesUnavailable();
                });
        }

        // --- Canli Savas Radari ---
        // Ayri bir "tick" ucnoktasi/setInterval KASITLI olarak YOK - zaten var olan fetchActiveTrades()
        // dongusu (5sn, bkz. yukarida) her turda updateLiveChartFromTrades()'i cagirir, acik grafik
        // varsa SADECE o turda guncellenir. Boylece Binance'e ekstra istek BINDIRILMEZ.
        var liveChartState = {
            tradeId: null,
            chart: null,
            series: null,
            entryLine: null,
            tpLine: null,
            slLine: null,
            lastCandle: null,
            ghostSeries: null,
            buildGhostData: null,
            tightPrices: null,
            fullPrices: null,
            showingAll: false
        };

        // "Tüm Seviyeler" butonu: varsayilan (mumlara odakli, sadece Giris+Zirh zorlanmis) gorunum
        // ile TP/Tetik'i de kapsayan genis gorunum arasinda gecis yapar - butona her tiklamada
        // hayalet seriye FARKLI fiyat kumesi verilip otomatik olcekleme yeniden tetiklenir
        function toggleLiveChartZoom() {
            if (!liveChartState.ghostSeries || !liveChartState.buildGhostData) return;
            liveChartState.showingAll = !liveChartState.showingAll;
            var prices = liveChartState.showingAll ? liveChartState.fullPrices : liveChartState.tightPrices;
            liveChartState.ghostSeries.setData(liveChartState.buildGhostData(prices));
            document.getElementById('liveChartZoomToggle').textContent = liveChartState.showingAll ? '🕯️ Sadece Mumlar' : '🔎 Tüm Seviyeler';
        }

        function openLiveChart(tradeId) {
            var modal = document.getElementById('liveChartModal');
            var backdrop = document.getElementById('liveChartBackdrop');
            var panel = document.getElementById('liveChartPanel');
            var loading = document.getElementById('liveChartLoading');
            var container = document.getElementById('liveChartContainer');

            loading.classList.remove('hidden');
            loading.textContent = 'Grafik yükleniyor...';
            container.classList.add('hidden');
            document.getElementById('liveChartPair').textContent = '—';
            document.getElementById('liveChartEntryValue').textContent = '—';
            document.getElementById('liveChartSlValue').textContent = '—';
            document.getElementById('liveChartTpValueWrap').innerHTML = '— Hedef (TP): <span id="liveChartTpValue">—</span>';
            document.getElementById('liveChartTriggerWrap').classList.add('hidden');

            modal.classList.remove('hidden');
            requestAnimationFrame(function () {
                backdrop.classList.remove('opacity-0');
                panel.classList.remove('opacity-0', 'scale-95');
            });

            safeFetch('/api/dashboard/live-chart?trade_id=' + tradeId)
                .then(function (d) {
                    if (!d.success) {
                        loading.textContent = 'Grafik yüklenemedi: ' + (d.message || 'bilinmeyen hata');
                        return;
                    }

                    document.getElementById('liveChartPair').textContent = d.pair;
                    document.getElementById('liveChartEntryValue').textContent = fmt$(d.entry_price);
                    document.getElementById('liveChartSlValue').textContent = fmt$(d.stop_loss_price);
                    var tpValueWrap = document.getElementById('liveChartTpValueWrap');
                    if (d.take_profit_removed) {
                        tpValueWrap.innerHTML = '🚀 Hedef: Sınırsız (∞)';
                    } else {
                        document.getElementById('liveChartTpValue').textContent = fmt$(d.take_profit_price);
                    }

                    if (typeof LightweightCharts === 'undefined') {
                        loading.textContent = 'Grafik kütüphanesi yüklenemedi (bağlantınızı kontrol edin).';
                        return;
                    }

                    // KRITIK: container createChart()'tan ONCE gorunur yapilmali - "hidden" (display:none)
                    // durumdayken olcculen genislik/yukseklik 0 olur, kutuphane 0x0 bir canvas olusturur
                    // ve sonradan gorunur yapmak bunu duzeltmez (25 Temmuz'da Playwright testinde
                    // yakalandi: canvas olusuyordu ama grafik tamamen bostu)
                    loading.classList.add('hidden');
                    container.classList.remove('hidden');

                    var chart = LightweightCharts.createChart(container, {
                        height: 480,
                        layout: { background: { color: 'transparent' }, textColor: '#4b5563' },
                        grid: {
                            vertLines: { color: 'rgba(0,0,0,0.05)' },
                            horzLines: { color: 'rgba(0,0,0,0.05)' }
                        },
                        timeScale: { timeVisible: true, secondsVisible: false },
                        // Ust/alt bosluk kucultuldu (varsayilan %20/%10) - varsayilan gorunum artik
                        // TUM 4 seviyeyi kapsadigi icin (bkz. asagida fullPrices), mumlara ayrilan
                        // dikey piksel MUMKUN OLDUGUNCA fazla olsun diye
                        rightPriceScale: { borderColor: 'rgba(0,0,0,0.1)', scaleMargins: { top: 0.08, bottom: 0.08 } }
                    });

                    var series = chart.addCandlestickSeries({
                        upColor: '#10b981', downColor: '#f43f5e',
                        borderVisible: false,
                        wickUpColor: '#10b981', wickDownColor: '#f43f5e'
                    });

                    series.setData(d.klines);
                    chart.timeScale().fitContent();

                    // KRITIK: Lightweight Charts fiyat cizgilerini (createPriceLine) otomatik
                    // olcaklendirmeye DAHIL ETMEZ - sadece SERI VERISINE gore olceklenir. Giris/TP/SL
                    // guncel mum araligindan uzaksa (ör. fiyat girisden bu yana buyuk hareket ettiyse)
                    // cizgiler GORUNMEZ KALIR. `series.autoscaleInfoProvider` denendi ama Playwright
                    // testinde etkisiz kaldi - bunun yerine, GORUNMEZ bir "hayalet" cizgi serisi
                    // eklenip belirlenen noktalara veri konuyor: otomatik olcekleme SADECE gercek
                    // SERI verisini dikkate alir, hayalet seri dolayli olarak fiyat eksenini genisletir.
                    // 25 Temmuz'da eklendi (kullanici geri bildirimi, IKI KEZ): once TUM 4 seviyeyi
                    // (Giris/TP/SL/Tetik) zorlamak mumlari sikistirdi; SADECE Giris+Zirh zorlamak bile
                    // (Zarar Kes genis bir %'de ayarliysa, ör. %1.5+, guncel mumlarin dogal oynakligindan
                    // HER ZAMAN daha genis olabildigi icin) YETERSIZ kaldi - varsayilan artik SAF mum
                    // gorunumu (hicbir seviye zorlanmaz, sadece GERCEK fiyat hareketine gore olceklenir),
                    // "Tüm Seviyeler" butonuyla TUM 4 seviyeyi kapsayan genis gorunume gecilebilir
                    var firstTime = d.klines.length > 0 ? d.klines[0].time : Math.floor(Date.now() / 1000);
                    var lastTime = d.klines.length > 0 ? d.klines[d.klines.length - 1].time : firstTime;
                    var tightPrices = [];
                    var fullPrices = [d.entry_price, d.stop_loss_price];
                    if (!d.take_profit_removed) fullPrices.push(d.take_profit_price);
                    if (d.trailing_trigger_price !== null && d.trailing_trigger_price !== undefined) fullPrices.push(d.trailing_trigger_price);
                    var ghostSeries = chart.addLineSeries({
                        lineVisible: false, lastValueVisible: false,
                        priceLineVisible: false, crosshairMarkerVisible: false
                    });
                    function buildGhostData(prices) {
                        // Her fiyat icin BENZERSIZ/ARTAN zaman damgasi sart (LWC kurali) - gercek
                        // gorunurluk zamanlamasinin onemi yok (cizgi zaten gorunmez), sadece deger
                        // otomatik olceklemeye katkida bulunsun diye eklenir
                        return prices.map(function (p, i) {
                            return { time: firstTime + i, value: p };
                        }).concat(prices.map(function (p, i) {
                            return { time: lastTime - i, value: p };
                        })).sort(function (a, b) { return a.time - b.time; });
                    }
                    // 25 Temmuz'da tekrar degistirildi (kullanici geri bildirimi): varsayilan
                    // ACILIS gorunumu artik TUM 4 seviyeyi (Giris/TP/SL/Tetik) kapsar - kullanici
                    // riskin BUYUK RESMINI ilk baksta gormek istiyor. Bunun matematiksel bedeli:
                    // mumlar (giris/TP/SL'e gore genelde COK daha dar bir aralikta hareket eder)
                    // sikisik gorunur - AYNI DOGRUSAL eksende ikisi de "buyuk" olamaz. Toggle butonu
                    // "Sadece Mumlar"a basilinca mum detayina yakinlasir
                    ghostSeries.setData(buildGhostData(fullPrices));

                    var entryLine = series.createPriceLine({
                        price: d.entry_price, color: '#9ca3af', lineWidth: 1,
                        lineStyle: LightweightCharts.LineStyle.Dashed,
                        axisLabelVisible: true, title: 'Giriş'
                    });

                    var tpLine = null;
                    if (!d.take_profit_removed) {
                        tpLine = series.createPriceLine({
                            price: d.take_profit_price, color: '#10b981', lineWidth: 1,
                            lineStyle: LightweightCharts.LineStyle.Solid,
                            axisLabelVisible: true, title: 'Hedef'
                        });
                    }

                    var slLine = series.createPriceLine({
                        price: d.stop_loss_price, color: '#f43f5e', lineWidth: 1,
                        lineStyle: LightweightCharts.LineStyle.Solid,
                        axisLabelVisible: true, title: 'Zırh'
                    });

                    // Izleyen Stop Tetik: fiyat bu seviyeye ulasirsa Zarar Kes yukari cekilmeye
                    // baslar - backend NULL doner eger tetik zaten gecilmisse (trailing_stop_stage>=1)
                    if (d.trailing_trigger_price !== null && d.trailing_trigger_price !== undefined) {
                        series.createPriceLine({
                            price: d.trailing_trigger_price, color: '#8b5cf6', lineWidth: 1,
                            lineStyle: LightweightCharts.LineStyle.Dotted,
                            axisLabelVisible: true, title: 'Tetik'
                        });
                        document.getElementById('liveChartTriggerValue').textContent = fmt$(d.trailing_trigger_price);
                        document.getElementById('liveChartTriggerWrap').classList.remove('hidden');
                    }

                    liveChartState.tradeId = tradeId;
                    liveChartState.chart = chart;
                    liveChartState.series = series;
                    liveChartState.entryLine = entryLine;
                    liveChartState.tpLine = tpLine;
                    liveChartState.slLine = slLine;
                    liveChartState.lastCandle = d.klines.length > 0 ? Object.assign({}, d.klines[d.klines.length - 1]) : null;
                    liveChartState.ghostSeries = ghostSeries;
                    liveChartState.buildGhostData = buildGhostData;
                    liveChartState.tightPrices = tightPrices;
                    liveChartState.fullPrices = fullPrices;
                    liveChartState.showingAll = true;
                    document.getElementById('liveChartZoomToggle').classList.remove('hidden');
                    document.getElementById('liveChartZoomToggle').textContent = '🕯️ Sadece Mumlar';
                })
                .catch(function () {
                    loading.textContent = 'Grafik yüklenemedi (bağlantı hatası).';
                });
        }

        function closeLiveChart() {
            var modal = document.getElementById('liveChartModal');
            var backdrop = document.getElementById('liveChartBackdrop');
            var panel = document.getElementById('liveChartPanel');
            backdrop.classList.add('opacity-0');
            panel.classList.add('opacity-0', 'scale-95');
            setTimeout(function () { modal.classList.add('hidden'); }, 200);

            // Grafik kaynaklarini serbest birak ve durumu sifirla - boylece fetchActiveTrades()'in
            // her 5sn'lik dongusu artik kapali bir grafigi GUNCELLEMEYE calismaz
            if (liveChartState.chart) {
                liveChartState.chart.remove();
            }
            liveChartState.tradeId = null;
            liveChartState.chart = null;
            liveChartState.series = null;
            liveChartState.entryLine = null;
            liveChartState.tpLine = null;
            liveChartState.slLine = null;
            liveChartState.lastCandle = null;
            liveChartState.ghostSeries = null;
            liveChartState.buildGhostData = null;
            liveChartState.tightPrices = null;
            liveChartState.fullPrices = null;
            liveChartState.showingAll = false;
            document.getElementById('liveChartZoomToggle').classList.add('hidden');
        }

        // fetchActiveTrades()'in HER turunda cagirilir - acik bir grafik yoksa aninda cikar (ekstra
        // is YAPMAZ). Anlik fiyat 1 dakikalik mum penceresinin ICINDEYSE mevcut mumun high/low/close'unu
        // (open SABIT kalir) GUNCELLER; pencere gectiyse (yeni dakika basladiysa) YENI bir mum EKLER -
        // boylece mumlar gercek zaman dilimlerini dogru yansitir, "son mum sonsuza dek tek fiyata
        // yapisip kalir" gibi yanlis bir gorsel OLUSMAZ. Zarar Kes'i Izleyen Stop degistirdiyse
        // kirmizi cizgi ayni turda yeni seviyeye tasinir
        function updateLiveChartFromTrades(trades) {
            if (!liveChartState.tradeId || !liveChartState.series || !liveChartState.lastCandle) return;

            var t = trades[liveChartState.tradeId];
            if (!t || t.current_price === null || t.current_price === undefined) return;

            var price = parseFloat(t.current_price);
            if (isNaN(price)) return;

            var candle = liveChartState.lastCandle;
            var nowBucket = Math.floor(Date.now() / 1000 / 60) * 60;

            if (nowBucket > candle.time) {
                candle = { time: nowBucket, open: price, high: price, low: price, close: price };
            } else {
                candle = {
                    time: candle.time,
                    open: candle.open,
                    high: Math.max(candle.high, price),
                    low: Math.min(candle.low, price),
                    close: price
                };
            }

            liveChartState.series.update(candle);
            liveChartState.lastCandle = candle;

            var newSl = parseFloat(t.stop_loss_price);
            if (!isNaN(newSl) && liveChartState.slLine) {
                liveChartState.slLine.applyOptions({ price: newSl });
                var slValueEl = document.getElementById('liveChartSlValue');
                if (slValueEl) slValueEl.textContent = fmt$(newSl);
            }
        }

        // Sayfa acikken YENI acilan/kapanan pozisyonlar icin tam sayfa yenilemesi beklemeden "Aktif
        // Avlar" listesini DOM seviyesinde senkronlar - eskiden updateTradeProgress() SADECE zaten
        // DOM'da var olan kartlarin ICERIGINI guncelliyordu, sayfa acildiktan SONRA acilan bir
        // pozisyon icin yeni kart HIC olusturulmuyordu (kullanici tam sayfa yenilemek zorunda
        // kalıyordu). Futures kartlarina (data-futures-progress) dokunmaz - sadece spot/UZUN kartlari.
        function syncHuntCards(trades) {
            var container = document.getElementById('huntsContainer');
            if (!container) return;

            var seenIds = Object.keys(trades);

            document.querySelectorAll('[data-hunt-card]').forEach(function(card) {
                if (seenIds.indexOf(card.getAttribute('data-hunt-card')) === -1) {
                    card.remove();
                }
            });

            seenIds.forEach(function(id) {
                if (document.querySelector('[data-hunt-card="' + id + '"]')) return;

                var t = trades[id];
                var placeholder = container.querySelector('[data-hunts-empty]');
                if (placeholder) placeholder.remove();

                var card = document.createElement('div');
                card.setAttribute('data-hunt-card', id);
                card.className = 'rounded-lg border border-black/5 bg-black/[0.02] px-3 py-2';
                card.innerHTML =
                    '<div class="flex justify-between items-center mb-1">'
                    + '<span class="flex items-center gap-1.5">'
                    + '<span class="font-mono-tech text-[9px] font-bold text-emerald-600 border border-emerald-400/40 rounded px-1 py-0.5">UZUN</span>'
                    + '<span data-coin-name="' + escapeHtml(t.pair || '') + '" class="font-mono-tech text-xs font-semibold text-gray-800">' + coinIconHtml(t.pair) + escapeHtml(t.pair || '') + '</span>'
                    + '<span data-trade-shield="' + id + '" class="font-mono-tech text-[9px] font-bold rounded px-1 py-0.5 border text-gray-400 border-black/10">🛡️ PASİF</span>'
                    + '</span>'
                    + '<span class="font-mono-tech text-[10px] text-gray-500">Giriş: ' + fmt$(t.entry_price) + '</span>'
                    + '</div>'
                    + '<div class="flex justify-between items-center mb-1.5">'
                    + '<span class="font-mono-tech text-[10px] text-rose-600">SL $<span data-trade-sl="' + id + '">' + fmt$(t.stop_loss_price).replace('$', '') + '</span></span>'
                    + '<span data-trade-tp="' + id + '" data-tp-removed="0">'
                    + '<span class="font-mono-tech text-[10px] text-emerald-600">TP ' + fmt$(t.take_profit_price) + '</span>'
                    + '</span>'
                    + '</div>'
                    + '<div class="flex gap-1 mt-1">'
                    + '<button type="button" onclick="openLiveChart(' + id + ')" class="flex-1 font-mono-tech text-[9px] text-cyan-600 border border-cyan-400/30 rounded px-1.5 py-0.5 hover:bg-cyan-400/10 transition-colors">📈 Canlı İzle</button>'
                    + '<button type="button" onclick="closePositionNow(' + id + ', \'' + (t.pair || '') + '\')" class="flex-1 font-mono-tech text-[9px] text-rose-600 border border-rose-400/30 rounded px-1.5 py-0.5 hover:bg-rose-400/10 transition-colors">✕ Şimdi Kapat</button>'
                    + '</div>'
                    + '<div data-trade-progress="' + id + '">'
                    + '<p class="font-mono-tech text-[10px] text-gray-500 mt-1">fiyat yükleniyor...</p>'
                    + '</div>';

                container.appendChild(card);

                updateShieldBadge(id, t.trailing_stop_stage);
                updateTakeProfitBadge(id, t.take_profit_removed);
            });

            updateHuntsCountBadge();
        }

        function updateHuntsCountBadge() {
            var badge = document.getElementById('huntsPositionCount');
            var container = document.getElementById('huntsContainer');
            if (!badge || !container) return;

            var spotCount = document.querySelectorAll('[data-hunt-card]').length;
            var futuresCount = document.querySelectorAll('[data-futures-progress]').length;
            badge.textContent = (spotCount + futuresCount) + ' POZİSYON';

            if (spotCount + futuresCount === 0 && !container.querySelector('[data-hunts-empty]')) {
                var p = document.createElement('p');
                p.setAttribute('data-hunts-empty', '1');
                p.className = 'font-mono-tech text-xs text-gray-500';
                p.textContent = 'Açık pozisyon yok';
                container.appendChild(p);
            }
        }

        // Sunucudan hic yanit alinamadiginda (ör. API/Binance erisilemez durumda), pozisyon
        // kartlari sonsuza kadar sunucu-yuklu "fiyat yükleniyor..." metninde TAKILI KALMASIN diye
        // hepsi tek seferde "fiyat alınamadı" durumuna cekilir - DB'den gelen temel pozisyon
        // bilgileri (parite/giris/TP/SL) zaten sayfa yuklenirken gosterilmisti, sadece CANLI
        // fiyat/PNL katmani "bilinmiyor" olarak isaretlenir
        function markTradePricesUnavailable() {
            document.querySelectorAll('[data-trade-progress]').forEach(function(container) {
                container.innerHTML = '<p class="font-mono-tech text-[10px] text-gray-500 mt-1">fiyat alınamadı</p>';
            });
        }

        // Izleyen Zirh rozeti: 0=henuz pasif (+%1.5 esigi gorulmedi), >=1=aktif (kar kilitlendi)
        function updateShieldBadge(id, stage) {
            var badge = document.querySelector('[data-trade-shield="' + id + '"]');
            if (!badge || stage === null || stage === undefined) return;
            var active = stage >= 1;
            badge.textContent = active ? '🛡️ AKTİF' : '🛡️ PASİF';
            badge.classList.toggle('text-violet-600', active);
            badge.classList.toggle('border-violet-400/40', active);
            badge.classList.toggle('text-gray-400', !active);
            badge.classList.toggle('border-black/10', !active);
        }

        // Kar Al Tavanini Kaldirma: pozisyon sayfa acikken Sinirsiz Izleme'ye gecerse (sabit TP
        // fiyati kaldirilirsa), sayfa yenilenmeden TP hucresini mor "Sinirsiz (∞)" rozetine
        // donusturur. Gecis TEK YONLUDUR (0'dan 1'e - backend bunu asla geri almaz), bu yuzden
        // data-tp-removed ile "zaten guncellendi mi" kontrolu yapilip gereksiz DOM yazimindan kacinilir
        function updateTakeProfitBadge(id, removed) {
            var container = document.querySelector('[data-trade-tp="' + id + '"]');
            if (!container || !removed || container.dataset.tpRemoved === '1') return;
            container.dataset.tpRemoved = '1';
            container.innerHTML = '<span class="font-mono-tech text-[9px] font-bold text-violet-600 border border-violet-400/40 rounded px-1.5 py-0.5 cursor-help" title="Sabit tavan kaldırıldı, trend izleniyor">🚀 Sınırsız (∞)</span>';
        }

        function updateTradeProgress(trades) {
            Object.keys(trades).forEach(function(id) {
                var container = document.querySelector('[data-trade-progress="' + id + '"]');
                if (!container) return;
                var t = trades[id];

                updateShieldBadge(id, t.trailing_stop_stage);
                updateTakeProfitBadge(id, t.take_profit_removed);
                var slEl = document.querySelector('[data-trade-sl="' + id + '"]');
                if (slEl && t.stop_loss_price !== null && t.stop_loss_price !== undefined) {
                    slEl.textContent = fmt$(parseFloat(t.stop_loss_price)).replace('$', '');
                }

                if (t.current_price === null || t.current_price === undefined) {
                    container.innerHTML = '<p class="font-mono-tech text-[10px] text-gray-500 mt-1">fiyat alınamadı</p>';
                    return;
                }
                var pct    = t.progress_percent || 0;
                var barCls = pct >= 66 ? 'bg-emerald-400' : pct >= 33 ? 'bg-amber-400' : 'bg-rose-400';

                var pnlHtml = '';
                if (t.unrealized_pnl_usdt !== null && t.unrealized_pnl_usdt !== undefined) {
                    var pnl     = parseFloat(t.unrealized_pnl_usdt);
                    var pnlPct  = parseFloat(t.unrealized_pnl_pct || 0);
                    var sign    = pnl >= 0 ? '+' : '';
                    var pnlCls  = pnl >= 0 ? 'text-emerald-600' : 'text-rose-600';
                    pnlHtml = '<div class="flex justify-between items-center mt-1.5 rounded-lg px-1.5 py-0.5 '
                        + (pnl >= 0 ? 'bg-emerald-400/5' : 'bg-rose-400/5') + '">'
                        + '<span class="font-mono-tech text-[10px] font-bold ' + pnlCls + '">'
                        + sign + '$' + Math.abs(pnl).toFixed(2) + ' USDT</span>'
                        + '<span class="font-mono-tech text-[10px] ' + pnlCls + '">'
                        + sign + pnlPct.toFixed(2) + '%</span>'
                        + '</div>';
                }

                container.innerHTML =
                    '<div class="flex justify-between items-center mt-1 mb-0.5">'
                    + '<span class="font-mono-tech text-[10px] text-gray-600">Şimdiki: '
                    + '<span class="text-gray-800">' + fmt$(t.current_price) + '</span></span>'
                    + '<span class="font-mono-tech text-[10px] text-violet-600">'
                    + (pct !== null ? pct.toFixed(1) + '%' : '—') + '</span>'
                    + '</div>'
                    + '<div class="hunt-progress-track w-full h-1.5 rounded-full">'
                    + '<div class="h-full rounded-full ' + barCls + ' transition-all duration-500"'
                    + ' style="width:' + Math.max(0, pct) + '%"></div></div>'
                    + pnlHtml;
            });
        }

        // --- Kısa (Futures) Pozisyonlar ---
        function fetchFuturesPositions() {
            safeFetch('/api/dashboard/futures-positions')
                .then(function(d) {
                    if (!d.success) { return; }
                    var trades = d.trades || {};
                    _openFuturesTradeCount = Object.keys(trades).length;
                    updateFuturesProgress(trades);
                    updateNavOpenPositions();
                })
                .catch(function() {});
        }

        function updateFuturesProgress(trades) {
            Object.keys(trades).forEach(function(id) {
                var container = document.querySelector('[data-futures-progress="' + id + '"]');
                if (!container) return;
                var t = trades[id];
                if (t.mark_price === null || t.mark_price === undefined) {
                    container.innerHTML = '<p class="font-mono-tech text-[10px] text-gray-500 mt-1">fiyat alınamadı</p>';
                    return;
                }

                var pnlHtml = '';
                if (t.unrealized_pnl_usdt !== null && t.unrealized_pnl_usdt !== undefined) {
                    var pnl    = parseFloat(t.unrealized_pnl_usdt);
                    var pnlPct = parseFloat(t.unrealized_pnl_pct || 0);
                    var sign   = pnl >= 0 ? '+' : '';
                    var pnlCls = pnl >= 0 ? 'text-emerald-600' : 'text-rose-600';
                    pnlHtml = '<div class="flex justify-between items-center mt-1.5 rounded-lg px-1.5 py-0.5 '
                        + (pnl >= 0 ? 'bg-emerald-400/5' : 'bg-rose-400/5') + '">'
                        + '<span class="font-mono-tech text-[10px] font-bold ' + pnlCls + '">'
                        + sign + '$' + Math.abs(pnl).toFixed(2) + ' USDT</span>'
                        + '<span class="font-mono-tech text-[10px] ' + pnlCls + '">'
                        + sign + pnlPct.toFixed(2) + '%</span>'
                        + '</div>';
                }

                var liqHtml = t.liquidation_price !== null && t.liquidation_price !== undefined
                    ? '<div class="font-mono-tech text-[9px] text-amber-600/80 mt-1">⚠️ Likidasyon: ' + fmt$(t.liquidation_price) + '</div>'
                    : '';

                container.innerHTML =
                    '<div class="flex justify-between items-center mt-1 mb-0.5">'
                    + '<span class="font-mono-tech text-[10px] text-gray-600">Mark: '
                    + '<span class="text-gray-800">' + fmt$(t.mark_price) + '</span></span>'
                    + '</div>'
                    + pnlHtml
                    + liqHtml;
            });
        }

        // --- Portföy Toplam Değer ---
        function fetchPortfolio() {
            safeFetch('/api/dashboard/portfolio')
                .then(function(d) {
                    var pvEl = document.getElementById('navPortfolioValue');
                    var urEl = document.getElementById('navUnrealized');
                    if (pvEl) pvEl.classList.remove('animate-pulse');
                    if (!d.success) {
                        if (pvEl) pvEl.textContent = '—';
                        return;
                    }
                    if (pvEl) {
                        if (d.total_value !== null) {
                            animateNumber(pvEl, parseFloat(d.total_value), { prefix: '$', decimals: 2 });
                        } else {
                            pvEl.textContent = '—';
                        }
                    }
                    // unrealized_pnl NULL ise (Binance erişilemez) "0" değil "—" gösterilir -
                    // aksi halde borsaya hiç erişilemediği hâlde yanıltıcı bir "$0.00 kâr" görünürdü
                    if (urEl) {
                        if (d.unrealized_pnl !== null && d.unrealized_pnl !== undefined) {
                            var pnl = parseFloat(d.unrealized_pnl);
                            urEl.className = 'font-bold font-mono-tech text-[11px] ' + (pnl >= 0 ? 'text-emerald-600' : 'text-rose-600');
                            animateNumber(urEl, pnl, { prefix: '$', decimals: 2, showSign: true });
                        } else {
                            urEl.className = 'font-bold font-mono-tech text-[11px] text-gray-400';
                            urEl.textContent = '—';
                        }
                    }
                })
                .catch(function() {});
        }

        // --- Bot Tarama Durumu ---
        function fetchScanStatus() {
            safeFetch('/api/dashboard/scan-status')
                .then(function(d) {
                    var runAtEl  = document.getElementById('scanRunAt');
                    var scoresEl = document.getElementById('scanScores');
                    if (!d.success || !d.has_data) {
                        if (runAtEl)  runAtEl.textContent  = 'tarama yok';
                        if (scoresEl) scoresEl.innerHTML   = '<span class="text-gray-300">Henüz çalışmadı</span>';
                        return;
                    }
                    // Göreli zaman
                    if (runAtEl) {
                        var runMs  = new Date(d.run_at).getTime();
                        var diffMs = Date.now() - runMs;
                        var diffM  = Math.floor(diffMs / 60000);
                        var agoTxt = diffM < 1 ? 'az önce' : diffM < 60 ? diffM + ' dk önce' : Math.floor(diffM/60) + ' sa önce';
                        runAtEl.textContent = agoTxt;
                    }
                    // Skor satırları
                    if (scoresEl) {
                        var html = '';
                        (d.top_scores || []).forEach(function(s) {
                            var isSelected = d.selected_symbol && s.symbol === d.selected_symbol;
                            var scoreColor = s.score >= 85 ? 'text-emerald-600' : s.score >= 70 ? 'text-amber-600' : 'text-gray-500';
                            var badge = isSelected
                                ? '<span class="text-[8px] text-violet-600 border border-violet-400/30 rounded px-0.5 ml-0.5">AL</span>'
                                : '';
                            html += '<span class="whitespace-nowrap ' + scoreColor + '">'
                                + s.symbol + ' ' + s.score + badge + '</span>';
                        });
                        if (d.positions_opened > 0) {
                            html += '<span class="text-emerald-600 whitespace-nowrap">→ ' + d.positions_opened + ' alım yapıldı</span>';
                        }
                        scoresEl.innerHTML = html || '<span class="text-gray-300">Sinyal üretilmedi</span>';
                    }
                })
                .catch(function() {});
        }

        // --- AI Monolog ---
        function fetchBotLogs() {
            safeFetch('/api/dashboard/logs')
                .then(function(d) {
                    if (!d.success) {
                        panelErr('monologContainer');
                        return;
                    }
                    renderMonolog(d.logs || []);
                })
                .catch(function() {
                    panelErr('monologContainer');
                });
        }

        function renderMonolog(logs) {
            var c = document.getElementById('monologContainer');
            if (!c) return;
            if (!logs.length) {
                c.innerHTML = '<p class="text-gray-400">Log kaydı yok</p>';
                return;
            }
            var html = '';
            logs.forEach(function(log) {
                var time   = (log.run_at || '').substring(11, 16);
                var sel    = log.selected_symbol || '—';
                var score  = log.selected_score  || '—';
                // positions_opened GLOBAL bir sayimdir (o turde pozisyon acilan TUM kullanicilarin
                // toplami) - "POZİSYON AÇILDI" yazisi SADECE backend'in bu kullanicinin KENDI order
                // gecmisiyle capraz kontrol ederek dogruladigi opened_for_me true ise gosterilir,
                // aksi halde baskasinin actigi bir pozisyon bu kullanicinin panelinde gorunmez
                var opened = !!log.opened_for_me;
                var clr    = opened ? 'text-emerald-600' : (log.notes ? 'text-rose-600' : 'text-gray-500');
                html += '<div class="monolog-line ' + clr + ' leading-4 py-0.5">'
                    + '<span class="text-gray-400">[' + time + ']</span> ';
                var selWithIcon = log.selected_symbol
                    ? '<span data-coin-name="' + escapeHtml(sel) + '">' + coinIconHtml(sel) + escapeHtml(sel) + '</span>'
                    : sel;
                if (opened) {
                    html += '▶ <span class="font-bold">' + selWithIcon + '</span> @' + score + ' → POZİSYON AÇILDI';
                } else if (log.notes) {
                    html += '⊘ ' + escapeHtml((log.notes + '').substring(0, 55));
                } else {
                    html += '○ ' + selWithIcon + ' / skor:' + score;
                }
                html += '</div>';
            });
            c.innerHTML = html;
            c.scrollTop = c.scrollHeight;
        }

        // "Son İşlemler" paneli - PHP'deki ilk-yukleme render'iyle (dashboard/index.php, $recentOrders
        // dongusu) AYNI etiket/renk kurallarini JS tarafinda tekrarlar, ikisi senkron kalmali
        var RECENT_ORDER_STATUS_MAP = {
            FILLED:    ['OK', 'bg-emerald-400/10 text-emerald-600'],
            FAILED:    ['HATA', 'bg-rose-400/10 text-rose-600'],
            PENDING:   ['BEKLİYOR', 'bg-amber-400/10 text-amber-600'],
            CANCELLED: ['İPTAL', 'bg-gray-400/10 text-gray-600'],
        };

        function trimQtyJs(value) {
            var fixed = parseFloat(value).toFixed(8).replace(/0+$/, '').replace(/\.$/, '');
            return fixed === '' ? '0' : fixed;
        }

        function fetchRecentOrders() {
            safeFetch('/api/dashboard/recent-orders')
                .then(function(d) {
                    if (!d.success) {
                        panelErr('recentOrdersContainer');
                        return;
                    }
                    renderRecentOrders(d.orders || []);
                })
                .catch(function() {
                    panelErr('recentOrdersContainer');
                });
        }

        function renderRecentOrders(orders) {
            var c = document.getElementById('recentOrdersContainer');
            if (!c) return;

            var countEl = document.getElementById('recentOrdersCount');
            if (countEl) { countEl.textContent = orders.length + ' KAYIT'; }

            if (!orders.length) {
                c.innerHTML = '<p class="font-mono-tech text-xs text-gray-500 px-4 py-3">Henüz işlem yok</p>';
                return;
            }

            var rows = orders.map(function(order) {
                var side = (order.side || '').toUpperCase();
                var status = (order.status || '').toUpperCase();

                var sideLabel = side === 'BUY' ? 'AL' : 'SAT';
                var sideClasses = side === 'BUY' ? 'bg-emerald-400/10 text-emerald-600' : 'bg-rose-400/10 text-rose-600';

                var statusInfo = RECENT_ORDER_STATUS_MAP[status] || [status, 'bg-gray-400/10 text-gray-600'];
                var statusLabel = statusInfo[0];
                var statusClasses = statusInfo[1];
                var errorMessage = order.error_message || '';
                var statusTitle = (status === 'FAILED' && errorMessage !== '') ? errorMessage : '';

                var lossReason = order.loss_reason || '';
                var lossIcon = lossReason !== ''
                    ? '<span class="ml-0.5 cursor-help" title="' + escapeHtml(lossReason) + '">ℹ️</span>'
                    : '';

                var statusTitleAttr = statusTitle !== '' ? ' title="' + escapeHtml(statusTitle) + '"' : '';
                var statusExtraClasses = statusTitle !== '' ? ' cursor-help border-b border-dashed border-rose-400/50' : '';

                return '<tr class="border-t border-black/5 hover:bg-black/[0.015] cursor-pointer transition-colors" onclick="openOrderDetail(' + parseInt(order.id, 10) + ')">'
                    + '<td data-coin-name="' + escapeHtml(order.pair || '') + '" class="px-4 py-1.5 font-semibold text-gray-800">' + coinIconHtml(order.pair) + escapeHtml(order.pair || '') + '</td>'
                    + '<td class="px-2 py-1.5">'
                    +   '<span class="rounded px-1.5 py-0.5 ' + sideClasses + '">' + sideLabel + '</span>' + lossIcon
                    + '</td>'
                    + '<td class="px-2 py-1.5 text-gray-600">' + trimQtyJs(order.quantity) + '</td>'
                    + '<td class="px-2 py-1.5 text-gray-600">$' + formatFullPrecisionPrice(order.price) + '</td>'
                    + '<td class="px-4 py-1.5">'
                    +   '<span class="rounded px-1.5 py-0.5 ' + statusClasses + statusExtraClasses + '"' + statusTitleAttr + '>' + escapeHtml(statusLabel) + '</span>'
                    + '</td>'
                    + '<td class="px-2 py-1.5 text-right text-gray-400">👁</td>'
                    + '</tr>';
            }).join('');

            c.innerHTML = '<table class="w-full font-mono-tech text-[11px]">'
                + '<thead><tr class="text-left text-gray-500 text-[9px] tracking-widest sticky top-0 bg-white">'
                +   '<th class="font-medium px-4 py-1.5">PARİTE</th>'
                +   '<th class="font-medium px-2 py-1.5">YÖN</th>'
                +   '<th class="font-medium px-2 py-1.5">MİKTAR</th>'
                +   '<th class="font-medium px-2 py-1.5">FİYAT</th>'
                +   '<th class="font-medium px-4 py-1.5">DURUM</th>'
                +   '<th class="font-medium px-2 py-1.5 text-right"></th>'
                + '</tr></thead>'
                + '<tbody>' + rows + '</tbody>'
                + '</table>';
        }

        // "Bekleyen Emirler" - musteri talebi (31 Temmuz): tum filtrelerden gecip GERCEK bir Binance
        // limit emri konulmus ama henuz DOLMAMIS adaylari gosterir - huntsContainer'daki (acik
        // POZISYON) kartlarindan AYRI, kendi kucuk container'i var (bkz. PHP tarafindaki ilk render)
        function fetchPendingOrders() {
            safeFetch('/api/dashboard/pending-orders')
                .then(function(d) {
                    if (!d.success) return;
                    renderPendingOrders(d.orders || []);
                })
                .catch(function() {});
        }

        function renderPendingOrders(orders) {
            var c = document.getElementById('pendingOrdersContainer');
            if (!c) return;

            if (!orders.length) {
                c.classList.add('hidden');
                c.innerHTML = '';
                return;
            }

            c.classList.remove('hidden');
            c.innerHTML = orders.map(function(po) {
                var remainingMin = Math.round(po.remaining_seconds / 60);
                return '<div data-pending-card="' + po.id + '" class="rounded-lg border border-dashed border-amber-400/40 bg-amber-400/[0.04] px-3 py-1.5">'
                    + '<div class="flex justify-between items-center">'
                    +   '<span class="flex items-center gap-1.5">'
                    +     '<span class="font-mono-tech text-[9px] font-bold text-amber-600 border border-amber-400/40 rounded px-1 py-0.5">⏳ BEKLİYOR</span>'
                    +     '<span class="font-mono-tech text-xs font-semibold text-gray-800">' + escapeHtml(po.pair) + '</span>'
                    +   '</span>'
                    +   '<span class="font-mono-tech text-[9px] text-amber-600">' + remainingMin + ' dk kaldı</span>'
                    + '</div>'
                    + '<div class="flex justify-between items-center mt-0.5">'
                    +   '<span class="font-mono-tech text-[10px] text-gray-500">Fiyat: $' + formatFullPrecisionPrice(po.limit_price) + ' · Miktar: ' + trimQtyJs(po.quantity) + '</span>'
                    +   '<span class="font-mono-tech text-[10px] text-gray-500">~$' + parseFloat(po.budget).toFixed(2) + '</span>'
                    + '</div>'
                    + '</div>';
            }).join('');
        }

        // --- AI Kalkanı (Görünmez Kalkan Raporu) ---
        var INTERVENTION_TYPE_LABELS = {
            MTF_TUZAK: 'Trend Tuzağı',
            SATIS_DUVARI: 'Satış Duvarı',
            ANTI_FOMO_ZIRVE: 'Zirve Yakınlığı',
            ANTI_FOMO_RSI: 'RSI Aşırı Alım',
            PULLBACK_BEKLENMEDI: 'Pullback Beklenmedi',
            ZAYIF_TEYIT: 'Zayıflayan Teyit',
            lot_size_guard: 'Lot Boyutu Koruması',
        };

        function fetchShield() {
            safeFetch('/api/dashboard/interventions')
                .then(function(d) {
                    if (!d.success) {
                        panelErr('shieldContainer');
                        return;
                    }
                    renderShield(d.interventions || []);
                })
                .catch(function() {
                    panelErr('shieldContainer');
                });
        }

        function renderShield(interventions) {
            var c = document.getElementById('shieldContainer');
            if (!c) return;
            if (!interventions.length) {
                c.innerHTML = '<p class="text-gray-400">Henüz engellenen bir tuzak yok.</p>';
                return;
            }
            var html = '';
            interventions.forEach(function(item) {
                var time = (item.created_at || '').substring(11, 16);
                var typeLabel = INTERVENTION_TYPE_LABELS[item.intervention_type] || item.intervention_type;
                html += '<div class="shield-line leading-4 py-0.5 text-sky-700">'
                    + '<span class="text-gray-400">[' + time + ']</span> '
                    + '🛡️ <span class="font-bold">' + escapeHtml(item.symbol) + '</span> '
                    + '<span class="text-gray-500">(' + typeLabel + ')</span> — İşlem İptal'
                    + '</div>';
            });
            c.innerHTML = html;
        }

        // --- Finansal Özet P&L ---
        function fetchPnl() {
            safeFetch('/api/dashboard/pnl')
                .then(function(d) {
                    if (!d.success) { return; }
                    renderPnl(d);
                })
                .catch(function() {});
        }

        function renderPnl(d) {
            function pnlClass(val) {
                return parseFloat(val) >= 0 ? 'text-emerald-600' : 'text-rose-600';
            }

            var daily   = document.getElementById('pnlDaily');
            var weekly  = document.getElementById('pnlWeekly');
            var monthly = document.getElementById('pnlMonthly');
            var wr      = document.getElementById('pnlWinRate');
            var wrDetail= document.getElementById('pnlWinDetail');
            var arc     = document.getElementById('winRateArc');
            var navDaily = document.getElementById('navDailyPnl');
            // 30 Temmuz'da eklendi: "TAMAMLANAN" ust bar sayaci hic id/JS guncellemesi almiyordu,
            // sayfa ilk acildiginda PHP'nin yazdigi deger sonsuza kadar sabit kalip yenileme
            // gerektiriyordu - /api/dashboard/pnl zaten total_trades donduruyordu, sadece bu alana
            // hic yazilmiyordu
            var navCompleted = document.getElementById('navCompletedOrders');
            if (navCompleted && d.total_trades !== undefined && d.total_trades !== null) {
                navCompleted.textContent = d.total_trades;
            }

            if (daily) {
                daily.className = 'font-mono-tech text-sm font-bold ' + pnlClass(d.daily_profit);
                animateNumber(daily, parseFloat(d.daily_profit) || 0, { prefix: '$', decimals: 2, showSign: true });
            }
            // Sayfa ilk acildiginda sunucu tarafinda hesaplanan (sadece gerceklesmis islemleri sayan,
            // acik pozisyonun kagit uzerindeki kar/zararini icermeyen) ust bar degeri burada, ayni
            // AJAX veriyle (acik pozisyonu da iceren) guncellenir - boylece iki farkli PNL rakami
            // ayni anda ekranda gorunmez
            if (navDaily) {
                navDaily.className = 'font-bold ' + pnlClass(d.daily_profit);
                animateNumber(navDaily, parseFloat(d.daily_profit) || 0, { prefix: '$', decimals: 2, showSign: true });
            }
            if (weekly) {
                weekly.className = 'font-mono-tech text-sm font-bold ' + pnlClass(d.weekly_profit);
                animateNumber(weekly, parseFloat(d.weekly_profit) || 0, { prefix: '$', decimals: 2, showSign: true });
            }
            if (monthly) {
                monthly.className = 'font-mono-tech text-sm font-bold ' + pnlClass(d.monthly_profit);
                animateNumber(monthly, parseFloat(d.monthly_profit) || 0, { prefix: '$', decimals: 2, showSign: true });
            }

            if (wr) {
                if (d.win_rate !== null && d.win_rate !== undefined) {
                    var rate = parseFloat(d.win_rate);
                    wr.className = 'font-mono-tech text-sm font-bold ' + (rate >= 60 ? 'text-emerald-600' : rate >= 40 ? 'text-amber-600' : 'text-rose-600');
                    animateNumber(wr, rate, { decimals: 1, suffix: '%', flash: false });
                    var arcColor = rate >= 60 ? 'rgba(52,211,153,0.75)' : rate >= 40 ? 'rgba(251,191,36,0.75)' : 'rgba(251,113,133,0.75)';
                    if (arc) {
                        var circ = 2 * Math.PI * 15; // r=15 → ~94.25
                        var filled = (circ * rate / 100).toFixed(1);
                        arc.setAttribute('stroke-dasharray', filled + ' ' + circ.toFixed(1));
                        arc.setAttribute('stroke', arcColor);
                    }
                } else {
                    wr.textContent = '—';
                    wr.className = 'font-mono-tech text-sm font-bold text-gray-400';
                }
                if (wrDetail && d.total_trades > 0) {
                    wrDetail.textContent = d.wins + '/' + d.total_trades + ' işlem';
                }
            }
        }

        // --- Risk Profili Kartı Seçimi ---
        function selectRiskProfile(btn) {
            var profile = btn.getAttribute('data-profile');
            if (!profile) { return; }

            var activeClasses   = ['border-emerald-400/60','bg-emerald-400/5','shadow-[0_0_18px_rgba(52,211,153,0.15)]'];
            var inactiveClasses = ['border-black/10','bg-black/[0.02]'];

            document.querySelectorAll('.risk-card').forEach(function(card) {
                activeClasses.forEach(function(c)   { card.classList.remove(c); });
                inactiveClasses.forEach(function(c) { card.classList.add(c); });
                card.querySelector('.font-semibold').className = 'font-semibold text-sm text-gray-800 mb-1';
                var badge = card.querySelector('[data-active-badge]');
                if (badge) { badge.remove(); }
            });

            activeClasses.forEach(function(c)   { btn.classList.add(c); });
            inactiveClasses.forEach(function(c) { btn.classList.remove(c); });
            btn.querySelector('.font-semibold').className = 'font-semibold text-sm text-emerald-600 mb-1';

            submitRiskProfile(profile, '');
        }

        // 12 Temmuz'da tespit edilen "sessiz ezme" bug'ı: bu istek, kullanıcının elle girdiği
        // özel bir Zarar Kes değerini hiçbir uyarı olmadan profilin sabit değerine sıfırlıyordu.
        // Artık sunucu böyle bir çakışma tespit ederse (needs_confirmation) HİÇBİR ŞEYİ
        // DEĞİŞTİRMEDEN önce sorar - kullanıcı "Tamam" derse profil değeri uygulanır ("overwrite"),
        // "İptal" derse SADECE diğer alanlar (eşik, maks. işlem) güncellenir, Zarar Kes'i AYNEN korunur ("preserve")
        function submitRiskProfile(profile, stopLossAction) {
            var msgEl = document.getElementById('riskSaveMsg');

            var body = new FormData();
            body.append('profile', profile);
            if (stopLossAction) {
                body.append('stop_loss_action', stopLossAction);
            }

            fetch(_BASE + '/api/settings/risk-profile', { method: 'POST', body: body })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.needs_confirmation) {
                        var overwrite = window.confirm(
                            'Elle girdiğiniz özel bir Zarar Kes değeriniz var (%' + d.current_stop_loss + ').\n' +
                            'Bu profili seçmek Zarar Kes\'i %' + d.profile_stop_loss + '\'e sıfırlayacak.\n\n' +
                            'Tamam: Zarar Kes de profile göre sıfırlansın.\n' +
                            'İptal: Sadece diğer ayarlar (AI eşiği, maks. işlem) güncellensin, Zarar Kes\'im (%' + d.current_stop_loss + ') korunsun.'
                        );
                        submitRiskProfile(profile, overwrite ? 'overwrite' : 'preserve');
                        return;
                    }

                    if (msgEl) {
                        msgEl.classList.remove('hidden','border-rose-400/30','bg-rose-400/10','text-rose-600');
                        if (d.success) {
                            msgEl.classList.add('border-emerald-400/30','bg-emerald-400/10','text-emerald-600');
                            msgEl.textContent = '✓ Risk profili kaydedildi: ' + profile
                                + ' | Eşik: ' + d.ai_score_threshold
                                + ' | SL: %' + d.stop_loss_percent
                                + (d.preserved_custom_stop_loss ? ' (özel değeriniz korundu)' : '')
                                + ' | Max: ' + d.max_active_trades + ' pozisyon';
                        } else {
                            msgEl.classList.add('border-rose-400/30','bg-rose-400/10','text-rose-600');
                            msgEl.textContent = '⊘ ' + (d.message || 'Kayıt başarısız.');
                        }
                        setTimeout(function() { msgEl.classList.add('hidden'); }, 5000);
                    }
                })
                .catch(function() {
                    if (msgEl) {
                        msgEl.classList.remove('hidden');
                        msgEl.classList.add('border-rose-400/30','bg-rose-400/10','text-rose-600');
                        msgEl.textContent = '⊘ Bağlantı hatası.';
                        setTimeout(function() { msgEl.classList.add('hidden'); }, 4000);
                    }
                });
        }

        // --- Sayfa başladığında tümünü çalıştır ---
        document.addEventListener('DOMContentLoaded', function() {
            fetchBalance();
            fetchRadar();
            fetchNews();
            fetchActiveTrades();
            fetchFuturesPositions();
            fetchBotLogs();
            fetchShield();
            fetchPnl();
            fetchPortfolio();
            fetchScanStatus();
            fetchSystemStatus(false);
            fetchRecentOrders();
            fetchPendingOrders();
            queueVisibleCoinIcons();

            // Ana Grafik (TradingView widget - bkz. loadChart yorumu). Kayan Bant artik KENDI
            // setInterval'ina ihtiyac duymuyor - TradingView'in Ticker Tape widget'i (yukarida embed
            // edildi) kendi ici WebSocket'iyle otomatik guncelleniyor, initTickerTape() cagrisi kaldirildi
            loadChart('BINANCE:BTCUSDT');

            // 29 Temmuz'da hizlandirildi (VPS gecisi sonrasi, "daha canli hissettirsin" talebi):
            // sadece "canli" hissi en cok etkileyen 3 kalem (acik pozisyon/bakiye/fiyat seridi)
            // sikilastirildi - digerleri (haberler/radar/bot loglari) kasitli DOKUNULMADI, onlarin
            // saniyede bir degismesinin gercek bir faydasi yok, sadece VPS'e ve Binance'e gereksiz
            // yuk bindirirdi (1GB RAM'lik VPS + coklu kullanici ihtimali)
            setInterval(fetchBalance,           15000);
            setInterval(fetchActiveTrades,      3000);
            setInterval(fetchPendingOrders,     3000);
            setInterval(fetchFuturesPositions,  3000);
            setInterval(fetchBotLogs,           60000);
            setInterval(fetchShield,            60000);
            setInterval(fetchPnl,               60000);
            setInterval(fetchPortfolio,         30000);
            setInterval(fetchScanStatus,        60000);
            // 30 Temmuz'da eklendi: "Son İşlemler" paneli eskiden SADECE ilk sayfa yuklemesinde
            // PHP tarafinda dolduruluyordu (setInterval dongusune DAHIL degildi) - manuel kapatma
            // gibi sayfa acikken olusan yeni bir siparis F5 atilmadan hic gorunmuyordu
            setInterval(fetchRecentOrders,      30000);
            setInterval(function () { fetchSystemStatus(false); }, 60000);
            // 22 Temmuz'da eklendi: fetchRadar/fetchNews eskiden SADECE sayfa ilk acildiginda ve
            // sekmeye geri donulunce (visibilitychange/focus) calisiyordu, digerleri gibi periyodik
            // degildi - "dashboard tam canli degil" geri bildirimi uzerine eklendi. Backend zaten
            // onbellekli oldugu icin (Radar 120sn, Haberler 15dk - bkz. DashboardController/
            // NewsService) bu periyotlar YENI OpenAI/RSS cagrisi tetiklemez, sadece onbellekten
            // taze veri ceker - ekstra maliyet yok
            setInterval(fetchRadar,             90000);
            setInterval(fetchNews,              120000);
            // "X sn/dk önce güncellendi" etiketlerini saniyede bir sayaç gibi ilerletir - bkz.
            // markUpdated()/tickFreshnessLabels() yorumu
            setInterval(tickFreshnessLabels,    1000);

            // AI Avcı ayarları (aç/kapa şalteri + bütçe/TP/SL/günlük limit) artık sayfa
            // yenilenmeden AJAX ile kaydediliyor - bkz. submitJsonForm/showToast
            var autoTradeForm = document.getElementById('autoTradeSettingsForm');
            if (autoTradeForm) {
                autoTradeForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    submitJsonForm(autoTradeForm);
                });
            }
        });

        // Ana ekrana eklenip arka plana alindiginda (kilit ekrani/uygulama degistirme) mobil
        // isletim sistemleri setInterval zamanlayicilarini duraklatir - sayfa tekrar on plana
        // geldiginde tum verileri hemen yeniden ceker, boylece "bayat" veri gormek yerine
        // gecikmeden guncel duruma doner.
        // TEK BASINA 'visibilitychange' iOS'ta ana ekrana eklenmis (standalone) PWA'larda HER ZAMAN
        // guvenilir sekilde tetiklenmiyor - sayfa arka planda tamamen donduruluyor/bellekten atiliyor
        // ve tekrar acildiginda bu olay hic atesenmeyebiliyor. Bu yuzden 'pageshow' (geri/ileri
        // onbellekten - bfcache - donuslerde dahil calisir) ve 'focus' (pencere/sekme odak kazaninca)
        // olaylari da AYNI yenileme fonksiyonuna baglanir - hangisi once tetiklenirse o yenilemeyi yapar
        function refreshAllData() {
            fetchBalance();
            fetchRadar();
            fetchNews();
            fetchActiveTrades();
            fetchFuturesPositions();
            fetchBotLogs();
            fetchShield();
            fetchPnl();
            fetchPortfolio();
            fetchScanStatus();
            fetchSystemStatus(false);
            fetchRecentOrders();
            fetchPendingOrders();
        }

        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                refreshAllData();
            }
        });

        window.addEventListener('pageshow', function() {
            refreshAllData();
        });

        window.addEventListener('focus', function() {
            refreshAllData();
        });

        <?php if ($openSettingsModal): ?>
        document.addEventListener('DOMContentLoaded', openSettingsModal);
        <?php endif; ?>
    </script>

    <!-- Toast bildirim kapsayicisi: AJAX form kaydetmelerinin basari/hata geri bildirimi buraya dusuyor -->
    <div id="toastContainer" class="fixed bottom-4 right-4 z-50 flex flex-col gap-2 items-end pointer-events-none"></div>
</body>
</html>
