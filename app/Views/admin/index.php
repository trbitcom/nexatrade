<?php

declare(strict_types=1);

use App\Core\Url;

/** @var array $stats */
/** @var array $users */
/** @var array $recentLogs */
/** @var array $settings */
/** @var float $listingSniperMinVolume */
/** @var float $smartMoneyMinTransfer */
/** @var string $watchedWalletsText */
/** @var string|null $successMessage */
/** @var string|null $errorMessage */

$currentUserId = (int) \App\Core\Session::get('user_id');

$statusLabels = ['active' => 'Aktif', 'passive' => 'Onay Bekliyor', 'banned' => 'Banlı'];
$statusClasses = [
    'active' => 'bg-emerald-400/10 text-emerald-600',
    'passive' => 'bg-amber-400/10 text-amber-600',
    'banned' => 'bg-rose-400/10 text-rose-600',
];
$roleClasses = [
    'admin' => 'bg-violet-400/10 text-violet-600',
    'user' => 'bg-gray-400/10 text-gray-600',
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>NexaTrade | Admin Paneli</title>
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
            <span class="font-mono-tech text-[10px] text-gray-500 tracking-widest border border-black/10 rounded px-1.5 py-0.5 ml-1">ADMIN</span>
        </div>
        <div class="flex items-center gap-3">
            <a href="<?= htmlspecialchars(Url::to('/admin/backtest'), ENT_QUOTES, 'UTF-8') ?>" class="font-mono-tech text-xs px-3 py-1.5 rounded-lg border border-cyan-400/30 text-cyan-600 hover:bg-cyan-400/10 hover:border-cyan-400/50 transition-colors">📊 BACKTEST</a>
            <a href="<?= htmlspecialchars(Url::to('/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="font-mono-tech text-xs px-3 py-1.5 rounded-lg border border-black/10 text-gray-600 hover:bg-black/5 hover:text-gray-900 transition-colors">← TERMİNAL</a>
            <a href="<?= htmlspecialchars(Url::to('/logout'), ENT_QUOTES, 'UTF-8') ?>" class="font-mono-tech text-xs px-3 py-1.5 rounded-lg border border-black/10 text-gray-600 hover:bg-black/5 hover:text-gray-900 transition-colors">ÇIKIŞ</a>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-5 py-6 space-y-5">
        <?php if (!empty($successMessage)): ?>
            <div class="rounded-lg border border-emerald-400/30 bg-emerald-400/10 text-emerald-600 text-sm px-4 py-2.5"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if (!empty($errorMessage)): ?>
            <div class="rounded-lg border border-rose-400/30 bg-rose-400/10 text-rose-600 text-sm px-4 py-2.5"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <!-- Platform istatistikleri -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="relative glass-panel rounded-2xl p-4">
                <div class="font-mono-tech text-[10px] tracking-widest text-gray-500">TOPLAM KULLANICI</div>
                <div class="font-mono-tech text-2xl font-bold text-gray-900 mt-1"><?= (int) $stats['total_users'] ?></div>
            </div>
            <div class="relative glass-panel rounded-2xl p-4">
                <div class="font-mono-tech text-[10px] tracking-widest text-gray-500">AI AVCI AKTİF</div>
                <div class="font-mono-tech text-2xl font-bold text-cyan-600 mt-1"><?= (int) $stats['auto_trade_users'] ?></div>
            </div>
            <div class="relative glass-panel rounded-2xl p-4">
                <div class="font-mono-tech text-[10px] tracking-widest text-gray-500">AÇIK POZİSYON</div>
                <div class="font-mono-tech text-2xl font-bold text-violet-600 mt-1"><?= (int) $stats['open_positions'] ?></div>
            </div>
            <div class="relative glass-panel rounded-2xl p-4">
                <div class="font-mono-tech text-[10px] tracking-widest text-gray-500">BUGÜN TAMAMLANAN</div>
                <div class="font-mono-tech text-2xl font-bold text-emerald-600 mt-1"><?= (int) $stats['trades_today'] ?></div>
            </div>
        </div>

        <!-- AI Skor Bandina Gore Performans -->
        <div class="relative glass-panel rounded-2xl overflow-hidden">
            <div class="px-5 py-3 border-b border-black/5 flex items-center justify-between">
                <span class="font-mono-tech text-xs tracking-wider text-gray-800">AI SKOR BANDINA GÖRE PERFORMANS</span>
                <span class="font-mono-tech text-[10px] text-gray-500 tracking-widest">TÜM KULLANICILAR — KÜMÜLATİF</span>
            </div>
            <div class="overflow-x-auto thin-scroll">
                <table class="w-full font-mono-tech text-xs">
                    <thead>
                        <tr class="text-left text-gray-500 text-[9px] tracking-widest border-b border-black/5">
                            <th class="font-medium px-5 py-2">SKOR BANDI</th>
                            <th class="font-medium px-2 py-2">İŞLEM</th>
                            <th class="font-medium px-2 py-2">KAZANAN</th>
                            <th class="font-medium px-2 py-2 w-1/3">KAZANMA ORANI</th>
                            <th class="font-medium px-5 py-2 text-right">NET PNL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scoreBandBreakdown as $band): ?>
                            <tr class="border-b border-black/5">
                                <td class="px-5 py-2.5 text-gray-800 font-semibold"><?= htmlspecialchars($band['score_band'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-2 py-2.5 text-gray-600"><?= $band['total_trades'] ?></td>
                                <td class="px-2 py-2.5 text-gray-600"><?= $band['wins'] ?></td>
                                <td class="px-2 py-2.5">
                                    <?php if ($band['win_rate'] === null): ?>
                                        <span class="text-gray-400">—</span>
                                    <?php else: ?>
                                        <div class="flex items-center gap-2">
                                            <div class="flex-1 h-1.5 rounded-full bg-black/5 overflow-hidden">
                                                <div class="h-full rounded-full <?= $band['win_rate'] >= 50 ? 'bg-emerald-500' : 'bg-rose-500' ?>" style="width: <?= min(100, max(0, $band['win_rate'])) ?>%"></div>
                                            </div>
                                            <span class="<?= $band['win_rate'] >= 50 ? 'text-emerald-600' : 'text-rose-600' ?> w-10 text-right"><?= number_format($band['win_rate'], 1) ?>%</span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-2.5 text-right <?= $band['net_profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>">
                                    <?= $band['net_profit'] >= 0 ? '+' : '' ?>$<?= number_format($band['net_profit'], 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Kullanicilar -->
        <div class="relative glass-panel rounded-2xl overflow-hidden">
            <div class="px-5 py-3 border-b border-black/5 flex items-center justify-between">
                <span class="font-mono-tech text-xs tracking-wider text-gray-800">KAYITLI KULLANICILAR</span>
                <span class="font-mono-tech text-[10px] text-gray-500 tracking-widest"><?= count($users) ?> KAYIT</span>
            </div>
            <div class="overflow-x-auto thin-scroll">
                <table class="w-full font-mono-tech text-xs">
                    <thead>
                        <tr class="text-left text-gray-500 text-[9px] tracking-widest border-b border-black/5">
                            <th class="font-medium px-5 py-2">AD</th>
                            <th class="font-medium px-2 py-2">E-POSTA</th>
                            <th class="font-medium px-2 py-2">ROL</th>
                            <th class="font-medium px-2 py-2">DURUM</th>
                            <th class="font-medium px-2 py-2">API KEY</th>
                            <th class="font-medium px-2 py-2">AI AVCI</th>
                            <th class="font-medium px-2 py-2">BÜTÇE %</th>
                            <th class="font-medium px-2 py-2">AÇIK POZ.</th>
                            <th class="font-medium px-2 py-2">DEVRE KESİCİ</th>
                            <th class="font-medium px-2 py-2">RİSK ONAYI</th>
                            <th class="font-medium px-2 py-2">KAYIT</th>
                            <th class="font-medium px-5 py-2">İŞLEM</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <?php
                            $uid = (int) $u['id'];
                            $isSelf = $uid === $currentUserId;
                            ?>
                            <tr class="border-b border-black/5">
                                <td class="px-5 py-2.5 text-gray-800 font-semibold"><?= htmlspecialchars((string) $u['name'], ENT_QUOTES, 'UTF-8') ?><?= $isSelf ? ' <span class="text-violet-600">(sen)</span>' : '' ?></td>
                                <td class="px-2 py-2.5 text-gray-600"><?= htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-2 py-2.5"><span class="rounded px-1.5 py-0.5 <?= $roleClasses[$u['role']] ?? 'bg-gray-400/10 text-gray-600' ?>"><?= htmlspecialchars((string) $u['role'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="px-2 py-2.5"><span class="rounded px-1.5 py-0.5 <?= $statusClasses[$u['status']] ?? 'bg-gray-400/10 text-gray-600' ?>"><?= $statusLabels[$u['status']] ?? $u['status'] ?></span></td>
                                <td class="px-2 py-2.5"><?= ((int) $u['has_api_key']) === 1 ? '<span class="text-emerald-600">var</span>' : '<span class="text-gray-400">yok</span>' ?></td>
                                <td class="px-2 py-2.5"><?= ((int) $u['auto_trade_enabled']) === 1 ? '<span class="text-cyan-600">açık</span>' : '<span class="text-gray-400">kapalı</span>' ?></td>
                                <td class="px-2 py-2.5 text-gray-600" title="Bakiyenin bu yüzdesi her işlemde dinamik olarak kullanılır">%<?= number_format((float) $u['auto_trade_budget_percent'], 1) ?></td>
                                <td class="px-2 py-2.5 text-gray-600"><?= (int) $u['open_positions'] ?></td>
                                <td class="px-2 py-2.5">
                                    <?php if (((int) ($u['circuit_breaker_active'] ?? 0)) === 1): ?>
                                        <span class="rounded px-1.5 py-0.5 bg-rose-400/10 text-rose-600" title="Bitiş: <?= htmlspecialchars((string) $u['circuit_breaker_until_formatted'], ENT_QUOTES, 'UTF-8') ?>">Kilitli</span>
                                    <?php else: ?>
                                        <span class="text-gray-400">açık</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-2 py-2.5">
                                    <?php if (((int) $u['is_risk_accepted']) === 1 && !empty($u['risk_accepted_at'])): ?>
                                        <span class="text-emerald-600" title="Onaylandı: <?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $u['risk_accepted_at'])), ENT_QUOTES, 'UTF-8') ?>">✓ <?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $u['risk_accepted_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php else: ?>
                                        <span class="rounded px-1.5 py-0.5 bg-rose-400/10 text-rose-600">Onaylanmadı</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-2 py-2.5 text-gray-500"><?= htmlspecialchars(date('d.m.Y', strtotime((string) $u['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-5 py-2.5">
                                    <div class="flex items-center gap-1.5">
                                        <?php // Devre Kesici reset'i BILEREK $isSelf korumasinin DISINDA: rol/durum/silme'nin
                                        // aksine kendi Devre Kesici'ni acmanin sistemden kilitlenme riski YOK, sadece
                                        // kendi otonom islemini devam ettirir - admin'in KENDI hesabi kilitlenirse
                                        // (herkes gibi ayni per-user mekanizmaya tabi) baska bir admin'e ihtiyac duymadan
                                        // acabilmesi gerekir (25 Temmuz'da canli tespit edildi: buton $isSelf icine
                                        // yanlislikla yerlesip kendi hesabinda hic gorunmuyordu) ?>
                                        <?php if (((int) ($u['circuit_breaker_active'] ?? 0)) === 1): ?>
                                            <button type="button" onclick="resetCircuitBreaker(<?= $uid ?>, '<?= htmlspecialchars(addslashes((string) $u['name']), ENT_QUOTES, 'UTF-8') ?>')" class="font-mono-tech text-[9px] text-rose-600 border border-rose-400/30 rounded px-1.5 py-0.5 hover:bg-rose-400/10 transition-colors">Kesiciyi Aç</button>
                                        <?php endif; ?>
                                        <?php if ($isSelf): ?>
                                            <span class="text-gray-400">—</span>
                                        <?php else: ?>
                                            <form method="POST" action="<?= htmlspecialchars(Url::to('/admin/users/status'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-1">
                                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                                <select name="status" onchange="this.form.submit()" class="bg-gray-100 border border-black/10 rounded px-1 py-0.5 text-[10px] text-gray-700">
                                                    <?php foreach (['active' => 'Aktif', 'passive' => 'Onay Bekliyor', 'banned' => 'Banlı'] as $val => $label): ?>
                                                        <option value="<?= $val ?>" <?= $u['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                            <form method="POST" action="<?= htmlspecialchars(Url::to('/admin/users/role'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-1">
                                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                                <select name="role" onchange="this.form.submit()" class="bg-gray-100 border border-black/10 rounded px-1 py-0.5 text-[10px] text-gray-700">
                                                    <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>Kullanıcı</option>
                                                    <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                </select>
                                            </form>
                                            <button type="button" onclick="resetUserPassword(<?= $uid ?>)" class="font-mono-tech text-[9px] text-amber-600 border border-amber-400/30 rounded px-1.5 py-0.5 hover:bg-amber-400/10 transition-colors">Şifre Sıfırla</button>
                                            <button type="button" onclick="deleteUser(<?= $uid ?>, '<?= htmlspecialchars(addslashes((string) $u['name']), ENT_QUOTES, 'UTF-8') ?>')" class="font-mono-tech text-[9px] text-rose-600 border border-rose-400/30 rounded px-1.5 py-0.5 hover:bg-rose-400/10 transition-colors">Sil</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Sifre sifirlama / kullanici silme icin gizli formlar - JS ile doldurup gonderilir -->
        <form id="resetPasswordForm" method="POST" action="<?= htmlspecialchars(Url::to('/admin/users/reset-password'), ENT_QUOTES, 'UTF-8') ?>" class="hidden">
            <input type="hidden" name="user_id" id="resetPasswordUserId">
            <input type="hidden" name="new_password" id="resetPasswordValue">
        </form>
        <form id="deleteUserForm" method="POST" action="<?= htmlspecialchars(Url::to('/admin/users/delete'), ENT_QUOTES, 'UTF-8') ?>" class="hidden">
            <input type="hidden" name="user_id" id="deleteUserId">
        </form>
        <form id="resetCircuitBreakerForm" method="POST" action="<?= htmlspecialchars(Url::to('/admin/users/reset-circuit-breaker'), ENT_QUOTES, 'UTF-8') ?>" class="hidden">
            <input type="hidden" name="user_id" id="resetCircuitBreakerUserId">
        </form>

        <div class="grid md:grid-cols-2 gap-5">
            <!-- Genel Ayarlar -->
            <div class="relative glass-panel rounded-2xl p-5">
                <div class="font-mono-tech text-xs tracking-wider text-gray-800 mb-1">GENEL AYARLAR</div>
                <p class="text-[11px] text-gray-500 mb-4">Boş bırakılan alanlar değiştirilmez. Kaydedilen değerler dosyadaki (config/app.php) varsayılanın önüne geçer.</p>

                <form method="POST" action="<?= htmlspecialchars(Url::to('/admin/settings'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-4">
                    <?php foreach ([
                        'openai_api_key' => 'OPENAI API KEY',
                        'groq_api_key' => 'GROQ API KEY (1. Yedek AI Sağlayıcı, opsiyonel)',
                        'gemini_api_key' => 'GEMINI API KEY (2. Yedek AI Sağlayıcı, opsiyonel)',
                        'webhook_token' => 'WEBHOOK TOKEN',
                        'auto_trade_token' => 'CRON / AUTO-TRADE TOKEN',
                        'telegram_bot_token' => 'TELEGRAM BOT TOKEN',
                        'telegram_admin_chat_id' => 'TELEGRAM ADMIN CHAT ID',
                        'telegram_bot_username' => 'TELEGRAM BOT KULLANICI ADI',
                        'telegram_webhook_secret' => 'TELEGRAM WEBHOOK SECRET',
                        'coingecko_api_key' => 'COINGECKO API KEY (Sosyal Radar, opsiyonel)',
                        'etherscan_api_key' => 'ETHERSCAN API KEY (Akıllı Para)',
                        'listing_sniper_token' => 'DUYURU AVCISI CRON TOKEN',
                        'smart_money_token' => 'AKILLI PARA CRON TOKEN',
                        'daily_summary_token' => 'GECE YARISI ÖZETİ CRON TOKEN (günde 1 kez, 00:00)',
                    ] as $field => $label): ?>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label for="<?= $field ?>" class="block text-[10px] tracking-widest text-gray-500"><?= $label ?></label>
                                <span class="text-[9px] text-gray-400"><?= htmlspecialchars($settings[$field]['source'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <?php if ($settings[$field]['preview'] !== null): ?>
                                <p class="font-mono-tech text-[10px] text-gray-500 mb-1">Kayıtlı: <span class="text-gray-700"><?= htmlspecialchars($settings[$field]['preview'], ENT_QUOTES, 'UTF-8') ?></span></p>
                            <?php endif; ?>
                            <input type="text" id="<?= $field ?>" name="<?= $field ?>" placeholder="Değiştirmek için yeni değer girin" class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech placeholder:text-gray-400 focus:outline-none focus:border-violet-400/60 focus:ring-1 focus:ring-violet-400/40">
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" class="w-full rounded-lg bg-violet-500 hover:bg-violet-400 transition-colors text-white text-sm font-semibold py-2.5">Ayarları Kaydet</button>
                </form>
            </div>

            <!-- Son Cron Calistirmalari -->
            <div class="relative glass-panel rounded-2xl overflow-hidden flex flex-col">
                <div class="px-5 py-3 border-b border-black/5">
                    <span class="font-mono-tech text-xs tracking-wider text-gray-800">SON BOT ÇALIŞTIRMALARI</span>
                </div>
                <div class="overflow-y-auto thin-scroll max-h-96 px-5 py-3 space-y-2">
                    <?php if (empty($recentLogs)): ?>
                        <p class="font-mono-tech text-xs text-gray-500">Henüz cron çalışmadı</p>
                    <?php else: ?>
                        <?php foreach ($recentLogs as $log): ?>
                            <?php
                            $scanned = json_decode((string) $log['scanned_symbols'], true) ?: [];
                            ?>
                            <div class="rounded-lg border border-black/5 bg-black/[0.02] px-3 py-2">
                                <div class="flex items-center justify-between">
                                    <span class="font-mono-tech text-[10px] text-gray-500"><?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $log['run_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="font-mono-tech text-[10px] text-gray-500"><?= count($scanned) ?> coin tarandı</span>
                                </div>
                                <div class="font-mono-tech text-xs mt-1">
                                    <?php if ($log['selected_symbol'] !== null): ?>
                                        <span class="text-emerald-600"><?= htmlspecialchars((string) $log['selected_symbol'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="text-gray-500">skor <?= (int) $log['selected_score'] ?> ·</span>
                                        <span class="text-gray-600"><?= (int) $log['positions_opened'] ?> pozisyon açıldı</span>
                                    <?php else: ?>
                                        <span class="text-gray-400">sinyal bulunamadı</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Gelismis Modul Ayarlari: Duyuru Avcisi likidite esigi, Akilli Para transfer esigi + izlenen cuzdanlar -->
        <div class="relative glass-panel rounded-2xl p-5 mt-5">
            <div class="font-mono-tech text-xs tracking-wider text-fuchsia-600 mb-1">GELİŞMİŞ MODÜL AYARLARI</div>
            <p class="text-[11px] text-gray-500 mb-4">Duyuru Avcısı ve Akıllı Para Kopyalayıcı modüllerinin risk eşikleri ve izleme listesi. Yukarıdaki API anahtarları girilmeden bu modüller çalışmaz.</p>

            <form method="POST" action="<?= htmlspecialchars(Url::to('/admin/settings'), ENT_QUOTES, 'UTF-8') ?>" class="grid md:grid-cols-2 gap-5">
                <div class="md:col-span-2">
                    <label for="market_scanner_whitelist" class="block text-[10px] tracking-widest text-cyan-600 mb-1">AI AVCI — PİYASA TARAMA BEYAZ LİSTESİ (virgülle ayrılmış semboller, ör. <span class="text-gray-600">SYNUSDT,BELUSDT,TLMUSDT</span>)</label>
                    <input type="text" id="market_scanner_whitelist" name="market_scanner_whitelist" value="<?= htmlspecialchars($marketScannerWhitelist, ENT_QUOTES, 'UTF-8') ?>" placeholder="Boş = kısıtlama yok, tüm piyasa taranır" class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech placeholder:text-gray-400 focus:outline-none focus:border-cyan-400/60 focus:ring-1 focus:ring-cyan-400/40">
                    <p class="text-[10px] text-gray-400 mt-1">Doldurulursa AI Avcı SADECE buradaki sembolleri tarar (backtestte kanıtlanmış coinlere odaklanmak için). Boş bırakılırsa tüm piyasa taranmaya devam eder.</p>
                </div>
                <div class="md:col-span-2">
                    <label for="market_scanner_blacklist" class="block text-[10px] tracking-widest text-rose-600 mb-1">AI AVCI — KARA LİSTE (virgülle ayrılmış semboller, ör. <span class="text-gray-600">BANKUSDT,DEXEUSDT</span>)</label>
                    <input type="text" id="market_scanner_blacklist" name="market_scanner_blacklist" value="<?= htmlspecialchars($marketScannerBlacklist, ENT_QUOTES, 'UTF-8') ?>" placeholder="Boş = kara liste yok" class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech placeholder:text-gray-400 focus:outline-none focus:border-rose-400/60 focus:ring-1 focus:ring-rose-400/40">
                    <p class="text-[10px] text-gray-400 mt-1">Buradaki semboller beyaz liste durumundan (dolu/boş) BAĞIMSIZ, HER ZAMAN taramanın dışında tutulur - gerçek işlem geçmişinde tutarlı zararlı çıkan coinler için.</p>
                </div>
                <div class="md:col-span-2">
                    <label for="decision_motor" class="block text-[10px] tracking-widest text-amber-600 mb-1">AI AVCI — KARAR MOTORU</label>
                    <select id="decision_motor" name="decision_motor" class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-amber-400/60 focus:ring-1 focus:ring-amber-400/40">
                        <option value="ai" <?= $decisionMotor === 'ai' ? 'selected' : '' ?>>AI (GPT Karar Skoru — varsayılan)</option>
                        <option value="deterministic" <?= $decisionMotor === 'deterministic' ? 'selected' : '' ?>>Deterministik Motor (RSI+MACD+Hacim, GPT-siz)</option>
                    </select>
                    <p class="text-[10px] text-gray-400 mt-1">"Deterministik Motor" seçilirse alım kararını GPT yerine TechnicalScoreEngine (teknik skor ≥70 + MACD olumlu + hacim düşmüyor) verir. GPT/AI Karar Skoru bu modda da Gölge Modda hesaplanmaya devam eder (loglarda görünür), sadece asıl karara katılmaz. 171 işlemlik geçmiş veriyle doğrulandı: bu formülü geçen işlemler başabaş, geçemeyenler platformun net zararının neredeyse tamamını oluşturuyordu.</p>
                </div>
                <div class="md:col-span-2">
                    <label for="scanner_coin_limit" class="block text-[10px] tracking-widest text-cyan-600 mb-1">AI AVCI — TARAMA KAPASİTESİ (aday coin sayısı)</label>
                    <input type="number" step="1" min="1" max="100" id="scanner_coin_limit" name="scanner_coin_limit" value="<?= htmlspecialchars((string) $scannerCoinLimit, ENT_QUOTES, 'UTF-8') ?>" class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-cyan-400/60 focus:ring-1 focus:ring-cyan-400/40">
                    <p class="text-[10px] text-gray-400 mt-1">Her taramada hacim/fiyat değişimine göre filtrelenip GPT'ye gönderilen aday coin sayısı. Daha yüksek değer fırsat bulma ihtimalini artırır ama tarama süresini/API isteği sayısını da artırır.</p>
                </div>
                <div class="space-y-4">
                    <div>
                        <label for="listing_sniper_min_quote_volume" class="block text-[10px] tracking-widest text-rose-600 mb-1">DUYURU AVCISI — ASGARİ 24S İŞLEM HACMİ (USDT)</label>
                        <input type="number" step="100" min="0" id="listing_sniper_min_quote_volume" name="listing_sniper_min_quote_volume" value="<?= htmlspecialchars((string) $listingSniperMinVolume, ENT_QUOTES, 'UTF-8') ?>" class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-rose-400/60 focus:ring-1 focus:ring-rose-400/40">
                        <p class="text-[10px] text-gray-400 mt-1">Yeni listelenen pariteler bu hacmi geçmeden alım yapılmaz.</p>
                    </div>
                    <div>
                        <label for="smart_money_min_transfer_usd" class="block text-[10px] tracking-widest text-gray-500 mb-1">AKILLI PARA — ASGARİ TRANSFER BÜYÜKLÜĞÜ (USD)</label>
                        <input type="number" step="1000" min="0" id="smart_money_min_transfer_usd" name="smart_money_min_transfer_usd" value="<?= htmlspecialchars((string) $smartMoneyMinTransfer, ENT_QUOTES, 'UTF-8') ?>" class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-sm text-gray-800 font-mono-tech focus:outline-none focus:border-violet-400/60 focus:ring-1 focus:ring-violet-400/40">
                        <p class="text-[10px] text-gray-400 mt-1">Bu değerin altındaki whale hareketleri gürültü kabul edilip yok sayılır.</p>
                    </div>
                </div>
                <div>
                    <label for="smart_money_watched_wallets" class="block text-[10px] tracking-widest text-gray-500 mb-1">İZLENEN BALİNA CÜZDANLARI (her satıra bir tane: <span class="text-gray-600">0xAdres|Etiket</span>)</label>
                    <textarea id="smart_money_watched_wallets" name="smart_money_watched_wallets" rows="6" placeholder="0x1234...abcd|Whale #1&#10;0x5678...efgh|Fon Cüzdanı" class="w-full bg-gray-100 border border-black/10 rounded-lg px-3 py-2 text-xs text-gray-800 font-mono-tech placeholder:text-gray-400 focus:outline-none focus:border-violet-400/60 focus:ring-1 focus:ring-violet-400/40"><?= htmlspecialchars($watchedWalletsText, ENT_QUOTES, 'UTF-8') ?></textarea>
                    <p class="text-[10px] text-gray-400 mt-1">Etiket isteğe bağlıdır. Tamamen boş kaydedilirse liste temizlenir.</p>
                </div>
                <div class="md:col-span-2">
                    <button type="submit" class="w-full rounded-lg bg-fuchsia-500 hover:bg-fuchsia-400 transition-colors text-black text-sm font-semibold py-2.5">Gelişmiş Modül Ayarlarını Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function resetUserPassword(userId) {
            var newPassword = prompt('Kullanıcı #' + userId + ' için yeni şifre girin (en az 8 karakter):');
            if (newPassword === null) { return; }
            if (newPassword.length < 8) {
                alert('Şifre en az 8 karakter olmalıdır.');
                return;
            }
            document.getElementById('resetPasswordUserId').value = userId;
            document.getElementById('resetPasswordValue').value = newPassword;
            document.getElementById('resetPasswordForm').submit();
        }

        function resetCircuitBreaker(userId, name) {
            var confirmed = confirm(
                '"' + name + '" için Devre Kesici\'yi manuel olarak açmak istediğinize emin misiniz? ' +
                'Bu, otomatik işlemi hemen yeniden başlatır ve geçmiş ardışık zarar serisini ileriye ' +
                'dönük sayımdan çıkarır (geçmiş kayıtlara dokunulmaz).'
            );
            if (!confirmed) { return; }
            document.getElementById('resetCircuitBreakerUserId').value = userId;
            document.getElementById('resetCircuitBreakerForm').submit();
        }

        function deleteUser(userId, name) {
            var confirmed = confirm(
                '"' + name + '" adlı kullanıcıyı ve TÜM verilerini (API anahtarları, işlem geçmişi, ' +
                'açık pozisyonlar) KALICI OLARAK silmek istediğinize emin misiniz? Bu işlem geri alınamaz.'
            );
            if (!confirmed) { return; }
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUserForm').submit();
        }
    </script>
</body>
</html>
