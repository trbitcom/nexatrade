<?php

declare(strict_types=1);

use App\Core\Url;

/** @var array|null $result */
/** @var string|null $errorMessage */

$symbolValue = htmlspecialchars((string) ($_POST['symbol'] ?? ''), ENT_QUOTES, 'UTF-8');
$daysValue = htmlspecialchars((string) ($_POST['days'] ?? '90'), ENT_QUOTES, 'UTF-8');
$takeProfitValue = htmlspecialchars((string) ($_POST['take_profit_percent'] ?? '20'), ENT_QUOTES, 'UTF-8');
$stopLossValue = htmlspecialchars((string) ($_POST['stop_loss_percent'] ?? '10'), ENT_QUOTES, 'UTF-8');
$technicalScoreValue = htmlspecialchars((string) ($_POST['technical_score_threshold'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>NexaTrade | Backtest</title>
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
            background-image: radial-gradient(circle at 20% 0%, rgba(139, 92, 246, 0.05), transparent 55%);
            -webkit-tap-highlight-color: transparent;
        }
        button, a {
            -webkit-tap-highlight-color: transparent;
            transition: transform 0.15s ease;
        }
        button:active, a:active {
            transform: scale(0.97);
        }

        .font-mono-tech { font-family: 'JetBrains Mono', monospace; }

        .glass-panel {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(0, 0, 0, 0.06);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04), 0 16px 32px -16px rgba(0, 0, 0, 0.10);
            animation: panel-in 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        }
        @media (hover: hover) and (pointer: fine) {
            .glass-panel:hover {
                transform: translateY(-1px);
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05), 0 20px 40px -18px rgba(0, 0, 0, 0.14);
                border-color: rgba(0, 0, 0, 0.09);
            }
        }
        @keyframes panel-in {
            from { opacity: 0; transform: translateY(10px) scale(0.995); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        @media (prefers-reduced-motion: reduce) {
            .glass-panel { animation: none; }
        }

        .thin-scroll::-webkit-scrollbar { height: 6px; width: 6px; }
        .thin-scroll::-webkit-scrollbar-thumb { background: rgba(0, 0, 0, 0.15); border-radius: 3px; }

        /* iOS Safari, 16px altindaki input'lara odaklaninca sayfayi otomatik yakinlastirir */
        @media (max-width: 1023px) {
            input, select, textarea { font-size: 16px !important; }
        }
    </style>
</head>
<body class="text-gray-800 min-h-screen">
    <nav class="flex items-center justify-between px-5 py-2.5 border-b border-violet-500/10 bg-white/85 backdrop-blur-md sticky top-0 z-10">
        <div class="flex items-center gap-2">
            <img src="<?= htmlspecialchars(Url::to('/assets/img/logo.png'), ENT_QUOTES, 'UTF-8') ?>" alt="NexaTrade" class="h-8 w-auto">
            <span class="font-mono-tech text-[10px] text-gray-500 tracking-widest border border-black/10 rounded px-1.5 py-0.5 ml-1">BACKTEST</span>
        </div>
        <div class="flex items-center gap-3">
            <a href="<?= htmlspecialchars(Url::to('/admin'), ENT_QUOTES, 'UTF-8') ?>" class="font-mono-tech text-xs px-3 py-1.5 rounded-lg border border-black/10 text-gray-600 hover:bg-black/5 hover:text-gray-900 transition-colors">← ADMIN</a>
            <a href="<?= htmlspecialchars(Url::to('/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="font-mono-tech text-xs px-3 py-1.5 rounded-lg border border-black/10 text-gray-600 hover:bg-black/5 hover:text-gray-900 transition-colors">TERMİNAL</a>
        </div>
    </nav>

    <div class="max-w-4xl mx-auto p-5 space-y-5">
        <div class="relative glass-panel rounded-2xl p-5">
            <div class="font-mono-tech text-xs tracking-wider text-cyan-600 mb-1">GEÇMİŞ VERİYLE STRATEJİ TESTİ</div>
            <p class="text-[11px] text-gray-500 mb-4">
                Gerçek geçmiş fiyat verisiyle, sistemin mekanik filtrelerini (hacim eşiği, %25 aşırı-pump limiti,
                RSI, BTC trend filtresi) ve sabit kâr-al/zarar-kes yüzdelerini test eder. <strong class="text-gray-600">AI skorunu içermez</strong> —
                gerçek botta AI katmanı ayrıca devreye girdiği için gerçek sonuçlar burada simüle edilenden daha seçici olabilir.
                Salt okunurdur, hiçbir gerçek işlem yapmaz.
            </p>

            <?php if ($errorMessage !== null): ?>
                <div class="rounded-lg border border-rose-400/30 bg-rose-400/10 text-rose-600 text-xs px-3 py-2 mb-4"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="POST" action="<?= htmlspecialchars(Url::to('/admin/backtest'), ENT_QUOTES, 'UTF-8') ?>" class="grid sm:grid-cols-4 gap-3">
                <div>
                    <label for="symbol" class="block text-[10px] tracking-widest text-gray-500 mb-1">SEMBOL</label>
                    <input type="text" id="symbol" name="symbol" value="<?= $symbolValue ?>" placeholder="TLMUSDT" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech uppercase focus:outline-none focus:border-cyan-400/60 focus:ring-1 focus:ring-cyan-400/40">
                </div>
                <div>
                    <label for="days" class="block text-[10px] tracking-widest text-gray-500 mb-1">GÜN SAYISI</label>
                    <input type="number" id="days" name="days" value="<?= $daysValue ?>" min="1" max="365" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-cyan-400/60 focus:ring-1 focus:ring-cyan-400/40">
                </div>
                <div>
                    <label for="take_profit_percent" class="block text-[10px] tracking-widest text-emerald-600 mb-1">KÂR AL (%)</label>
                    <input type="number" step="0.1" id="take_profit_percent" name="take_profit_percent" value="<?= $takeProfitValue ?>" min="0.1" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-emerald-400/60 focus:ring-1 focus:ring-emerald-400/40">
                </div>
                <div>
                    <label for="stop_loss_percent" class="block text-[10px] tracking-widest text-rose-600 mb-1">ZARAR KES (%)</label>
                    <input type="number" step="0.1" id="stop_loss_percent" name="stop_loss_percent" value="<?= $stopLossValue ?>" min="0.1" required class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-rose-400/60 focus:ring-1 focus:ring-rose-400/40">
                </div>
                <div class="sm:col-span-4">
                    <label for="technical_score_threshold" class="block text-[10px] tracking-widest text-fuchsia-600 mb-1">TEKNİK SKOR EŞİĞİ (İSTEĞE BAĞLI — 1-100, GPT DEĞİL, BELİRLEYİCİ KURAL MOTORU)</label>
                    <input type="number" id="technical_score_threshold" name="technical_score_threshold" value="<?= $technicalScoreValue ?>" min="1" max="100" placeholder="Boş = sadece mekanik filtreler (RSI/hacim/BTC/pump limiti)" class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech placeholder:text-gray-400 focus:outline-none focus:border-fuchsia-400/60 focus:ring-1 focus:ring-fuchsia-400/40">
                    <p class="text-[10px] text-gray-400 mt-1">Doldurulursa, RSI + hacim trendi + MA20 trend teyidini birleştiren belirleyici (deterministik) bir puanlama da ek bir filtre olarak uygulanır — bu eşiği geçmeyen adaylar da elenir.</p>
                </div>
                <div class="sm:col-span-4 flex items-center gap-2">
                    <input type="checkbox" id="apply_recent_peak_filter" name="apply_recent_peak_filter" value="1" <?= isset($_POST['apply_recent_peak_filter']) ? 'checked' : '' ?> class="rounded border-black/20 text-fuchsia-500 focus:ring-fuchsia-400/40">
                    <label for="apply_recent_peak_filter" class="text-[11px] text-gray-600">Zirve Mesafesi filtresi (deneysel, 27 Temmuz) — fiyat son 5 günün zirvesinden %10'dan fazla aşağıdaysa aday elenir. Henüz canlıya taşınmadı, sadece karşılaştırma için.</label>
                </div>
                <div class="sm:col-span-4">
                    <button type="submit" class="w-full rounded-lg bg-cyan-500 hover:bg-cyan-400 transition-colors text-black text-sm font-semibold py-2.5">Testi Çalıştır</button>
                </div>
            </form>
        </div>

        <?php if ($result !== null): ?>
            <div class="relative glass-panel rounded-2xl p-5">
                <div class="font-mono-tech text-xs tracking-wider text-violet-600 mb-4">
                    SONUÇ: <?= htmlspecialchars($result['symbol'], ENT_QUOTES, 'UTF-8') ?>
                    | son <?= (int) $result['days'] ?> gün
                    | Kâr Al %<?= htmlspecialchars((string) $result['take_profit_percent'], ENT_QUOTES, 'UTF-8') ?>
                    / Zarar Kes %<?= htmlspecialchars((string) $result['stop_loss_percent'], ENT_QUOTES, 'UTF-8') ?>
                    | Komisyon %<?= htmlspecialchars((string) $result['fee_percent'], ENT_QUOTES, 'UTF-8') ?> (gidiş-dönüş, sonuçlara dahil)
                </div>

                <?php if ($result['total_trades'] === 0): ?>
                    <p class="text-sm text-gray-600">Bu dönemde hiçbir zaman filtrelerin hepsini geçen bir sinyal oluşmadı.</p>
                <?php else: ?>
                    <?php
                        $winRate = (float) $result['win_rate'];
                        $cumulative = (float) $result['cumulative_return_percent'];
                        $winRateColor = $winRate >= 50 ? 'text-emerald-600' : 'text-amber-600';
                        $cumulativeColor = $cumulative >= 0 ? 'text-emerald-600' : 'text-rose-600';
                    ?>
                    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                        <div class="rounded-lg border border-black/5 bg-black/[0.02] px-3 py-2.5">
                            <div class="text-[9px] text-gray-500 tracking-widest mb-1">TOPLAM İŞLEM</div>
                            <div class="font-mono-tech text-lg font-bold text-gray-900"><?= (int) $result['total_trades'] ?></div>
                        </div>
                        <div class="rounded-lg border border-black/5 bg-black/[0.02] px-3 py-2.5">
                            <div class="text-[9px] text-gray-500 tracking-widest mb-1">KAZANAN / KAYBEDEN</div>
                            <div class="font-mono-tech text-lg font-bold"><span class="text-emerald-600"><?= (int) $result['wins'] ?></span> / <span class="text-rose-600"><?= (int) $result['losses'] ?></span></div>
                        </div>
                        <div class="rounded-lg border border-black/5 bg-black/[0.02] px-3 py-2.5">
                            <div class="text-[9px] text-gray-500 tracking-widest mb-1">KAZANMA ORANI</div>
                            <div class="font-mono-tech text-lg font-bold <?= $winRateColor ?>">%<?= htmlspecialchars((string) $winRate, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="rounded-lg border border-black/5 bg-black/[0.02] px-3 py-2.5">
                            <div class="text-[9px] text-gray-500 tracking-widest mb-1">ORT. SONUÇLANMA</div>
                            <div class="font-mono-tech text-lg font-bold text-gray-900"><?= htmlspecialchars((string) $result['avg_hours_to_resolve'], ENT_QUOTES, 'UTF-8') ?>s</div>
                        </div>
                        <div class="rounded-lg border border-black/5 bg-black/[0.02] px-3 py-2.5">
                            <div class="text-[9px] text-gray-500 tracking-widest mb-1">KÜMÜLATİF GETİRİ</div>
                            <div class="font-mono-tech text-lg font-bold <?= $cumulativeColor ?>"><?= $cumulative >= 0 ? '+' : '' ?><?= htmlspecialchars((string) $cumulative, ENT_QUOTES, 'UTF-8') ?>%</div>
                        </div>
                    </div>

                    <p class="text-[10px] text-gray-400 mt-4">
                        Başa baş kazanma oranı (bu Kâr Al/Zarar Kes oranı + komisyon için):
                        %<?= htmlspecialchars(number_format((($result['stop_loss_percent'] + $result['fee_percent']) / ($result['take_profit_percent'] + $result['stop_loss_percent'])) * 100, 1), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
