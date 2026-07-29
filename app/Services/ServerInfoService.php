<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use RuntimeException;
use Throwable;

// Musterilerin Binance API anahtarini IP kisitlamasiyla olusturabilmesi icin sunucunun GERCEK
// disa-donuk (outbound) IP adresini doner. $_SERVER['SERVER_ADDR'] sadece gelen HTTP istegini
// hangi arayuzun karsiladigini soyler - bu, sunucunun Binance'e GIDEN istek yaparken kullandigi
// IP ile HER ZAMAN ayni olmayabilir (ozellikle paylasimli/proxy'li hostinglerde). Bu yuzden
// dogrulugu garanti etmek icin harici bir "IP echo" servisine (ipify) sorulur, sonuc 24 saat
// onbelleklenir (sabit IP'li bir sunucuda gunde bir kere sorgulamak yeterlidir) ve harici servis
// erisilemezse SERVER_ADDR'a (yine de yoktan iyidir) geri dusulur
final class ServerInfoService
{
    private const IP_ECHO_URL = 'https://api.ipify.org?format=text';
    private const CONNECT_TIMEOUT_SECONDS = 2;
    private const TIMEOUT_SECONDS = 3;
    private const CACHE_KEY = 'server_public_ip';
    private const CACHE_SECONDS = 86400; // 24 saat - sabit IP'li sunucuda sik sorgulamaya gerek yok

    public static function getPublicIp(): string
    {
        $cached = Setting::getMeta(self::CACHE_KEY);

        if ($cached !== null && strtotime((string) $cached['updated_at']) > time() - self::CACHE_SECONDS) {
            $value = trim((string) $cached['value']);

            if ($value !== '') {
                return $value;
            }
        }

        try {
            $ip = self::fetchFromEchoService();
            Setting::set(self::CACHE_KEY, $ip);

            return $ip;
        } catch (Throwable $e) {
            error_log('NexaTrade ServerInfoService: dış IP alınamadı - ' . $e->getMessage());

            // Onbellekte eski (suresi dolmus) bir deger varsa, SERVER_ADDR'dan once onu tercih ederiz
            if ($cached !== null && trim((string) $cached['value']) !== '') {
                return trim((string) $cached['value']);
            }

            $fallback = $_SERVER['SERVER_ADDR'] ?? null;

            return is_string($fallback) && $fallback !== '' ? $fallback : 'IP alınamadı';
        }
    }

    private static function fetchFromEchoService(): string
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => self::IP_ECHO_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; NexaTradeBot/1.0)',
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
            throw new RuntimeException("IP echo servisi HTTP {$httpCode} döndürdü.");
        }

        $ip = trim((string) $rawResponse);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('IP echo servisi geçersiz bir yanıt döndürdü.');
        }

        return $ip;
    }
}
