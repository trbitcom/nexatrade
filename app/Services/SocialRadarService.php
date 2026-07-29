<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use RuntimeException;
use Throwable;

// CoinGecko'nun /search/trending uc noktasindan, o an arama hacmine gore en cok ilgi goren
// coinleri ceker. CryptoPanic Nisan 2026'da ucretsiz planini tamamen kaldirdigi icin veri
// kaynagi buraya tasindi. CoinGecko'nun kendi "trending" algoritmasi zaten bir ilgi/spike
// tespiti yaptigi icin (10 dakikada bir kendi tarafinda guncellenir), CryptoPanic doneminde
// burada yapilan kendi taban/coklayici (baseline/spike-multiplier) hesabina artik gerek yok -
// donen liste dogrudan aday olarak kullanilir.
// Tespit edilen semboller DOGRUDAN alim yapmaz - AutoTradeController bunlari SentimentService'e
// (OpenAI) onay icin gonderir, tipki MarketScanner'in mevcut adaylari gibi
final class SocialRadarService
{
    private const TRENDING_URL = 'https://api.coingecko.com/api/v3/search/trending';
    // Katı zaman aşımı: CoinGecko yanıt vermezse istek en fazla 5 saniyede kesilir, sistemi asla kilitlemez
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TIMEOUT_SECONDS = 5;

    private readonly string $apiKey;

    public function __construct()
    {
        // NOT: diger servislerin (Etherscan/eski CryptoPanic) aksine, bu anahtar OPSIYONELDIR -
        // CoinGecko'nun trending uc noktasi anahtarsiz (keyless public API) da calisir, bos
        // birakilirsa modul YINE DE calismaya devam eder, sadece daha dusuk/istikrarsiz bir hiz
        // sinirina (dakikada ~5-15 istek) tabi olur
        $dbValue = Setting::get('coingecko_api_key');

        if ($dbValue !== null) {
            $this->apiKey = $dbValue;
        } else {
            $config = require __DIR__ . '/../../config/app.php';
            $this->apiKey = (string) ($config['coingecko_api_key'] ?? '');
        }
    }

    // @return array<array{symbol: string, mention_count: int, baseline: int}>
    public function detectSpikes(): array
    {
        try {
            $trending = $this->fetchTrendingCoins();
            $spikes = [];

            foreach ($trending as $rank => $coin) {
                $symbolCode = strtoupper(trim((string) ($coin['symbol'] ?? '')));

                if ($symbolCode === '') {
                    continue;
                }

                // 'mention_count'/'baseline' alanlari geriye donuk uyumluluk icin korunuyor -
                // AutoTradeController::fetchTradableSocialRadarSymbols() sadece 'symbol' kolonunu
                // okur, bu yuzden cagiran taraf hic degismedi. CoinGecko'nun sira numarasini
                // (0=en cok ilgi goren) kabaca bir "ilgi puanina" cevirir, sadece bilgi amaclidir
                $spikes[] = [
                    'symbol' => $symbolCode . 'USDT',
                    'mention_count' => max(1, 15 - $rank),
                    'baseline' => 0,
                ];
            }

            return $spikes;
        } catch (Throwable $e) {
            error_log('NexaTrade SocialRadarService: trend taraması başarısız - ' . $e->getMessage());

            return [];
        }
    }

    // CoinGecko'nun anlik "en cok aranan/ilgi goren" coin listesini ceker (genelde ~15 coin,
    // kendi tarafinda 10 dakikada bir guncellenir). @return array<array{symbol: string}>
    private function fetchTrendingCoins(): array
    {
        $headers = ['Accept: application/json'];

        if ($this->apiKey !== '') {
            $headers[] = 'x-cg-demo-api-key: ' . $this->apiKey;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::TRENDING_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            // CoinGecko 15 Temmuz denetiminde: aciklayici bir User-Agent olmadan istek 403 ile
            // reddediliyor ("Please add a descriptive User-Agent to your request") - varsayilan
            // PHP/cURL User-Agent'i (bos/generic) artik yeterli degil
            CURLOPT_USERAGENT => 'NexaTrade/1.0 (+https://nexatrade.topcumedya.com.tr)',
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
        ]);

        $rawResponse = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrno !== 0) {
            throw new RuntimeException("cURL hatası ({$curlErrno}): {$curlError}");
        }

        if ($httpCode >= 400) {
            throw new RuntimeException("CoinGecko API HTTP {$httpCode}: " . substr((string) $rawResponse, 0, 300));
        }

        $decoded = json_decode((string) $rawResponse, true);
        $coins = $decoded['coins'] ?? [];

        if (!is_array($coins)) {
            return [];
        }

        return array_values(array_map(
            static fn (array $entry): array => ['symbol' => (string) ($entry['item']['symbol'] ?? '')],
            $coins
        ));
    }
}
