<?php

declare(strict_types=1);

namespace App\Services;

// LOT_SIZE (Adim Yuvarlama) Guvenlik Kalkani: Binance'in her sembol icin zorunlu kildigi
// floorToStep() yuvarlamasi, DUSUK butce + YUKSEK birim fiyatli bir varliga (ör. BTC) girildiginde
// pozisyonun oransal olarak buyuk bir kismini "fire" (dust) olarak yiyebiliyor. 20 Temmuz'da canli
// ortamda tespit edildi: 5.76 USDT'lik bir BTC alimi (0.00009 BTC) tek bir step yuvarlamasiyla
// %11 kucularak, Izleyen Zirh Zarar Kes'i girisin USTUNE cekmis olsa bile pozisyonu zararla
// kapanmaya zorladi - yuzeyde "Zirh calismadi" gibi gorunse de kok neden pozisyonun stepSize'a
// gore ZATEN cok kucuk olmasiydi. Bu, TEK BIR sembole ozgu degil - dusuk butceli/yuksek fiyatli
// HER kombinasyonda tekrarlanabilir, bu yuzden TUM otonom alim modullerinin (AI Avci, Duyuru
// Avcisi, Akilli Para Kopyalayici) gercek Binance ALIM emri gondermeden HEMEN ONCE cagirmasi
// gereken TEK, paylasilan kontrol olarak tasarlandi - RiskManagerService'in devre kesicisiyle
// AYNI "TEK paylasilan mekanizma, her modul kendi cagirir" felsefesini takip eder
final class LotSizeGuardService
{
    // Yuvarlama kaybi (fire) bu yuzdeyi asarsa pozisyon GUVENLI SAYILMAZ - alim denenmeden atlanir.
    // 21 Temmuz'da %0.5'ten %1.5'e gevsetildi: canli loglarda dusuk butce + yuksek birim fiyatli
    // (ETH/BTC gibi) coinlerde %0.5 fazla siki bulunup gecerli sinyalleri engelliyordu (ör. "%0.79
    // asiyor" reddi). %1.5 hala tipik %2-10 araligindaki Zarar Kes yuzdelerinin YANINDA kucuk kalir,
    // ama artik gercekci dusuk butceli alimlari da gecirir - TUM otonom modulleri (AI Avci, Futures,
    // Duyuru Avcisi, Akilli Para) AYNI ANDA etkiler, cunku bu PAYLASILAN tek bir guvenlik kontroludur
    public const DEFAULT_MAX_SLIPPAGE_TOLERANCE_PERCENT = 1.5;

    // Butce/fiyat oranindan hesaplanan HEDEFLENEN (henuz yuvarlanmamis) miktar ile Binance'in
    // LOT_SIZE kuralina gore asagi yuvarlanmis GERCEKTE ALINABILECEK miktar arasindaki farki
    // (fire yuzdesi) hesaplayip pozisyonun guvenli olup olmadigina karar verir. Saf hesaplama -
    // HTTP cagrisi YAPMAZ, cagiran taraf stepSize'i onceden BinanceService::getSymbolFilters()'tan almis olmalidir
    // @param float $targetQuantity Ideal (yuvarlanmamis) miktar - genelde $budget / $price
    // @param float $stepSize Binance'in bu sembol icin LOT_SIZE filtresi
    // @param float $maxSlippageTolerancePercent Varsayilan DEFAULT_MAX_SLIPPAGE_TOLERANCE_PERCENT (%0.5)
    // @return array{safe: bool, floored_quantity: float, fire_percent: float}
    public static function evaluate(
        float $targetQuantity,
        float $stepSize,
        float $maxSlippageTolerancePercent = self::DEFAULT_MAX_SLIPPAGE_TOLERANCE_PERCENT
    ): array {
        // Gecersiz girdi (sifir/negatif hedef miktar) - guvenli degil, %100 fire olarak isaretlenir
        if ($targetQuantity <= 0.0) {
            return ['safe' => false, 'floored_quantity' => 0.0, 'fire_percent' => 100.0];
        }

        // stepSize 0/negatifse (ör. Binance filtresi okunamadi) yuvarlama uygulanamaz - bu durumu
        // "guvenli" saymak yanlis olur, cagiran taraf zaten kendi varsayilanina (0.0001) dusuyor
        $flooredQuantity = $stepSize > 0.0
            ? floor($targetQuantity / $stepSize) * $stepSize
            : $targetQuantity;

        // Yuvarlama miktari TAMAMEN sifirlarsa (ör. butce tek bir step'ten bile kucuk) kesinlikle guvensiz
        if ($flooredQuantity <= 0.0) {
            return ['safe' => false, 'floored_quantity' => 0.0, 'fire_percent' => 100.0];
        }

        $firePercent = (($targetQuantity - $flooredQuantity) / $targetQuantity) * 100;

        return [
            'safe' => $firePercent <= $maxSlippageTolerancePercent,
            'floored_quantity' => $flooredQuantity,
            'fire_percent' => round($firePercent, 4),
        ];
    }
}
