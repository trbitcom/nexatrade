<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use RuntimeException;
use Throwable;

// Telegram Bot API'sini cURL ile tetikleyip kritik bot hareketlerini (pozisyon acildi/kapandi,
// devre kesici, KRITIK hatalar) anlik olarak bildirir. Herhangi bir hatada (bos token, agdan
// erisilemedi, gecersiz chat_id vb.) sistemi riske atmamak icin asla exception firlatmaz,
// sadece error_log'a yazip false doner - tipki BinanceService/SentimentService gibi
final class TelegramService
{
    private const API_URL = 'https://api.telegram.org/bot%s/sendMessage';
    // Katı zaman aşımı: Telegram yanıt vermezse istek en fazla 5 saniyede kesilir, sistemi asla kilitlemez
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TIMEOUT_SECONDS = 5;

    private readonly string $botToken;
    private readonly ?string $adminChatId;

    public function __construct()
    {
        $config = require __DIR__ . '/../../config/app.php';

        // Once admin panelinden (DB) girilen degere bak, yoksa config/app.php'deki degere don
        $dbToken = Setting::get('telegram_bot_token');
        $this->botToken = $dbToken ?? (string) ($config['telegram_bot_token'] ?? '');

        $dbChatId = Setting::get('telegram_admin_chat_id');
        $configChatId = (string) ($config['telegram_admin_chat_id'] ?? '');
        $resolvedChatId = $dbChatId ?? $configChatId;
        $this->adminChatId = $resolvedChatId !== '' ? $resolvedChatId : null;
    }

    // Genel sistem hatalari veya kullanicinin kendi Telegram'ini henuz baglamadigi durumlar icin
    // varsayilan (admin) sohbete mesaj gonderir
    public function notifyAdmin(string $message): bool
    {
        if ($this->adminChatId === null) {
            error_log('NexaTrade TelegramService: TELEGRAM_ADMIN_CHAT_ID tanımlı değil, admin bildirimi gönderilemedi.');

            return false;
        }

        return $this->send($this->adminChatId, $message);
    }

    // Belirli bir kullanicinin kendi baglamis oldugu chat_id'sine dogrudan mesaj gonderir
    public function notifyUser(string $chatId, string $message): bool
    {
        return $this->send($chatId, $message);
    }

    private function send(string $chatId, string $message): bool
    {
        if ($this->botToken === '') {
            error_log('NexaTrade TelegramService: TELEGRAM_BOT_TOKEN tanımlı değil, mesaj gönderilemedi.');

            return false;
        }

        try {
            $url = sprintf(self::API_URL, $this->botToken);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'chat_id' => $chatId,
                    'text' => $message,
                ]),
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
                throw new RuntimeException("Telegram API HTTP {$httpCode}: " . substr((string) $rawResponse, 0, 300));
            }

            return true;
        } catch (Throwable $e) {
            error_log('NexaTrade TelegramService: mesaj gönderilemedi - ' . $e->getMessage());

            return false;
        }
    }
}
