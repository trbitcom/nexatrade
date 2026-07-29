<?php

declare(strict_types=1);

use App\Core\Url;

/** @var string|null $error */
/** @var string|null $success */
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>NexaTrade | Giriş Yap</title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(Url::to('/assets/img/favicon.png'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- cdn.tailwindcss.com (calisma zamaninda JS ile CSS ureten, uretim icin onerilmeyen yontem)
         yerine derleme zamaninda uretilmis, kucuk ve statik bir CSS dosyasi - sayfa acilisini hizlandirir -->
    <link rel="stylesheet" href="<?= htmlspecialchars(Url::to('/assets/css/tailwind.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'Space Grotesk', 'Inter', sans-serif;
            background-color: #f5f5f7;
            -webkit-tap-highlight-color: transparent;
            overflow-x: hidden;
        }
        button, a {
            -webkit-tap-highlight-color: transparent;
            transition: transform 0.15s ease;
        }
        button:active, a:active {
            transform: scale(0.97);
        }

        .font-mono-tech { font-family: 'JetBrains Mono', monospace; }
        .font-display { font-family: 'Space Grotesk', sans-serif; }

        /* ---- Sol/ust taraf: koyu, canli marka paneli ---- */
        .hero-panel {
            background-color: #0b0a14;
            background-image:
                radial-gradient(circle at 15% 20%, rgba(139, 92, 246, 0.35), transparent 45%),
                radial-gradient(circle at 85% 80%, rgba(34, 211, 238, 0.22), transparent 45%),
                linear-gradient(160deg, #0b0a14 0%, #120f22 100%);
            position: relative;
            overflow: hidden;
        }
        .hero-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.04) 1px, transparent 1px);
            background-size: 42px 42px;
            mask-image: radial-gradient(circle at 30% 30%, black, transparent 75%);
        }

        /* ---- Arka planda yavasca suzulen bulanik renk lekeleri ---- */
        .aurora {
            position: absolute;
            border-radius: 9999px;
            filter: blur(70px);
            pointer-events: none;
        }
        .aurora-1 {
            width: 380px; height: 380px;
            top: -8%; left: -12%;
            background: rgba(139, 92, 246, 0.45);
            animation: drift-1 16s ease-in-out infinite alternate;
        }
        .aurora-2 {
            width: 320px; height: 320px;
            bottom: -10%; right: -8%;
            background: rgba(34, 211, 238, 0.32);
            animation: drift-2 18s ease-in-out infinite alternate;
        }
        @keyframes drift-1 {
            from { transform: translate(0, 0) scale(1); }
            to   { transform: translate(40px, 30px) scale(1.08); }
        }
        @keyframes drift-2 {
            from { transform: translate(0, 0) scale(1); }
            to   { transform: translate(-30px, -25px) scale(1.05); }
        }
        @media (prefers-reduced-motion: reduce) {
            .aurora { animation: none; }
        }

        /* ---- Ozellik listesi: sirayla belirir ---- */
        .stagger > * {
            opacity: 0;
            animation: fade-up 0.55s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .stagger > *:nth-child(1) { animation-delay: 0.05s; }
        .stagger > *:nth-child(2) { animation-delay: 0.15s; }
        .stagger > *:nth-child(3) { animation-delay: 0.25s; }
        .stagger > *:nth-child(4) { animation-delay: 0.35s; }
        .stagger > *:nth-child(5) { animation-delay: 0.45s; }
        .stagger > *:nth-child(6) { animation-delay: 0.55s; }
        @keyframes fade-up {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @media (prefers-reduced-motion: reduce) {
            .stagger > * { opacity: 1; animation: none; }
        }

        .feature-row {
            transition: transform 0.2s ease, background-color 0.2s ease;
        }
        @media (hover: hover) and (pointer: fine) {
            .feature-row:hover {
                transform: translateX(4px);
                background-color: rgba(255, 255, 255, 0.05);
            }
        }

        @keyframes pulse-ring {
            0%   { transform: scale(0.9); opacity: 0.9; }
            100% { transform: scale(2.2); opacity: 0; }
        }
        .pulse-ring { animation: pulse-ring 1.8s cubic-bezier(0.2, 0.8, 0.2, 1) infinite; }

        /* ---- Sag/alt taraf: giris formu karti ---- */
        .glass-panel {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(0, 0, 0, 0.06);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04), 0 16px 32px -16px rgba(0, 0, 0, 0.10);
            animation: panel-in 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        @keyframes panel-in {
            from { opacity: 0; transform: translateY(10px) scale(0.99); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        @media (prefers-reduced-motion: reduce) {
            .glass-panel { animation: none; }
        }

        /* ---- Hatali giris: panel hafifce sallanarak dikkat ceker (bkz. asagida inline style) ---- */
        @keyframes shake {
            10%, 90% { transform: translateX(-1px); }
            20%, 80% { transform: translateX(2px); }
            30%, 50%, 70% { transform: translateX(-4px); }
            40%, 60% { transform: translateX(4px); }
        }

        input {
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.15s ease;
        }
        input:focus {
            transform: translateY(-1px);
        }

        /* iOS Safari, 16px altindaki input'lara odaklaninca sayfayi otomatik yakinlastirir */
        @media (max-width: 1023px) {
            input { font-size: 16px !important; }
        }

        /* ---- Giris butonu: gonderim sirasinda donen bir yukleme ikonu gosterir ---- */
        @keyframes spin { to { transform: rotate(360deg); } }
        .btn-spinner {
            display: none;
            width: 14px; height: 14px;
            border: 2px solid rgba(255, 255, 255, 0.4);
            border-top-color: #fff;
            border-radius: 9999px;
            animation: spin 0.6s linear infinite;
        }
        .btn-loading .btn-spinner { display: inline-block; }
        .btn-loading .btn-label { opacity: 0.85; }
    </style>
</head>
<body class="min-h-screen text-gray-800">
    <div class="min-h-screen flex flex-col lg:flex-row">

        <!-- SOL: Marka / ozellik paneli (mobilde sadece kompakt logo/baslik - form'a hemen gecilsin
             diye ozellik listesi/canli fiyat seridi mobilde gizli, sadece lg+ ekranda gorunur) -->
        <div class="hero-panel lg:w-1/2 xl:w-3/5 flex flex-col justify-center px-8 sm:px-12 lg:px-20 py-8 sm:py-10 lg:py-0 text-white">
            <div class="aurora aurora-1"></div>
            <div class="aurora aurora-2"></div>

            <div class="relative stagger max-w-md mx-auto lg:mx-0 w-full">
                <div class="flex items-center gap-3 mb-2">
                    <img src="<?= htmlspecialchars(Url::to('/assets/img/logo.png'), ENT_QUOTES, 'UTF-8') ?>" alt="NexaTrade" class="h-11 w-auto">
                    <div class="flex items-center gap-1.5">
                        <span class="relative flex w-2 h-2">
                            <span class="pulse-ring absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full w-2 h-2 bg-emerald-400"></span>
                        </span>
                        <span class="font-mono-tech text-[10px] text-emerald-400 tracking-widest">CANLI</span>
                    </div>
                </div>

                <h1 class="font-display text-2xl sm:text-3xl lg:text-4xl font-bold leading-tight mb-3">
                    Kripto piyasasında<br><span class="text-violet-400">otonom</span> avantaj.
                </h1>
                <p class="hidden lg:block text-sm text-gray-400 mb-8 max-w-sm">
                    NexaTrade; piyasayı 7/24 tarar, GPT destekli karar motoruyla değerlendirir ve
                    riskini senin belirlediğin sınırlar içinde otomatik yönetir.
                </p>

                <div class="hidden lg:block space-y-1 mb-8">
                    <div class="feature-row flex items-start gap-3 rounded-lg px-3 py-2.5">
                        <span class="text-lg leading-none">⚡</span>
                        <div>
                            <div class="text-sm font-semibold text-white">Anlık Piyasa Taraması</div>
                            <div class="text-xs text-gray-400">RSI, MACD ve hacim sinyalleri saniyeler içinde işlenir</div>
                        </div>
                    </div>
                    <div class="feature-row flex items-start gap-3 rounded-lg px-3 py-2.5">
                        <span class="text-lg leading-none">🧠</span>
                        <div>
                            <div class="text-sm font-semibold text-white">GPT Destekli Karar Motoru</div>
                            <div class="text-xs text-gray-400">Gerçek verilerle beslenen, gerekçeli AI değerlendirmesi</div>
                        </div>
                    </div>
                    <div class="feature-row flex items-start gap-3 rounded-lg px-3 py-2.5">
                        <span class="text-lg leading-none">🎯</span>
                        <div>
                            <div class="text-sm font-semibold text-white">Listeleme Avcısı & Akıllı Para</div>
                            <div class="text-xs text-gray-400">Yeni listelemeleri ve balina hareketlerini anında yakalar</div>
                        </div>
                    </div>
                    <div class="feature-row flex items-start gap-3 rounded-lg px-3 py-2.5">
                        <span class="text-lg leading-none">🛡️</span>
                        <div>
                            <div class="text-sm font-semibold text-white">Otomatik Risk Yönetimi</div>
                            <div class="text-xs text-gray-400">Portföy bazlı risk sınırı ve devre kesici koruması</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Canli fiyat seridi - sadece lg+ ekranda (mobilde form'a hizli erisim onceliklidir) -->
            <div class="hidden lg:block relative mt-2 lg:absolute lg:bottom-8 lg:left-0 lg:right-0 opacity-90">
                <div class="tradingview-widget-container">
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
                        "colorTheme": "dark",
                        "locale": "tr"
                    }
                    </script>
                </div>
            </div>
        </div>

        <!-- SAG: Giris formu -->
        <div class="lg:w-1/2 xl:w-2/5 flex items-center justify-center px-4 py-12">
            <div class="w-full max-w-sm relative glass-panel rounded-2xl p-8" style="<?= !empty($error) ? 'animation: panel-in 0.5s cubic-bezier(0.16, 1, 0.3, 1) both, shake 0.5s cubic-bezier(0.36, 0.07, 0.19, 0.97) 0.5s both;' : '' ?>">
                <div class="stagger">
                    <div class="mb-6 lg:text-left text-center">
                        <h2 class="font-display text-xl font-bold text-gray-900">Tekrar hoş geldin</h2>
                        <p class="text-xs text-gray-500 mt-1">Hesabına giriş yap ve terminale devam et.</p>
                    </div>

                    <?php if (!empty($success)): ?>
                        <div class="rounded-lg border border-emerald-400/30 bg-emerald-400/10 text-emerald-600 text-xs px-3 py-2 mb-4">
                            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($error)): ?>
                        <div class="rounded-lg border border-rose-400/30 bg-rose-400/10 text-rose-600 text-xs px-3 py-2 mb-4">
                            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= htmlspecialchars(Url::to('/login'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-4" onsubmit="var b=document.getElementById('loginBtn'); b.classList.add('btn-loading'); b.disabled=true;">
                        <div>
                            <label for="email" class="block text-[10px] tracking-widest text-gray-500 mb-1">E-POSTA</label>
                            <input type="email" id="email" name="email" required autofocus class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-violet-400/60 focus:ring-1 focus:ring-violet-400/40">
                        </div>
                        <div>
                            <label for="password" class="block text-[10px] tracking-widest text-gray-500 mb-1">ŞİFRE</label>
                            <input type="password" id="password" name="password" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-violet-400/60 focus:ring-1 focus:ring-violet-400/40">
                        </div>
                        <button type="submit" id="loginBtn" class="w-full flex items-center justify-center gap-2 rounded-lg bg-violet-500 hover:bg-violet-400 transition-colors text-white text-sm font-semibold py-2.5">
                            <span class="btn-spinner"></span>
                            <span class="btn-label">Giriş Yap</span>
                        </button>
                    </form>

                    <p class="text-center text-xs text-gray-500 mt-6">
                        Hesabın yok mu?
                        <a href="<?= htmlspecialchars(Url::to('/register'), ENT_QUOTES, 'UTF-8') ?>" class="text-violet-600 hover:text-violet-500 transition-colors">Kayıt ol</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
