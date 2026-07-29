<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

// Binance'in -1021 hata kodunu ("Timestamp for this request was Xms ahead of the server's time")
// dondurdugunde firlatilir - BinanceApiTimeoutException'daki AYNI mantik: genel RuntimeException'dan
// BILINCLI olarak AYRI tutulur ki signedRequest() bunu "gecici saat kaymasi, bir kez daha taze
// sunucu saatiyle denenebilir" olarak tanıyip TEK SEFERLIK bir yeniden deneme yapabilsin (bkz.
// signedRequest() yorumu). 27 Temmuz'da ZECUSDT OCO hatasi (canli, gercek para) sonrasi eklendi
final class BinanceTimestampException extends RuntimeException
{
}
