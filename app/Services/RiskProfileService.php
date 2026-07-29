<?php

declare(strict_types=1);

namespace App\Services;

// Risk profili tanimlamalari ve profil bazli esik degerleri
// Tek kaynak: controller, model ve auto-trade motoru hepsi buradan okur
final class RiskProfileService
{
    // Her profil icin sabitlenen motor esikleri
    // ai_score_threshold degerleri, tarama 5 dakikada bire sıklaştırıldıktan (bkz. CHANGELOG 1.15.6)
    // ve İzleyen Zırh'ın aktivasyonu sabit +%1.5'e/kilit breakeven+%0.3'e çekildikten (bkz. 1.15.3)
    // SONRA, her biri ~10 puan (~%10-14) asagi cekildi: motor artik "mukemmel" firsati beklerken
    // kilitlenmek yerine "yeterince iyi" adaylari da degerlendirip, daha sik ama daha SIKI korumali
    // (dar Zarar Kes + hizli kar kilitleme) denemeler yapiyor - eski degerler: safe=90, balanced=80,
    // aggressive=70. 15 Temmuz'da (islem sikligi cok dusuk bulunup) BIR KADEME DAHA indirildi -
    // eski degerler: safe=80, balanced=70, aggressive=60. Bu, RSI esiginin (70->75) ve
    // MAX_CANDIDATES_PER_RUN'in (5->10, bkz. AutoTradeController) gevsetilmesiyle BIRLIKTE
    // yapildi - islem hacmi/sikligi kasitli olarak artirildi, riski de orantili sekilde artirir.
    // 22 Temmuz'da (SentimentService "prop-trader" promptuna gecince, bkz. CHANGELOG 1.44.0) BIR
    // KADEME DAHA indirildi - eski degerler: safe=70, balanced=60, aggressive=50. Yeni prompt
    // "gercekten iyi" firsatlara dahi 60-65 bandinda cimri puan verecek sekilde tasarlandigindan,
    // eski esikler AI'nin kendi "iyi firsat" bandinin USTUNDE kalip botu fiilen susturuyordu. Bu
    // degisiklik SADECE yeni puanlama dagilimina UYUM icin yapildi - motorun risk istahi degismedi.
    // Mevcut kullanicilarin DB'deki (user_api_keys.ai_score_threshold) donmus degerleri de aynı anda
    // BACKFILL edildi (bkz. 1.44.1 dagitim notu) - aksi halde bu sabitler sadece profili YENIDEN
    // SECEN kullanicilari etkilerdi, zaten aktif kullanicilari degil
    //
    // 26 Temmuz'da ai_score_threshold UCU DE 70'E ESITLENDI (65/55/45 -> 70/70/70): gercek islem
    // verisinde (168 kapanan islem, calculateScoreBandBreakdown) 60-69 AI skor bandinin HER IKI
    // kullanicida da net zarar getirdigi (%33.3 kazanma orani) tespit edildi - "agresif" profilin
    // dusuk esigi (45) bu zayif bandi dogrudan aday havuzuna sokuyordu. Risk profili artik GIRIS
    // esigini degil, SADECE pozisyon icindeki risk istahini (stop_loss_percent, max_active_trades)
    // yonetiyor - "agresiflik" artik "zayif sinyale daha kolay girmek" degil, "pozisyon acildiktan
    // sonra daha genis SL/daha fazla eszamanli pozisyon kabul etmek" anlamina geliyor. Bu degisiklik
    // hem spot (AutoTradeController) hem futures'i (user_api_keys.ai_score_threshold ortak sutunu
    // uzerinden) etkiler - RiskProfileService ikisi icin de TEK kaynak oldugu icin kasitli.
    // globalMinThreshold() bu degisikligi otomatik yansitir (min(70,70,70)=70), ayrica dokunulmadi.
    public const PROFILES = [
        'safe' => [
            'label'              => 'Güvenli',
            'emoji'              => '🛡️',
            'ai_score_threshold' => 70,
            'stop_loss_percent'  => 2.0,
            'max_active_trades'  => 1,
        ],
        'balanced' => [
            'label'              => 'Dengeli',
            'emoji'              => '⚖️',
            'ai_score_threshold' => 70,
            'stop_loss_percent'  => 5.0,
            'max_active_trades'  => 3,
        ],
        'aggressive' => [
            'label'              => 'Agresif',
            'emoji'              => '🔥',
            'ai_score_threshold' => 70,
            'stop_loss_percent'  => 10.0,
            'max_active_trades'  => 5,
        ],
    ];

    // Profil bilgilerini doner; bilinmeyen profil icin 'balanced' fallback uygular
    public static function get(string $profile): array
    {
        return self::PROFILES[$profile] ?? self::PROFILES['balanced'];
    }

    // Tum kullanicilardaki en dusuk esik — hangi coinlerin aday havuzuna girmesi gerektigini belirler
    // run() bunu, birim basina minimum 1 agresif kullanici icin dahi coin sectirmeye kullanir
    public static function globalMinThreshold(): int
    {
        return min(array_column(self::PROFILES, 'ai_score_threshold'));
    }

    public static function isValid(string $profile): bool
    {
        return array_key_exists($profile, self::PROFILES);
    }

    // 12 Temmuz'da tespit edilen "sessiz ezme" bug'ı için: Risk Profili kartına tıklamak,
    // kullanıcının "AI Avcı Ayarları" formundan ELLE girdiği bir Zarar Kes değerini hiçbir uyarı
    // olmadan profilin sabit değerine (ör. Agresif=10.0) sıfırlıyordu. Bu kontrol, mevcut bir
    // değerin 3 STANDART profil değerinden HİÇBİRİNE denk gelmediğini (yani kullanıcı tarafından
    // özelleştirildiğini) tespit eder - DashboardController bunu görürse önce onay ister
    public static function isCustomStopLoss(float $stopLossPercent): bool
    {
        foreach (self::PROFILES as $profile) {
            if (abs($profile['stop_loss_percent'] - $stopLossPercent) < 0.001) {
                return false;
            }
        }

        return true;
    }
}
