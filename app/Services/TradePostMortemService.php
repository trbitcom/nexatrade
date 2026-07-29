<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;

// Trade Post-Mortem (Islem Otopsisi): zararla kapanan bir pozisyon icin sadece PNL yazip gecmek
// yerine kok nedenini tespit eder. Once UCUZ, deterministik kurallar (hiz/BTC etkisi/zirh-slippage)
// sirayla denenir - GPT gerektirmezler, ASLA basarisiz olmazlar. Hicbiri eslesmezse SON CARE olarak
// SentimentService::explainLoss() ile TEK cumlelik bir AI yorumu istenir (o da fail-open'dir).
// Cagiran taraf (AutoTradeController) bu analizi pozisyon zaten KAPANDIKTAN SONRA cagirir - analiz
// hicbir zaman kapanisi bloklamaz/geciktirmez, en kotu ihtimalle genel bir metinle sonuclanir
final class TradePostMortemService
{
    // Pozisyon acilistan bu kadar dakikadan KISA surede Zarar Kes'e carparsa "ani volatilite" sayilir
    private const RAPID_LOSS_MINUTES = 15.0;

    // BTC'nin kapanis anindaki kisa vadeli (son BTC_LOOKBACK_MINUTES) degisimi bu esigin ALTINDAYSA
    // (ör. -%1.5, esik -%1.0'dan daha dusuk/negatif) "genel piyasa dususu" sebebi one cikar
    private const BTC_DROP_THRESHOLD_PERCENT = -1.0;
    private const BTC_LOOKBACK_MINUTES = 15;

    private readonly MarketScanner $scanner;
    private readonly SentimentService $sentiment;

    public function __construct()
    {
        $this->scanner = new MarketScanner();
        $this->sentiment = new SentimentService();
    }

    // @param array $trade active_trades satirinin TAMAMI (opened_at, trailing_stop_stage vb. icerir)
    public function analyze(array $trade): string
    {
        $pair = (string) $trade['pair'];

        $rapidLossReason = $this->checkRapidLoss($trade);
        if ($rapidLossReason !== null) {
            return $rapidLossReason;
        }

        $btcReason = $this->checkBtcInfluence();
        if ($btcReason !== null) {
            return $btcReason;
        }

        $shieldReason = $this->checkShieldSlippage($trade);
        if ($shieldReason !== null) {
            return $shieldReason;
        }

        try {
            return $this->sentiment->explainLoss($pair);
        } catch (Throwable $e) {
            // SentimentService zaten fail-open'dir (exception firlatmaz), ama bu analiz hicbir
            // sekilde pozisyon kapanisini etkilememeli - son bir guvenlik agi olarak burada da yakalanir
            error_log("NexaTrade TradePostMortemService: {$pair} icin AI yedegi basarisiz - " . $e->getMessage());

            return 'Zarar sebebi net olarak tespit edilemedi.';
        }
    }

    // Hiz Kontrolu: pozisyon acilistan cok kisa sure sonra (varsayilan <15dk) Zarar Kes'e carptiysa,
    // bu genellikle ani/sert bir fiyat igne'sidir (flash wick) - baska bir kurala bakmaya gerek yok
    private function checkRapidLoss(array $trade): ?string
    {
        $openedAt = strtotime((string) ($trade['opened_at'] ?? ''));

        if ($openedAt === false) {
            return null;
        }

        $minutesOpen = (time() - $openedAt) / 60;

        if ($minutesOpen < self::RAPID_LOSS_MINUTES) {
            return sprintf(
                'Ani Volatilite: Pozisyon açıldıktan sadece %.1f dakika sonra Zarar Kes\'e çarptı - fiyat muhtemelen ani/sert bir "iğne" hareketi yaptı.',
                max(0.0, $minutesOpen)
            );
        }

        return null;
    }

    // Lider Etkisi: kapanis aninda BTC kendisi de kisa vadede belirgin dususteyse, coin muhtemelen
    // kendi sinyalinden bagimsiz olarak BTC ile birlikte suruklendi (yuksek korelasyon)
    private function checkBtcInfluence(): ?string
    {
        $btcChange = $this->scanner->getBtcRecentChangePercent(self::BTC_LOOKBACK_MINUTES);

        if ($btcChange !== null && $btcChange <= self::BTC_DROP_THRESHOLD_PERCENT) {
            return sprintf(
                'BTC Düşüş Dalgalanmasına Yakalandı: Kapanış anında BTC son %d dakikada %%%.2f değişti.',
                self::BTC_LOOKBACK_MINUTES,
                $btcChange
            );
        }

        return null;
    }

    // Zirh Durumu: Izleyen Zirh zaten aktiflesmis (trailing_stop_stage >= 1, yani kar kilitlenmis)
    // olmasina ragmen pozisyon YINE DE zararla kapandiysa, bu beklenmedik bir durumdur - en olasi
    // aciklama, kilitlenen kucuk kar payinin komisyon/slippage tarafindan tuketilmesidir
    private function checkShieldSlippage(array $trade): ?string
    {
        $stage = (int) ($trade['trailing_stop_stage'] ?? 0);

        if ($stage >= 1) {
            return 'Zırh Tetiklendi Ancak Komisyona/Slippage\'a Gitti: İzleyen Zırh kâr kilitlemişti, ama pozisyon yine de zararla kapandı.';
        }

        return null;
    }
}
