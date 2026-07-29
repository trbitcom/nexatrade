<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use RuntimeException;
use Throwable;

// OpenAI Chat Completions API'sini kullanarak coin bazinda gercek piyasa duyarlilik skoru uretir
// Herhangi bir API hatasinda (timeout, kota asimi, gecersiz anahtar vb.) sistemi riske atmamak
// icin notr (50) skor doner ve hatayi error_log'a yazar - asla exception firlatmaz
final class SentimentService
{
    private const BUY_THRESHOLD = 80;
    private const DEFAULT_NEUTRAL_SCORE = 50;

    // 22 Temmuz'da eklendi: canli testte modelin cok DUSUK hacimli (ör. 80.000 USDT) ama buyuk
    // yuzdeli (ör. +%40) "supheli pump" senaryolarinda hacmi YANLIS "guclu" sanip 1.44.2'nin
    // "CESARET KURALI"ni tetikleyip 85 puan verebildigi tespit edildi - bkz. CHANGELOG 1.44.2. Bu
    // sinir, MarketScanner'in kurulu-coin esiginin (5.000.000) ALTINDA tutuldu (MarketScanner
    // adaylari zaten bunu asiyor, bu guard onlar icin no-op) - hedef, hacim FLORU OLMAYAN tek yol
    // olan Sosyal Radar/Pusu Kurtarma'dan gelen sig sembolleri modele HIC SORMADAN mekanik olarak
    // reddetmek (bkz. AutoTradeController::fetchTradableSocialRadarSymbols - orada hacim kontrolu
    // YOK). Ayrica gereksiz OpenAI/Groq/Gemini cagrisini da onler (butce tasarrufu)
    private const MIN_VOLUME_FOR_AI_SCORING_USDT = 3_000_000.0;
    private const LOW_VOLUME_REJECTION_SCORE = 30;
    private const LOW_VOLUME_REJECTION_REASON = 'Hacim çok düşük, likidite riski (Sistem Reddi)';

    // 22 Temmuz'da eklendi: "Cift Katmanli Zirve Korumasi"nin mikro katmani - MIN_VOLUME_FOR_AI_SCORING_USDT
    // ile AYNI mekanik-guard mimarisi, cunku prompt-ici versiyonu GUVENILMEZ cikti (bkz. SCORING_SYSTEM_PROMPT
    // yorumu - model, gercek sayilara bakmadan "Gunluk Zirve Bilgisi" bloğunun VARLIGINA gore reddediyordu).
    // Coin GUNUN ZIRVESINE cok yakinsa (position_percent_24h >= 85) VE zaten devasa bir artis yapmissa
    // (priceChangePercent >= 15) FOMO/pullback riski mekanik olarak KESIN sayilir - modele hic sorulmaz
    private const DAILY_PEAK_POSITION_THRESHOLD = 85.0;
    private const DAILY_PEAK_CHANGE_THRESHOLD = 15.0;
    private const DAILY_PEAK_REJECTION_SCORE = 40;
    private const DAILY_PEAK_REJECTION_REASON = 'Aşırı alım bölgesinde, düzeltme (pullback) riski yüksek (Sistem Reddi)';
    private const OPENAI_URL = 'https://api.openai.com/v1/chat/completions';
    // gpt-3.5-turbo'dan yukseltildi: gpt-4o-mini fiyat/hacim gibi sayisal verilere dayali
    // muhakemede belirgin sekilde daha tutarli, maliyeti de hala dusuk (mikro-islem basina)
    private const MODEL = 'gpt-4o-mini';

    // 22 Temmuz'da eklendi: OpenAI'nin gunluk kota (RPD) asimi TUM puanlamayi durdurabildigi
    // canli olarak tespit edildikten sonra (bkz. CHANGELOG) yedek saglayicilar. Groq, OpenAI ile
    // BIREBIR AYNI Chat Completions istek/yanit formatini kullanir (kasitli uyumluluk) - sadece
    // URL/model/anahtar farklidir. Gemini'nin formati FARKLIDIR (bkz. buildProviderRequest/
    // extractMessageContent). Ikisi de admin panelinden opsiyonel girilir - bos birakilirsa
    // zincire hic girmezler, eski (sadece OpenAI) davranis aynen korunur
    private const GROQ_URL = 'https://api.groq.com/openai/v1/chat/completions';
    private const GROQ_MODEL = 'llama-3.3-70b-versatile';
    private const GEMINI_URL_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    // 22 Temmuz'da UC AYRI deneme sonrasi bulundu: gemini-1.5-flash kaldirilmis (404), ListModels'in
    // listeledigi gemini-2.5-flash bile "no longer available to new users" diye reddediliyordu (404) -
    // ListModels'in listelemesi, o SPESIFIK hesabin erisimini garanti etmiyor. Kullanicinin gercek
    // anahtariyla 8 aday model TEK TEK gercek bir generateContent cagrisiyla test edilip SADECE
    // gemini-flash-latest ve gemini-flash-lite-latest'in calistigi kanitlandi (digerleri 429 kota
    // ya da 404 "yeni kullanicilara kapali" verdi). "-latest" bir sabit versiyon DEGIL, otomatik
    // kayan bir takma ad (alias) - Google'in altini degistirme riski var ama SU AN icin hesaba
    // gercekten acik olan tek secenekler bunlar. Lite (daha ucuz/hizli katman) tercih edildi -
    // bu zaten sadece OpenAI kota asiminda devreye giren bir YEDEK saglayici
    private const GEMINI_MODEL = 'gemini-flash-lite-latest';
    // 28 Temmuz'da 10sn/15sn'den DUZELTILDI: CLAUDE.md'nin zorunlu 3sn/5sn standardina uymuyordu.
    // Bu servis OpenAI->Groq->Gemini 3'lu zincir dener (bkz. asagi) - eski degerlerle worst-case
    // 3x15sn=45sn surebiliyordu. reconcileActiveTradesInternal() acik pozisyonlari tek tek kontrol
    // etmeden ONCE scoreOpenPositionSymbols() ile bu zinciri cagirdigindan, yavas/yanit vermeyen bir
    // saglayici TEK BASINA cron-job.org'un sabit 30sn zaman asimini asip TUM turu (SOLUSDT #193 dahil,
    // hicbir pozisyon kontrol edilmeden) sessizce iptal edebiliyordu - canli tespit edildi
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TIMEOUT_SECONDS = 5;
    private const MARKET_PULSE_CACHE_KEY = 'market_pulse_text';
    private const MARKET_PULSE_CACHE_SECONDS = 900; // 15 dakika: dashboard her yenilendiginde yeniden uretmesin

    // 22 Temmuz'da canli tespit edildi: analyze()/analyzeMany() 4 BAGIMSIZ cagiran tarafindan
    // (AutoTradeController'in ana taramasi + Dinamik Kacis pozisyon izleme + DCA kontrolu,
    // DashboardController'in AI Radar'i, FuturesTradingService'in kendi taramasi) hicbir ortak
    // onbellek OLMADAN kullaniliyordu - AYNI sembol birkac dakika icinde 3-4 kez BAGIMSIZ OpenAI
    // cagrisiyla yeniden puanlaniyordu. Gunluk 10.000 istek kotasi (OpenAI hesabinin RPD siniri)
    // bu yuzden saatler icinde tukeniyor, sonrasinda TUM cagrilar HTTP 429 alip sessizce notr (50)
    // skora duesuyordu - "sistem hicbir sey almiyor" seklinde gorunen asil kok neden buydu (skor 50,
    // kullanicinin kendi esiginin - 50/60/70 - hep altinda kaliyordu). Tek bir paylasilan, sembol
    // basina onbellek TUM cagiran taraflar icin gecerli - kim ilk sorarsa OpenAI'ye gider, digerleri
    // ayni pencerede onun sonucunu yeniden kullanir. POSITION_MONITOR_INTERVAL_SECONDS (300) ile
    // AYNI deger secildi, tutarlilik icin
    private const SYMBOL_SCORE_CACHE_KEY = 'sentiment_symbol_score_cache';
    private const SYMBOL_SCORE_CACHE_SECONDS = 300;

    // 22 Temmuz'da "prop-trader" moduna gecirildi (kullanici talebi) - eskiden daha genel/"haber
    // yorumcusu" tonundaki prompt, SADECE karliliga odaklanan acimasiz bir analist kimligine
    // degistirildi. Karar esiklerine (BUY_THRESHOLD, kullanicilarin ai_score_threshold'u) DOKUNMAZ -
    // sadece modelin HAM puanini/uslubunu etkiler, o puan yine ayni sıkı esiklerden gecmek zorundadir.
    // AYNI GUN (22 Temmuz) canli veriyle DENGELENDI: ilk versiyon asiri korkak cikti - gercek bir
    // ornekte REUSDT +%26.5/24s + 389M USDT hacim gibi ders kitabi bir kirilima SADECE 45 puan verdi
    // (kendi "60+" kuralina ragmen), cunku "kuskuda kaldiginda DUSUK puan ver, asla iyimser tahmin
    // yapma" ifadesi cok baskindi ve modeli net, veriyle desteklenmis sinyalleri de bastirmaya
    // itiyordu. Bu versiyon o ifadeyi yumusatip yerine acik bir "CESARET KURALI" ekliyor (guclu
    // %degisim + guclu hacim = 60-85 arasi puanla ODULLENDIR), "TEMKIN KURALI"nu (yatay/dusuk hacim
    // = 40-50) ise OLDUGU GIBI koruyor - o kisim canli veride dogru calisiyordu. Asagida
    // buildScoringPrompt() ile ayni REUSDT verisi kullanilarak gercek API cagrisiyla dogrulandi.
    // AYNI GUN, "Cift Katmanli Zirve Korumasi"nin mikro katmani icin ONCE prompt-ici bir "ANTI-FOMO
    // KURALI" denendi (coin gunun zirvesine yakinsa + zaten buyuk artis yapmissa skoru dusur) - ANCAK
    // canli testte modelin bunu GUVENILMEZ uyguladigi kanitlandi: "Gunluk Zirve Bilgisi" bloğu prompta
    // GORUNDUGU AN, icindeki gercek sayilara (pozisyon %30 olsa, degisim sadece %3 olsa BILE) HIC
    // BAKMADAN otomatik reddediyordu - yon-okuma hatasindaki (bkz. yukarida) AYNI kategori sorun:
    // model bileşik esik karsilastirmasini (pozisyon>=85 VE degisim>=15) guvenilir sekilde
    // uygulayamiyor. Bu yuzden kural PROMPTTAN CIKARILDI, yerine analyze()/analyzeMany()'de
    // isAtDailyPeakFomoRisk() ile PHP'de KESIN/mekanik olarak uygulaniyor (bkz. o metod) - TIPKI
    // MIN_VOLUME_FOR_AI_SCORING_USDT guard'i gibi. buildDailyPeakPromptFragment() hala calisir ama
    // artik SADECE bilgilendirici baglam metni gonderir, "puanı zorla dusur" talimati YOKTUR
    private const SCORING_SYSTEM_PROMPT = 'Sadece "SAYI|GEREKÇE" formatında yanıt ver. Başka hiçbir metin ekleme. ' .
        'Sen kâr odaklı, disiplinli bir kripto trade algoritmasısın - tek hedefin kazanma ihtimali yüksek ' .
        'işlemler bulmak. Haberlerdeki genel dedikodulara/spekülasyona prim verme. SADECE şunlara odaklan: ' .
        'fiyat hareketi, hacim anomalileri (volume spike), ani kırılımlar (breakout), net dönüş sinyalleri ' .
        '(reversal). CESARET KURALI: 24 saatlik fiyat değişimi güçlü pozitifse (ör. +%5 ve üzeri) VE hacim ' .
        'de güçlüyse, bu net bir momentum sinyalidir - veriyle desteklenmiş böyle bir kırılımı ASLA bastırma, ' .
        'cesurca 60 ile 85 arası yüksek bir puanla ödüllendir. TEMKİN KURALI: yatay (%1-3 civarı) veya hacmi ' .
        'düşük bir grafik görüyorsan puanı 40-50 bandında tut - temkinli ol ama net, veriyle desteklenmiş bir ' .
        'kırılımı asla kaçırma. Puanı SADECE gerçekten kararsız veya tehlikeli (pump-dump, veri desteği ' .
        'olmayan ani hareket) durumlarda 50 altına çek. Gerekçede uzun/edebi cümle KURMA - Binance AI ' .
        'analizleri gibi kısa, net, teknik ve aksiyon odaklı ol (örnek: "Güçlü hacimle direnç kırılımı, ' .
        'ivme yukarı yönlü." veya "Balina satış baskısı, trend zayıf.").';

    private readonly string $apiKey;
    private readonly string $groqKey;
    private readonly string $geminiKey;

    public function __construct()
    {
        $config = require __DIR__ . '/../../config/app.php';

        // Once admin panelinden (DB) girilen anahtara bak, yoksa config/app.php'deki degere don -
        // ucu de ayni "Ayar Deseni" (bkz. CLAUDE.md), her ucu icin ayri ayri
        $this->apiKey = Setting::get('openai_api_key') ?? (string) ($config['openai_api_key'] ?? '');
        $this->groqKey = Setting::get('groq_api_key') ?? (string) ($config['groq_api_key'] ?? '');
        $this->geminiKey = Setting::get('gemini_api_key') ?? (string) ($config['gemini_api_key'] ?? '');
    }

    // Saglayici zincirini onceliklendirilmis sirada doner - SADECE anahtari girilmis olanlar
    // listeye girer. OpenAI -> Groq -> Gemini: OpenAI varsayilan/ana saglayici, digerleri SADECE
    // bir oncekinin basarisiz oldugu durumda devreye giren yedekler
    // @return list<array{name: string, format: string, api_key: string, url: string, model: string}>
    private function getProviderChain(): array
    {
        $providers = [];

        if ($this->apiKey !== '') {
            $providers[] = ['name' => 'OpenAI', 'format' => 'openai', 'api_key' => $this->apiKey, 'url' => self::OPENAI_URL, 'model' => self::MODEL];
        }

        if ($this->groqKey !== '') {
            $providers[] = ['name' => 'Groq', 'format' => 'openai', 'api_key' => $this->groqKey, 'url' => self::GROQ_URL, 'model' => self::GROQ_MODEL];
        }

        if ($this->geminiKey !== '') {
            $providers[] = [
                'name' => 'Gemini',
                'format' => 'gemini',
                'api_key' => $this->geminiKey,
                'url' => sprintf(self::GEMINI_URL_TEMPLATE, self::GEMINI_MODEL),
                'model' => self::GEMINI_MODEL,
            ];
        }

        return $providers;
    }

    // Tum SAYI|GEREKCE puanlama isteklerinin (tekli/toplu, hangi saglayici olursa olsun) ORTAK
    // prompt metni - bkz. eski buildRequestPayload() yorumu, mantik degismedi, sadece saglayiciya
    // ozel govde/header olusturmadan AYRILDI (bkz. buildProviderRequest)
    // @param array{priceChangePercent: float, quoteVolume: float}|null $marketData
    private function buildScoringPrompt(string $symbol, ?array $marketData): string
    {
        if ($marketData !== null) {
            // Gercek MarketScanner verisi var: model artik sembol adindan tahmin yurutmuyor,
            // gercek 24s fiyat degisimi/hacmi uzerinden degerlendiriyor - dusen bir coin
            // artik yukselen bir coinle ayni jenerik "olumlu" skoru alamaz
            $macroTrendText = $this->buildMacroTrendPromptFragment($marketData) . $this->buildDailyPeakPromptFragment($marketData);

            // 22 Temmuz'da eklendi: canli veride gpt-4o-mini'nin buyuk POZITIF yuzdeleri (ör. +%26.5)
            // TUTARLI sekilde "dusus" olarak yanlis okudugu tespit edildi (4/4 tekrarli testte ayni
            // hata) - kok neden, eski format pozitif sayida ISARETSIZ gorunuyordu ("%26.50 degisti",
            // + yok, sadece negatifte - vardi), model buyuk sayiyi "cokus" ile iliskilendiriyordu.
            // Simdi hem sayiya acik +/- isareti (sprintf '+' bayragi) EKLENDI hem de yon PHP'de
            // (ground truth, modelin YORUMUNA birakilmadan) hesaplanip metne YON: ifadesiyle
            // acikca yaziliyor - model artik isareti CIKARSAMAK zorunda degil
            $directionWord = $marketData['priceChangePercent'] >= 0 ? 'YÜKSELİŞ' : 'DÜŞÜŞ';

            return sprintf(
                'Kıdemli bir kripto analisti olarak %s paritesini analiz et. ' .
                'Gerçek piyasa verisi: son 24 saatte fiyat %%%+.2f değişti (YÖN: %s), ' .
                '24 saatlik işlem hacmi %.0f USDT.%s ' .
                'Bu gerçek verilere dayanarak yükseliş potansiyeline 1-100 arası bir skor ver ' .
                '(düşüş trendindeki bir coine yüksek skor verme) ve en fazla 8 kelimelik çok kısa bir gerekçe yaz. ' .
                'Gerekçede "düşüş trendi ve düşük işlem hacmi" gibi genel/kalıp bir ifade KULLANMA - ' .
                'verilen gerçek yüzde ve hacim rakamlarına doğrudan atıfta bulunan, bu paritaye özgü bir ifade yaz. ' .
                'Yanıtı TAM OLARAK şu formatta ver: SAYI|GEREKÇE (örnek: 82|Güçlü alım hacmi ve pozitif momentum)',
                $symbol,
                $marketData['priceChangePercent'],
                $directionWord,
                $marketData['quoteVolume'],
                $macroTrendText
            );
        }

        return sprintf(
            'Kıdemli bir kripto analisti olarak %s paritesinin mevcut duyarlılığını analiz et. ' .
            'Yükseliş potansiyeline 1-100 arası bir skor ver ve en fazla 8 kelimelik çok kısa bir gerekçe yaz. ' .
            'Yanıtı TAM OLARAK şu formatta ver: SAYI|GEREKÇE (örnek: 82|Güçlü alım hacmi ve pozitif momentum)',
            $symbol
        );
    }

    // Saglayiciya ozel istek govdesini/header'larini olusturur - 'openai' formati (OpenAI VE Groq,
    // kasitli olarak AYNI Chat Completions govdesi) ile 'gemini' formati (Google'in kendi
    // generateContent semasi: contents/parts + ayri system_instruction alani) arasinda dallanir
    // @param array{name: string, format: string, api_key: string, url: string, model: string} $provider
    // @param array{priceChangePercent: float, quoteVolume: float}|null $marketData
    // @return array{0: string, 1: string[], 2: string} [url, headers, jsonBody]
    private function buildProviderRequest(array $provider, string $symbol, ?array $marketData): array
    {
        $prompt = $this->buildScoringPrompt($symbol, $marketData);

        if ($provider['format'] === 'gemini') {
            $url = $provider['url'] . '?key=' . urlencode($provider['api_key']);
            $headers = ['Content-Type: application/json'];
            $body = json_encode([
                'system_instruction' => ['parts' => [['text' => self::SCORING_SYSTEM_PROMPT]]],
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 60],
            ], JSON_UNESCAPED_UNICODE);

            return [$url, $headers, (string) $body];
        }

        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $provider['api_key']];
        $body = json_encode([
            'model' => $provider['model'],
            'messages' => [
                ['role' => 'system', 'content' => self::SCORING_SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.3,
            // Model bazen talimata ragmen once birkac kelime aciklama ekleyebiliyor (ör. "Skor: 82");
            // dusuk bir deger secilirse yanit sayiya ulasmadan kesilebiliyor (finish_reason=length)
            'max_tokens' => 40,
        ], JSON_UNESCAPED_UNICODE);

        return [$provider['url'], $headers, (string) $body];
    }

    // $marketData verilirse (MarketScanner'in gercek 24s fiyat degisimi/hacmi), OpenAI promptuna
    // eklenir - boylece skor sembol adindan "tahmin" yerine gercek piyasa verisine dayanir
    // @param array{priceChangePercent: float, quoteVolume: float}|null $marketData
    // @return array{symbol: string, score: int, reason: string, is_buy_signal: bool}
    public function analyze(string $symbol, ?array $marketData = null): array
    {
        if ($this->isVolumeTooLowForAiScoring($marketData)) {
            return [
                'symbol' => $symbol,
                'score' => self::LOW_VOLUME_REJECTION_SCORE,
                'reason' => self::LOW_VOLUME_REJECTION_REASON,
                'is_buy_signal' => false,
            ];
        }

        if ($this->isAtDailyPeakFomoRisk($marketData)) {
            return [
                'symbol' => $symbol,
                'score' => self::DAILY_PEAK_REJECTION_SCORE,
                'reason' => self::DAILY_PEAK_REJECTION_REASON,
                'is_buy_signal' => false,
            ];
        }

        $cache = $this->loadSymbolCache();
        $cached = $this->getCachedEntry($cache, $symbol);

        $result = $cached ?? $this->fetchSentimentScore($symbol, $marketData);

        if ($cached === null) {
            $this->rememberIfReal($cache, $symbol, $result);
        }

        return [
            'symbol' => $symbol,
            'score' => $result['score'],
            'reason' => $result['reason'],
            'is_buy_signal' => $result['score'] > self::BUY_THRESHOLD,
        ];
    }

    // bkz. MIN_VOLUME_FOR_AI_SCORING_USDT yorumu - marketData yoksa (ör. acik pozisyon yeniden
    // puanlama gibi hacim bilgisi olmayan cagrilar) guard devre disi kalir, sadece GERCEK hacim
    // verisiyle gelen cagrilarda devreye girer
    // @param array{priceChangePercent: float, quoteVolume: float}|null $marketData
    private function isVolumeTooLowForAiScoring(?array $marketData): bool
    {
        return $marketData !== null && $marketData['quoteVolume'] < self::MIN_VOLUME_FOR_AI_SCORING_USDT;
    }

    // bkz. DAILY_PEAK_POSITION_THRESHOLD yorumu - high_24h/low_24h/position_percent_24h yoksa
    // (ör. MarketScanner::calculateDailyPeakPosition high<=low donduyse) guard devre disi kalir
    // @param array{priceChangePercent: float, quoteVolume: float, position_percent_24h?: float}|null $marketData
    private function isAtDailyPeakFomoRisk(?array $marketData): bool
    {
        if ($marketData === null || !isset($marketData['position_percent_24h'])) {
            return false;
        }

        return $marketData['position_percent_24h'] >= self::DAILY_PEAK_POSITION_THRESHOLD
            && $marketData['priceChangePercent'] >= self::DAILY_PEAK_CHANGE_THRESHOLD;
    }

    // bkz. SYMBOL_SCORE_CACHE_KEY yorumu - tum onbellegi TEK bir JSON blob olarak (Setting uzerinden)
    // okur/yazar, sembol sayisi arttikca ayri satirlar birikmesin diye
    // @return array<string, array{score: int, reason: string, cached_at: int}>
    private function loadSymbolCache(): array
    {
        $raw = Setting::get(self::SYMBOL_SCORE_CACHE_KEY);

        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    // @param array<string, array{score: int, reason: string, cached_at: int}> $cache
    // @return array{score: int, reason: string}|null taze (SYMBOL_SCORE_CACHE_SECONDS icinde) bir kayit yoksa null
    private function getCachedEntry(array $cache, string $symbol): ?array
    {
        $entry = $cache[$symbol] ?? null;

        if ($entry === null || !isset($entry['cached_at'])) {
            return null;
        }

        if (time() - (int) $entry['cached_at'] > self::SYMBOL_SCORE_CACHE_SECONDS) {
            return null;
        }

        return ['score' => (int) $entry['score'], 'reason' => (string) $entry['reason']];
    }

    // Basarisiz (notr fallback: score=50 VE reason='') sonuclar KASITLI olarak onbelleklenmez -
    // aksi halde gecici bir OpenAI hatasi/kota asimi, onbellek suresi boyunca "gercek skor" gibi
    // diger TUM cagiran taraflara da yayilirdi
    // @param array<string, array{score: int, reason: string, cached_at: int}> $cache
    // @param array{score: int, reason: string} $result
    private function rememberIfReal(array $cache, string $symbol, array $result): void
    {
        if ($result['reason'] === '' && $result['score'] === self::DEFAULT_NEUTRAL_SCORE) {
            return;
        }

        $cache[$symbol] = ['score' => $result['score'], 'reason' => $result['reason'], 'cached_at' => time()];
        $this->saveSymbolCache($cache);
    }

    // @param array<string, array{score: int, reason: string, cached_at: int}> $cache
    private function saveSymbolCache(array $cache): void
    {
        // Onbellek suresinin 4 kati kadar eski kayitlar temizlenir - JSON blob'un sinirsiz
        // buyumesini onler (taranan sembol havuzu zamanla degisir/genisler)
        $cutoff = time() - (self::SYMBOL_SCORE_CACHE_SECONDS * 4);

        foreach ($cache as $symbol => $entry) {
            if (!isset($entry['cached_at']) || (int) $entry['cached_at'] < $cutoff) {
                unset($cache[$symbol]);
            }
        }

        Setting::set(self::SYMBOL_SCORE_CACHE_KEY, json_encode($cache, JSON_UNESCAPED_UNICODE));
    }

    // 25 adayi TEK TEK sirayla beklemek (~25 x 1-3sn = 25-75sn) paylasimli hostinglerin varsayilan
    // PHP zaman asimini (genelde 30sn) asip istegin yarida kesilmesine yol aciyordu ("Bağlantı Yok").
    // Bunun yerine istekler curl_multi ile KUCUK GRUPLAR (batch) halinde ES ZAMANLI gonderilir -
    // hem toplam sureyi ~5 kata kadar kisaltir hem de TUM adaylari tek seferde (25 istek ayni anda)
    // gondermeyip OpenAI'nin ani (burst) hiz sinirini tetikleme riskini sinirli tutar
    private const BATCH_SIZE = 5;
    private const BATCH_DELAY_MICROSECONDS = 300_000; // gruplar arasi bekleme (0.3 saniye)

    // @param string[] $symbols
    // @param array<string, array{priceChangePercent: float, quoteVolume: float}> $marketDataMap sembol => gercek piyasa verisi
    // @return array<array{symbol: string, score: int, reason: string, is_buy_signal: bool}>
    public function analyzeMany(array $symbols, array $marketDataMap = []): array
    {
        if ($symbols === []) {
            return [];
        }

        // bkz. SYMBOL_SCORE_CACHE_KEY yorumu - onbellekte taze bir kaydi olan semboller icin
        // OpenAI'ye HIC gidilmez, sadece eksik olanlar toplu/es zamanli cekilir
        $cache = $this->loadSymbolCache();
        $scoresBySymbol = [];
        $needsFetch = [];

        foreach ($symbols as $symbol) {
            if ($this->isVolumeTooLowForAiScoring($marketDataMap[$symbol] ?? null)) {
                $scoresBySymbol[$symbol] = [
                    'score' => self::LOW_VOLUME_REJECTION_SCORE,
                    'reason' => self::LOW_VOLUME_REJECTION_REASON,
                ];
                continue; // modele hic sorulmaz, onbellege de yazilmaz - her cagride mekanik olarak yeniden degerlendirilir
            }

            if ($this->isAtDailyPeakFomoRisk($marketDataMap[$symbol] ?? null)) {
                $scoresBySymbol[$symbol] = [
                    'score' => self::DAILY_PEAK_REJECTION_SCORE,
                    'reason' => self::DAILY_PEAK_REJECTION_REASON,
                ];
                continue;
            }

            $cachedEntry = $this->getCachedEntry($cache, $symbol);

            if ($cachedEntry !== null) {
                $scoresBySymbol[$symbol] = $cachedEntry;
            } else {
                $needsFetch[] = $symbol;
            }
        }

        // Hicbir saglayici anahtari (OpenAI/Groq/Gemini) girilmemisse fetchBatchConcurrently() zaten
        // bunu tespit edip notr donuyor - burada AYRICA erken kontrol etmeye gerek yok, tek fark
        // "hicbir saglayici yok" durumunun log mesaji artik fetchBatchConcurrently() icinde, tum
        // saglayicilari kapsayacak sekilde yaziliyor (bkz. o fonksiyon)
        $freshlyFetched = [];

        foreach (array_chunk($needsFetch, self::BATCH_SIZE) as $batchIndex => $batch) {
            if ($batchIndex > 0) {
                usleep(self::BATCH_DELAY_MICROSECONDS);
            }

            $batchResults = $this->fetchBatchConcurrently($batch, $marketDataMap);
            $scoresBySymbol += $batchResults;
            $freshlyFetched += $batchResults;
        }

        if ($freshlyFetched !== []) {
            // KRITIK: rememberIfReal() burada dongude COKLU kez CAGRILMAZ - PHP dizileri referansla
            // degil KOPYALANARAK gecer, art arda cagrilsa her seferinde AYNI eski $cache'ten baslar
            // ve saveSymbolCache() bir onceki adimin eklerini SILIP sadece son sembolu yazardi. Bunun
            // yerine tum taze sonuclar TEK bir yerel $cache uzerinde birlestirilip TEK seferde kaydedilir
            foreach ($freshlyFetched as $symbol => $entry) {
                if ($entry['reason'] === '' && $entry['score'] === self::DEFAULT_NEUTRAL_SCORE) {
                    continue; // basarisiz (notr fallback) sonuc onbelleklenmez - bkz. rememberIfReal yorumu
                }

                $cache[$symbol] = ['score' => $entry['score'], 'reason' => $entry['reason'], 'cached_at' => time()];
            }

            $this->saveSymbolCache($cache);
        }

        $results = [];
        foreach ($symbols as $symbol) {
            $result = $scoresBySymbol[$symbol] ?? ['score' => self::DEFAULT_NEUTRAL_SCORE, 'reason' => ''];
            $results[] = [
                'symbol' => $symbol,
                'score' => $result['score'],
                'reason' => $result['reason'],
                'is_buy_signal' => $result['score'] > self::BUY_THRESHOLD,
            ];
        }

        return $results;
    }

    // Bir grup sembolu (en fazla BATCH_SIZE) saglayici ZINCIRI uzerinden es zamanli gecirir - ONCE
    // TUMU ana saglayiciya (OpenAI) concurrent gonderilir, O SAGLAYICIDA basarisiz olanlar SADECE
    // kendileri bir sonraki saglayiciya (Groq, sonra Gemini) yine grup halinde concurrent tekrar
    // denenir. 22 Temmuz'da eklendi (bkz. CHANGELOG) - OpenAI'nin gunluk kota (RPD) asimi tek
    // saglayicida TUM puanlamayi durdurabiliyordu
    // @return array<string, array{score: int, reason: string}> sembol => sonuc
    private function fetchBatchConcurrently(array $symbols, array $marketDataMap): array
    {
        $providers = $this->getProviderChain();

        if ($providers === []) {
            error_log('NexaTrade SentimentService: hiçbir AI sağlayıcı anahtarı tanımlı değil (toplu tarama için nötr skor döndürülüyor).');

            return array_fill_keys($symbols, ['score' => self::DEFAULT_NEUTRAL_SCORE, 'reason' => '']);
        }

        $remaining = $symbols;
        $results = [];

        foreach ($providers as $provider) {
            if ($remaining === []) {
                break;
            }

            $providerResults = $this->fetchBatchFromProvider($remaining, $marketDataMap, $provider);
            $stillFailed = [];

            foreach ($remaining as $symbol) {
                if (isset($providerResults[$symbol])) {
                    $results[$symbol] = $providerResults[$symbol];
                } else {
                    $stillFailed[] = $symbol;
                }
            }

            $remaining = $stillFailed;
        }

        // Zincirdeki HICBIR saglayici basarili olamadi - notr fallback
        foreach ($remaining as $symbol) {
            $results[$symbol] = ['score' => self::DEFAULT_NEUTRAL_SCORE, 'reason' => ''];
        }

        return $results;
    }

    // Bir grubu TEK bir saglayiciya karsi es zamanli (curl_multi) sorar - basarisiz olan
    // semboller sonuc dizisine HIC girmez (cagiran taraf - fetchBatchConcurrently - bunu "bu
    // saglayicida basarisiz" olarak yorumlayip zincirdeki bir sonraki saglayiciya devreder)
    // @param array{name: string, format: string, api_key: string, url: string, model: string} $provider
    // @return array<string, array{score: int, reason: string}>
    private function fetchBatchFromProvider(array $symbols, array $marketDataMap, array $provider): array
    {
        $multiHandle = curl_multi_init();
        $handles = [];

        foreach ($symbols as $symbol) {
            [$url, $headers, $body] = $this->buildProviderRequest($provider, $symbol, $marketDataMap[$symbol] ?? null);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            ]);

            curl_multi_add_handle($multiHandle, $ch);
            $handles[$symbol] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($multiHandle, $running);

            if ($running > 0) {
                curl_multi_select($multiHandle);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];

        foreach ($handles as $symbol => $ch) {
            try {
                $curlErrno = curl_errno($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $rawResponse = (string) curl_multi_getcontent($ch);

                if ($curlErrno !== 0) {
                    throw new RuntimeException("cURL hatası ({$curlErrno}): " . curl_error($ch));
                }

                if ($httpCode >= 400) {
                    throw new RuntimeException("{$provider['name']} API HTTP {$httpCode}: " . substr($rawResponse, 0, 300));
                }

                $content = $this->extractMessageContent($rawResponse, $provider['format']);
                $results[$symbol] = $this->buildResultFromContent($content, $marketDataMap[$symbol] ?? null);
            } catch (Throwable $e) {
                error_log("NexaTrade SentimentService: {$symbol} için {$provider['name']} başarısız (toplu) - " . $e->getMessage());
                // KASITLI: $results[$symbol] atanmaz - cagiran taraf bunu "bu saglayicida basarisiz"
                // sayip zincirdeki bir sonraki saglayiciya devreder (bkz. fetchBatchConcurrently)
            }

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);

        return $results;
    }

    // AI Radar'da taranan coinlerin fiyat hareketi + duyarlilik skorlarina bakarak 2-3 cumlelik
    // Turkce bir "genel piyasa nabzi" yorumu uretir. 15 dakika onbelleklenir (app_settings uzerinden)
    // ki Dashboard her yenilendiginde ekstra bir OpenAI cagrisi tetiklenmesin.
    // @param array<array{symbol: string, priceChangePercent: float, score: int}> $radarData
    public function generateMarketPulse(array $radarData): string
    {
        $cached = Setting::getMeta(self::MARKET_PULSE_CACHE_KEY);

        if ($cached !== null && strtotime((string) $cached['updated_at']) > time() - self::MARKET_PULSE_CACHE_SECONDS) {
            return (string) $cached['value'];
        }

        if ($this->apiKey === '' || $radarData === []) {
            return $cached['value'] ?? 'AI piyasa yorumu için OpenAI API anahtarı gereklidir.';
        }

        try {
            $summaryLines = [];

            foreach ($radarData as $coin) {
                $summaryLines[] = sprintf(
                    '%s: %+.1f%% (AI skor %d)',
                    $coin['symbol'],
                    $coin['priceChangePercent'],
                    $coin['score']
                );
            }

            $prompt = sprintf(
                'Kripto piyasasında taranan coinlerin son 24 saatlik fiyat değişimi ve AI duyarlılık skorları: %s. ' .
                'Bu verilere dayanarak genel piyasa nabzını 2-3 cümlelik kısa bir Türkçe yorumla özetle. ' .
                'Sadece yorumu yaz, başlık veya madde işareti ekleme.',
                implode(', ', $summaryLines)
            );

            $payload = [
                'model' => self::MODEL,
                'messages' => [
                    ['role' => 'system', 'content' => 'Kısa, net, profesyonel bir kripto piyasa yorumcususun. Sadece istenen yorumu yaz.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.5,
                'max_tokens' => 150,
            ];

            $rawResponse = $this->postToOpenAi($payload);
            $content = trim($this->extractMessageContent($rawResponse));

            Setting::set(self::MARKET_PULSE_CACHE_KEY, $content);

            return $content;
        } catch (Throwable $e) {
            error_log('NexaTrade SentimentService: piyasa nabzı özeti alınamadı - ' . $e->getMessage());

            return $cached['value'] ?? 'Piyasa yorumu şu anda oluşturulamadı.';
        }
    }

    // Trade Post-Mortem'in AI yedegi: hicbir kural bazli kontrol (hiz/BTC etkisi/zirh-slippage)
    // eslesmediginde, coinin kisa vadede neden dustugune dair TEK cumlelik bir yorum ister. Diger
    // tum SentimentService metodlariyla AYNI fail-open ilkesi: hata durumunda genel bir metne
    // duser, ASLA exception firlatmaz - bir islemin kapanmasi/kaydi bu cagriya bagimli olmamali
    public function explainLoss(string $symbol): string
    {
        $fallback = 'Net bir kural eşleşmedi - muhtemelen coine özgü veya kısa vadeli bir piyasa dalgalanması.';

        if ($this->apiKey === '') {
            return $fallback;
        }

        try {
            $payload = [
                'model' => self::MODEL,
                'messages' => [
                    ['role' => 'system', 'content' => 'Sadece istenen tek cümlelik Türkçe yorumu yaz. Başka hiçbir metin ekleme.'],
                    ['role' => 'user', 'content' => sprintf(
                        '%s paritesi son 1 saatte neden düştü, 1 cümleyle (en fazla 15 kelime) özetle.',
                        $symbol
                    )],
                ],
                'temperature' => 0.4,
                'max_tokens' => 60,
            ];

            $rawResponse = $this->postToOpenAi($payload);
            $explanation = trim($this->extractMessageContent($rawResponse));

            // 20 Temmuz'da tespit edildi: OpenAI bazen bos/anlamsiz bir tamamlama donduruyor -
            // bu bir HATA/exception DEGIL (istek basariyla tamamlandi), bu yuzden try/catch bunu
            // hic YAKALAMIYORDU ve bos string '' loss_reason olarak DB'ye yaziliyordu (musteri
            // panelinde "sebep yok" gibi gorunuyordu). Bos sonuc da artik ayni fallback'e duser
            return $explanation !== '' ? $explanation : $fallback;
        } catch (Throwable $e) {
            error_log("NexaTrade SentimentService: {$symbol} için zarar açıklaması alınamadı - " . $e->getMessage());

            return $fallback;
        }
    }

    // Ingilizce metin listesini (ör. haber basliklari) TEK bir OpenAI cagrisiyla Turkceye cevirir
    // Cagrilan taraf (NewsService) sonucu kendi onbelleginde tutar, bu metod her seferinde yeniden cagrilmaz
    // Ceviri basarisiz olursa orijinal (Ingilizce) listeyi aynen dondurur - hicbir sey gostermemekten iyidir
    // @param string[] $texts
    // @return string[]
    public function translateToTurkish(array $texts): array
    {
        if ($texts === [] || $this->apiKey === '') {
            return $texts;
        }

        try {
            $numbered = [];

            foreach (array_values($texts) as $index => $text) {
                $numbered[] = ($index + 1) . '. ' . $text;
            }

            $prompt = "Aşağıdaki İngilizce kripto para haber başlıklarını doğal ve akıcı Türkçeye çevir. " .
                "Her satırı aynı numarayla, aynı sırada döndür. Başka hiçbir açıklama ekleme.\n\n" .
                implode("\n", $numbered);

            $payload = [
                'model' => self::MODEL,
                'messages' => [
                    ['role' => 'system', 'content' => 'Sadece istenen numaralı çeviri listesini döndür. Başka hiçbir metin ekleme.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.3,
                'max_tokens' => 1000,
            ];

            $rawResponse = $this->postToOpenAi($payload);
            $content = $this->extractMessageContent($rawResponse);

            return $this->parseNumberedTranslations($content, array_values($texts));
        } catch (Throwable $e) {
            error_log('NexaTrade SentimentService: haber başlığı çevirisi alınamadı - ' . $e->getMessage());

            return $texts;
        }
    }

    // "1. Çeviri metni" seklindeki satirlari ayristirir; bir satir eksik/bozuksa o satir icin
    // orijinal (Ingilizce) metne geri duser, boylece tek bir satirin bozulmasi tum listeyi etkilemez
    // @param string[] $originals
    // @return string[]
    private function parseNumberedTranslations(string $content, array $originals): array
    {
        $lines = preg_split('/\r?\n/', trim($content)) ?: [];
        $parsed = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*(\d+)[.\)]\s*(.+)$/', $line, $matches)) {
                $parsed[((int) $matches[1]) - 1] = trim($matches[2]);
            }
        }

        $result = [];

        foreach ($originals as $index => $original) {
            $result[] = $parsed[$index] ?? $original;
        }

        return $result;
    }

    // Sembolu (ve varsa gercek fiyat degisimi/hacmi) saglayici ZINCIRI uzerinden (OpenAI -> Groq ->
    // Gemini) gonderip 1-100 arasi bir yukselis potansiyeli skoru + kisa gerekce ister - zincirdeki
    // ilk basarili saglayicinin sonucu kullanilir, biri basarisiz olursa sirada bir sonraki denenir
    // @param array{priceChangePercent: float, quoteVolume: float}|null $marketData
    // @return array{score: int, reason: string}
    private function fetchSentimentScore(string $symbol, ?array $marketData = null): array
    {
        $providers = $this->getProviderChain();

        if ($providers === []) {
            error_log("NexaTrade SentimentService: hiçbir AI sağlayıcı anahtarı tanımlı değil ({$symbol} için nötr skor döndürülüyor).");

            return ['score' => self::DEFAULT_NEUTRAL_SCORE, 'reason' => ''];
        }

        foreach ($providers as $provider) {
            try {
                [$url, $headers, $body] = $this->buildProviderRequest($provider, $symbol, $marketData);
                $rawResponse = $this->postRaw($url, $headers, $body, $provider['name']);
                $content = $this->extractMessageContent($rawResponse, $provider['format']);

                return $this->buildResultFromContent($content, $marketData);
            } catch (Throwable $e) {
                error_log("NexaTrade SentimentService: {$symbol} için {$provider['name']} başarısız - " . $e->getMessage());
                continue; // zincirdeki bir sonraki saglayiciyi dene
            }
        }

        error_log("NexaTrade SentimentService: {$symbol} için TÜM sağlayıcılar başarısız oldu, nötr skor döndürülüyor.");

        return ['score' => self::DEFAULT_NEUTRAL_SCORE, 'reason' => ''];
    }

    // OpenAI'nin dondurdugu ham metni (SAYI|GEREKCE) skor+gerekceye cevirir; gerekce metni gercek
    // veriyle celisiyorsa tutarli bir metinle degistirir - hem tekli hem toplu yol ortak kullanir
    // @param array{priceChangePercent: float, quoteVolume: float}|null $marketData
    private function buildResultFromContent(string $content, ?array $marketData): array
    {
        $result = $this->parseScoreAndReason($content);

        // Model bazen SKORU gercek veriye gore dogru hesaplasa bile, GEREKCE METNINDE
        // yanlis yon soyleyebiliyor (ör. fiyat %18 yukselmisken "dususte" demek gibi).
        // Karar mekanizmasi zaten sayisal skora dayandigi icin bu metnin kendisi islemi
        // etkilemez, ama kullaniciya yanlis bilgi gostermemek icin gercek veriyle celisen
        // bir gerekce tespit edilirse, gercek sayilara dayanan tutarli bir metinle degistirilir
        if ($marketData !== null && $this->reasonContradictsDirection($result['reason'], $marketData['priceChangePercent'])) {
            $result['reason'] = $this->buildFallbackReason($marketData['priceChangePercent'], $marketData['quoteVolume']);
        }

        return $result;
    }

    // Modelin gerekce metninde kullandigi yon kelimelerinin, gercek fiyat degisiminin isaretiyle
    // (yukseliş/dusus) celisip celismedigini kontrol eder. Kucuk/nötr degisimlerde (±%1 ici)
    // yon iddiasi zaten anlamli olmadigindan kontrol atlanir
    private function reasonContradictsDirection(string $reason, float $priceChangePercent): bool
    {
        if (abs($priceChangePercent) < 1.0) {
            return false;
        }

        $normalizedReason = mb_strtolower($reason, 'UTF-8');
        $declineWords = ['düşüş', 'düştü', 'negatif', 'gerileme', 'azalış'];
        $riseWords = ['yükseliş', 'yükseldi', 'pozitif', 'artış', 'artan'];

        $mentionsDecline = false;
        foreach ($declineWords as $word) {
            if (str_contains($normalizedReason, $word)) {
                $mentionsDecline = true;
                break;
            }
        }

        $mentionsRise = false;
        foreach ($riseWords as $word) {
            if (str_contains($normalizedReason, $word)) {
                $mentionsRise = true;
                break;
            }
        }

        if ($priceChangePercent > 0 && $mentionsDecline && !$mentionsRise) {
            return true;
        }

        if ($priceChangePercent < 0 && $mentionsRise && !$mentionsDecline) {
            return true;
        }

        return false;
    }

    // MarketScanner::calculateMacroTrend()'in hesapladigi 3 aylik (90 gunluk) yuksek/dusuk/pozisyon%
    // verisi $marketData icinde varsa, GPT promptuna eklenecek ek bir cumle uretir - yoksa bos
    // string doner (prompt formatini bozmadan sessizce atlanir). Amac: GPT'nin SADECE anlik 24s
    // harekete bakip 3 aylik zirveye (dirence) cok yakin bir coine yanlislikla yuksek skor
    // vermesini (FOMO/tepeden alim riski) onlemek
    private function buildMacroTrendPromptFragment(array $marketData): string
    {
        if (!isset($marketData['high_90d'], $marketData['low_90d'], $marketData['position_percent_90d'])) {
            return '';
        }

        return sprintf(
            ' Makro Trend Bilgisi: Bu coin son 90 günde en düşük %s, en yüksek %s seviyelerini görmüştür. ' .
            'Şu anki fiyat bu 3 aylık aralıkta %%%.1f seviyesindedir (100%%\'e yakınsa 3 aylık zirveye/dirence yakın demektir). ' .
            'Fiyat zirveye çok yakınsa (ör. %%80 üzeri), anlık hacim yüksek olsa bile bunu riskli/"tepeden alım" ihtimali olarak değerlendirip skoru düşür.',
            $this->formatMacroPrice($marketData['low_90d']),
            $this->formatMacroPrice($marketData['high_90d']),
            $marketData['position_percent_90d']
        );
    }

    // 22 Temmuz'da eklendi: "Cift Katmanli Zirve Korumasi"nin MIKRO katmani - buildMacroTrendPromptFragment()
    // (90 GUNLUK zirve) ile AYNI mantigin 24 SAATLIK karsiligi. Gerekce: 90 gunluk zirveye uzak ama
    // SON birkac saatte sert pompalanip KENDI gunluk zirvesine yapisan bir coin (canli ornek: LISTAUSDT,
    // AI skoru 85 ile alindi, 13.9 dakika sonra Zarar Kes'e carpti - "Ani Volatilite/iğne" olarak
    // siniflandirildi, muhtemelen tam zirveden alinan bir duzeltme/pullback'ti) 90 gunluk kontrolden
    // GECER ama bu YENI kontrol olmadan yakalanamazdi. NOT: net esik durumu (pozisyon>=85 VE
    // degisim>=15) artik isAtDailyPeakFomoRisk() ile MEKANIK olarak yakalanip modele hic sorulmadan
    // reddediliyor (bkz. o metod - prompt-ici versiyonu guvenilmez cikti). Bu fragment SADECE o sert
    // esigin ALTINDA kalan (ör. pozisyon %60-84) daha gri/belirsiz durumlar icin modele BAGLAM verir,
    // zorunlu bir puan talimati ICERMEZ. high_24h/low_24h/position_percent_24h yoksa sessizce atlanir
    private function buildDailyPeakPromptFragment(array $marketData): string
    {
        if (!isset($marketData['high_24h'], $marketData['low_24h'], $marketData['position_percent_24h'])) {
            return '';
        }

        return sprintf(
            ' Günlük Zirve Bilgisi: Bu coin son 24 saatte %s ile %s arasında hareket etti. ' .
            'Şu anki fiyat bu günlük aralıkta %%%.1f seviyesindedir (100%%\'e yakınsa GÜNÜN zirvesine yapışık, ' .
            'düşükse zirveden düzeltme yapmış demektir) - bunu bağlam olarak değerlendir.',
            $this->formatMacroPrice($marketData['low_24h']),
            $this->formatMacroPrice($marketData['high_24h']),
            $marketData['position_percent_24h']
        );
    }

    // Mikro-cap coinlerin fiyatlarini (ör. 0.0000342) bilimsel notasyona donusturmeden, anlamli
    // basamak sayisini koruyarak GPT promptuna okunakli sekilde yazar
    private function formatMacroPrice(float $price): string
    {
        if ($price >= 1) {
            return number_format($price, 2);
        }

        $formatted = rtrim(rtrim(sprintf('%.8f', $price), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    // Modelin gerekce metni gercek veriyle celisince kullanilan, sayilara birebir dayali
    // (dolayisiyla asla yanlis yon soyleyemeyen) yedek aciklama
    private function buildFallbackReason(float $priceChangePercent, float $quoteVolume): string
    {
        $direction = $priceChangePercent >= 0 ? 'yükseliş' : 'düşüş';

        return sprintf('Son 24s %%%.1f %s, hacim %.0f USDT', $priceChangePercent, $direction, $quoteVolume);
    }

    private function postToOpenAi(array $payload): string
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => self::OPENAI_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
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
            throw new RuntimeException("OpenAI API HTTP {$httpCode}: " . substr((string) $rawResponse, 0, 300));
        }

        return (string) $rawResponse;
    }

    // postToOpenAi() ile AYNI cURL mantigi ama saglayici-bagimsiz (url/header/govde disaridan
    // gelir) - explainLoss()/translateToTurkish()/generateMarketPulse() BILINCLI olarak hala
    // postToOpenAi()'i (sadece OpenAI) kullanmaya devam ediyor, kapsam SADECE asil alim kararini
    // etkileyen puanlama yoluna (fetchSentimentScore/fetchBatchFromProvider) daraltildi
    // @param string[] $headers
    private function postRaw(string $url, array $headers, string $body, string $providerName): string
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
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
            throw new RuntimeException("{$providerName} API HTTP {$httpCode}: " . substr((string) $rawResponse, 0, 300));
        }

        return (string) $rawResponse;
    }

    // 'openai' formati (OpenAI VE Groq - ikisi de AYNI govde) icin choices[0].message.content,
    // 'gemini' formati icin candidates[0].content.parts[0].text okunur
    private function extractMessageContent(string $rawResponse, string $format = 'openai'): string
    {
        $decoded = json_decode($rawResponse, true);

        if ($format === 'gemini') {
            $content = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
        } else {
            $content = $decoded['choices'][0]['message']['content'] ?? null;
        }

        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('AI sağlayıcı yanıtında beklenen içerik bulunamadı.');
        }

        return $content;
    }

    // "SAYI|GEREKÇE" formatini ayristirir; model talimata tam uymayip fazladan metin donduruse
    // bile (ör. "Skor: 82 | Güçlü hacim") ilk sayiyi ve varsa "|" sonrasi gerekceyi guvenli sekilde yakalar
    // @return array{score: int, reason: string}
    private function parseScoreAndReason(string $content): array
    {
        $cleaned = trim($content);
        $parts = explode('|', $cleaned, 2);

        if (!preg_match('/-?\d+/', $parts[0], $matches)) {
            throw new RuntimeException("OpenAI yanıtı sayıya çevrilemedi: \"{$cleaned}\"");
        }

        $score = max(1, min(100, (int) $matches[0]));
        $reason = isset($parts[1]) ? trim($parts[1]) : '';

        return ['score' => $score, 'reason' => $reason];
    }
}
