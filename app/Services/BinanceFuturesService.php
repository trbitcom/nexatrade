<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

// Binance USDT-M Futures REST API (fapi.binance.com) ile cURL uzerinden imzali istekler kurar.
// BinanceService'ten (spot, api.binance.com) BILINCLI olarak AYRI tutulur - iki API'nin
// endpoint/parametre semantigi farkli oldugundan (ör. OCO yerine ayri STOP_MARKET/TAKE_PROFIT_MARKET
// emirleri, kaldirac ayari, likidasyon fiyati) tek bir servise zorlamak hataya acik olurdu
final class BinanceFuturesService
{
    private const BASE_URL = 'https://fapi.binance.com';
    // BinanceService (spot) ile AYNI 5000->10000 genisletmesi + ayni gerekce: paylasimli hosting
    // sunucu saati kayabiliyor, tek basina recvWindow buyutmek kok nedeni cozmuyor - asil duzeltme
    // getServerTimeOffsetMs()
    private const RECV_WINDOW = 10000;
    // 27 Temmuz'da CLAUDE.md kuralina (3sn/5sn) geri dondurudu - BinanceService.php'deki AYNI
    // gerekce/olay: 10sn/15sn ihlali, PHP-FPM worker havuzunun asili istekler yuzunden tukenmesine
    // (site "donmasina") katkida bulunmustu. RECV_WINDOW (yukarida) BU DEGISIKLIKTEN AYRI/ETKILENMEZ
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TIMEOUT_SECONDS = 5;

    // BinanceService'teki ayniyla ayni amac: surec basina bir kez hesaplanip onbelleklenen
    // yerel-sunucu saat farki (ms)
    private ?int $serverTimeOffsetMs = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $secretKey,
    ) {
    }

    // Bir sembol icin kaldiraci ayarlar - HER pozisyon acilisindan ONCE cagrilmalidir, aksi halde
    // Binance hesaptaki mevcut (varsayilan veya bir onceki islemden kalma) kaldiraci kullanir
    public function setLeverage(string $symbol, int $leverage): array
    {
        try {
            $params = [
                'symbol' => strtoupper($symbol),
                'leverage' => $leverage,
            ];

            $response = $this->signedRequest('POST', '/fapi/v1/leverage', $params);

            return ['success' => true, 'raw' => $response];
        } catch (Throwable $e) {
            $this->logError('setLeverage hatasi: ' . $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // USDT-M futures cuzdanindaki kullanilabilir USDT bakiyesini doner (marj icin ayrilabilir kisim) -
    // acik izole pozisyonlara kilitlenmis marji HARIC tutar (yeni islem acmadan once "serbest marj
    // yeterli mi" kontrolu icin dogru alan budur, bkz. FuturesTradingService::shortForAllUsers)
    public function getUsdtBalance(): float
    {
        $response = $this->signedRequest('GET', '/fapi/v2/balance');

        foreach ($response as $entry) {
            if (($entry['asset'] ?? '') === 'USDT') {
                return (float) ($entry['availableBalance'] ?? $entry['balance'] ?? 0);
            }
        }

        return 0.0;
    }

    // 22 Temmuz'da eklendi: getUsdtBalance()'dan BILINCLI olarak FARKLI - o "serbest/kullanilabilir"
    // marji doner (acik izole pozisyonlara kilitli marji HARIC tutar), bu ise TOPLAM cuzdan bakiyesini
    // doner (kilitli marj DAHIL). Dashboard'daki portfoy toplami gibi "su an her seyi kapatsam elimde
    // ne kalir" hesaplari icin dogru alan budur - canli testte availableBalance'in acik bir DEXEUSDT
    // pozisyonu varken gercek bakiyeden ~6.5 USDT (kilitli izole marj) eksik gosterdigi dogrulandi
    public function getWalletBalance(): float
    {
        $response = $this->signedRequest('GET', '/fapi/v2/balance');

        foreach ($response as $entry) {
            if (($entry['asset'] ?? '') === 'USDT') {
                return (float) ($entry['balance'] ?? 0);
            }
        }

        return 0.0;
    }

    // Anlik piyasa fiyatini doner (public, imza gerektirmez)
    public function getPrice(string $symbol): float
    {
        $url = self::BASE_URL . '/fapi/v1/ticker/price?symbol=' . urlencode(strtoupper($symbol));

        $response = $this->request('GET', $url);

        return (float) ($response['price'] ?? 0);
    }

    // Bir sembolun LOT_SIZE (stepSize), PRICE_FILTER (tickSize) ve MIN_NOTIONAL filtrelerini
    // futures exchangeInfo'dan okur. NOT: spot'un aksine /fapi/v1/exchangeInfo, ?symbol=... parametresini
    // YOK SAYAR ve HER ZAMAN TUM sembollerin tam listesini doner - bu yuzden ilk eleman degil,
    // istenen sembolle ESLESEN eleman aranmali (aksi halde yanlis sembolun hassasiyet kurallari
    // uygulanir ve Binance "Precision is over the maximum defined for this asset" hatasi doner)
    public function getSymbolFilters(string $symbol): array
    {
        $url = self::BASE_URL . '/fapi/v1/exchangeInfo';

        $response = $this->request('GET', $url);
        $normalizedSymbol = strtoupper($symbol);

        $symbolInfo = null;

        foreach ($response['symbols'] ?? [] as $entry) {
            if (($entry['symbol'] ?? '') === $normalizedSymbol) {
                $symbolInfo = $entry;
                break;
            }
        }

        $stepSize = 0.0001;
        $minNotional = 0.0;
        $tickSize = 0.00000001;

        if ($symbolInfo === null) {
            $this->logError("getSymbolFilters: {$normalizedSymbol} exchangeInfo listesinde bulunamadı, varsayılan hassasiyet kullanılıyor.");

            return ['step_size' => $stepSize, 'min_notional' => $minNotional, 'tick_size' => $tickSize];
        }

        foreach ($symbolInfo['filters'] ?? [] as $filter) {
            if (($filter['filterType'] ?? '') === 'LOT_SIZE') {
                $stepSize = (float) ($filter['stepSize'] ?? $stepSize);
            }

            if (($filter['filterType'] ?? '') === 'MIN_NOTIONAL') {
                $minNotional = (float) ($filter['notional'] ?? $minNotional);
            }

            if (($filter['filterType'] ?? '') === 'PRICE_FILTER') {
                $tickSize = (float) ($filter['tickSize'] ?? $tickSize);
            }
        }

        return ['step_size' => $stepSize, 'min_notional' => $minNotional, 'tick_size' => $tickSize];
    }

    // Pozisyon acan/kapatan MARKET emri. $reduceOnly=true iken sadece mevcut pozisyonu azaltir/kapatir
    // (yanlislikla ters yonde YENI bir pozisyon acmayi engeller) - kapanis emirlerinde HER ZAMAN true olmali
    public function placeMarketOrder(string $symbol, string $side, float $quantity, bool $reduceOnly = false): array
    {
        try {
            $params = [
                'symbol' => strtoupper($symbol),
                'side' => strtoupper($side),
                'type' => 'MARKET',
                'quantity' => $this->formatNumber($quantity),
            ];

            if ($reduceOnly) {
                $params['reduceOnly'] = 'true';
            }

            $response = $this->signedRequest('POST', '/fapi/v1/order', $params);

            return [
                'success' => true,
                'order_id' => $response['orderId'] ?? null,
                'raw' => $response,
            ];
        } catch (Throwable $e) {
            $this->logError('placeMarketOrder hatasi: ' . $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Zarar Kes: tetiklendiginde TUM pozisyonu MARKET fiyattan kapatir (closePosition=true, miktar
    // gonderilmez). NOT: bazi dusuk hacimli/yeni futures sozlesmelerinde Binance bu emir tipini
    // "-4120 Order type not supported" hatasiyla reddedebiliyor - cagiran taraf (FuturesTradingService)
    // basarisiz olursa kendi izleme mekanizmasina (mark fiyati polling) dusmelidir
    public function placeStopMarketOrder(string $symbol, string $side, float $stopPrice): array
    {
        return $this->placeClosePositionOrder($symbol, $side, 'STOP_MARKET', $stopPrice);
    }

    // Kar Al: tetiklendiginde TUM pozisyonu MARKET fiyattan kapatir (bkz. placeStopMarketOrder yorumu)
    public function placeTakeProfitMarketOrder(string $symbol, string $side, float $stopPrice): array
    {
        return $this->placeClosePositionOrder($symbol, $side, 'TAKE_PROFIT_MARKET', $stopPrice);
    }

    private function placeClosePositionOrder(string $symbol, string $side, string $type, float $stopPrice): array
    {
        try {
            $params = [
                'symbol' => strtoupper($symbol),
                'side' => strtoupper($side),
                'type' => $type,
                'stopPrice' => $this->formatNumber($stopPrice),
                'closePosition' => 'true',
            ];

            $response = $this->signedRequest('POST', '/fapi/v1/order', $params);

            return [
                'success' => true,
                'order_id' => $response['orderId'] ?? null,
                'raw' => $response,
            ];
        } catch (Throwable $e) {
            $this->logError("{$type} hatasi: " . $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getOrderStatus(string $symbol, int $orderId): array
    {
        $params = [
            'symbol' => strtoupper($symbol),
            'orderId' => $orderId,
        ];

        return $this->signedRequest('GET', '/fapi/v1/order', $params);
    }

    // Acik bir emri iptal eder (ör. Kar Al gerceklestiginde bekleyen Zarar Kes'i temizlemek icin,
    // ya da native TP/SL reddedilirse basarili olan tek emri geri almak icin)
    public function cancelOrder(string $symbol, int $orderId): array
    {
        try {
            $params = [
                'symbol' => strtoupper($symbol),
                'orderId' => $orderId,
            ];

            $response = $this->signedRequest('DELETE', '/fapi/v1/order', $params);

            return ['success' => true, 'raw' => $response];
        } catch (Throwable $e) {
            $this->logError('cancelOrder hatasi: ' . $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Acik pozisyonun guncel likidasyon fiyatini/mark fiyatini/gerceklesmemis PNL'ini doner.
    // Hesap "one-way" modundaysa (hedge mode kapali - bu entegrasyonun varsaydigi mod) sembol
    // basina TEK pozisyon kaydi doner
    public function getPositionRisk(string $symbol): array
    {
        $params = ['symbol' => strtoupper($symbol)];
        $response = $this->signedRequest('GET', '/fapi/v2/positionRisk', $params);
        $first = is_array($response) ? ($response[0] ?? []) : [];

        return [
            'liquidation_price' => (float) ($first['liquidationPrice'] ?? 0),
            'mark_price' => (float) ($first['markPrice'] ?? 0),
            'position_amt' => (float) ($first['positionAmt'] ?? 0),
            'unrealized_profit' => (float) ($first['unRealizedProfit'] ?? 0),
        ];
    }

    // 27 Temmuz'da eklendi: BinanceService::signedRequest() ile AYNI -1021 (saat kaymasi) tek
    // seferlik yeniden deneme mantigi - bkz. oradaki yorum. Genel baglanti kesintisi retry'i
    // ile KARISTIRILMAMALI, o BILINCLI olarak eklenmedi (worker havuzu tukenme riski)
    private function signedRequest(string $method, string $endpoint, array $params = [], bool $isRetry = false): array
    {
        $params['timestamp'] = (int) round(microtime(true) * 1000) + $this->getServerTimeOffsetMs();
        $params['recvWindow'] = self::RECV_WINDOW;

        $queryString = http_build_query($params);
        $queryString .= '&signature=' . $this->sign($queryString);

        $url = self::BASE_URL . $endpoint . '?' . $queryString;

        try {
            return $this->request($method, $url);
        } catch (BinanceTimestampException $e) {
            if ($isRetry) {
                throw $e;
            }

            $this->serverTimeOffsetMs = null;
            unset($params['timestamp'], $params['recvWindow']);

            return $this->signedRequest($method, $endpoint, $params, true);
        }
    }

    // BinanceService::getServerTimeOffsetMs() ile AYNI mantik, futures'in kendi (imzasiz)
    // /fapi/v1/time uc noktasina karsi - iki API'nin saatleri ayri senkronize edilmis olabilir,
    // bu yuzden spot'unkiyle PAYLASILMAZ, kendi bagimsiz olcumu yapar
    private function getServerTimeOffsetMs(): int
    {
        if ($this->serverTimeOffsetMs !== null) {
            return $this->serverTimeOffsetMs;
        }

        try {
            $localTimeMs = (int) round(microtime(true) * 1000);
            $response = $this->request('GET', self::BASE_URL . '/fapi/v1/time');
            $serverTimeMs = (int) ($response['serverTime'] ?? 0);

            $this->serverTimeOffsetMs = $serverTimeMs > 0 ? $serverTimeMs - $localTimeMs : 0;
        } catch (Throwable $e) {
            $this->serverTimeOffsetMs = 0;
        }

        return $this->serverTimeOffsetMs;
    }

    private function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->secretKey);
    }

    private function request(string $method, string $url): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['X-MBX-APIKEY: ' . $this->apiKey],
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
        ]);

        $rawResponse = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrno !== 0) {
            $this->logError("cURL hatasi ({$curlErrno}): {$curlError}");
            throw new RuntimeException('Binance Futures sunucusuna baglanilamadi (zaman asimi veya baglanti hatasi).');
        }

        $decoded = json_decode((string) $rawResponse, true);

        if ($httpCode >= 400) {
            $errorCode = is_array($decoded) ? ($decoded['code'] ?? null) : null;
            $message = is_array($decoded) ? ($decoded['msg'] ?? 'Bilinmeyen Binance Futures API hatasi') : 'Bilinmeyen Binance Futures API hatasi';
            $logMessage = $errorCode !== null ? "HTTP {$httpCode} (kod {$errorCode}) - {$message}" : "HTTP {$httpCode} - {$message}";

            $this->logError($logMessage);

            if ($errorCode === -1021) {
                throw new BinanceTimestampException($message);
            }

            throw new RuntimeException($message);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.8f', $value), '0'), '.');
    }

    private function logError(string $message): void
    {
        $logDir = __DIR__ . '/../../storage/logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $entry = sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $message, PHP_EOL);
        file_put_contents($logDir . '/binance_futures_errors.log', $entry, FILE_APPEND);
    }
}
