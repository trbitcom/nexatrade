<?php

declare(strict_types=1);

namespace App\Services;

// GPT/OpenAI'nin olasiliksal, bazen tutarsiz olabilen "yorumu" yerine - tamamen BELIRLEYICI
// (deterministik) kurallarla calisan bir puanlama motoru. Ayni girdi HER ZAMAN ayni skoru
// uretir, bu yuzden gecmis veriyle tam olarak backtest edilebilir (GPT skorunu gecmisin her
// saatine tekrar tekrar sormak pahali/yavas oldugu icin bu mumkun degildi).
// 1-100 arasi skor + hangi kurallarin calistigini soyleyen acik/izlenebilir bir gerekce doner.
final class TechnicalScoreEngine
{
    private const MOVING_AVERAGE_PERIOD = 20;
    private const TREND_LOOKBACK = 5;
    private const MACD_FAST_PERIOD = 12;
    private const MACD_SLOW_PERIOD = 26;
    private const MACD_SIGNAL_PERIOD = 9;
    private const RSI_PERIOD = 14;

    // "Pusu" (dipten donus) tespitinde, kac mum geriye kadar "asiri satim (RSI<30) gormus muydu"
    // diye bakilacagini belirler - 5 dakikalik mumlarda 6 mum = son 30 dakika
    private const AMBUSH_OVERSOLD_LOOKBACK = 6;

    // @param float[] $closes15m Kronolojik sirali 15 DAKIKALIK kapanis fiyatlari - "Vur-Kac"
    // (scalping) modelinin BIRINCIL karar zaman dilimi (MA20/MACD/RSI buradan hesaplanir).
    // Eskiden bu parametre 1 SAATLIK kapanislardi - zaman agirligi kasitli olarak daha hizli
    // bir zaman dilimine kaydirildi (bkz. CHANGELOG "Vur-Kaç Scalping Modeli")
    // @param float|null $volumeDeltaRatio MarketScanner::calculateVolumeDelta() - son 15 dakikanin
    // hacminin, ONCEKI 15 dakikaya oraniydir (2.0 = 2 kat). Eskiden saatlik/booelan
    // isVolumeIncreasing() kullanilirdi - artik cok daha kisa vadeli bir "ani ilgi" sinyali kullanilir
    // @param float[]|null $closes5m 5 DAKIKALIK kapanis fiyatlari - SADECE "Pusu" (ambush) sinyali
    // icin: RSI'in asiri satimdan DONUS anini ve MACD'nin ERKEN pozitif kesisimini yakalar.
    // null verilirse pusu tespiti atlanir (ambush_detected her zaman false doner)
    // @param float|null $rsi1h MarketScanner::calculateRsi() - eski (1 saatlik) RSI degeri, artik
    // SADECE dusuk agirlikli bir baglam/teyit sinyali olarak kullanilir (asil karar 15m/5m'den gelir)
    // @return array{score: int, reason: string, ambush_detected: bool, rsi_15m: float|null, macd_positive: bool|null, volume_delta: float|null}
    public function calculateScore(
        array $closes15m,
        int $currentIndex15m,
        float $priceChangePercent,
        ?float $volumeDeltaRatio,
        ?array $closes5m = null,
        ?int $currentIndex5m = null,
        ?float $rsi1h = null
    ): array {
        $score = 50;
        $reasons = [];

        // 1) Fiyat momentum kalitesi: saglikli bir yukselis mi, yoksa zaten asiri uzamis mi?
        // (24 saatlik degisim orani, zaman dilimi degisikliginden bagimsiz - degismedi)
        if ($priceChangePercent >= 3.0 && $priceChangePercent <= 15.0) {
            $score += 15;
            $reasons[] = 'sağlıklı yükseliş momentumu';
        } elseif ($priceChangePercent > 15.0) {
            $score += 5;
            $reasons[] = 'güçlü ama uzamış hareket';
        } elseif ($priceChangePercent < 0) {
            $score -= 15;
            $reasons[] = 'düşüş trendi';
        }

        // 2) Hacim deltasi (YENI): son 15 dakikadaki hacim sicramasi, onceki 15 dakikaya oranla.
        // Eski saatlik artan/azalan boolean yerine, scalping modeli icin cok daha kisa vadeli ve
        // hassas bir "ani ilgi" sinyali kullanilir
        if ($volumeDeltaRatio !== null) {
            if ($volumeDeltaRatio >= 3.0) {
                $score += 25;
                $reasons[] = sprintf('ani hacim sıçraması (%.1fx)', $volumeDeltaRatio);
            } elseif ($volumeDeltaRatio >= 1.5) {
                $score += 15;
                $reasons[] = sprintf('artan hacim deltası (%.1fx)', $volumeDeltaRatio);
            } elseif ($volumeDeltaRatio < 1.0) {
                $score -= 10;
                $reasons[] = sprintf('azalan hacim deltası (%.1fx)', $volumeDeltaRatio);
            }
        }

        // 3) 15 dakikalik RSI (eskiden 1 saatlikti - artik BIRINCIL zaman dilimi bu):
        // 45-65 "saglikli" bolge (ne asiri satilmis ne asiri alinmis, yukselecek yer var).
        // NOT: >=70 sert filtresi zaten 1h RSI uzerinden AutoTradeController'da AYRICA uygulanir
        // (bu motor o filtreye DOKUNMAZ) - burasi SADECE bilgi amacli ek bir ceza/odul katmanidir
        $rsiSeries15m = $this->rsiSeries($closes15m);
        $rsi15m = $rsiSeries15m[$currentIndex15m] ?? null;

        if ($rsi15m !== null) {
            if ($rsi15m >= 45 && $rsi15m <= 65) {
                $score += 15;
                $reasons[] = sprintf('sağlıklı 15dk RSI (%.0f)', $rsi15m);
            } elseif ($rsi15m > 65 && $rsi15m < 70) {
                $score += 5;
                $reasons[] = sprintf('15dk RSI ısınıyor (%.0f)', $rsi15m);
            } elseif ($rsi15m >= 70) {
                $score -= 25;
                $reasons[] = sprintf('15dk RSI aşırı alım (%.0f)', $rsi15m);
            } else {
                $score -= 5;
                $reasons[] = sprintf('15dk RSI zayıf (%.0f)', $rsi15m);
            }
        }

        // 4) 1 saatlik RSI (ESKI birincil sinyal, artik DUSUK agirlikli bir baglam katmani -
        // "1H ağırlığını düşür" kuralı burada uygulanir: eski ±15/±25 yerine sadece ±5/±10)
        if ($rsi1h !== null) {
            if ($rsi1h >= 45 && $rsi1h <= 65) {
                $score += 5;
                $reasons[] = sprintf('1s RSI de destekliyor (%.0f)', $rsi1h);
            } elseif ($rsi1h >= 70) {
                $score -= 10;
                $reasons[] = sprintf('1s RSI aşırı alım (%.0f, düşük ağırlık)', $rsi1h);
            }
        }

        // 5) Hareketli ortalama (MA20) trend teyidi - artik 15 DAKIKALIK kapanislardan hesaplanir
        // (eskiden 1 saatlikti): fiyat YUKSELEN bir ortalamanin uzerindeyse guclu bir teyit,
        // DUSEN bir ortalamanin altindaysa net bir uyari isaretidir
        $ma20 = $this->movingAverage($closes15m, $currentIndex15m, self::MOVING_AVERAGE_PERIOD);
        $ma20Previous = $this->movingAverage($closes15m, $currentIndex15m - self::TREND_LOOKBACK, self::MOVING_AVERAGE_PERIOD);

        if ($ma20 !== null && $ma20Previous !== null) {
            $priceAboveMa = $closes15m[$currentIndex15m] > $ma20;
            $maRising = $ma20 > $ma20Previous;

            if ($priceAboveMa && $maRising) {
                $score += 15;
                $reasons[] = 'fiyat yükselen 15dk MA20 üzerinde';
            } elseif (!$priceAboveMa && !$maRising) {
                $score -= 15;
                $reasons[] = 'fiyat düşen 15dk MA20 altında';
            }
        }

        // 6) MACD histogrami - artik 15 DAKIKALIK kapanislardan hesaplanir (eskiden 1 saatlikti).
        // Pozitifse kisa vadeli momentum uzun vadeliyi geciyor demektir (yukselis teyidi)
        $macdHistogramSeries15m = $this->calculateMacdHistogramSeries($closes15m);
        $macdHistogram15m = $macdHistogramSeries15m[$currentIndex15m] ?? null;

        if ($macdHistogram15m !== null) {
            if ($macdHistogram15m > 0) {
                $score += 15;
                $reasons[] = '15dk MACD momentum teyidi olumlu';
            } else {
                $score -= 15;
                $reasons[] = '15dk MACD momentum teyidi olumsuz';
            }
        }

        // 7) YENI - "Dipten Dönüş Pusu": 5 dakikalik grafikte RSI asiri satimdan (RSI<30) DONUP
        // yukari kesmis VE MACD histogrami ayni anda negatiften pozitife ERKEN gecmisse (ikisi
        // BIRLIKTE gorulmeden tetiklenmez - tek basina RSI donusu veya tek basina MACD kesisimi
        // yeterli degildir, yanlis pozitif riskini azaltmak icin), agresif bir skor artisi verilir.
        // ambush_detected bayragi, cagiran tarafin (AutoTradeController) bunu GPT skoruna baraj-
        // yakini bir telafi puani olarak uygulayip uygulamayacagina karar vermesi icin doner
        $ambushDetected = false;

        if ($closes5m !== null && $currentIndex5m !== null) {
            $rsiSeries5m = $this->rsiSeries($closes5m);
            $macdSeries5m = $this->calculateMacdHistogramSeries($closes5m);

            $oversoldReversal = $this->detectOversoldReversal($rsiSeries5m, $currentIndex5m);
            $earlyMacdBuySignal = $this->detectEarlyMacdCross($macdSeries5m, $currentIndex5m);

            if ($oversoldReversal && $earlyMacdBuySignal) {
                $ambushDetected = true;
                $score += 30;
                $reasons[] = '5dk pusu: RSI dipten dönüş + MACD erken AL sinyali';
            }
        }

        $score = max(1, min(100, $score));

        // rsi_15m: AutoTradeController'daki Anti-FOMO RSI (15dk) Freni bu degeri ZATEN burada
        // hesaplanmis oldugu icin (yukaridaki 3. adim) TEKRAR bir Binance cagrisi yapmadan
        // dogrudan kullanabilsin diye disari acilir - skorun kendisi bu degeri zaten iceriyordu,
        // sadece ham deger cagiran tarafa hic ulasmiyordu
        // macd_positive/volume_delta: 26 Temmuz'da eklendi (Deterministik Motor icin) - AYNI sebep:
        // $macdHistogram15m/$volumeDeltaRatio zaten yukarida (6. ve 2. adim) hesaplanmisti, skora
        // dahil edilip SADECE 'reason' metnine gomuluyordu, cagiran tarafa yapisal olarak hic
        // ulasmiyordu. Skor hesaplama mantigina (yukarisi) HICBIR DOKUNUS yok, sadece disari acilim
        return [
            'score' => $score,
            'reason' => implode(', ', $reasons),
            'ambush_detected' => $ambushDetected,
            'rsi_15m' => $rsi15m,
            'macd_positive' => $macdHistogram15m !== null ? ($macdHistogram15m > 0) : null,
            'volume_delta' => $volumeDeltaRatio,
        ];
    }

    // RSI'in TUM $closes serisi icin, her indeksteki degerini doner (tek bir nokta degil) -
    // "Pusu" tespitinde gecmis birkac mumun RSI'ina bakmak gerektigi icin (MarketScanner::calculateRsi()
    // sadece SON degeri donuyor, seri gerektiren bu senaryo icin yetersiz kalirdi). Wilder'in standart
    // yumusatma yontemini kullanir (ilk periyot sonrasi basit ortalama yerine agirlikli kayan ortalama)
    // @param float[] $closes
    // @return array<int, float|null>
    private function rsiSeries(array $closes, int $period = self::RSI_PERIOD): array
    {
        $n = count($closes);
        $series = array_fill(0, $n, null);

        if ($n < $period + 1) {
            return $series;
        }

        $totalGain = 0.0;
        $totalLoss = 0.0;

        for ($i = 1; $i <= $period; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $totalGain += $change > 0 ? $change : 0.0;
            $totalLoss += $change < 0 ? abs($change) : 0.0;
        }

        $avgGain = $totalGain / $period;
        $avgLoss = $totalLoss / $period;
        $series[$period] = $this->rsiFromAverages($avgGain, $avgLoss);

        for ($i = $period + 1; $i < $n; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gain = $change > 0 ? $change : 0.0;
            $loss = $change < 0 ? abs($change) : 0.0;

            $avgGain = (($avgGain * ($period - 1)) + $gain) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $loss) / $period;
            $series[$i] = $this->rsiFromAverages($avgGain, $avgLoss);
        }

        return $series;
    }

    private function rsiFromAverages(float $avgGain, float $avgLoss): float
    {
        if ($avgLoss == 0.0) {
            return 100.0;
        }

        $relativeStrength = $avgGain / $avgLoss;

        return 100 - (100 / (1 + $relativeStrength));
    }

    // "Dipten donus": mevcut RSI, bir onceki muma gore YUKSELIYOR (donus baslamis) VE
    // AMBUSH_OVERSOLD_LOOKBACK mum icinde (mevcut mum HARIC) en az bir kez RSI<30 (asiri satim)
    // gormus. Sadece "RSI yukseliyor" yetmez - yakin gecmiste gercekten asiri satim OLMALI,
    // aksi halde her kucuk RSI dalgalanmasi yanlislikla "donus" sayilirdi
    private function detectOversoldReversal(array $rsiSeries, int $currentIndex): bool
    {
        $current = $rsiSeries[$currentIndex] ?? null;
        $previous = $rsiSeries[$currentIndex - 1] ?? null;

        if ($current === null || $previous === null || $current <= $previous) {
            return false;
        }

        $start = max(0, $currentIndex - self::AMBUSH_OVERSOLD_LOOKBACK);

        for ($i = $start; $i < $currentIndex; $i++) {
            if (($rsiSeries[$i] ?? null) !== null && $rsiSeries[$i] < 30.0) {
                return true;
            }
        }

        return false;
    }

    // "Erken MACD AL sinyali": histogram bir onceki mumda negatif/sifirdi, mevcut mumda POZITIFE
    // gecti - yani sinyal cizgisini YENI kesmis (kesisimin GEC kalmis/uzamis bir versiyonu degil,
    // TAM O ANDAKI erken teyidi)
    private function detectEarlyMacdCross(array $macdSeries, int $currentIndex): bool
    {
        $current = $macdSeries[$currentIndex] ?? null;
        $previous = $macdSeries[$currentIndex - 1] ?? null;

        if ($current === null || $previous === null) {
            return false;
        }

        return $previous <= 0.0 && $current > 0.0;
    }

    private function movingAverage(array $closes, int $endIndex, int $period): ?float
    {
        if ($endIndex < $period - 1 || $endIndex >= count($closes) || $endIndex < 0) {
            return null;
        }

        $slice = array_slice($closes, $endIndex - $period + 1, $period);

        return array_sum($slice) / $period;
    }

    // MACD histogramini (MACD cizgisi - Sinyal cizgisi) TUM $closes dizisi icin TEK seferde
    // hesaplar (dizinin her elemani icin ayri ayri EMA'yi bastan hesaplamak yerine, iteratif olarak
    // ilerler - buyuk bir gecmis veri uzerinde tekrar tekrar cagrildiginda performans farki yaratir).
    // Donen dizinin indeksleri $closes ile birebir hizalidir; yeterli isinma verisi olmayan
    // baslangic indeksleri icin null doner
    // @param float[] $closes
    // @return array<int, float|null>
    public function calculateMacdHistogramSeries(array $closes): array
    {
        $n = count($closes);
        $fastEma = $this->emaSeries($closes, self::MACD_FAST_PERIOD);
        $slowEma = $this->emaSeries($closes, self::MACD_SLOW_PERIOD);

        $macdLine = [];
        for ($i = 0; $i < $n; $i++) {
            $macdLine[$i] = ($fastEma[$i] !== null && $slowEma[$i] !== null) ? $fastEma[$i] - $slowEma[$i] : null;
        }

        $signalLine = $this->emaSeriesFromValues($macdLine, self::MACD_SIGNAL_PERIOD);

        $histogram = [];
        for ($i = 0; $i < $n; $i++) {
            $histogram[$i] = ($macdLine[$i] !== null && $signalLine[$i] !== null) ? $macdLine[$i] - $signalLine[$i] : null;
        }

        return $histogram;
    }

    // $closes dizisinin TAMAMI icin, her indekste o ana kadarki EMA degerini iceren bir dizi doner
    // (ilk $period-1 indeks icin yeterli isinma verisi olmadigindan null)
    // @param float[] $closes
    // @return array<int, float|null>
    private function emaSeries(array $closes, int $period): array
    {
        $n = count($closes);
        $series = array_fill(0, $n, null);

        if ($n < $period) {
            return $series;
        }

        $multiplier = 2 / ($period + 1);
        $ema = array_sum(array_slice($closes, 0, $period)) / $period;
        $series[$period - 1] = $ema;

        for ($i = $period; $i < $n; $i++) {
            $ema = (($closes[$i] - $ema) * $multiplier) + $ema;
            $series[$i] = $ema;
        }

        return $series;
    }

    // emaSeries() ile ayni mantik, ama girdi olarak kapanis fiyatlari yerine (bazilari null olabilen)
    // onceden hesaplanmis bir deger dizisi alir (MACD sinyal cizgisi, MACD cizgisinin EMA'sidir)
    // @param array<int, float|null> $values
    // @return array<int, float|null>
    private function emaSeriesFromValues(array $values, int $period): array
    {
        $n = count($values);
        $series = array_fill(0, $n, null);

        // Ilk gecerli (null olmayan) $period ardisik degeri bul, oradan itibaren hesapla
        $validStart = null;
        for ($i = 0; $i <= $n - $period; $i++) {
            $slice = array_slice($values, $i, $period);
            if (!in_array(null, $slice, true)) {
                $validStart = $i;
                break;
            }
        }

        if ($validStart === null) {
            return $series;
        }

        $multiplier = 2 / ($period + 1);
        $ema = array_sum(array_slice($values, $validStart, $period)) / $period;
        $seedIndex = $validStart + $period - 1;
        $series[$seedIndex] = $ema;

        for ($i = $seedIndex + 1; $i < $n; $i++) {
            if ($values[$i] === null) {
                continue;
            }

            $ema = (($values[$i] - $ema) * $multiplier) + $ema;
            $series[$i] = $ema;
        }

        return $series;
    }
}
