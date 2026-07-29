<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

// Binance'e (veya piyasa verisi ucuna) yapilan bir cURL istegi zaman asimina ugradiginda veya
// baglanti kurulamadiginda firlatilir - MarketScanner::fetchPublicJson() ve BinanceService::request()
// kendi BAGIMSIZ curl bloklarinda (proje konvansiyonu: ortak HTTP istemci sinifi yok) AYNI bu tipi
// kullanir. Genel RuntimeException'dan BILINCLI olarak AYRI tutulur ki cagiran taraf (ör.
// ListingSniperController) "gecici bir API yavasligi/zaman asimi" ile "gercek bir hata"yi (ör.
// gecersiz API anahtari, Binance'in donduglu bir is hatasi) birbirinden ayirt edip FARKLI,
// zarif bir yanit verebilsin (bkz. 21 Temmuz CHANGELOG)
final class BinanceApiTimeoutException extends RuntimeException
{
}
