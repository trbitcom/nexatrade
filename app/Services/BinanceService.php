<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

// Binance REST API ile cURL uzerinden imzali (signed) istekler kurar
final class BinanceService
{
    private const BASE_URL = 'https://api.binance.com';
    // 5000'den 10000'e cikarildi: paylasimli hosting sunucu saati Binance ile tam senkron
    // olmayabiliyor, tek basina 5000ms tolerans -1021 ("Timestamp ... outside of recvWindow")
    // hatalarini onlemeye yetmiyordu (bkz. binance_errors.log, 6-14 Temmuz arasi tekrarlayan kayitlar)
    private const RECV_WINDOW = 10000;
    // Katı zaman aşımı: CLAUDE.md kuralı geregi HER dis API cagrisi 3sn baglanti + 5sn toplam
    // kullanmak ZORUNDADIR - "sistem asla uzun sure kilitlenmemelidir". 27 Temmuz'da 10sn/15sn'den
    // BURAYA geri dondurudu: bu ihlal, ayni gece PHP-FPM/lsphp worker havuzunun Binance yavasladiginda
    // (agirlikli olarak ag/baglanti sorunu, bkz. binance_errors.log) 3 KAT daha uzun sure asili kalip
    // tukenmesine (site tamamen "donmus" hale gelmesine) DOGRUDAN katkida bulunmus bir mimari
    // ihlaldi - RECV_WINDOW (yukarida, Binance'in kendi saat toleransi) ile KARISTIRILMAMALI, o AYRI
    // bir ayar ve dogru sekilde 10000 kalmaya devam ediyor
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TIMEOUT_SECONDS = 5;

    // Bu PHP surecinin (tek bir cron calismasi) yerel saati ile Binance'in sunucu saati arasindaki
    // fark (ms) - signedRequest() tarafindan lazy hesaplanip surec boyunca onbelleklenir. Sadece
    // recvWindow'u genisletmek KOK NEDENI cozmez (saat kaymasi buyukse yine patlar) - asil duzeltme
    // gonderilen timestamp'in KENDISINI Binance'in gercek saatine gore duzeltmek
    private ?int $serverTimeOffsetMs = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $secretKey,
    ) {
    }

    // Sifirdan buyuk (>0) tum bakiyeleri dondurur, ornegin: [['asset' => 'USDT', 'free' => 12.5, 'locked' => 0.0]]
    public function getBalances(): array
    {
        $response = $this->signedRequest('GET', '/api/v3/account');

        $balances = [];

        foreach ($response['balances'] ?? [] as $balance) {
            $free = (float) $balance['free'];
            $locked = (float) $balance['locked'];

            if ($free + $locked > 0) {
                $balances[] = [
                    'asset' => $balance['asset'],
                    'free' => $free,
                    'locked' => $locked,
                ];
            }
        }

        return $balances;
    }

    // Anlik piyasa fiyatini doner. Genel (public) bir endpoint oldugu icin imza gerektirmez
    public function getPrice(string $symbol): float
    {
        $url = self::BASE_URL . '/api/v3/ticker/price?symbol=' . urlencode(strtoupper($symbol));

        $response = $this->request('GET', $url);

        return (float) ($response['price'] ?? 0);
    }

    // 2 Agustos'ta eklendi: apiPortfolio()/fetchActiveTrades() acik pozisyon sayisi kadar (N) SIRALI
    // getPrice() cagrisi yapiyordu - her biri ayri TCP/TLS baglantisi (paylasilan HTTP istemci
    // YOK, bkz. "Servis Deseni" CLAUDE.md), portfolio ucnoktasi bu yuzden 3+ saniyeye cikiyordu
    // (musteri hem mobil hem webde yavaslik bildirdi, gercek olcumle dogrulandi). symbol parametresi
    // OLMADAN /api/v3/ticker/price TUM sembollerin fiyatini TEK istekte doner - N cagriyi 1'e indirir.
    // @return array<string, float> sembol => fiyat
    public function getAllPrices(): array
    {
        $url = self::BASE_URL . '/api/v3/ticker/price';

        $response = $this->request('GET', $url);
        $prices = [];

        foreach ($response as $ticker) {
            $symbol = (string) ($ticker['symbol'] ?? '');

            if ($symbol !== '') {
                $prices[$symbol] = (float) ($ticker['price'] ?? 0);
            }
        }

        return $prices;
    }

    // Canli Savas Radari (Trade Diagnostics grafigi) icin: son $limit adet mumu doner. Genel
    // (public) bir endpoint oldugu icin imza gerektirmez - MarketScanner/BacktestService'teki
    // KENDI private fetchKlines()'larindan KASITLI olarak AYRI/BAGIMSIZ birakildi (o iki metod
    // farkli donus formatlari/kullanim amaclari icin zaten test edilmis, dokunulmadi)
    // @return array<array{time:int,open:float,high:float,low:float,close:float}>
    public function getKlines(string $symbol, string $interval, int $limit): array
    {
        $url = self::BASE_URL . '/api/v3/klines?' . http_build_query([
            'symbol' => strtoupper($symbol),
            'interval' => $interval,
            'limit' => $limit,
        ]);

        $response = $this->request('GET', $url);
        $candles = [];

        foreach ($response as $kline) {
            $candles[] = [
                'time' => (int) ($kline[0] / 1000),
                'open' => (float) $kline[1],
                'high' => (float) $kline[2],
                'low' => (float) $kline[3],
                'close' => (float) $kline[4],
            ];
        }

        return $candles;
    }

    // Daha once verilen bir emrin (ör. bekleyen Kar Al/LIMIT SELL) guncel durumunu sorgular
    // "Aktif Avlar" mutabakati icin kullanilir: emir gerceklesti mi, hala beklemede mi, iptal mi edildi
    public function getOrderStatus(string $symbol, int $orderId): array
    {
        $params = [
            'symbol' => strtoupper($symbol),
            'orderId' => $orderId,
        ];

        return $this->signedRequest('GET', '/api/v3/order', $params);
    }

    // Taslak emir gonderme metodu: basarili/basarisiz durumunu her zaman bir dizi olarak dondurur (exception firlatmaz)
    public function placeOrder(string $symbol, string $side, string $type, float $quantity, ?float $price = null): array
    {
        try {
            $params = [
                'symbol' => strtoupper($symbol),
                'side' => strtoupper($side),
                'type' => strtoupper($type),
                'quantity' => $this->formatNumber($quantity),
                // Komisyon Takibi (22 Temmuz): FULL yaniti ZORUNLU kilar - fills[] (dolayisiyla
                // commission/commissionAsset) sadece bu modda garanti donuyor. MARKET/LIMIT emirlerde
                // varsayilan zaten FULL'dur ama acikca belirtmek gelecekte Binance varsayilani
                // degistirirse sessizce komisyon verisi kaybetmemizi onler
                'newOrderRespType' => 'FULL',
            ];

            if (strtoupper($type) === 'LIMIT' && $price !== null) {
                $params['price'] = $this->formatNumber($price);
                $params['timeInForce'] = 'GTC';
            }

            $response = $this->signedRequest('POST', '/api/v3/order', $params);

            return [
                'success' => true,
                'order_id' => $response['orderId'] ?? null,
                'raw' => $response,
            ];
        } catch (Throwable $e) {
            $this->logError('placeOrder hatasi: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // OCO (One-Cancels-the-Other) emri: Kar Al (LIMIT) ve Zarar Kes emirlerini TEK istekte,
    // birbirine bagli olarak gonderir - biri gerceklesince digeri otomatik iptal olur.
    // Basarili/basarisiz durumunu her zaman bir dizi olarak dondurur (exception firlatmaz)
    // $stopLimitPrice VERILMEZSE (null), Binance zarar kes bacagini duz STOP_LOSS (PIYASA emri)
    // olarak yorumlar - tetiklenince NE FIYATTAN OLURSA OLSUN aninda satar. 15 Temmuz'da
    // NEARUSDT'de yasanan "Izleyen Zirh tetiklendi ama slippage'a gitti" olayindan sonra
    // BILINCLI olarak eski STOP_LOSS_LIMIT (%0.5 tolerans payli limit emri) yerine bu
    // tercih edildi: volatil bir coinde fiyat bu payi asip hizla duserse limit emri HIC
    // DOLMAZ, pozisyon korumasiz kalirdi. Cok sert bir ani cokuste piyasa emrinin fiyati
    // tahminden kotu olabilir, ama bu HER ZAMAN emrin hic dolmayip pozisyonun tamamen
    // korumasiz kalmasindan iyidir
    public function placeOCOOrder(
        string $symbol,
        string $side,
        float $quantity,
        float $takeProfitPrice,
        float $stopTriggerPrice,
        ?float $stopLimitPrice = null
    ): array {
        try {
            $params = [
                'symbol' => strtoupper($symbol),
                'side' => strtoupper($side),
                'quantity' => $this->formatNumber($quantity),
                'price' => $this->formatNumber($takeProfitPrice),
                'stopPrice' => $this->formatNumber($stopTriggerPrice),
            ];

            if ($stopLimitPrice !== null) {
                $params['stopLimitPrice'] = $this->formatNumber($stopLimitPrice);
                $params['stopLimitTimeInForce'] = 'GTC';
            }

            $response = $this->signedRequest('POST', '/api/v3/order/oco', $params);

            $takeProfitOrderId = null;
            $stopLossOrderId = null;

            foreach ($response['orderReports'] ?? [] as $report) {
                if (in_array($report['type'] ?? '', ['STOP_LOSS_LIMIT', 'STOP_LOSS'], true)) {
                    $stopLossOrderId = $report['orderId'] ?? null;
                } else {
                    $takeProfitOrderId = $report['orderId'] ?? null;
                }
            }

            return [
                'success' => true,
                'order_list_id' => $response['orderListId'] ?? null,
                'take_profit_order_id' => $takeProfitOrderId,
                'stop_loss_order_id' => $stopLossOrderId,
                'raw' => $response,
            ];
        } catch (Throwable $e) {
            $this->logError('placeOCOOrder hatası: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // Bir OCO emir grubunun genel durumunu sorgular (listOrderStatus: EXECUTING / ALL_DONE / REJECT)
    // Hangi bacagin (Kar Al mi Zarar Kes mi) gerceklestigini ayirt etmek icin getOrderStatus() ile
    // her iki bacak ayri ayri kontrol edilmelidir
    public function getOcoOrderStatus(int $orderListId): array
    {
        $params = ['orderListId' => $orderListId];

        return $this->signedRequest('GET', '/api/v3/orderList', $params);
    }

    // Bir sembol icin GERCEKLESMIS (fill) islem gecmisini doner - getOrderStatus() bir API
    // kesintisi yuzunden basarisiz olduğunda/tutarsiz durum bildirdiğinde, reconcileActiveTrades()'in
    // son care olarak Binance'in KENDI gercek islem kayitlarindan (orderId eslestirerek) hangi OCO
    // bacaginin gercekten doldugunu dogrulayabilmesi icin eklendi (10 Temmuz TIAUSDT veri kaybi sonrasi)
    public function getMyTrades(string $symbol, int $limit = 20): array
    {
        $params = ['symbol' => strtoupper($symbol), 'limit' => $limit];

        return $this->signedRequest('GET', '/api/v3/myTrades', $params);
    }

    // Komisyon Takibi (22 Temmuz): bir MARKET/LIMIT emrinin POST yanitindaki fills[] dizisinden
    // toplam komisyonu cikarir - EK bir API cagrisi GEREKMEZ, placeOrder()'in zaten donen $raw'inda
    // bulunur. Tek bir emir kismi gerceklesmelerle BIRDEN FAZLA fill'e sahip olabilir - komisyonlar
    // TOPLANIR. commissionAsset normalde TUM fill'lerde aynidir (hesabin quote/base varligi ya da
    // BNB indirimliyse BNB - Binance bir emrin butun fill'lerine AYNI varligi uygular); ilk fill'in
    // varligi kullanilir, farkli cikarsa (cok nadir) sessizce loglanir. Saf (network'ten bagimsiz)
    // bir fonksiyondur - gercek Binance cagrisi olmadan sentetik veriyle test edilebilir
    public static function extractFillCommission(array $raw): array
    {
        $fills = $raw['fills'] ?? [];

        if ($fills === []) {
            return ['commission' => null, 'commission_asset' => null];
        }

        $totalCommission = 0.0;
        $asset = null;
        $mixedAssets = false;

        foreach ($fills as $fill) {
            $totalCommission += (float) ($fill['commission'] ?? 0);
            $fillAsset = $fill['commissionAsset'] ?? null;

            if ($asset === null) {
                $asset = $fillAsset;
            } elseif ($fillAsset !== null && $fillAsset !== $asset) {
                $mixedAssets = true;
            }
        }

        if ($mixedAssets) {
            error_log('BinanceService::extractFillCommission: fills farklı commissionAsset içerdi, ilk varlık kullanıldı.');
        }

        return ['commission' => $totalCommission, 'commission_asset' => $asset];
    }

    // Komisyon Takibi: getOrderStatus() (GET /api/v3/order) komisyon BİLGİSİ DÖNDÜRMEZ - sadece
    // myTrades trade-seviyesinde commission/commissionAsset taşır. reconcileActiveTrades()'teki OCO
    // bacak kapanışları (Kar Al/Zarar Kes tetiklendiğinde) bu yüzden komisyonu SONRADAN, bu metodla
    // öğrenir. Saf toplama mantığı test edilebilirlik için sumTradeCommissions()'a ayrıldı
    public function getCommissionForOrder(string $pair, int $orderId): array
    {
        try {
            $trades = $this->getMyTrades($pair);
        } catch (Throwable $e) {
            return ['commission' => null, 'commission_asset' => null];
        }

        return self::sumTradeCommissions($trades, $orderId);
    }

    // Saf (network'ten bagimsiz) fonksiyon: myTrades sonucundan belirli bir orderId'ye ait TUM
    // trade'lerin komisyonunu toplar - kismi gerceklesmelerde bir emir birden fazla trade'e bolunmus
    // olabilir. Gercek Binance cagrisi olmadan sentetik veriyle test edilebilir
    public static function sumTradeCommissions(array $trades, int $orderId): array
    {
        $totalCommission = 0.0;
        $asset = null;
        $found = false;

        foreach ($trades as $t) {
            if ((int) ($t['orderId'] ?? 0) !== $orderId) {
                continue;
            }

            $found = true;
            $totalCommission += (float) ($t['commission'] ?? 0);

            if ($asset === null) {
                $asset = $t['commissionAsset'] ?? null;
            }
        }

        return $found ? ['commission' => $totalCommission, 'commission_asset' => $asset] : ['commission' => null, 'commission_asset' => null];
    }

    // Bir sembolde (veya sembol verilmezse HESAP GENELINDE) o an ACIK olan TUM emirleri doner -
    // getOcoOrderStatus() belirli bir orderListId'ye bagimliyken, bu metod "gercekte Binance'te
    // ne kilitli/bekliyor?" sorusunu bagimsiz olarak cevaplar (tanilama ve mutabakat kontrolleri icin)
    public function getOpenOrders(?string $symbol = null): array
    {
        $params = $symbol !== null ? ['symbol' => strtoupper($symbol)] : [];

        return $this->signedRequest('GET', '/api/v3/openOrders', $params);
    }

    // Kar Al Tavanini Kaldirma (22 Temmuz): Sinirsiz Izleme aktiflesince mevcut OCO tamamen iptal
    // edilip YERINE SADECE bu tekil Zarar Kes emri konur - boylece sabit Kar Al tavani devreden
    // cikar. placeOCOOrder()'daki AYNI tercih tekrarlanir: stopLimitPrice VERILMEZSE (varsayilan)
    // duz STOP_LOSS (PIYASA) emri - volatil bir dususte limit emrin hic dolmama riskini onler
    // (bkz. placeOCOOrder yorumu - NEARUSDT slippage olayindan sonra bilincli tercih)
    public function placeStopLossOrder(string $symbol, string $side, float $quantity, float $stopPrice, ?float $stopLimitPrice = null): array
    {
        try {
            $params = [
                'symbol' => strtoupper($symbol),
                'side' => strtoupper($side),
                'type' => $stopLimitPrice !== null ? 'STOP_LOSS_LIMIT' : 'STOP_LOSS',
                'quantity' => $this->formatNumber($quantity),
                'stopPrice' => $this->formatNumber($stopPrice),
            ];

            if ($stopLimitPrice !== null) {
                $params['price'] = $this->formatNumber($stopLimitPrice);
                $params['timeInForce'] = 'GTC';
            }

            $response = $this->signedRequest('POST', '/api/v3/order', $params);

            return [
                'success' => true,
                'order_id' => $response['orderId'] ?? null,
                'raw' => $response,
            ];
        } catch (Throwable $e) {
            $this->logError('placeStopLossOrder hatası: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // Kar Al Tavanini Kaldirma sonrasi tek yonlu (OCO'suz) Zarar Kes emrini iptal etmek icin -
    // cancelOcoOrder()'in tekil-emir karsiligi. BinanceFuturesService::cancelOrder() ile AYNI desen
    public function cancelOrder(string $symbol, int $orderId): array
    {
        try {
            $params = [
                'symbol' => strtoupper($symbol),
                'orderId' => $orderId,
            ];

            $response = $this->signedRequest('DELETE', '/api/v3/order', $params);

            return [
                'success' => true,
                'raw' => $response,
            ];
        } catch (Throwable $e) {
            $this->logError('cancelOrder hatası: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // Acik bir OCO emir grubunu tamamen iptal eder (DCA'nin, eski koruma emrini kaldirip yeni
    // (ortalama maliyete gore yeniden hesaplanmis) bir OCO ile degistirebilmesi icin gerekir).
    // placeOrder/placeOCOOrder ile ayni never-throw ['success' => bool, ...] desenini takip eder
    public function cancelOcoOrder(string $symbol, int $orderListId): array
    {
        try {
            $params = [
                'symbol' => strtoupper($symbol),
                'orderListId' => $orderListId,
            ];

            $response = $this->signedRequest('DELETE', '/api/v3/orderList', $params);

            return [
                'success' => true,
                'raw' => $response,
            ];
        } catch (Throwable $e) {
            $this->logError('cancelOcoOrder hatası: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // Bir sembolun LOT_SIZE (stepSize) ve MIN_NOTIONAL/NOTIONAL filtrelerini exchangeInfo'dan okur.
    // DCA'nin birlestirilmis (initial + DCA) miktarini borsanin gercekten kabul edecegi bir
    // step'e yuvarlayabilmesi icin gerekir - genel (public) bir uc nokta oldugundan imza gerektirmez.
    // Filtre bulunamazsa guvenli varsayilanlar doner (stepSize=0.0001, minNotional=0.0)
    public function getSymbolFilters(string $symbol): array
    {
        $url = self::BASE_URL . '/api/v3/exchangeInfo?symbol=' . urlencode(strtoupper($symbol));

        $response = $this->request('GET', $url);

        $stepSize = 0.0001;
        $minNotional = 0.0;
        $tickSize = 0.00000001;

        $filters = $response['symbols'][0]['filters'] ?? [];

        foreach ($filters as $filter) {
            if (($filter['filterType'] ?? '') === 'LOT_SIZE') {
                $stepSize = (float) ($filter['stepSize'] ?? $stepSize);
            }

            if (in_array($filter['filterType'] ?? '', ['MIN_NOTIONAL', 'NOTIONAL'], true)) {
                $minNotional = (float) ($filter['minNotional'] ?? $filter['notional'] ?? $minNotional);
            }

            if (($filter['filterType'] ?? '') === 'PRICE_FILTER') {
                $tickSize = (float) ($filter['tickSize'] ?? $tickSize);
            }
        }

        return ['step_size' => $stepSize, 'min_notional' => $minNotional, 'tick_size' => $tickSize];
    }

    // Parametreleri timestamp/recvWindow ile tamamlayip HMAC-SHA256 imzasi ekleyerek istegi gonderir.
    // 27 Temmuz'da eklendi: -1021 (saat kaymasi) hatasi alinirsa TEK SEFERLIK, onbellekteki offset'i
    // ATIP taze bir /api/v3/time cagrisiyla yeniden hesaplayip AYNI istegi bir kez daha dener - canli
    // bir olayda (ZECUSDT OCO hatasi, korumasiz pozisyon) bu senkron sapmasi OCO yerlestirmeyi
    // tamamen basarisiz kilmisti. BILEREK dar tutuldu: SADECE -1021'e ozel, TEK deneme (sonsuz
    // donguye/genel baglanti kesintisi retry'ina KARISTIRILMAMALI - bkz. BinanceApiTimeoutException,
    // o AYRI tutulur ve retry EDILMEZ, worker havuzu tukenme riski yuzunden BILINCLI olarak)
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
                throw $e; // zaten bir kez tazelenmis offset'le denendi, tekrar denemiyoruz
            }

            $this->serverTimeOffsetMs = null; // onbellegi at, bir sonraki getServerTimeOffsetMs() taze cagri yapsin
            unset($params['timestamp'], $params['recvWindow']);

            return $this->signedRequest($method, $endpoint, $params, true);
        }
    }

    // Binance'in genel (imzasiz) /api/v3/time uc noktasindan gercek sunucu saatini ceker ve yerel
    // saatle farkini (ms) doner - surec icinde SADECE ILK cagrida gercek bir istek atilir, sonrasi
    // onbellekten doner (her signedRequest() icin ayri bir HTTP gidis-donusu gereksiz olurdu).
    // Sunucu saati alinamazsa (ag hatasi) 0 (duzeltmesiz) doner - bu, MEVCUT (duzeltmesiz) davranistan
    // daha kotu degildir, fail-open: bu katman asla sistemi kilitlemez/yeni bir hataya yol acmaz
    private function getServerTimeOffsetMs(): int
    {
        if ($this->serverTimeOffsetMs !== null) {
            return $this->serverTimeOffsetMs;
        }

        try {
            $localTimeMs = (int) round(microtime(true) * 1000);
            $response = $this->request('GET', self::BASE_URL . '/api/v3/time');
            $serverTimeMs = (int) ($response['serverTime'] ?? 0);

            $this->serverTimeOffsetMs = $serverTimeMs > 0 ? $serverTimeMs - $localTimeMs : 0;
        } catch (Throwable $e) {
            $this->serverTimeOffsetMs = 0;
        }

        return $this->serverTimeOffsetMs;
    }

    // Binance'in istedigi HMAC-SHA256 imzalama mantigi
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
            // BinanceApiTimeoutException (RuntimeException'dan turer) - cagiran taraf bunu genel
            // hatalardan AYIRT edip "gecici API yavasligi" icin farkli/zarif bir yanit verebilir
            throw new BinanceApiTimeoutException('Binance sunucusuna baglanilamadi (zaman asimi veya baglanti hatasi).');
        }

        $decoded = json_decode((string) $rawResponse, true);

        if ($httpCode >= 400) {
            // Binance'in dondurdugu gercek hata mesajini/kodunu sakla (ör. "-2015 Invalid API-key, IP, or permissions for action.")
            // boylece "gecersiz anahtar" ile "IP kisitlamasi" veya "izin eksik" birbirinden ayirt edilebilsin
            $errorCode = is_array($decoded) ? ($decoded['code'] ?? null) : null;
            $message = is_array($decoded) ? ($decoded['msg'] ?? 'Bilinmeyen Binance API hatasi') : 'Bilinmeyen Binance API hatasi';
            $logMessage = $errorCode !== null ? "HTTP {$httpCode} (kod {$errorCode}) - {$message}" : "HTTP {$httpCode} - {$message}";

            $this->logError($logMessage);

            if ($errorCode === -1021) {
                throw new BinanceTimestampException($message);
            }

            throw new RuntimeException($message);
        }

        return is_array($decoded) ? $decoded : [];
    }

    // PHP'nin kucuk/buyuk sayilari bilimsel notasyona (ör. 1.0E-5) cevirmesini engeller, imza ile gonderilen deger birebir ayni olmali
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
        file_put_contents($logDir . '/binance_errors.log', $entry, FILE_APPEND);
    }
}
