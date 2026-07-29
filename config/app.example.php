<?php

declare(strict_types=1);

// Uygulama geneli ayarlar - SABLON dosya, gercek anahtar/sifre icermez.
// VPS'e ilk kurulumda: bu dosyayi app.php olarak kopyalayip gercek degerleri doldurun
// (app.php .gitignore'da, repoya asla girmez - bkz. gecis plani)
//
//   cp config/app.example.php config/app.php

return [
    // Uygulamanin surum numarasi (SemVer: Major.Minor.Patch). Dashboard'daki surum rozeti ve
    // "Neler Yeni?" penceresi buradan okur - CHANGELOG.md'deki en ust kayitla ELDE senkron tutulmali.
    // Kural: her kodlama/hata duzeltme/yeni modul sonrasi bu deger + CHANGELOG.md birlikte guncellenir
    'app_version' => '1.0.0',

    // Webhook isteklerini yetkisiz tetiklemeye karsi koruyan gizli anahtar
    // TradingView alert JSON body'sinde "token" alani veya URL'de ?token=... olarak gonderilmelidir
    // Uretmek icin: php -r "echo bin2hex(random_bytes(24));"
    'webhook_token' => '',

    // Otonom tarama/alim dongusunu tetikleyen Cron Job icin ayri bir gizli anahtar
    // Webhook token'dan farkli tutulur: bu endpoint gercek para ile market emri acabilir
    // VPS/crontab'a gecince CLI'dan tetiklendiginde bu kontrol otomatik atlanir (bkz. CliKernel)
    'auto_trade_token' => '',

    // SentimentService'in gercek duygu/haber analizi icin kullandigi OpenAI API anahtari
    // https://platform.openai.com/api-keys adresinden alinir. Bos birakilirsa SentimentService
    // notr (50) skor dondurup hatayi error_log'a yazar, sistemi hicbir zaman kilitlemez
    'openai_api_key' => '',

    // OpenAI gunluk kota (RPD) asimi TUM AI puanlamasini durdurdugunda devreye giren yedek
    // saglayicilar - SentimentService OpenAI -> Groq -> Gemini sirasiyla dener, ilk basarili olan
    // kullanilir, hicbiri yoksa/basarisizsa notr (50) skora duser.
    // Groq: https://console.groq.com/keys - Gemini: https://aistudio.google.com/apikey
    // Ikisi de bos birakilabilir (o zaman zincir sadece OpenAI'dan ibaret kalir)
    'groq_api_key' => '',
    'gemini_api_key' => '',

    // TelegramService'in kritik bot bildirimlerini (pozisyon acildi/kapandi, devre kesici, KRITIK hatalar)
    // gonderdigi bot token'i. @BotFather uzerinden alinir. Bos birakilirsa TelegramService sessizce
    // gonderimi atlar ve hatayi error_log'a yazar, sistemi hicbir zaman kilitlemez
    'telegram_bot_token' => '',

    // Genel sistem hatalarinin ve kullanicinin henuz kendi Telegram'ini baglamadigi durumlarda
    // bildirimlerin dusecegi varsayilan (admin) sohbet kimligi
    'telegram_admin_chat_id' => '',

    // @BotFather'dan alinan bot kullanici adi (basindaki @ olmadan). Kullanicilarin "Telegram'i Bagla"
    // butonunun gittigi https://t.me/{username}?start={token} deep link'ini olusturmak icin kullanilir
    'telegram_bot_username' => '',

    // Telegram'in /api/telegram/webhook uc noktasina yaptigi her istekte gonderdigi
    // X-Telegram-Bot-Api-Secret-Token basligiyla karsilastirilir (setWebhook cagrisinda secret_token
    // olarak ayni deger verilmelidir). Bos birakilirsa dogrulama atlanir (yerel gelistirme icin)
    'telegram_webhook_secret' => '',

    // SocialRadarService'in "hype/trend spike" tespiti icin kullandigi CoinGecko API anahtari.
    // OPSIYONELDIR - CoinGecko'nun trending uc noktasi anahtarsiz (keyless public API) da calisir,
    // bos birakilirsa modul YINE DE calisir, sadece daha dusuk/istikrarsiz bir hiz sinirina tabi olur.
    // https://www.coingecko.com/en/developers/dashboard adresinden alinabilir
    'coingecko_api_key' => '',

    // SmartMoneyTracker'in izlenen balina cuzdanlarinin ERC-20 hareketlerini okumak icin kullandigi
    // Etherscan API anahtari. https://etherscan.io/apis adresinden ucretsiz alinir
    'etherscan_api_key' => '',

    // Duyuru Avcisi (ListingSniperService) ve Akilli Para (SmartMoneyTracker) icin ayri, kendi
    // cron uc noktalarini koruyan gizli anahtarlar - ana auto_trade_token'dan bagimsiz tutulur
    'listing_sniper_token' => '',
    'smart_money_token' => '',

    // Futures (kaldiracli KISA/short) modulunun kendi cron uc noktasini koruyan gizli anahtar
    'futures_trading_token' => '',

    // Gece Yarisi Hesap Ozeti (DailySummaryService) icin ayri gizli anahtar
    'daily_summary_token' => '',

    // Hizli Pozisyon Takipcisi (AutoTradeController::runFastTracker) icin ayri gizli anahtar
    'fast_tracker_token' => '',

    // Duyuru Avcisi'nin yeni bir listelemeye alim yapmadan once gerektirdigi asgari 24 saatlik
    // islem hacmi (USDT)
    'listing_sniper_min_quote_volume' => 10000.0,

    // Akilli Para Kopyalayici'nin "onemli" sayacagi asgari transfer buyuklugu (USD)
    'smart_money_min_transfer_usd' => 50000.0,

    // AI Avci'nin (MarketScanner) her tarama turunde hacim/fiyat degisimine gore filtreleyip
    // taradigi aday coin sayisi
    'scanner_coin_limit' => 25,

    // Whitelist/blacklist'ten BAGIMSIZ, HER ZAMAN taramadan disarida tutulacak pariteler (virgulle ayrik)
    'market_scanner_blacklist' => '',

    // AI Avci'nin alim kararini hangi motorun verdigini secer - 'ai' (GPT/AI Karar Skoru) veya
    // 'deterministic' (TechnicalScoreEngine'in RSI/MACD/hacim formulu, GPT-siz)
    'decision_motor' => 'deterministic',
];
