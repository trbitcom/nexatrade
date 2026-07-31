<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Throwable;

// Dashboard panellerinde (AI Radar, AI Monolog, Aktif Avlar, Son Islemler) coin adinin yanina
// kucuk bir logo koymak icin - musteri talebi (31 Temmuz). Binance'in kendisi genel bir logo API'si
// sunmuyor, TradingView'in kendi logo CDN'i hotlink'e kapali (denendi, 403) - CoinGecko'nun
// /coins/markets?symbols= uc noktasi gercek logo URL'leri veriyor (test edildi, calisiyor).
// Tum sonuclar TEK bir Setting anahtarinda (JSON blob) KALICI olarak onbelleklenir - NewsService'in
// 15dk'lik cache'inden FARKLI olarak logolar pratikte hic degismedigi icin "bulundu" sonuclari
// sonsuza kadar saklanir, sadece "bulunamadi" sonuclari periyodik tekrar denenir (CoinGecko yeni
// bir coin eklemis olabilir). Rate limit riskini azaltmak icin EKSIK sembollerin TAMAMI TEK bir
// istekte (virgulle ayrilmis symbols= parametresi) toplu cekilir
final class CoinIconService
{
    private const CACHE_KEY = 'coin_icon_cache';
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TIMEOUT_SECONDS = 5;
    // "Bulunamadi" sonuclari bu sureden once tekrar denenmez - CoinGecko'nun kesintisiz calistigi
    // varsayimiyla, gereksiz tekrar isteklerini onlemek icin (bkz. yukaridaki genel yorum)
    private const NOT_FOUND_RETRY_SECONDS = 604800; // 7 gun

    // @param string[] $baseAssets Coin'in USDT-siz taban sembolu (ör. 'BTC', 'SKHYB') - pair DEGIL
    // @return array<string,?string> Buyuk harfli taban sembol => logo URL'i, ya da bulunamadiysa null
    public function getIconUrls(array $baseAssets): array
    {
        $cache = $this->loadCache();
        $now = time();
        $missing = [];
        $seen = [];

        foreach ($baseAssets as $asset) {
            $asset = strtoupper(trim($asset));

            if ($asset === '' || isset($seen[$asset])) {
                continue;
            }

            $seen[$asset] = true;
            $entry = $cache['icons'][$asset] ?? null;

            if ($entry === null) {
                $missing[] = $asset;
                continue;
            }

            if ($entry['url'] === null && ($now - (int) $entry['checked_at']) > self::NOT_FOUND_RETRY_SECONDS) {
                $missing[] = $asset;
            }
        }

        if ($missing !== []) {
            $fetched = $this->fetchFromCoinGecko($missing);

            foreach ($missing as $asset) {
                $cache['icons'][$asset] = ['url' => $fetched[$asset] ?? null, 'checked_at' => $now];
            }

            $this->saveCache($cache);
        }

        $result = [];

        foreach (array_keys($seen) as $asset) {
            $result[$asset] = $cache['icons'][$asset]['url'] ?? null;
        }

        return $result;
    }

    // Fail-open: herhangi bir adim basarisiz olursa bos dizi doner - cagiran taraf logosuz devam
    // eder, hicbir panelin gorunmesini/calismasini ASLA engellemez
    private function fetchFromCoinGecko(array $baseAssets): array
    {
        try {
            $symbolsParam = implode(',', array_map(static fn (string $a): string => strtolower($a), $baseAssets));
            $url = 'https://api.coingecko.com/api/v3/coins/markets?vs_currency=usd&symbols=' . urlencode($symbolsParam) . '&per_page=250';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                // CoiGecko aciklayici bir User-Agent olmadan 403 dondurur (bkz. SocialRadarService'te
                // 15 Temmuz'da bulunan AYNI kisit) - varsayilan bos/generic PHP cURL User-Agent'i yeterli degil
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; NexaTradeBot/1.0)',
            ]);
            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                return [];
            }

            $data = json_decode($response, true);

            if (!is_array($data)) {
                return [];
            }

            $result = [];

            foreach ($data as $coin) {
                $symbol = strtoupper((string) ($coin['symbol'] ?? ''));
                $image = (string) ($coin['image'] ?? '');

                // CoinGecko ayni ticker'i birden fazla farkli projede kullanabilir - varsayilan
                // siralama piyasa degerine gore (buyukten kucuge) oldugu icin ILK eslesme (en
                // yuksek piyasa degerli olan) alinir, sonraki tekrarlar yoksayilir
                if ($symbol !== '' && $image !== '' && !isset($result[$symbol])) {
                    $result[$symbol] = $image;
                }
            }

            return $result;
        } catch (Throwable $e) {
            error_log('[CoinIconService] ' . $e->getMessage());

            return [];
        }
    }

    private function loadCache(): array
    {
        $raw = Setting::get(self::CACHE_KEY);

        if ($raw === null) {
            return ['icons' => []];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && isset($decoded['icons']) && is_array($decoded['icons'])
            ? $decoded
            : ['icons' => []];
    }

    private function saveCache(array $cache): void
    {
        $encoded = json_encode($cache);

        if ($encoded !== false) {
            Setting::set(self::CACHE_KEY, $encoded);
        }
    }
}
