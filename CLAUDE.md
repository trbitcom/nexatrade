# NexaTrade — Proje Rehberi

Bu dosya, bu depoda çalışırken Claude Code'a (ve gelecekteki oturumlara) rehberlik eder.

## Proje Nedir

NexaTrade, Binance üzerinde otonom kripto para al-sat işlemleri yapan, çok kullanıcılı (multi-tenant)
bir PHP 8 SaaS platformudur. Framework kullanmaz — özel (custom) bir MVC yapısı, PDO ile doğrudan
MySQL erişimi ve `declare(strict_types=1)` ile yazılmıştır. **Git deposu değildir** (`.git` yok) —
sürüm takibi `config/app.php`'deki `app_version` + `CHANGELOG.md` ikilisiyle elle yapılır.

**Dil:** Tüm kod yorumları, commit/changelog mesajları ve kullanıcı arayüzü **Türkçe**dir. Kullanıcıyla
iletişim de Türkçe olmalıdır (aksi istenmedikçe).

**Ortam:** Yerel geliştirme XAMPP üzerinde (`/Applications/XAMPP/xamppfiles/htdocs/nexatrade`), canlı
ortam ise ayrı bir **cPanel** sunucusudur. Bu iki ortam arasında otomatik senkronizasyon YOKTUR —
değiştirilen dosyalar kullanıcı tarafından elle (FileZilla vb.) cPanel'e yüklenir (bkz. "Dağıtım İş Akışı").

## Mimari Kısıtlar (Değiştirilemez Kurallar)

- **Sonsuz döngü veya `sleep()` YOK.** Sistem yalnızca gelen bir HTTP isteğiyle (webhook POST veya
  cron'un tetiklediği GET) çalışır: işini yapar ve sonlanır. Otonom modüller cPanel Cron Job'larıyla
  periyodik olarak tetiklenir — sunucu tarafında sürekli çalışan bir işlem asla olmaz.
- **Belge kökü proje köküdür, `public/` DEĞİL.** Kök dizindeki `.htaccess`, `app/`, `config/` ve
  `database.sql`'e doğrudan erişimi engeller, geri kalan her isteği `public/index.php`'ye yönlendirir.
  Statik varlıklar (logo, favicon vb.) bu yüzden `public/assets/` değil, proje kökündeki `assets/`
  altında olmalı — aksi halde 404 alırsınız (bu proje boyunca bir kere yaşanmış bir hata).
- **PDO her zaman hazırlanmış sorgularla (prepared statements) kullanılır.** `PDO::ATTR_EMULATE_PREPARES`
  kapalıdır — `LIMIT` gibi yerlerde tam sayı bağlarken `PDO::PARAM_INT` açıkça belirtilmelidir.
- **Dış API çağrılarında katı zaman aşımı zorunludur.** Her `curl_setopt_array` çağrısı
  `CURLOPT_CONNECTTIMEOUT` (3 sn) ve `CURLOPT_TIMEOUT` (5 sn) içermelidir — bir API yanıt vermezse
  sistem asla uzun süre kilitlenmemelidir. Yeni bir dış servis eklerken bu değerleri birebir taklit edin.

## Dizin Yapısı

```
app/
  Controllers/   — HTTP giriş noktaları (aşağıda tam liste)
  Core/          — Router, Database (Singleton), Session, AuthMiddleware, Url
  Models/        — Veritabanı erişim katmanı (aşağıda tam liste)
  Services/      — İş mantığı + dış API entegrasyonları (aşağıda tam liste)
  Views/         — Düz PHP şablonları (auth/, admin/, dashboard/) — templating engine yok
public/
  index.php      — Front controller (router burada kurulur)
  assets/        — CSS/JS (Tailwind derlenmiş çıktısı burada — CDN değil, `npm run build:css`)
assets/img/      — Statik görseller (logo, favicon) — belge kökü proje kökü olduğu için BURADA olmalı
config/
  app.php        — Uygulama ayarları (token'lar, API anahtarları, eşikler) — bkz. "Ayar Deseni"
  database.php   — DB bağlantı bilgisi + `encryption_key`
database.sql     — Şema + kronolojik migration geçmişi (idempotent: `CREATE TABLE IF NOT EXISTS` +
                   sonradan eklenen `ALTER TABLE ... ADD COLUMN` blokları, her biri hangi bug/özelliğin
                   sebep olduğunu açıklayan bir yorumla)
storage/logs/    — Her serviste tekrarlanan aynı desenle yazılan hata logları (bkz. "Loglama")
CHANGELOG.md     — Sürüm geçmişinin TEK doğru kaynağı (bkz. "Versiyonlama Kuralı")
```

### Controller'lar (`app/Controllers/`)

| Dosya | Görevi |
|---|---|
| `AuthController` | Kayıt (yeni kullanıcı `status='passive'` ile başlar, admin onayı gerekir), giriş, çıkış |
| `AdminController` | Kullanıcı yönetimi (durum/rol/silme/şifre sıfırlama) + tüm gizli anahtar/eşik ayarları |
| `DashboardController` | Ana müşteri paneli + ~20 AJAX/JSON uç noktası (bakiye, radar, haberler, pozisyonlar, PNL vb.) |
| `AutoTradeController` | **Ana "AI Avcı" (spot/LONG) motoru** — en büyük dosya (~1860 satır). Tarama, puanlama, filtreler, alım, mevcut pozisyon yönetimi (trailing stop, DCA, cooldown) hep burada |
| `AutoFuturesTradeController` | İnce bir cron sarmalayıcı — tüm iş mantığı `FuturesTradingService`'te |
| `ListingSniperController` | Cron sarmalayıcı — `ListingSniperService::run()` |
| `SmartMoneyController` | Cron sarmalayıcı — `SmartMoneyTracker::run()` |
| `TelegramWebhookController` | Telegram'ın çağırdığı genel uç nokta — `/start {token}` deep-link doğrulaması |
| `WebhookController` | TradingView tarzı dış sinyal alıcısı |
| `BacktestController` | Sadece admin — mekanik filtre kurallarını (AI YOK) geçmiş verilerle simüle eder |

### Servisler (`app/Services/`)

| Dosya | Görevi |
|---|---|
| `BinanceService` | İmzalı Binance **spot** REST çağrıları |
| `BinanceFuturesService` | İmzalı Binance **futures** (`fapi.binance.com`) REST çağrıları — spot'tan bilinçli olarak ayrı (farklı emir tipleri, kaldıraç, likidasyon fiyatı) |
| `Encryption` | AES-256-CBC ile API anahtarı şifreleme/çözme |
| `MarketScanner` | Genel (kimlik doğrulamasız) 24s ticker taraması — "Dynamic Volume Pool"u besler (Top 50, `DYNAMIC_POOL_SIZE`) + RSI/ATR gibi teknik gösterge hesaplamaları (`calculateRsi()`, `calculateAtr()`) |
| `SentimentService` | OpenAI tabanlı duyarlılık puanlaması — hata durumunda nötr (50) döner, asla patlamaz |
| `TechnicalScoreEngine` | AI'dan bağımsız, tamamen deterministik 1-100 teknik puanlama (RSI/MA20/MACD, 15dk mumlar + 5dk "Dipten Dönüş Pusu" dedektörü) |
| `SocialRadarService` | CryptoPanic tabanlı "hype spike" tespiti — SentimentService'e aday besler, kendisi asla alım yapmaz |
| `SmartMoneyTracker` | Etherscan tabanlı balina cüzdanı takibi/kopyalama |
| `ListingSniperService` | AI onayı BEKLEMEDEN yeni listelemeye anında alım (agresif sabit TP/SL) |
| `FuturesTradingService` | Futures (SHORT) motoru — v1 kapsamı bilinçli dar: sabit/düşük kaldıraç, izole marj, kullanıcı başına en fazla 1 eşzamanlı kısa pozisyon |
| `RiskManagerService` | **Paylaşılan devre kesici** — tüm otonom giriş noktaları (spot, futures, sniper, smart money) buradan geçer. Ayrıca `isNear24hHigh()` gibi SAF/stateless yardımcı fonksiyonlar da burada yaşar — bkz. "Modüler Hard-Reject Fonksiyonları" deseni |
| `RiskProfileService` | 3 risk profilinin (güvenli/dengeli/agresif) TEK doğru kaynağı + özel (manuel) stop-loss tespiti |
| `TelegramService` | Telegram bot bildirimleri — asla patlamaz, sessizce loglar |
| `NewsService` | Cointelegraph RSS, 15 dk önbellekli |
| `ChangelogService` | `CHANGELOG.md`'yi canlı olarak ayrıştırıp dashboard'daki "Yenilikler" modalına besler |
| `ServerInfoService` | Sunucunun gerçek çıkış IP'sini döner (Binance API-key kurulum rehberi için), 24s önbellekli |
| `BacktestService` | Mekanik filtreleri (AI HARİÇ) geçmiş Binance mumlarına karşı simüle eder — hem admin panelindeki `BacktestController` hem de `scripts/backtest.php` (CLI) bu TEK sınıfı çağırır, artık ayrı/senkronsuz bir kopya yok (25 Temmuz'da birleştirildi) |

### Modeller (`app/Models/`)

| Dosya | Görevi |
|---|---|
| `User` | Kimlik doğrulama, Telegram bağlantısı, risk onayı takibi |
| `ApiKey` | Şifreli borsa anahtarları + kullanıcı başına TÜM işlem ayarları (bütçe, risk profili, modül aç/kapa, devre kesici durumu) — en büyük model dosyası |
| `ActiveTrade` | Açık **spot** pozisyonların tek doğru kaynağı |
| `ActiveFuturesTrade` | Açık **futures (SHORT)** pozisyonların tek doğru kaynağı — spot'tan kasıtlı olarak tamamen ayrı tablo/model |
| `Order` | Tüm gerçekleşen/başarısız emirler (spot + futures) |
| `KnownSymbol` | Görülmüş tüm USDT paritelerinin takibi — yeni listeleme tespiti bu tabloyla diff alınarak yapılır |
| `BotLog` | Her cron çalıştırmasının yapısal özeti — 15 günden eski satırlar kendiliğinden temizlenir |
| `Setting` | `app_settings` tablosunu destekleyen anahtar/değer deposu — bkz. "Ayar Deseni" |
| `SymbolCooldown` | Kullanıcı+sembol bazlı "intikam alımı" kilidi (devre kesiciden AYRI, çok daha dar kapsamlı) |
| `AiIntervention` | "Görünmez Kalkan" — sert filtrelerin (MTF trend, emir defteri duvarı) bir alımı ne zaman/neden engellediğinin müşteriye açık kaydı |

## Kurulmuş Desenler (Yeni Kod Yazarken Taklit Edin)

### Ayar Deseni: "Önce DB, sonra dosya"
Hemen hemen her gizli anahtar/eşik (`webhook_token`, `openai_api_key`, token'lar, eşikler...) önce
`Setting::get($key)` ile `app_settings` tablosundan okunur; DB'de yoksa `config/app.php`'deki
varsayılana düşülür. `AdminController::saveSettings()` DB'ye yazar ve o andan itibaren dosyanın önüne
geçer. **Yeni bir gizli anahtar/eşik eklerken bu deseni birebir tekrarlayın.**

### Servis Deseni: Bağımsız, asla patlamayan cURL sarmalayıcılar
Ortak bir HTTP istemci sınıfı YOKTUR — her serviste kendi `curl_init()` bloğu vardır (bilinçli tercih,
proje boyunca tutarlı). Dış API'ye bağlı "olursa iyi olur" servisler (Sentiment, Telegram, News,
SocialRadar) **fail-open**dır: hata durumunda nötr/boş değer döner, `error_log`'a yazar, asla exception
fırlatmaz — bu sistemi hiçbir zaman kilitlemez. Güvenlik-kritik kontroller (devre kesici, MTF/emir
defteri filtreleri) bilinçli olarak farklı davranabilir — bazıları da "fail-open, asla yanlışlıkla bir
alımı engellemez" şeklinde tasarlanmıştır (v1.11.2 CHANGELOG notuna bakın) — bu bir risk/kullanılabilirlik
tercihidir, unutulmamalı.

### Bildirim Yönlendirmesi
Rutin bildirimler (pozisyon açıldı/kapandı) **sadece** ilgili müşterinin kendi bağladığı Telegram'a
gider — admin'e düşmez (platform büyüdükçe admin'in her müşterinin her işlemiyle boğulmaması için
kasıtlı tasarım). Kritik olaylar (devre kesici, korumasız pozisyon) **hem müşteriye hem admine** gider.

### Devre Kesici vs. Sembol Soğuması — İKİSİ FARKLI MEKANİZMA
- `RiskManagerService` = hesap genelinde devre kesici: 24 saat içinde ardışık 3 kayıp → TÜM otonom
  modüller 24 saatliğine kilitlenir (`ApiKey.circuit_breaker_until`), `auto_trade_enabled` bayrağından
  BAĞIMSIZ (bayrağın tek başına güvenilir olmadığı canlı bir olayda tespit edildi).
- `SymbolCooldown` = kullanıcı+sembol bazlı, çok daha dar kapsamlı: bir coinde zarar/erken çıkış olunca
  SADECE o coin o kullanıcı için N saat kilitlenir, botun geri kalanını durdurmaz.
Bunları birbirine karıştırmayın — ayrı tablolar, ayrı amaçlar.

### Modüler Hard-Reject Fonksiyonları (Canlı + Backtest Ortak Kaynak)
Yeni bir sert alım-reddi kuralı (RSI eşiği, Zirve Yakınlığı/`isNear24hHigh()` gibi) eklerken, mantığı
`AutoTradeController`'a gömmek yerine `RiskManagerService`'e (veya `MarketScanner`'a, gösterge
hesaplamaysa) SAF/stateless bir fonksiyon olarak ekleyin, sonra hem canlı tarama döngüsünden hem
`BacktestService`'ten AYNI fonksiyonu çağırın — eşik sabitini de HER İKİ dosyada senkron tutun (25
Temmuz'da RSI eşiğinde ve Zirve Yakınlığı eşiğinde iki kez yaşanan "laboratuvar canlıdan farklı
çalışıyor" hatasından ders). Ayrıca dikkat: bu tip fonksiyonlarda eşiği "gevşetmek" her zaman sayıyı
DÜŞÜRMEK anlamına gelmez — `isNear24hHigh()` gibi `>= threshold ise REDDET` mantığıyla çalışan
fonksiyonlarda gevşetmek eşiği YÜKSELTMEK demektir (aynı gün ters yönde uygulanıp canlı veriyle
yakalanmış bir hata).

### Piyasa Tarama Beyaz Listesi'nin Kapsamı Sınırlı
Admin panelindeki "AI Avcı — Piyasa Tarama Beyaz Listesi" (`market_scanner_whitelist`,
`MarketScanner::getWhitelistedSymbols()`) SADECE AI Avcı'nın (spot/LONG) ana `scanTopMovers()`
havuzunu kısıtlar. Duyuru Avcısı, Akıllı Para Kopyalayıcı, Sosyal Radar (kendi
`fetchTradableUsdtSymbols()`'ünü kullanır, whitelist'e TABİ DEĞİLDİR) ve Webhook bu listeden BAĞIMSIZ
çalışmaya devam eder. `FuturesTradingService` de 25 Temmuz'dan itibaren BAĞIMSIZ
(`scanTopMovers(ignoreWhitelist: true)`) - spot'un whitelist'i YÜKSELİŞ adaylarına odaklanmak için
seçilir, futures ise TAM TERSİNE DÜŞÜŞ adayı arar, aynı liste ikisine uymaz. "Sadece şu coinlerde
işlem yapsın" denildiğinde bu tek ayarın (spot dışında) yeterli olmadığını unutmayın.

### Spot/Futures İzolasyonu
Spot ve Futures modülleri kasıtlı ve tutarlı şekilde birbirinden izole tutulur: ayrı modeller
(`ActiveTrade`/`ActiveFuturesTrade`), ayrı servisler (`BinanceService`/`BinanceFuturesService`), ayrı
controller/cron token'ları — "birindeki bug diğerini asla bozmasın" gerekçesiyle. Paylaşılan TEK bileşen
`RiskManagerService`'in devre kesicisi (ve `Order::calculateRolling24hPNL`, çünkü futures emirleri de
`orders` tablosuna notional değer olarak yazılır).

### Loglama
Merkezi bir Logger sınıfı yok — her serviste aynı `is_dir()` / `mkdir(0755, true)` /
`file_put_contents(..., FILE_APPEND)` deseni tekrarlanır, `storage/logs/{servis}_errors.log` gibi
kendi log dosyasına yazar.

## Otonom Modüller — Genel Bakış

| Modül | Tetikleyici | AI Onayı? | Devre Kesiciye Tabi mi? |
|---|---|---|---|
| AI Avcı (spot/LONG) | `GET /api/auto-trade/run` cron (~15 dk) | Evet (Sentiment + Technical) | Evet |
| Duyuru Avcısı | `GET /api/listing-sniper/run` cron (~1 dk, çok sık) | **Hayır** — anında alır | Evet |
| Akıllı Para Kopyalayıcı | `GET /api/smart-money/run` cron (~5 dk) | Hayır (whale sinyali) | Evet |
| Futures (SHORT) | `GET /api/futures-trade/run` cron | Evet (simetrik eşik) | Evet (paylaşılan) |
| Sosyal Radar | Ana AI Avcı döngüsüne aday besler | Evet (kendisi karar vermez) | — |

Tüm cron uç noktaları `?token=` ile korunur, her modülün kendi ayrı gizli anahtarı vardır
(`auto_trade_token`, `listing_sniper_token`, `smart_money_token`, `futures_trading_token`).
**Bu uç noktalar cPanel'de gerçek Cron Job olarak tanımlanmadan sistem kendiliğinden asla çalışmaz.**

## Veritabanı

Şema `database.sql`'de idempotent şekilde tutulur (taze kurulum + kronolojik migration günlüğü aynı
dosyada). Her `ALTER TABLE` bloğu, hangi bug/özelliğin onu gerektirdiğini açıklayan bir yorumla gelir —
yeni bir migration eklerken bu konvansiyonu sürdürün. **Yerel `database.sql` ile canlı MySQL şeması
arasında sürüklenme (drift) olmamalı** — bir tabloyu değiştirdiğinizde HER İKİSİNİ DE güncelleyin.

Ana tablolar: `users`, `user_api_keys`, `orders`, `active_trades`, `active_trade_fills`,
`active_futures_trades`, `known_symbols`, `bot_logs`, `app_settings`, `symbol_cooldowns`,
`ai_interventions`, `smart_money_seen_txs`, `webhook_logs`.

Performans indeksleri (`user_id`, `status`, `pair`/`symbol` gibi sık `WHERE` edilen sütunlar) `users`,
`active_trades`, `orders`, `user_api_keys` üzerinde mevcuttur — yeni sık sorgulanan bir sütun eklerken
aynı desenle indeks eklemeyi unutmayın.

## Versiyonlama Kuralı (Otonom, Talimat Beklemeden)

**Her kod değişikliğinden sonra** (küçük düzeltmeler dahil):
1. `config/app.php`'deki `app_version` değerini SemVer'e göre bir tık yukarı taşı (Patch: bug fix,
   Minor: yeni özellik/modül — varsayılan, Major: kırıcı değişiklik).
2. `CHANGELOG.md`'ye profesyonel Türkçe ile bir giriş ekle. **Her madde TEK satır olmalı** — parser
   sadece `- ` ile başlayan satırları yakalar, elle kaydırılmış (word-wrapped) devam satırları
   sessizce kaybolur.
3. Bu iki dosya birbiriyle senkron kalmalı (`ChangelogService` `CHANGELOG.md`'yi canlı ayrıştırıp
   dashboard'a besler — DB'de ayrı bir kopya YOK).

## Dağıtım İş Akışı (Yerel → cPanel)

Bu bir git deposu değildir ve CI/CD yoktur — kullanıcı değiştirilen dosyaları elle (FileZilla vb.)
cPanel'e yükler. Bu yüzden **her değişiklik sonrası**:
- Değiştirilen/oluşturulan dosyaların TAM listesini (repo-göreli yollarla) açıkça belirtin — "bazı
  dosyaları düzenledim" yeterli değildir.
- Tailwind sınıfları değiştiyse `npm run build:css` çalıştırıp derlenmiş CSS'in de yükleneceğini belirtin.
- Eğer değişiklik bir tabloyu etkiliyorsa ve o tablo canlıda zaten mevcutsa, `CREATE TABLE IF NOT EXISTS`
  değişikliği TEK BAŞINA yeterli DEĞİLDİR — ayrıca gerçek bir `ALTER TABLE` migration'ı da verilmeli
  (aksi halde canlı sunucuda hiçbir şey olmaz, sessizce eksik kalır).

## Test Etme

- **Her zaman XAMPP'in kendi ikili dosyalarını kullanın**: `/Applications/XAMPP/xamppfiles/bin/php` ve
  `/Applications/XAMPP/xamppfiles/bin/mysql -uroot nexatrade` (Homebrew'daki `php`/`mysql` XAMPP'in
  MySQL soketine erişemez).
- Composer/vendor YOK — uygulama kendi basit `spl_autoload_register` (PSR-4 benzeri) yükleyicisini
  kullanır (`public/index.php`'de tanımlı). Bir test betiği yazarken bunu taklit edin veya
  `public/index.php`'nin başındaki autoloader bloğunu doğrudan çalıştırın.
- Gerçek model/servis metodlarını gerçek yerel veritabanına karşı çalıştırıp assert eden, sonra
  oluşturduğu test satırlarını temizleyen küçük atılabilir betikler yazmak tercih edilen yöntemdir
  (scratchpad dizininde). `php -l` yeterli değildir — asıl davranışı gerçekten çalıştırıp gözlemleyin.
- UI değişiklikleri için Playwright ile ekran görüntüsü alıp gözle doğrulayın (proje boyunca tutarlı
  şekilde kullanılan yöntem); test sonrası `node_modules` ve geçici dosyaları temizleyin.
- Binance gibi dış API'lere gerçek (kasıtlı geçersiz anahtarla bile olsa) istek atarak "zarif hata"
  davranışını doğrulamak, sadece kod okumaktan daha güvenilirdir — bu proje boyunca tutarlı bir pratiktir.
- `scripts/backtest.php SEMBOL [GUN] [TP] [SL]` — tek sembol/parametre için hızlı backtest.
  `scripts/optimize_thresholds.php [GUN] [TP] [SL]` ve `scripts/optimize_tpsl.php [GUN]` — sabit
  8 coin (BTC/ETH/SOL/BNB/XRP/ADA/AVAX/DOGE) üzerinde çoklu eşik/TP-SL kombinasyonu tarayıp kümülatif
  özet tablosu basan CLI araçları (25 Temmuz'da eklendi). Geniş taramalar (90 gün × çok kombinasyon)
  uzun sürebilir — cPanel'de `nohup php scripts/... > sonuc.txt 2>&1 &` ile arka planda çalıştırıp
  `cat sonuc.txt` ile sonucu okuyun; aynı anda birden fazla kopyasını başlatmamaya dikkat edin
  (`pkill -f optimize_...php` ile temizleyip TEK kopya çalıştırın).

## Bilinen Riskler / Dikkat Edilecekler

- Yeni bir tek-seferlik tanılama betiği (kök dizine) yazarken MUTLAKA `audit_v15.php`'deki gibi bir
  `if (php_sapi_name() !== 'cli') { die(...); }` koruması ekleyin, sonra kullanınca silin — kök dizin
  belge köküdür, `.htaccess` sadece `app/`/`config/`/`database.sql`'i korur, gelişigüzel bırakılan bir
  PHP dosyası doğrudan web'den çalıştırılabilir (24 Temmuz'da kontrol edildi: projede artık böyle
  korumasız bir dosya yok, ama gelecekte eklenecek her tanılama betiği için bu kural geçerli).
- `SYSTEM_AUDIT.md` (v1.5.1) ve `PROJE_BRIFINGI_CHATGPT.md` (v1.6.4) kök dizinde duruyor ama BAYAT —
  güncel mimari referansı olarak kullanmayın, kodun kendisiyle çapraz kontrol etmeden güvenmeyin.
- Dashboard'un dış API'lere bağlı verileri (bakiye, AI Radar, haberler, açık pozisyon anlık fiyatı)
  sayfa senkron yüklenirken ÇEKİLMEMELİDİR — sayfa anında açılmalı, bu veriler `/dashboard/data/*` /
  `/api/dashboard/*` uç noktalarından `fetch()` ile sayfa yüklendikten SONRA gelmelidir (performans
  optimizasyonu — AI Radar'daki OpenAI çağrı zinciri tek başına ~10 saniyeyi bulabiliyordu).

## Oturum İçi Davranış Kuralları

- Kullanıcı kısa bir durum bildirimi yaptığında ("X'i aktif ettim" gibi), bunu sadece bilgi (FYI) olarak
  al — açıkça istenmedikçe doğrulama/kontrol/DB sorgusu zincirine girme.
- Bir değişiklik yaptıktan sonra HER ZAMAN: (1) hangi dosyaların cPanel'e yüklenmesi gerektiğini net
  şekilde listele, (2) değişikliği gerçekten çalıştırıp test et (sadece `php -l` yetmez).
