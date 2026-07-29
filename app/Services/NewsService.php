<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use RuntimeException;
use Throwable;

// Cointelegraph'in genel (kimlik dogrulamasiz) RSS akisindan gercek, guncel kripto haber basliklerini ceker
// Sonuc 15 dakika onbelleklenir (app_settings uzerinden) ki panel her yenilendiginde tekrar cekilmesin
final class NewsService
{
    private const FEED_URL = 'https://cointelegraph.com/rss';
    // Katı zaman aşımı: RSS kaynağı yanıt vermezse istek en fazla 5 saniyede kesilir, sistemi asla kilitlemez
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TIMEOUT_SECONDS = 5;
    private const CACHE_KEY = 'crypto_news_cache';
    private const CACHE_SECONDS = 900;
    private const MAX_ITEMS = 12;

    // @return array<array{title: string, link: string, pubDate: string}>
    public function fetchLatest(): array
    {
        $cached = Setting::getMeta(self::CACHE_KEY);

        if ($cached !== null && strtotime((string) $cached['updated_at']) > time() - self::CACHE_SECONDS) {
            $decoded = json_decode((string) $cached['value'], true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        try {
            $items = $this->fetchFromFeed();
            Setting::set(self::CACHE_KEY, json_encode($items, JSON_UNESCAPED_UNICODE));

            return $items;
        } catch (Throwable $e) {
            error_log('NexaTrade NewsService: haber akışı alınamadı - ' . $e->getMessage());

            // Onbellekte eski (suresi dolmus) veri varsa onu donduruyoruz - hicbir sey gostermemekten iyidir
            if ($cached !== null) {
                $decoded = json_decode((string) $cached['value'], true);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            return [];
        }
    }

    private function fetchFromFeed(): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => self::FEED_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; NexaTradeBot/1.0)',
            CURLOPT_FOLLOWLOCATION => true,
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
            throw new RuntimeException("Haber akışı HTTP {$httpCode} döndürdü.");
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) $rawResponse);

        if ($xml === false || !isset($xml->channel->item)) {
            throw new RuntimeException('RSS gövdesi ayrıştırılamadı.');
        }

        $items = [];

        foreach ($xml->channel->item as $item) {
            $items[] = [
                'title' => trim((string) $item->title),
                'link' => trim((string) $item->link),
                'pubDate' => trim((string) $item->pubDate),
            ];

            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $this->translateTitles($items);
    }

    // Basliklari TEK bir OpenAI cagrisiyla Turkceye cevirip geri yazar. Sonuc zaten Turkce olarak
    // onbelleklenecegi icin (fetchLatest) her onbellek isabetinde tekrar ceviri yapilmaz
    private function translateTitles(array $items): array
    {
        if ($items === []) {
            return $items;
        }

        $sentiment = new SentimentService();
        $translated = $sentiment->translateToTurkish(array_column($items, 'title'));

        foreach ($items as $index => &$item) {
            $item['title'] = $translated[$index] ?? $item['title'];
        }
        unset($item);

        return $items;
    }
}
