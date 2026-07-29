<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActiveFuturesTrade;
use App\Models\ActiveTrade;
use App\Models\ApiKey;
use App\Models\Order;

// Otonom para harcayan TUM giris noktalarinin (AI Avci, Duyuru Avcisi, Akilli Para Kopyalayici)
// yeni bir islem denemeden once cagirmasi gereken TEK devre kesici. Boylece herhangi bir modulden
// gelen kayiplar, kullanicinin toplam risk profilini ortak olarak etkiler - paralel/ayri bir
// devre kesici mantigi kurulmaz (bir modulde yapilan duzeltmenin digerlerine yansimama riskini onler)
final class RiskManagerService
{
    // Ardisik kac kayip (stop-loss) sonrasi otomatik islemin tamamen durdurulacagi esik
    private const CONSECUTIVE_LOSS_LIMIT = 3;

    // 10 Temmuz'da tespit edilen bug: bu sinir olmadan, GUNLER once olmus 3 eski zarar (hesap o
    // tarihten beri hic yeni islem yapmamis olsa BILE) "guncel seri" sayilip, kilit her acildiginda
    // (24s dolunca ya da admin manuel actiginda) hesap HICBIR YENI islem yapmadan aninda yeniden
    // tetikleniyordu - hesap fiilen SONSUZA KADAR kilitli kalabiliyordu. Artik SADECE bu pencere
    // icinde kapanan zararlar "guncel seri" sayilir - COOLDOWN_HOURS ile AYNI (24s) tutuldu,
    // boylece "3 zarar -> 24s kilit" mantigi kendi icinde tutarli kalir
    private const CONSECUTIVE_LOSS_WINDOW_HOURS = 24;

    // Devre kesici tetiklendiginde soguma suresi (saat). 9 Temmuz TIAUSDT RCA'sinda, sadece
    // auto_trade_enabled bayragina guvenmenin yeterli OLMADIGI tespit edildi: bayrak (tarayici
    // onbellegi/tekrarlanan form kaydi gibi bilinmeyen bir yolla) devre kesiciden HEMEN SONRA
    // tekrar 1'e donebiliyordu, sistem de sorgusuzca tekrar islem acmaya calisiyordu. Bu sure,
    // bayragin durumundan TAMAMEN BAGIMSIZ, atlanamayan ikinci bir kilit saglar
    private const COOLDOWN_HOURS = 24;

    // Yeni bir alim denemeden once UC risk kontrolu yapar:
    // 0) Soguma suresi: devre kesici yakin zamanda tetiklendiyse, auto_trade_enabled formdan/
    //    onbellekten ne sekilde 1 yapilmis olursa olsun, soguma suresi dolmadan islem KESINLIKLE yapilmaz
    // 1) Ardisik zarar limiti: son N kapanan pozisyonun hepsi zararla kapandiysa TUM otonom
    //    modulleri (AI Avci + Duyuru Avcisi + Akilli Para + DCA) KALICI olarak durdurur + soguma baslatir
    // 2) Gunluk maksimum zarar limiti: kayan 24 saatlik penceredeki zarar, kullanicinin TOPLAM
    //    OZKAYNAGININ (bakiye + acik pozisyonlarin maliyeti - eski sabit tek-islem butcesi DEGIL)
    //    belirledigi yuzdesini asarsa bu turu GECICI olarak atlar (pencere zamanla kendiliginden acilir)
    // @param float $totalEquity Cagiran tarafin hesapladigi guncel bakiye + acik pozisyonlarin maliyeti
    // @return string|null Engellendiyse kullanicinin/admin'in okuyacagi sebep metni, engel yoksa null
    public function checkCircuitBreaker(int $userId, array $userKey, float $totalEquity): ?string
    {
        // Manuel Kill Switch: asagidaki TUM kontrollerden (soguma, ardisik zarar, gunluk limit) ONCE
        // gelir - kullanici/yonetici hicbir zarari beklemeden istedigi an botu durdurabilir. bkz.
        // ApiKey::setManualKillSwitch() yorumu - SURESIZDIR, sadece tekrar kapatilinca acilir
        if ((int) ($userKey['manual_kill_switch'] ?? 0) === 1) {
            return 'Manuel Kill Switch aktif - kullanıcı/yönetici otonom işlemleri elle durdurdu.';
        }

        $cooldownBlock = $this->checkCooldown($userId);

        if ($cooldownBlock !== null) {
            return $cooldownBlock;
        }

        // bkz. ApiKey::resetLossStreak() yorumu - admin bilincli olarak eski bir seriyi "unut" dediyse
        // (canli olayda: devre kesici surekli yeniden tetiklenip hicbir yeni islemin denenmesine FIRSAT
        // vermeyen kisir donguye karsi), bu tarihten ONCE kapanan islemler sayima hic girmez
        $lossStreakResetAt = ApiKey::getLossStreakResetAt($userId);
        $recentClosed = ActiveTrade::findRecentClosed($userId, self::CONSECUTIVE_LOSS_LIMIT, self::CONSECUTIVE_LOSS_WINDOW_HOURS, $lossStreakResetAt);

        if (count($recentClosed) === self::CONSECUTIVE_LOSS_LIMIT && $this->allClosedWithLoss($recentClosed)) {
            ApiKey::disableAutoTrade($userId);
            ApiKey::setCircuitBreakerCooldown($userId, self::COOLDOWN_HOURS);

            return sprintf(
                'Ardışık %d zarar kes (stop-loss) tetiklendi, tüm otonom modüller otomatik olarak durduruldu (%d saat soğuma).',
                self::CONSECUTIVE_LOSS_LIMIT,
                self::COOLDOWN_HOURS
            );
        }

        $maxDailyLossPercent = (float) $userKey['max_daily_loss_percent'];
        $rolling24hPnl = Order::calculateRolling24hPNL($userId);

        if ($rolling24hPnl >= 0) {
            return null;
        }

        $lossAmount = abs($rolling24hPnl);
        $maxLossAmount = $totalEquity * ($maxDailyLossPercent / 100);

        if ($lossAmount <= $maxLossAmount) {
            return null;
        }

        return sprintf(
            'Son 24 saatteki zarar (%.2f USDT), günlük limiti (%.2f USDT / toplam özkaynağın %%%.0f\'i) aştı, işlem geçici olarak durduruldu.',
            $lossAmount,
            $maxLossAmount,
            $maxDailyLossPercent
        );
    }

    // Futures (KISA/short) modulu icin ayni devre kesici mantigi: kaldiracli pozisyonlarin ardisik
    // kaybi, spot'takinden AYRI sayilir (bir short serisi kotu gitmesi, spot'un hakkini yemesin) -
    // ama gunluk zarar limiti Order tablosundaki TUM (spot+futures) gerceklesmis islemlerden hesaplanan
    // calculateRolling24hPNL uzerinden zaten PAYLASILIR, cunku futures kapanislari da orders'a notional
    // deger olarak yaziliyor (bkz. FuturesTradingService) - boylece toplam gunluk risk tek yerden gorulur
    public function checkFuturesCircuitBreaker(int $userId, array $userKey, float $totalEquity): ?string
    {
        // bkz. checkCircuitBreaker()'daki AYNI Manuel Kill Switch kontrolu - tek bayrak spot+futures'i BIRLIKTE durdurur
        if ((int) ($userKey['manual_kill_switch'] ?? 0) === 1) {
            return 'Manuel Kill Switch aktif - kullanıcı/yönetici otonom işlemleri elle durdurdu.';
        }

        $cooldownBlock = $this->checkCooldown($userId);

        if ($cooldownBlock !== null) {
            return $cooldownBlock;
        }

        // bkz. checkCircuitBreaker()'daki AYNI yorum - futures icin de gecerli
        $lossStreakResetAt = ApiKey::getLossStreakResetAt($userId);
        $recentClosed = ActiveFuturesTrade::findRecentClosed($userId, self::CONSECUTIVE_LOSS_LIMIT, self::CONSECUTIVE_LOSS_WINDOW_HOURS, $lossStreakResetAt);

        if (count($recentClosed) === self::CONSECUTIVE_LOSS_LIMIT && $this->allClosedWithLoss($recentClosed)) {
            ApiKey::disableAutoTrade($userId);
            ApiKey::setCircuitBreakerCooldown($userId, self::COOLDOWN_HOURS);

            return sprintf(
                'Futures: ardışık %d zarar kes (stop-loss) tetiklendi, tüm otonom modüller otomatik olarak durduruldu (%d saat soğuma).',
                self::CONSECUTIVE_LOSS_LIMIT,
                self::COOLDOWN_HOURS
            );
        }

        $maxDailyLossPercent = (float) $userKey['max_daily_loss_percent'];
        $rolling24hPnl = Order::calculateRolling24hPNL($userId);

        if ($rolling24hPnl >= 0) {
            return null;
        }

        $lossAmount = abs($rolling24hPnl);
        $maxLossAmount = $totalEquity * ($maxDailyLossPercent / 100);

        if ($lossAmount <= $maxLossAmount) {
            return null;
        }

        return sprintf(
            'Son 24 saatteki zarar (%.2f USDT), günlük limiti (%.2f USDT / toplam özkaynağın %%%.0f\'i) aştı, işlem geçici olarak durduruldu.',
            $lossAmount,
            $maxLossAmount,
            $maxDailyLossPercent
        );
    }

    // --- Flaş Çöküş Koruması (Global Crash Protection) ---
    // BTC'nin SADECE son FLASH_CRASH_LOOKBACK_MINUTES icinde bu yuzdeden fazla dustugu ani/sert
    // durumlarda, TUM otonom modullerin YENI alim/pozisyon acmasi DERHAL durdurulur - acik
    // pozisyonlarin yonetimi (Zarar Kes/Kar Al/Izleyen Zirh) bundan ETKILENMEZ, cagiran taraf
    // SADECE yeni giris denemesini atlamalidir. Mevcut checkCircuitBreaker() ile KARISTIRILMAMALI:
    // o kullanici bazli/gecmis kayip odakli KALICI bir kilittir (DB'ye yazilir); bu ise TAMAMEN
    // piyasa kosuluna bagli, kullanici bazli DEGILDIR ve DB'ye hicbir kilit YAZILMAZ - BTC
    // toparlanir toparlanmaz bir sonraki cron turunde otomatik acilir. Ayni sekilde mevcut
    // AutoTradeController::BTC_DOWNTREND_THRESHOLD_PERCENT (-%3/24 saat, aday bazli daha yavas bir
    // piyasa rejimi filtresi) ile de FARKLI bir mekanizma - bu cok daha KISA vadeli (1 saat) ve
    // DAHA SERT bir esik, tarama/OpenAI puanlama BASLAMADAN once TEK seferde kontrol edilmek uzere tasarlandi
    private const FLASH_CRASH_THRESHOLD_PERCENT = -5.0;
    private const FLASH_CRASH_LOOKBACK_MINUTES = 60;

    // @return string|null Flas cokus aktifse kullaniciya/admin'e gosterilecek sebep metni,
    // aktif degilse VEYA BTC verisi alinamadiysa (fail-open - bu kontrol asla yanlislikla
    // TUM sistemi kilitlememeli) null
    public function checkFlashCrash(): ?string
    {
        $scanner = new MarketScanner();
        $btcChangePercent = $scanner->getBtcRecentChangePercent(self::FLASH_CRASH_LOOKBACK_MINUTES);

        if ($btcChangePercent === null || $btcChangePercent > self::FLASH_CRASH_THRESHOLD_PERCENT) {
            return null;
        }

        return sprintf(
            'BTC son %d dakikada %%%.2f değişti (eşik %%%.1f) - piyasa sakinleşene kadar yeni işlem askıya alındı.',
            self::FLASH_CRASH_LOOKBACK_MINUTES,
            $btcChangePercent,
            self::FLASH_CRASH_THRESHOLD_PERCENT
        );
    }

    // 24 Temmuz'da eklendi: fiyatin 24 saatlik zirveye ne kadar yakin oldugunu kontrol eden SAF
    // (stateless) fonksiyon - DB/network bagimliligi yok, sadece aritmetik. Boylece hem canli
    // AutoTradeController aday tarama fazi HEM DE BacktestService gecmis mum verisiyle AYNI metodu
    // cagirip birebir tutarli sonuc alir (RSI esiginde oldugu gibi iki dosyada ayri ayri sabit
    // tanimlayip elle senkron tutma riskine girilmez). $high24h <= 0 ise (veri yok/hatali) fail-open
    // döner - bu kontrol asla yanlislikla TUM sistemi kilitlememeli
    public function isNear24hHigh(float $currentPrice, float $high24h, float $threshold = 99.0): bool
    {
        if ($high24h <= 0.0) {
            return false;
        }

        return ($currentPrice / $high24h) * 100.0 >= $threshold;
    }

    // 27 Temmuz'da eklendi: isNear24hHigh()'in MANTIKSAL TERSİ - fiyatin son N GUNUN zirvesinden
    // ne kadar ASAGIDA oldugunu kontrol eden SAF (stateless) fonksiyon. ZAMAUSDT #186 zarar
    // sonrasi (27 Temmuz) tespit edildi: giris fiyati son birkac gunun zirvesinden %12.7 asagidaydi
    // (yani "tepede alim" degil, "zirveden dususun ORTASINDA alim") - kisa vadeli (15dk/1h)
    // gostergeler saglikli gorunse bile motor bunu HIC gormuyordu. isNear24hHigh() GIBI hem canli
    // AutoTradeController HEM DE BacktestService AYNI metodu cagirmali - iki dosyada ayri mantik
    // YAZILMAZ. $recentPeak <= 0 ise (veri yok/hatali) fail-open doner - bu kontrol asla
    // yanlislikla TUM sistemi kilitlememeli. Yon dikkat: "gevsetmek" burada YUZDEYI YUKSELTMEK
    // demektir (isNear24hHigh'taki "gevsetmek = esigi DUSURMEK" ile TERS yonde calisir - bkz.
    // CLAUDE.md "Modüler Hard-Reject Fonksiyonları" yorumu, ayni hata iki kez yasanmamali)
    public function isFarBelowRecentPeak(float $currentPrice, float $recentPeak, float $maxDropPercent): bool
    {
        if ($recentPeak <= 0.0) {
            return false;
        }

        $dropPercent = (($recentPeak - $currentPrice) / $recentPeak) * 100.0;

        return $dropPercent >= $maxDropPercent;
    }

    private function allClosedWithLoss(array $trades): bool
    {
        foreach ($trades as $trade) {
            if ($trade['status'] !== 'closed_loss') {
                return false;
            }
        }

        return true;
    }

    // Devre kesici soguma suresini kontrol eder - HEM spot HEM futures kontrolu tarafindan ortak
    // kullanilir. auto_trade_enabled bayraginin KENDISINE guvenmez, dogrudan veritabanindan taze
    // bir okuma yapar - boylece bayrak hangi yoldan (form/onbellek/manuel) 1 yapilmis olursa olsun
    // bu kontrol atlanamaz. Soguma suresi dolmus veya hic tetiklenmemisse null doner (engel yok)
    private function checkCooldown(int $userId): ?string
    {
        // "Aktif mi" karsilastirmasi ApiKey::getCircuitBreakerStatus() icinde TAMAMEN MySQL'in
        // kendi saatiyle (NOW()) yapiliyor - PHP tarafinda ayrica strtotime()/time() KARSILASTIRMASI
        // YAPILMAZ (bkz. o metodun yorumu - PHP/MySQL saat dilimi farki gecmis bir sogumayi bile
        // "hala aktif" gosterebiliyordu)
        $cooldownUntilFormatted = ApiKey::getCircuitBreakerStatus($userId);

        if ($cooldownUntilFormatted === null) {
            // Kilit bittiyse (ya da hic olmadiysa) Telegram bildirim susturucusunu sifirla -
            // bir sonraki kilitlenmede sistem tekrar (sadece 1 kez/gun) bildirim atabilsin.
            // resetCooldownNotif() zaten sadece GERCEKTEN NULL olmayan satirlari gunceller,
            // bu yuzden temiz kullanicilar icin her cron turunda gereksiz yazma olmaz
            ApiKey::resetCooldownNotif($userId);

            return null;
        }

        return sprintf(
            'Devre kesici soğuma süresinde - %s tarihine kadar otonom işlem yapılamaz.',
            $cooldownUntilFormatted
        );
    }
}
