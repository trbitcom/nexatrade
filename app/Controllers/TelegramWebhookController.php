<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Services\TelegramService;
use Throwable;

// Telegram Bot API'sinin dogrudan pingledigi genel (kimlik dogrulamasiz oturum) uc nokta.
// Musteri, "Telegram'i Bagla" linkiyle botta "/start {token}" komutunu tetikledikten sonra
// buraya gelen guncellemeyi okuyup ilgili NexaTrade hesabina chat_id'yi kalici olarak baglar.
// NOT: Bu uc nokta yalnizca gercek, herkese acik bir HTTPS alan adindan Telegram'in setWebhook
// ile kayitli oldugu URL'ye ulastiginda calisir - localhost'tan Telegram sunuculari erisemez
final class TelegramWebhookController
{
    public function handle(): void
    {
        header('Content-Type: application/json');

        if (!$this->isValidWebhookRequest()) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Geçersiz webhook isteği.']);
            return;
        }

        try {
            $raw = file_get_contents('php://input');
            $update = json_decode((string) $raw, true);

            $text = trim((string) ($update['message']['text'] ?? ''));
            $chatId = $update['message']['chat']['id'] ?? null;

            if ($chatId !== null && preg_match('/^\/start\s+(\S+)$/', $text, $matches) === 1) {
                $this->linkAccount($matches[1], (string) $chatId);
            }
        } catch (Throwable $e) {
            error_log('NexaTrade TelegramWebhookController: ' . $e->getMessage());
        }

        // Telegram, 200 disinda bir yanit alirsa ayni guncellemeyi tekrar tekrar gonderir;
        // bu yuzden yukarida ne olursa olsun (eslesme yoksa dahi) her zaman 200 donulur
        http_response_code(200);
        echo json_encode(['status' => 'ok']);
    }

    // Tokeni users.telegram_verify_token uzerinden dogrular; eslesme yoksa sessizce yok sayar
    // (gecersiz/suresi gecmis bir token icin Telegram'a hata gostermek anlamli bilgi sizdirmaz, sadece yok sayilir)
    private function linkAccount(string $token, string $chatId): void
    {
        $user = User::findByTelegramVerifyToken($token);

        if ($user === false) {
            return;
        }

        User::linkTelegramChat((int) $user['id'], $chatId);

        $telegram = new TelegramService();
        $telegram->notifyUser(
            $chatId,
            'Hesabınız başarıyla bağlandı. Otonom al-sat bildirimleriniz buraya iletilecektir.'
        );
    }

    // Telegram'in setWebhook cagrisinda ayni deger secret_token olarak verilmisse, her istekte
    // X-Telegram-Bot-Api-Secret-Token basligini kontrol ederek uc noktayi Telegram disindan
    // rastgele POST atan kimseye karsi korur. Deger yapilandirilmamissa (bos) dogrulama atlanir
    private function isValidWebhookRequest(): bool
    {
        $expectedSecret = Setting::get('telegram_webhook_secret');

        if ($expectedSecret === null) {
            $config = require __DIR__ . '/../../config/app.php';
            $expectedSecret = (string) ($config['telegram_webhook_secret'] ?? '');
        }

        if ($expectedSecret === '') {
            return true;
        }

        $providedSecret = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');

        return $providedSecret !== '' && hash_equals($expectedSecret, $providedSecret);
    }
}
