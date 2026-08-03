# Değişiklik Günlüğü (Changelog)

Bu dosya NexaTrade'in sürüm geçmişini tutar. Format [Keep a Changelog](https://keepachangelog.com/tr/1.0.0/) ilkelerini, sürümleme ise [Semantic Versioning](https://semver.org/lang/tr/) (Major.Minor.Patch) kurallarını izler.

## [1.91.0] - 2026-08-04

### Yeni Özellik
- ["Korumaya Al" eklendi] Doğal Bırak'ın tersi - GIGGLEUSDT #326 canlı deneyiminde fark edildi: Doğal Moddaki bir pozisyona geri dönüp tekrar koruma koyacak bir yol yoktu. Artık "Aktif Avlar" kartındaki buton, pozisyon Doğal Moddayken "🔒 Korumaya Al"a dönüşüyor - tıklanınca GÜNCEL fiyata göre (kullanıcının kendi Zarar Kes/Kâr Al yüzdeleriyle) yeni bir OCO konup pozisyon normal/otomatik yönetime geri döner. Yeni `AutoTradeController::rearmProtection()`, `ActiveTrade::disableManualMode()`, `POST /api/dashboard/rearm-protection`. Şema değişikliği yok, mevcut sütunlar kullanılıyor.

## [1.90.2] - 2026-08-04

### Arayüz (düzeltme)
- [dashboard/index.php] Bir önceki satır-oranı yaklaşımı (0.6fr/1.4fr) Aktif Avlar'ı büyütürken AYNI satırdaki Teknik Analiz Özeti/Son İşlemler'i de istenmeden büyütüyordu - müşteri geri bildirdi. Grid yeniden yapılandırıldı: Aktif Avlar artık kendi sütununu (en sağ) baştan sona kaplıyor, diğer panellerin boyutuna dokunmadan çok daha fazla dikey alan kazanıyor. Haberler/Yeni Listelenen üst-alt sıralandı (yan yana değil).


### Arayüz
- [dashboard/index.php] Masaüstü grid oranı ayarlandı (`grid-template-rows: 1fr 1fr` → `0.75fr 1.25fr`) - müşteri talebi: Haberler/Yeni Listelenen (üst satır) biraz kısaltıldı, Aktif Avlar'ın bulunduğu alt satır büyütüldü, birden fazla açık pozisyon kaydırmadan görülebilsin diye. Sadece web/masaüstü - mobilde ayrı bir grid kuralı zaten devrede, bu değişiklik orada etkisiz.

## [1.90.1] - 2026-08-04

### Hata Düzeltme
- [dashboard/index.php, DashboardController] Sayfa açıkken açılan yeni bir futures (KISA) pozisyonu, sayfa yenilenmeden "Aktif Avlar" listesinde hiç görünmüyordu - `updateFuturesProgress()` sadece DOM'da ZATEN var olan kartların içeriğini güncelliyordu, yeni kart hiç oluşturmuyordu (spot tarafında `syncHuntCards()` ile daha önce çözülen AYNI sorun, futures kapsam dışı bırakılmıştı). Yeni `syncFuturesCards()` eklendi, `apiFuturesPositions()` artık kart iskeletini (parite/giriş/TP/SL/kaldıraç) de dönüyor.

## [1.90.0] - 2026-08-04

### Yeni Özellik
- [FuturesTradingService] Futures (KISA) modülü artık spot ile AYNI `decision_motor` ayarını paylaşıyor - müşteri talebi: "AI'a gerek yok, motor karar versin". `decision_motor='deterministic'` iken GPT/SentimentService'e hiç dokunulmadan `TechnicalScoreEngine` (MarketScanner::calculateTechnicalScore ile) kullanılıyor - bu motor zaten çift yönlü (düşen fiyat/MA20, negatif MACD, azalan hacim için puanı düşürüyor), yani düşük skor gerçek bir "ayı" okuması, ayrı bir bearish motor yazmaya gerek kalmadı. `decision_motor='ai'` iken davranış AYNEN korunuyor (SentimentService).

## [1.89.3] - 2026-08-04

### Hata Düzeltme
- [AutoTradeController] Anti-FOMO Freni (15dk RSI) reddi loglanırken `AiIntervention::record()` çağrısında tanımsız `$rsi15mCeiling` değişkenine atıf yapılıyordu (kopyala-yapıştır hatası, muhtemelen eşik sabiti isim değiştirdiğinde unutulmuş) - her tetiklenişinde PHP Warning basıyordu, bugünkü eşik sıkılaştırmasıyla (v1.89.0) daha sık görünür hale geldi. `self::PULLBACK_RSI_OVERBOUGHT_THRESHOLD` ile değiştirildi.

## [1.89.2] - 2026-08-04

### Hata Düzeltme (Kritik)
- [AutoTradeController] Doğal Bırak (`apiCancelPositionOrders`) ile mutabakat döngüsü (`reconcileActiveTradesInternal`) arasında yarış durumu tespit edildi - canlı olayda görüldü (AVAXUSDT #333): OCO Binance'te iptal edildikten sonra, `manual_mode=1` DB'ye yazılana kadarki kısa pencerede mutabakat turu araya girerse, iptal edilmiş (dolmamış) OCO'yu "hangi bacak gerçekleşti belirlenemedi" fallback'ine düşürüp pozisyonu coin GERÇEKTE SATILMAMIŞKEN "kapalı" işaretliyordu - hiçbir satış kaydı/PNL oluşmadan. Artık bu fallback'e düşmeden önce TAZE veriyle `manual_mode` tekrar kontrol ediliyor - yarış durumu tespit edilirse pozisyon AÇIK ve manuel modda bırakılıyor, yanlışlıkla kapatılmıyor.

## [1.89.1] - 2026-08-02

### Performans
- [DashboardController, BinanceService] `/api/dashboard/portfolio` uç noktası ölçülen gerçek yanıt süresiyle 3.2 saniyeye çıkıyordu (mobil ve web'de yavaşlık şikayeti üzerine doğrulandı) - kök neden: açık pozisyon başına ayrı, sıralı `getPrice()` çağrısı (paylaşılan HTTP istemci yok, her çağrı kendi TCP/TLS bağlantısını açıyor). Yeni `BinanceService::getAllPrices()` TÜM sembollerin fiyatını TEK istekte döner - `apiPortfolio()` ve `fetchActiveTrades()` (Aktif Avlar) artık bunu kullanıyor, N çağrı 1'e indi.

## [1.89.0] - 2026-08-02

### Ayar Değişikliği
- [AutoTradeController] Anti-FOMO 15dk RSI eşiği (`PULLBACK_RSI_OVERBOUGHT_THRESHOLD`) 70'ten 65'e sıkılaştırıldı - 311 kapanan işlemin `entry_rsi_15m` verisiyle gerçek kazanma oranı incelendi: RSI 45-65 aralığında girilen işlemler ~%67 kazanırken, 65-75 aralığında %50'ye düşüyordu (n=16, GIGGLEUSDT canlı olayı sonrası yapılan inceleme sırasında bulundu). Küçük örneklem - sonraki işlemlerle izlenmeli.

## [1.88.0] - 2026-08-02

### Yeni Özellik
- [Aktif Avlar] "🔓 Doğal Bırak" butonu eklendi - müşteri talebi: kâra geçip sonra yukarı-aşağı savrularak küçük bir rakama satan pozisyonlar için, koruma emrini (OCO/Zarar Kes) bilerek iptal edip pozisyonu otomatik yönetimden (İzleyen Stop/Fitil Koruması/DCA) çıkarma seçeneği. Pozisyon SATILMAZ, açık kalır - sadece Yükseliş Uyarısı bildirimleriyle (bilgi amaçlı) takip edilip müşteri istediği an "Şimdi Kapat" ile elle kapatır. Geri dönüşü YOKTUR. Yeni `active_trades.manual_mode` sütunu + `ActiveTrade::enableManualMode()` + `DashboardController::apiCancelPositionOrders()` (`POST /api/dashboard/cancel-position-orders`). Korumasız Pozisyon Alarmı bu durumu artık "acil" saymıyor (bilerek yapılan bir tercih, canlı bir hata değil).

## [1.87.0] - 2026-07-31

### Hata Düzeltme (Kritik)
- [AutoTradeController] "Kâr Kilitleme" (İzleyen Stop sıkılaştırma) sırasında yeni OCO Binance'in "relationship of the prices" hatasıyla reddedilirse pozisyon SAATLERCE korumasız kalabiliyordu - canlı olayda tespit edildi (UNIUSDT #281, Kullanıcı #1, ~9 saat korumasız kaldı, fiyat zaten kendi Zarar Kes seviyesinin altına inmişti). Giriş anındaki OCO reddi için zaten var olan Acil Durum Protokolü (v1.74.0: fiyat Kâr Al'ı geçmişse anında piyasa satışı, şelale düşüşündeyse anında piyasa satışı, aksi halde tek başına Zarar Kes yedek emri) artık `replaceOcoWithNewStop()` (Kâr Kilitleme'nin ortak çekirdeği) içinde de uygulanıyor - iki çağıran nokta (Kademeli İzleyen Stop + Fitil Koruması sıkılaştırma) otomatik olarak kapsanıyor.

## [1.86.2] - 2026-07-31

### Geri Alma
- [dashboard/index.php] "Tümünü Gör" modalı (v1.86.0/1.86.1, masaüstünde 3+ açık pozisyonu kaydırmadan gösterme denemesi) müşteri talebiyle tamamen geri alındı - istenen şekilde çözmedi. Panel v1.85.1 öncesi haline döndü.

## [1.85.1] - 2026-07-31

### İyileştirme
- [dashboard/index.php] Mobilde "Aktif Avlar" paneli artık kaydırma gerektirmeden tüm açık pozisyonları gösteriyor - müşteri talebi. Diğer panellerle paylaşılan genel 420px yükseklik sınırı bu panel için kaldırıldı, panel kaç pozisyon varsa o kadar büyüyor; sayfanın kendisi normal şekilde uzuyor, küçük bir kutu içinde ayrı bir iç-kaydırma olmuyor. Playwright ile (3 test pozisyonu, mobil viewport) doğrulandı - `hasInternalScroll: false`.

## [1.85.0] - 2026-07-31

### Hata Düzeltme (Kritik) - Kök Neden
- [public/index.php] PHP'nin varsayılan saat dilimi hiçbir yerde ayarlanmamıştı (fallback UTC), VPS'teki MariaDB ise Europe/Istanbul - bu fark aynı türden ÜÇ AYRI canlı bug'a yol açmıştı (PendingLimitOrder zaman aşımı/ZAMAUSDT #97, Fitil Koruması sıkılaştırması hiç çalışmıyordu, TradePostMortemService'in "0.0 dakika" yanlış etiketi - gerçek veride 14/21 kayıpta görüldü, gerçek süreler 3dk-2sa arasıydı). `date_default_timezone_set('Europe/Istanbul')` eklendi (hem HTTP hem CLI/cron aynı bu dosyadan geçtiği için ikisini de kapsar) - `strtotime()` artık MySQL ile AYNI saat diliminde yorum yapıyor, bu SINIFTAKİ tüm hatalar (bulunanlar + henüz bulunmamış olabilecekler) tek noktadan düzeldi. Yerelde doğrulandı (MySQL NOW() ile strtotime()/time() farkı artık doğru, ~0 dakika çıkıyor).

## [1.84.2] - 2026-07-31

### Hata Düzeltme (Kritik)
- [AutoTradeController, ActiveTrade] Fitil Koruması (Wick Shield) SONSUZA KADAR sıkılaştırılmıyordu - "neden kaybettik" analizinde tespit edildi. Kök neden: `tightenStopLossIfEligible()` geçen süreyi PHP'nin `strtotime()`/`time()`'ı ile hesaplıyordu (ZAMAUSDT #97 ve BANKUSDT/ADAUSDT NOTIONAL olaylarındaki AYNI hata sınıfı, üçüncü kez bulundu) - VPS'te MariaDB Europe/Istanbul, PHP varsayılan UTC olduğu için fark HER ZAMAN büyük bir negatif sayı çıkıyor, "henüz vakti gelmedi" kontrolü asla geçilemiyordu. Sonuç: her pozisyon, girişten sonra kullanıcının GERÇEK Zarar Kes yüzdesine (Güvenli %2/Dengeli %5/Agresif %10) hiç sıkılaştırılmadan, sürekli geniş (2 katı, en az %3) "fitil koruması" seviyesinde kalıyordu. `ActiveTrade::findById()` artık `TIMESTAMPDIFF(SECOND, opened_at, NOW())` ile MySQL'in kendi saatiyle hesaplanan `seconds_since_opened` alanını döndürüyor, PHP tarafında ayrıca saat karşılaştırması yapılmıyor.

## [1.84.1] - 2026-07-31

### Hata Düzeltme (Kritik)
- [AutoTradeController, PendingLimitOrder] Per-kullanıcı maksimum açık pozisyon limiti (`max_active_trades` - Güvenli 1/Dengeli 3/Agresif 5) canlı veride sistematik olarak aşılıyordu - Kullanıcı #1 (agresif, limit 5) için gerçek zirve eşzamanlı pozisyon sayısı 8'e kadar çıkmıştı, birden fazla kez 6-7'ye ulaşmıştı. Kök neden: limit kontrolü sadece ZATEN DOLMUŞ pozisyonları sayıyordu (`ActiveTrade::countOpenForUser`), henüz dolmamış bekleyen limit emirlerini hiç saymıyordu - aynı veya art arda birkaç tarama turunda farklı paritelere konulan pending emirlerin TAMAMI sonradan dolduğunda gerçek pozisyon sayısı limitin çok üzerine çıkabiliyordu. Artık `PendingLimitOrder::countForUser()` ile bekleyen emirler de sayıma dahil ediliyor.

## [1.84.0] - 2026-07-31

### Yeni Özellik
- [dashboard/index.php] "Teknik Analiz Özeti" paneli TradingView'ın resmi Technical Analysis widget'ına geri döndü (Ana Grafik/Kayan Bant'taki aynı gerekçe - özel VPS IP'si). ÖNEMLİ: bu panel bilerek SADECE görsel/bilgi amaçlıdır, gerçek alım kararını veren Deterministik Motor/AI Avcı'ya kasıtlı olarak bağlanmadı - panel o an grafikte hangi coin açıksa onu gösterir (botun kendi tarama döngüsüyle senkron değil), ikisini birleştirmek test edilmemiş bir sinyali canlıya sokmak olurdu. Eski kendi-hesapladığımız RSI/SMA/MACD gauge'u kod tabanında bozulmadan duruyor (hidden), hızlı geri dönüş için. Sembol değişimi (JSON-config embed'in çalışma-zamanı API'si olmadığı için container'ı temizleyip `<script>`'i `createElement`+`appendChild` ile sıfırdan ekleme deseni) Playwright ile doğrulandı.

## [1.83.2] - 2026-07-31

### Hata Düzeltme
- [dashboard/index.php, auth/login.php, auth/register.php, admin/index.php, admin/backtest.php] Coin ikonları müşterinin mobil tarayıcısında dev boyutlu görünüyordu, masaüstünde/yerelde doğruydu - kök neden: derlenmiş `assets/css/tailwind.css` hiç önbellek kırma (cache-busting) parametresi taşımıyordu, mobil tarayıcı `w-3.5 h-3.5` kuralını içermeyen ESKİ bir kopyayı önbellekten sunmaya devam ediyordu. Tüm `<link rel="stylesheet">` etiketlerine dosyanın kendi `filemtime()`'ına dayalı `?v=` parametresi eklendi - CSS her `npm run build:css` ile değiştiğinde URL otomatik değişir, tarayıcı yeni dosyayı çekmek ZORUNDA kalır. Yerelde gerçek HTTP isteğiyle doğrulandı.

## [1.83.1] - 2026-07-31

### Hata Düzeltme
- [dashboard/index.php] Mobilde Kayan Fiyat Bandı'nın altındaki rakamlar tam görünmüyordu - TradingView Ticker Tape widget'ının `displayMode: "adaptive"` ayarı dar ekranda sürekli kayan tek satır yerine 2 satırlık bir ızgaraya dönüşüp sabit 46px'lik container'da kırpılıyordu (yüzde değişim satırı hiç görünmüyordu, son sembol yatay kesiliyordu). `"regular"` sabitlendi - Playwright ile mobil viewport'ta doğrulandı.

### İyileştirme
- [dashboard/index.php] Mobilde "Aktif Avlar" paneli artık Ana Grafik'in ÜSTÜNDE gösteriliyor - müşteri talebi: açık pozisyonları grafiği kaydırmadan hemen görmek istiyor. `.terminal-grid`'in mobil `display:block`'u `display:flex; flex-direction:column`'a çevrildi (görsel davranış aynı kaldı) ve `.area-hunts`'a `order:-1` verildi - masaüstü grid sırası/HTML yapısı DEĞİŞMEDİ, sadece mobilde CSS ile öne alındı.

## [1.83.0] - 2026-07-31

### Yeni Özellik
- [CoinIconService, DashboardController, dashboard/index.php] AI Radar, AI Monolog, Aktif Avlar, Bekleyen Emirler, Son İşlemler ve Geçmiş panellerinde coin adının başında küçük logo eklendi - müşteri talebi. TradingView'ın kendi logo CDN'i hotlink'e kapalı olduğu için (denendi, 403) CoinGecko'nun `/coins/markets?symbols=` uç noktası kullanıldı - sonuçlar `app_settings` içinde KALICI önbelleklenir (logolar pratikte hiç değişmediği için), yeni `/api/dashboard/coin-icons` uç noktası ekrandaki TÜM eksik sembolleri TEK toplu istekte getirir. Gerçek 24 farklı sembolle (obscure "tokenized stock" coinleri dahil: SKHYB, ZAMA, SNDKB) test edildi, hepsi bulundu. Playwright ile görsel doğrulandı - ilk denemede ikon devasa boyutta çıktı, kök neden `npm run build:css` çalıştırılmamış olmasıydı (yeni Tailwind sınıfları derlenmiş CSS'e hiç girmemişti), düzeltildi.

### Hata Düzeltme
- [CoinIconService] İlk sürüm CoinGecko'ya açıklayıcı bir `User-Agent` göndermiyordu, 403 ile reddediliyordu - `SocialRadarService`'te 15 Temmuz'da bulunan AYNI kısıtlama, atlanmıştı. `CURLOPT_USERAGENT` eklendi.

## [1.82.1] - 2026-07-31

### Yeni Özellik
- [dashboard/index.php] Kayan Fiyat Bandı TradingView'ın resmi Ticker Tape widget'ına geri döndü - müşteri talebi: coin ikonları görünsün. `showSymbolLogo: true` ile BTC/ETH/BNB/SOL/XRP logoları artık şeritte görünüyor. Eski Binance-REST tabanlı özel şerit (`initTickerTape`/`refreshTickerTape`) kod tabanında bozulmadan duruyor, kullanılmıyor - hızlı geri dönüş için. Playwright ile (gerçek giriş) doğrulandı.

## [1.82.0] - 2026-07-31

### Yeni Özellik
- [dashboard/index.php] Ana Grafik paneli TradingView'ın tam özellikli Advanced Chart widget'ına geri döndü - müşteri talebi: "her coin tıkladığımda sanki Binance'e girmişim gibi görmek istiyorum" (çizim araçları, onlarca indikatör, zaman dilimi sekmeleri). 22 Temmuz'da "bad auth token" hatası yüzünden kaldırılmıştı (paylaşımlı hosting IP'sinin TradingView tarafında bir kota/itibar sorununa takılması şüpheleniliyordu); artık kendi özel VPS IP'mizdeyiz, aynı sorun görülmedi. Eski `lightweight-charts` yolu (`initLightweightChart`) kod tabanında bozulmadan duruyor - tekrar sorun çıkarsa tek fonksiyon değişikliğiyle geri dönülür. "Canlı İzle" (pozisyon mini-grafik) ve Teknik Analiz Özeti gösterge motoru DEĞİŞMEDİ.

### Hata Düzeltme
- [dashboard/index.php] Mobilde Ana Grafik paneli tamamen görünmüyordu - kök neden: `.area-chart`'a sadece `max-height` verilmişti (bir ÜST SINIR, gerçek bir yükseklik değil), `#chartWidgetContainer`'ın (`flex-1 min-h-0`) büyüyeceği somut bir yükseklik olmadığı için grafik motoru 0 yükseklikli bir container'da sessizce hiçbir şey çizmiyordu. Playwright ile (masaüstü + mobil viewport, gerçek giriş) doğrulandı - düzeltme sonrası mobilde grafik container'ı ~620px yükseklikte, gerçek TradingView içeriğiyle render ediyor.

## [1.81.1] - 2026-07-31

### Hata Düzeltme (Kritik)
- [AutoTradeController, PendingLimitOrder] Bekleyen limit emirlerinin 15dk zaman aşımı hiç tetiklenmiyordu - canlı olayda tespit edildi (ZAMAUSDT #97, 17dk'dır bekliyordu). Kök neden: yaş hesabı PHP'nin `strtotime()`/`time()`'ı ile yapılıyordu, VPS'te MariaDB `Europe/Istanbul`'a ayarlıyken PHP'nin varsayılan saat dilimi UTC kaldığı için 3 saatlik fark yaşı hep negatif çıkarıyor, emir asla süresi dolmuş sayılmıyordu. `SymbolCooldown`/`ApiKey`'de zaten kullanılan MySQL-taraflı yaş hesabına (`TIMESTAMPDIFF`) geçirildi.

## [1.81.0] - 2026-07-31

### Yeni Özellik
- [DashboardController, PendingLimitOrder, dashboard/index.php] "Bekleyen Emirler" paneli eklendi - müşteri talebi: "kaçtan/ne kadarlık alacağını önceden bilmek istiyorum". Tüm filtrelerden geçip gerçek bir Binance limit emri konulmuş ama henüz dolmamış adaylar artık Aktif Avlar panelinin üstünde (⏳ BEKLİYOR, fiyat/miktar/kalan süre ile) canlı gösteriliyor - yeni `/api/dashboard/pending-orders` uç noktası, 3sn'de bir otomatik yenileniyor.

## [1.80.1] - 2026-07-31

### İyileştirme
- [DashboardController, dashboard/index.php] "Şimdi Kapat" butonu artık Binance market satışı gerçekleşir gerçekleşmez anında yanıt veriyor - eskiden komisyon sorgusu, Trade Post-Mortem analizi ve Telegram bildirimi (üçü de birer ağ çağrısı) tamamlanana kadar tarayıcı bekliyordu, kullanıcı butonun "takıldığını" hissediyordu. `fastcgi_finish_request()` ile yanıt hemen döndürülüp kayıt/bildirim adımları arka planda tamamlanıyor; kart da sunucudan yeni veri beklemeden anında kaldırılıyor.

## [1.80.0] - 2026-07-31

### Hata Düzeltme (Kritik)
- [AutoTradeController, ActiveTrade, database.sql] Korumasız Pozisyon Alarmı eklendi - canlı olayda tespit edildi: Volkan'ın #243 BANKUSDT pozisyonunda OCO hiç girilememiş, `reconcileActiveTradesInternal()` bu durumu SESSİZCE ve SONSUZA KADAR atlıyordu (koruma emri olmayan pozisyonlar mutabakat döngüsünün en başında `continue` ile geçiliyordu). Coin %64 çökene kadar hiç fark edilmedi, hiçbir alarm tekrarlanmadı. Artık böyle bir pozisyon her mutabakat turunda kontrol edilip 6 saatte bir admin+müşteriye tekrar "ACİL: Pozisyon Korumasız" bildirimi gönderiliyor.

## [1.79.2] - 2026-07-31

### İyileştirme
- [ChangelogService] `getEntries()` artık `$limit` sayıda sürüme ulaşır ulaşmaz erken kesiliyor - eskiden dashboard her açıldığında CHANGELOG.md'nin TAMAMI (dosya büyüdükçe sınırsızca yavaşlayan bir israf) parse edilip sonradan kırpılıyordu. Ölçüldü: ~0.46ms → ~0.05ms/çağrı (yerelde), çıktı önceki davranışla birebir aynı doğrulandı.

## [1.79.1] - 2026-07-31

### Yeni Özellik
- [AutoTradeController] "Yeni Pozisyon Açıldı" bildirimine de teknik gerekçe eklendi ("neye göre aldı" bilgisi) - Yükseliş Uyarısı'ndaki AYNI deterministik motor (buildTechnicalContext, artık iki yerden ortak çağrılıyor) giriş anındaki güncel teknik durumu raporluyor, AI modunda GPT'nin skoru da ayrıca eklenir.

## [1.79.0] - 2026-07-31

### Yeni Özellik
- [AutoTradeController] Yükseliş Uyarısı bildirimleri artık "teknik akıllı" - sadece yüzde değil, TechnicalScoreEngine'in (tarama turunda adaylar için kullanılanla BİREBİR aynı, deterministik, OpenAI maliyeti OLMAYAN) ürettiği RSI/MACD/hacim gerekçesini ve Kâr Al hedefine kalan mesafeyi de içeriyor. Herhangi bir adım başarısız olursa (Binance yavaşlarsa vb.) sessizce atlanır, bildirimin kendisini asla engellemez.

## [1.78.1] - 2026-07-31

### İyileştirme
- [AutoTradeController, ActiveTrade, database.sql] Yükseliş Uyarısı tek eşikli (+%2, tek seferlik) yapıdan kademeli/dinamik yapıya geçirildi - artık +%1'de başlayıp her +%1'de bir (+%2, +%3, ...) tekrar bildirim gönderiliyor. `rise_alert_sent` bayrağı (dün kullanılmadan) `rise_alert_last_percent` ile değiştirildi.

## [1.78.0] - 2026-07-31

### Yeni Özellik
- [AutoTradeController, ActiveTrade, database.sql] "Yükseliş Uyarısı": açık bir pozisyon girişten +%2 karına ulaştığında müşteriye bilgi amaçlı bir Telegram bildirimi gönderiliyor - "İzleyen Stop/Kademeli Kâr Alma otomatik stratejisine hiçbir dokunuş yok, isterseniz Şimdi Kapat butonuyla manuel kâr alın" mesajıyla. Bir pozisyonda sadece bir kez gönderilir (`active_trades.rise_alert_sent`). Zaten her turda çekilen mevcut fiyat verisi kullanılır, ekstra Binance isteği yok.

## [1.77.1] - 2026-07-31

### Hata Düzeltme
- [AutoTradeController] Dolmadan iptal edilen (kullanıcının Binance üzerinden ELLE iptal ettiği durumlar dahil) bekleyen limit alım emirlerine artık 1 saatlik kısa bir sembol soğuması uygulanıyor - eskiden hiç uygulanmıyordu, bu yüzden kullanıcı bakiyesi kilitlendiği için bir emri iptal ettiğinde, coin hâlâ sinyal veriyorsa bot bir sonraki tarama turunda aynı pariteye hemen tekrar emir koyuyordu ("iptal ediyorum tekrar atıyor" döngüsü, EULUSDT üzerinde canlı gözlemlendi).

## [1.77.0] - 2026-07-30

### Yeni Özellik
- [DashboardController, dashboard/index.php] "Son İşlemler" paneli artık diğer paneller gibi sayfa yenilenmeden otomatik güncelleniyor (yeni `/api/dashboard/recent-orders` uç noktası, 30sn'de bir) - eskiden SADECE ilk sayfa yüklemesinde PHP tarafında dolduruluyordu, "Şimdi Kapat" gibi sayfa açıkken oluşan yeni bir sipariş F5 atılmadan hiç görünmüyordu (EULUSDT #229 manuel kapatma sonrası fark edildi).

## [1.76.2] - 2026-07-30

### Hata Düzeltme (Kritik)
- [database.sql] `orders.type` ENUM'una `manual_close` ve `market_emergency` eklendi - EULUSDT #229 canlı olayında tespit edildi: "Şimdi Kapat" butonu `finalizeSpotClose()`'a `orderType='manual_close'` gönderiyordu ama bu değer ENUM'da yoktu, `Order::create()` "Data truncated for column 'type'" hatasıyla patlayıp kritik "Sistem Kaydı Başarısız" uyarısı üretiyordu (pozisyon aslında Binance'te kapanmıştı, sadece kayıt başarısız oluyordu - bir sonraki reconcile turu kendi kendini onarıp `closed_manual` ile telafi etti). v1.74.0'daki Acil Durum Protokolü'nün `market_emergency` değeri de AYNI riski taşıyordu (henüz hiç tetiklenmemişti), o da düzeltildi.
- [storage/logs] İzinler düzeltildi - CLI cron'lar (root) ile web istekleri (www-data) farklı kullanıcılar olduğu için, root'un oluşturduğu log dosyalarına www-data yazamıyordu ("Şimdi Kapat" gibi web-tetikli işlemlerde `logAutomationError()` sessizce `Permission denied` hatası veriyordu). Klasör artık `www-data` grup sahipliğinde, grup yazma izniyle (`775` + `setgid`) - hem root hem www-data yazabiliyor.

## [1.76.1] - 2026-07-30

### Hata Düzeltme
- [AutoTradeController] `finalizeSpotClose()`'un Telegram bildirimi, kapanış sebebine bakmaksızın her zaman "(Kâr Al)"/"(Zarar Kes)" yazıyordu - müşterinin yeni "Şimdi Kapat" butonuyla (v1.76.0) manuel kapattığı bir pozisyon bile botun kendisi otomatik kapatmış gibi görünüyordu. Artık `$orderType==='manual_close'` ise mesaj açıkça "(Manuel Kapatma)" yazıyor.

## [1.76.0] - 2026-07-29

### Yeni Özellik
- [Dashboard] "Aktif Avlar" panelindeki açık pozisyon kartlarına manuel **"✕ Şimdi Kapat"** butonu eklendi - müşteri otomatik Kâr Al/İzleyen Stop hedefini beklemeden, gördüğü anlık kâr/zararla pozisyonu piyasadan anında kapatabilir. Onay penceresi ("bu işlem geri alınamaz") ile korunuyor. Backend: `DashboardController::apiClosePosition()` mevcut korumayı (OCO veya SL-only) iptal edip piyasadan satıyor, ardından `AutoTradeController::finalizeSpotClose()` (artık `public`) ile AYNI kapanış mekanizmasını (gerçek PNL/loglama/bildirim/soğuma) çağırıyor - kapanış mantığı ikinci kez yazılmadı.

## [1.75.3] - 2026-07-29

### Hata Düzeltme
- [Order] `STATS_CUTOFF_AT` bir önceki sürümde (1.75.2) yanlışlıkla '2026-07-30 00:00:00' (VPS'in gerçek tarihinden İLERİDE) yazılmıştı - `date` ile doğrulanan gerçek sunucu saatine göre bu, bugünün (BANKUSDT #231 dahil) hiçbir işleminin sayılamaması anlamına geliyordu. '2026-07-29 21:00:00'e düzeltildi - günün erken saatlerindeki mutabakat/test gürültüsünü hâlâ dışlıyor, VPS canlıya alındıktan sonraki gerçek işlemleri içine alıyor.

## [1.75.2] - 2026-07-30

### Değişiklik
- [Order] `STATS_CUTOFF_AT` 8 Temmuz'dan 30 Temmuz'a çekildi - VPS geçişi/mutabakat/test işlemlerinin kirlettiği "kazanma oranı/tamamlanan işlem" istatistikleri artık bugünden itibaren temiz sayılıyor. Gerçek işlem geçmişi (`orders`/`active_trades`) hiçbir şekilde silinmedi/değiştirilmedi - sadece istatistik hesaplama penceresi kaydırıldı, aynı mekanizma 22 Temmuz'da ilk kez bu amaçla kurulmuştu.

## [1.75.1] - 2026-07-30

### Hata Düzeltme
- [dashboard/index.php] Üst bardaki "TAMAMLANAN" ve "AÇIK POZİSYON" sayaçları hiç `id`/JS güncellemesi almıyordu - sayfa ilk açıldığında PHP'nin yazdığı değer sayfa yenilenene kadar sonsuza dek sabit kalıyordu. "TAMAMLANAN" artık zaten var olan `/api/dashboard/pnl` döngüsüne (60sn) eklendi, "AÇIK POZİSYON" spot+futures pozisyon sayılarının toplamı olarak her iki döngüde de (3sn) güncelleniyor. Diğer üst bar öğeleri (Bakiye, Günlük PNL, Portföy, GR.DIŞI) zaten doğru çalışıyordu, sadece 30-60sn aralıkla.

## [1.75.0] - 2026-07-29

### Yeni Özellik
- [BacktestService] `runTrailingStopComparison()` eklendi - `AutoTradeController::applyTrailingStopIfEligible()`'daki gerçek çok aşamalı İzleyen Stop mantığını (Aşama 1 parametreli, Aşama 2 sabit %4/%2.5, Aşama 3 sabit %6/%4, ardından Sürekli İzleme) geçmiş veride simüle eder - `run()`'un basit sabit TP/SL modelinden tamamen farklı bir çıkış mantığı. Giriş taraması aynı `passesEntryFilters()` (yeni, `run()` ile paylaşılan) fonksiyonunu kullanır. Giriş sonrası dönem 15 dakikalık mumlarla simüle edilir (saatlik mum İzleyen Stop'un dar eşiklerini simüle etmek için çok kaba kalırdı) - bkz. dosya içi sınırlama notu (ATR çarpanı simüle edilmez, sonuç yön gösterici kabul edilmeli).
- [scripts/compare_trailing_stops.php] Yeni CLI aracı - mevcut ve önerilen İzleyen Stop (Tetik/Kilit) parametrelerini 8 coin üzerinde yan yana karşılaştırır. "Gemini Altın Oran" önerisini (Tetik %2.0/Kilit %0.5) mevcut canlı ayarla (Tetik %1.5/Kilit %1.0) karşılaştırmak için eklendi.
- **Not**: Yerel makineden test edilirken Binance'e aralıklı TLS bağlantı hataları yaşandı (yerel ağ/sistem kaynaklı, kodla ilgisi yok) - VPS'te çalıştırılması öneriliyor, orada bağlantı tüm oturum boyunca sorunsuzdu.

## [1.74.2] - 2026-07-29

### İyileştirme
- [dashboard/index.php] Açık pozisyon/futures yenileme 5sn→3sn, bakiye 30sn→15sn, fiyat şeridi 15sn→5sn'ye hızlandırıldı - VPS geçişi sonrası "daha canlı hissettirsin" talebi. Haberler/radar/bot logları kasıtlı dokunulmadı, saniyelik değişmelerinin faydası yok, gereksiz sunucu/Binance yükü olurdu.

## [1.74.1] - 2026-07-29

### Hata Düzeltme
- [storage/logs] `.gitkeep` eklendi - Git boş klasörleri takip etmediği için her yeni `git clone` sonrası `storage/logs/` hiç oluşmuyordu, ilk cron çalıştırmasında "klasör yok" hatasına yol açıyordu (VPS ilk kurulumunda elle `mkdir` ile geçici çözülmüştü). Artık klasörün kendisi repoyla birlikte geliyor.

## [1.74.0] - 2026-07-29

### Yeni Özellik
- [AutoTradeController] OCO emri "The relationship of the prices for the orders is not correct" hatasıyla reddedildiğinde artık **3 aşamalı Acil Durum Protokolü** devreye giriyor (COTIUSDT canlı olayı sonrası): (1) güncel fiyat zaten Kâr Al seviyesini geçmişse veya (2) Zarar Kes'in %1 daha altına (şelale eşiği) düşmüşse, pozisyon beklemeden **piyasadan anında satılıp gerçek PNL ile kapatılır** (`finalizeSpotClose()` üzerinden) - önceki (v1.72.6) "tek başına Zarar Kes emri dene" yedeği artık SADECE fiyat bu iki uç durumun dışındaysa (normal aralıkta veya küçük bir iğne) çalışıyor. %1 marj kasıtlı: projenin "Ani Fitil Koruması" felsefesiyle tutarlı olsun, her ufak iğne anında panik satışı tetiklemesin diye eklendi.

## [1.73.2] - 2026-07-29

### Hata Düzeltme
- [database.sql] `active_futures_trades` ve `pending_signals` tabloları için toplam 3 `ALTER TABLE` migrasyon bloğu, kendi `CREATE TABLE`'larından ÖNCE yer alıyordu - dosyayı sıfırdan tek seferde (`mysql < database.sql`) baştan sona çalıştırınca "tablo bulunamadı" hatasına yol açıyordu. Bloklar kendi tablolarının `CREATE TABLE`'ından SONRAYA taşındı, tüm dosya artık yeniden doğrulandı (script ile taranıp başka sıralama hatası kalmadığı teyit edildi). Canlı cPanel sunucusunda fark edilmemişti çünkü şema orada aşamalı/kronolojik olarak kurulmuştu, hiçbir zaman tek seferde baştan çalıştırılmamıştı.
- **VPS geçişi notu**: `ADD COLUMN IF NOT EXISTS` söz dizimi MariaDB'ye özgüdür, gerçek Oracle MySQL bunu desteklemez - VPS'e MySQL yerine MariaDB kurulması gerektiği bu süreçte tespit edildi (bkz. sohbet geçmişi).

## [1.73.1] - 2026-07-29

### Hata Düzeltme
- [database.sql] `known_symbols.expected_start_time` sütununun `COMMENT` metnindeki kaçırılmamış kesme işareti (`Avcisi'nin` → `Avcisi''nin`) düzeltildi - VPS'e ilk kurulumda dosyayı sıfırdan (`mysql < database.sql`) içeri alırken bu satırdan itibaren tüm importu bozan bir sözdizimi hatasına yol açıyordu. Canlı cPanel sunucusunda fark edilmemişti çünkü şema orada zaten satır satır, aşamalı olarak kurulmuştu.

## [1.73.0] - 2026-07-28

### Yeni Özellik (VPS/Git geçişine hazırlık)
- [CliKernel] Yeni `App\Core\CliKernel` sınıfı ve `public/index.php`'ye eklenen kısa devre ile artık tüm cron modülleri (`auto-trade-run`, `fast-tracker`, `listing-sniper`, `smart-money`, `futures-trade`, `daily-summary`) doğrudan `php public/index.php cli:<komut>` şeklinde CLI'dan tetiklenebiliyor - VPS'in kendi crontab'ı bu şekilde çağırınca cron-job.org'un sabit 30sn HTTP zaman aşımı sınırı tamamen devre dışı kalır. `Router.php`'ye dokunulmadı, HTTP tarafı birebir eskisi gibi çalışmaya devam ediyor.
- [Controllers] `AutoTradeController` (`run`/`runFastTracker`), `AutoFuturesTradeController`, `DailySummaryController`, `SmartMoneyController`, `ListingSniperController`'daki token kontrolleri `PHP_SAPI === 'cli'` olduğunda otomatik geçerli sayılıyor - bir HTTP isteği bunu asla taklit edemeyeceği için güvenli bir bypass. `WebhookController` (TradingView dış sinyali) bilinçli olarak bu listeye dahil edilmedi, token kontrolü aynen sürüyor.
- [Config] `config/app.example.php` ve `config/database.example.php` eklendi (placeholder değerlerle) - Git'e ilk geçişte gerçek `config/app.php`/`config/database.php` `.gitignore`'a alınıp bu şablonlardan türetilecek.
- [.gitignore] İlk kez eklendi - hassas config dosyaları, `storage/logs/*`, `node_modules/`, bayat referans dokümanları (`SYSTEM_AUDIT.md`, `PROJE_BRIFINGI_CHATGPT.md`) ve kök dizindeki tek seferlik tanılama betiklerini (`check_*.php` vb.) dışarıda bırakıyor.

## [1.72.6] - 2026-07-28

### Hata Düzeltme (Kritik)
- [AutoTradeController] **OCO emri "The relationship of the prices for the orders is not correct" hatasıyla reddedildiğinde artık pes edilmiyor.** Canlı olayda tespit edildi (COTIUSDT, Kullanıcı #1 ve #6 aynı anda): alım ile OCO gönderimi arasındaki saniyelerde fiyat hızlı hareket edip Kar Al/Zarar Kes bandının dışına çıkınca Binance OCO'yu reddediyor, pozisyon tamamen korumasız kalıyordu. Artık bu durumda GÜNCEL fiyattan hesaplanmış TEK BAŞINA bir Zarar Kes emri (OCO değil, `placeStopLossOrder`) denenir - Binance'in OCO'ya özgü "relationship" kısıtı yok, çoğunlukla başarılı olur. Başarılı olursa pozisyon en azından aşağı yönde korunur (Kâr Al otomasyonu o pozisyon için yok, müşteriye ayrı bir ⚠️ bilgilendirmesiyle belirtiliyor) - hem müşteri hem admin bildirimi hâlâ gidiyor ama artık "tamamen çıplak" değil "kısmen korumalı" bilgisiyle.

## [1.72.5] - 2026-07-28

### Hata Düzeltme (Kritik)
- [SentimentService] **Zaman aşımı süreleri projenin zorunlu 3sn/5sn standardına DÜZELTİLDİ (eskiden 10sn/15sn idi).** Canlı olayda tespit edildi: SOLUSDT #193 Zarar Kes'i geçmişken sistemde `open` kalmış, Telegram bildirimi hiç gitmemişti - kök neden, `reconcileActiveTradesInternal()`'ın her pozisyonu tek tek kontrol etmeden ÖNCE çağırdığı `scoreOpenPositionSymbols()`'un OpenAI→Groq→Gemini 3'lü yedek zincirinde eski değerlerle worst-case 45 saniyeye kadar çıkabilmesiydi - bu da dış cron tetikleyicisi cron-job.org'un sabit, yükseltilemeyen 30sn zaman aşımını tek başına aşıp TÜM turu (SOL dahil, hiçbir pozisyon kontrol edilmeden) sessizce iptal ediyordu. Aynı fonksiyonu paylaşan `runFastTracker()` (Hızlı Pozisyon Takipçisi) da bu yüzden aynı anda zaman aşımına uğruyordu.
- [AutoTradeController] `reconcileActiveTradesInternal()`'a 20 saniyelik bir zaman bütçesi eklendi - SentimentService düzeltmesi yetmezse (ör. Binance API kendisi yavaşlarsa) bile, bütçe dolduğunda yarım kalan pozisyonlar (hiçbiri işlem ORTASINDA kesilmez) bir sonraki cron turuna bırakılır, sonsuza kadar unutulmazlar.
- [ActiveTrade] `findAllOpen()`'a `ORDER BY opened_at ASC` eklendi - eskiden sırasızdı, yukarıdaki zaman bütçesi eklenince aynı pozisyonun her turda sona kalıp sürekli aç kalması riskini taşıyordu; artık en uzun süredir açık (en çok risk biriktirmiş) pozisyonlar her turda öncelikli kontrol edilir.
- **Not:** SOLUSDT #193'ün DB'deki tutarsız durumu (gerçekte Binance'te kapanmış, sistemde hâlâ `open`) bu düzeltme kapsamında OTOMATİK giderilmedi - gerçek kapanış fiyatı/miktarı doğrulanıp elle mutabakat yapılması gerekiyor (bkz. PEPEUSDT #176/ONDOUSDT #184 emsali).

## [1.72.4] - 2026-07-28

### Araç Geliştirme (canlı davranış değişikliği YOK)
- [BacktestService] Zirve Mesafesi filtresi (`applyRecentPeakFilter`) artık opsiyonel `$recentPeakLookbackHours`/`$recentPeakMaxDropPercent` parametreleriyle gün yerine SAAT bazlı da test edilebiliyor (varsayılan null = eski 5 günlük davranış, geriye dönük uyumlu). PUMPUSDT #194 canlı olayı (son 1-2 saatlik yerel zirveden düşüşün ortasında alım) sonrası denendi.
- **Test sonucu (bilgi amaçlı, kod değişikliği DEĞİL):** Güncel canlı ayarlarla 6 kısa pencere/eşik kombinasyonu (2s/%1.5, 2s/%2.5, 4s/%1.5, 4s/%2.5, 6s/%2.5) test edildi - 5'i durumu kötüleştirdi, sadece 1'i (4s/%1.5) iyileşme gösterdi. Tek bir olumlu sonuç, 6 kombinasyon denendiğinde istatistiksel olarak beklenen bir tesadüf olabileceğinden (çoklu karşılaştırma riski) güvenilir bulunmadı - canlıya TAŞINMADI.

## [1.72.3] - 2026-07-28

### Hata Düzeltme
- [BacktestService] **MACD histogramı önceden hesaplanıyordu ama hiçbir yerde kullanılmıyordu - backtest, canlı motordaki MACD kapısını (`deterministicPass`'in bir parçası) hiç uygulamıyordu, laboratuvar/saha burada sessizce senkronsuzdu.** 28 Temmuz'da (MACD kapısını gevşetme fikrini test ederken) fark edildi. Yeni opsiyonel `$requireMacdPositive` parametresi (varsayılan `false`, mevcut `scripts/backtest.php`/`optimize_thresholds.php` çağrılarını SESSİZCE değiştirmez) artık MACD histogramını gerçekten kontrol ediyor.
- **Test sonucu (bilgi amaçlı, kod değişikliği DEĞİL):** Güncel canlı ayarlarla (TP %8/SL %2.5/skor≥70, 8 coin, 90 gün) MACD kapısı kaldırılınca sonuç KÖTÜLEŞTİ (-96.99% → -119.33% kümülatif net PNL, 8 coinin 6'sında daha kötü, hiçbirinde daha iyi değil) - MACD kapısını gevşetme fikri bu veriyle DOĞRULANMADI, canlı koda hiçbir değişiklik yapılmadı, kapı olduğu gibi kalıyor.

## [1.72.2] - 2026-07-28

### Hata Düzeltme
- [Order::calculateYesterdayPNL (eski adıyla calculateTodayPNL), DailySummaryService] **Gece Yarısı Hesap Özeti, gönderildiği anda HENÜZ BAŞLAMIŞ olan günün verisine bakıyordu - her zaman "0 kapanan işlem" gösteriyordu.** Canlıda tespit edildi (28 Temmuz 00:00 özeti): "Bugün (28.07.2026) kapanan işlem: 0" yazdı, oysa bir önceki gün (27 Temmuz) ZEC/ONDO/ESP gibi gerçek kapanmış işlemler vardı. Kök neden: sorgu `CURDATE()` (yani "bugün, o ana kadar") kullanıyordu - cron TAM gece yarısında tetiklendiği için "bugün" o anda 0 saniye geçmiş oluyordu, biten günün (dünün) verisi hiç görülmüyordu. `Order::calculateTodayPNL()` artık `calculateYesterdayPNL()` olarak yeniden adlandırıldı ve `CURDATE() - INTERVAL 1 GÜN` ile `CURDATE()` arasını (yani biten takvim gününü) sorguluyor - bildirim metni de "Bugün" yerine "Dün" diyecek şekilde güncellendi. Bu metod SADECE DailySummaryService tarafından kullanıldığı için başka hiçbir yeri etkilemiyor.
- **Test durumu:** Gerçek yerel DB'ye karşı 3 senaryo (dün/bugün/2 gün önce karışık işlemler) ile doğrulandı - sadece dünkü işlem sayıldı, PNL doğru hesaplandı. Test sırasında PHP CLI'nin varsayılan saat dilimi (Europe/Berlin) ile yerel MySQL sunucusunun saat dilimi arasında gece yarısına yakın 1 günlük kaymaya yol açabilen bir fark bulundu - bu SADECE test script'ini etkiliyordu (gerçek üretim kodu tarih sınırlarını tamamen MySQL tarafında, CURDATE() ile hesaplıyor, PHP saat dilimine hiç bağımlı değil), test bu farka göre düzeltilip yeniden doğrulandı.

## [1.72.1] - 2026-07-27

### Hata Düzeltme
- [AutoTradeController::applyTrailingStopIfEligible] **Kademeli İzleyen Stop'ta Aşama 1 ile Aşama 2/3 arasında Zarar Kes tamamen donuk kalıyordu - araya sıkışan zirveler hiç korunmuyordu.** Canlıda tespit edildi (AEROUSDT, 27 Temmuz 22:xx, +%0.10 net kâr bildirimi): pozisyon %1.53'te Aşama 1'i tetikleyip Zarar Kes'i +%1.0'a kilitledi, fiyat +%1.8'e kadar çıktı ama Aşama 2'nin (%4) eşiğine hiç ulaşmadan geri döndü - o fazladan %0.8'lik kâr payını koruyan hiçbir mekanizma yoktu, pozisyon sadece +%1.0 ile kapandı. Artık en az 1 aşama kilitliyken (veya Kâr Al tavanı zaten kaldırılmışsa) Sınırsız İzleme (`applyContinuousTrailing`) de HER turda PARALEL çalışıyor - `applyContinuousTrailing()`'in kendi "sadece mevcut Zarar Kes'ten daha iyiyse uygula" kuralı sayesinde asla geriye gitmiyor, discrete kademe kilidini ASLA kötüleştirmiyor, sadece iyileştirme fırsatı varsa devreye giriyor. Aynı turda hem discrete kademe atlaması HEM continuous izleme birden tetiklenip çakışmasın diye `applyDiscreteTrailingStage()` artık bir kademe atlayıp atlamadığını (`bool`) döndürüyor - atladıysa o turda continuous ÇALIŞTIRILMIYOR. **DÜREST NOT:** Bu düzeltme AEROUSDT'nin bu spesifik sonucunu GERİYE DÖNÜK değiştirmez ve muhtemelen o günkü zirvede (+%1.8) bile sonucu değiştirmezdi - %1.5'lik mesafe payı, %1.0'lık sabit kilitten daha GENİŞ olduğu için o kadar mütevazı bir zirvede continuous izleme yine de mevcut kilidi iyileştiremezdi (kendi "asla kötüleştirme" kuralı devreye girerdi). Asıl fayda daha BÜYÜK ara-zirvelerde (ör. %3-3.9 arası, Aşama 2'ye hiç ulaşmadan geri dönen) ortaya çıkacak.
- **Test durumu:** `php -l` ile sözdizimi doğrulandı. Yeni dallanma mantığı (aynı turda çakışma engeli, TP kaldırıldıktan sonra discrete kontrolün tamamen devre dışı kalması dahil) 7 senaryoda saf mantık testiyle doğrulandı - hepsi geçti. Gerçek Binance OCO/Zarar Kes değiştirme çağrıları canlı API gerektirdiği için yerelde uçtan uca test EDİLEMEDİ.

## [1.72.0] - 2026-07-27

### Yeni Özellik
- [AutoTradeController - Kademeli İzleyen Stop (3 Aşama)] **Normal (sniper olmayan) pozisyonlarda İzleyen Stop artık TEK sabit aşama yerine 3 kademeli aşamaya çıkarıldı.** Canlı veri analizinde (27 Temmuz, bugün kapanan 23 işlemin %70'i pozisyon kapandıktan SONRAKI 12 saat içinde %2.2'yi aşıp bazıları %30-44'e kadar gitmişti) tek aşamalı sistemin (kullanıcının DB'deki tek trigger/lock ayarı) yetersiz kaldığı görüldü: coin hedefe (%5 TP) ulaşamasa bile SADECE ilk düşük aşamada kilitlenip kalıyordu, daha ileri giden coinler için ekstra koruma yoktu. Yeni asamalar (`NORMAL_TRAILING_STOP_STAGE_2`, `NORMAL_TRAILING_STOP_STAGE_3`): **%4'e ulaşırsa Zarar Kes +%2.5'e, %6'ya ulaşırsa +%4.0'e çekilir** (Aşama 1 - kullanıcının DB ayarı, kullanıcı #1 için %2.5→%1.0 - değişmedi). Bu aşamaların ötesinde Sınırsız İzleme (`applyContinuousTrailing`) aynı şekilde devam eder. `applyPartialTakeProfitIfEligible()`'daki `$maxTrailingStage` sabiti de (eskiden hardcoded `1`, pozisyon #105 canlı fatal hatasının kaynağıydı) YENİ maksimum aşamayla (`3`) senkron tutuldu - senkronsuz bırakılsaydı AYNI "Undefined constant" tarzı hatayı bu kez sessiz bir mantık hatası olarak tekrar yaratırdı.
- **Kullanıcı #1'in `trailing_distance_percent` ayarı %2.2'den %1.5'e düşürüldü** (DB güncellemesi, kod değişikliği değil) - Sınırsız İzleme aşamasında zirveden daha az geri çekilme payı bırakıp daha fazla kâr kilitlemek için.
- **Test durumu:** `php -l` ile sözdizimi doğrulandı. Aşama seçim mantığı (`array_reverse` üzerinden en yüksek uygun aşamayı bulma) gerçek kod ile birebir aynı mantıkla 6 senaryoda (hiç tetiklenmeme, sıralı ilerleme, doğrudan üst aşamaya sıçrama, Aşama 3 sonrası durma, Kademeli Kâr Alma eşiğinin altı) doğrulandı. Gerçek Binance emirleriyle uçtan uca (OCO değiştirme) canlı API gerektirdiği için yerelde test EDİLEMEDİ - ilk gerçek Aşama 2/3 tetiklenmesinde izlenmeli.

## [1.71.4] - 2026-07-27

### Yeni Özellik
- [BinanceService, BinanceFuturesService, BinanceTimestampException (yeni)] **Binance'in -1021 ("Timestamp ... ahead of the server's time") saat senkron hatası artık TEK SEFERLİK otomatik yeniden denemeyle toparlanıyor.** Canlıda tespit edildi (ZECUSDT, 27 Temmuz 16:57): OCO emri bu hata yüzünden başarısız olmuş, pozisyon geçici olarak korumasız kalmıştı (bkz. v1.71.1). `signedRequest()` artık -1021 aldığında süreç içi önbelleklenen sunucu saati farkını atıp taze bir `/api/v3/time` (spot) veya `/fapi/v1/time` (futures) çağrısıyla yeniden hesaplıyor, AYNI isteği bir kez daha deniyor - ikinci denemede de başarısız olursa hata normal şekilde yukarı fırlatılır (sonsuz döngü yok). BİLEREK dar tutuldu: genel bağlantı kesintisi (`BinanceApiTimeoutException`) için AYNI türde bir retry EKLENMEDİ - `BinanceService.php`'de belgelenen geçmiş worker-havuzu tükenmesi olayı (bkz. v1.71.3 notu) göz önünde bulunduruldu, bu ikisi birbirine karıştırılmamalı.
- **Test durumu:** `php -l` ile sözdizimi doğrulandı, retry mantığı (tek seferlik, `isRetry` bayrağıyla sonsuz döngü engeli) elle izlendi. Gerçek -1021 hatası canlı API anahtarı ve gerçek bir saat kayması gerektirdiği için yerelde uçtan uca test EDİLEMEDİ - bir sonraki gerçek oluşumda izlenmeli.

## [1.71.3] - 2026-07-27

### Yeni Özellik
- [AutoTradeController - Binance Bağlantı Kesintisi Uyarısı] **Binance API'sine ulaşılamadığında (barındırma sağlayıcısının ağ/DDoS koruma kesintisi) artık sessizce log'a yazılıp geçilmiyor.** Canlıda tespit edildi (27 Temmuz 17:25-17:35+): mutabakat döngüsü art arda "Binance sunucusuna bağlanılamadı" hatası alıyordu, ne kullanıcıya ne admine hiçbir bildirim gitmiyordu - sorun elle terminal kontrolüyle bulundu. Kod BU KESİNTİYİ ÇÖZEMEZ (altyapı sorunu, barındırma firmasıyla çözülmesi gerekiyor) ama artık görünür: mutabakat döngüsündeki bağlantı hatası `BINANCE_CONNECTIVITY_ALERT_THRESHOLD_MINUTES` (5 dk) kadar sürerse admin'e TEK BİR kritik Telegram uyarısı gider (aynı kesinti boyunca tekrarlanmaz - spam etmez, `app_settings` üzerinden state tutulur), bağlantı düzelince de ayrı bir "düzeldi" mesajı gönderilir. **Bilinçli olarak retry/timeout mekanizması EKLENMEDİ** - `BinanceService.php`'deki 27 Temmuz gece yarısı yorumunda belgelenen geçmiş olay (timeout 3sn/5sn'den 10sn/15sn'e çıkarılınca PHP-FPM worker havuzunun tükenip sitenin tamamen kilitlenmesi) göz önünde bulundurularak, kesinti sırasında ek bekleme/deneme eklemenin aynı riski tekrar yaratabileceği değerlendirildi - bu özellik sadece GÖZLEMLENEBİLİRLİK ekliyor, davranışı değiştirmiyor.
- **Test durumu:** `recordBinanceConnectivityFailure()`/`recordBinanceConnectivitySuccess()` reflection ile gerçek `AutoTradeController` örneği üzerinden, gerçek yerel `Setting` modeli ve DB'ye karşı 9 senaryoda (ilk hata, eşik altı tekrar, eşik aşımı, spam koruması, başarıyla temizlenme, kesinti yokken no-op) doğrulandı - hepsi geçti.

## [1.71.2] - 2026-07-27

### Yeni Özellik
- [AutoTradeController - Zayıflayan Teyit Freni] **Ardışık Çift Onay'da teyit turunda skor düşüp asgari eşiğe (70) yaslanan sinyaller artık alınmıyor.** Canlıda tespit edildi (ZECUSDT #189, 27 Temmuz): ilk taramada Deterministik Motor Skoru 95 iken teyit turunda 70'e (tam eşiğe) düşmüştü, yine de "art arda 2 tur geçti" kuralı teknik olarak sağlandığı için alım yapıldı - pozisyon ~35 dakika içinde stop-loss'a gitti. Yeni kural: teyit turunda skor İLK turdan düşmüş VE hâlâ eşiğe yakınsa (`PENDING_SIGNAL_WEAK_CONFIRM_MAX_SCORE` = 80 ve altı) alım atlanır, "Görünmez Kalkan" kaydına (`ZAYIF_TEYIT`) düşer. BİLEREK dar tutuldu - sadece "düşüyor" şartı 27 Temmuz sabahı hacim_delta'da yaşanan aynı doğal salınım sorununu (PENGUUSDT/PEPEUSDT/ZAMAUSDT'nin asla art arda 2 tur geçememesi) tekrar yaratırdı; 80 üzerindeki skorlarda küçük gerilemeler yok sayılmaya devam ediyor. **Test durumu:** Bu mekanizma tur-tur canlı tarama döngüsüne bağlı olduğu için `BacktestService`'in mum-bazlı simülasyonunda karşılığı yok, geriye dönük test edilemedi - `php -l` ile sözdizimi doğrulandı, mantık ZEC (95→70, reddedilir) ve ONDO (100→100 ve 100→90, reddedilmez) senaryolarıyla elle doğrulandı, canlıda ilk tetiklenmede izlenmeli.

## [1.71.1] - 2026-07-27

### Hata Düzeltme (KRİTİK)
- [AutoTradeController::protectPositionWithOco] **OCO (Kâr Al/Zarar Kes) emri başarısız olduğunda pozisyon `active_trades`'e HİÇ kaydedilmiyordu - sistem pozisyonun varlığını tamamen unutuyordu.** Canlıda tespit edildi (ZECUSDT, 27 Temmuz 16:57): Binance saat senkronu hatası (-1021, "Timestamp for this request was 1000ms ahead of the server's time") yüzünden OCO başarısız oldu, alım (`orders` tablosunda) kaydedildi ama `active_trades`'e hiç yazılmadı. `hasOpenPositionForPair()` bu yüzden "bu kullanıcının bu coinde açık pozisyonu yok" sanıp bir sonraki taramada AYNI coini TEKRAR aldı (4 dakika sonra, 2. bir ZECUSDT alımı). Artık OCO başarısız olsa bile pozisyon `active_trades`'e `oco_order_list_id/take_profit_order_id/stop_loss_order_id = NULL` ile "açık ama korumasız" olarak kaydediliyor - mevcut `reconcileActiveTradesInternal()`'daki `oco_order_list_id === null` koruması (DCA-başarısız-OCO senaryosuyla AYNI desen) sayesinde bir sonraki tur bunu yanlışlıkla "kapandı" saymıyor, sadece atlıyor; asıl önemlisi `hasOpenPositionForPair()` artık doğru "true" döndüğü için AYNI coin tekrar tekrar alınamıyor. **Etkilenen gerçek pozisyon:** Kullanıcı #1, ZECUSDT, 0.029 adet @ 504.20 (Order #1000) - elle manuel olarak Binance'te korunması gerekiyor, bu düzeltme GERİYE DÖNÜK bu pozisyonu otomatik düzeltmez.

## [1.71.0] - 2026-07-27

### Yeni Özellik
- [AutoTradeController, PendingLimitOrder (yeni model), pending_limit_orders (yeni tablo)] **Pullback Kalkanı, aktif bekleme (12sn polling) yerine gerçek bir bekleyen LIMIT ALIŞ emrine dönüştürüldü.** Canlıda tekrar tekrar tespit edildi (PENGUUSDT, ZECUSDT - 27 Temmuz): kesintisiz/düz yükselen coinler kısa (12sn) pencerede hiçbir zaman yeterli geri çekilme göstermiyor, motor doğru karar verse bile alım hiç gerçekleşemiyordu. Artık `huntForAllUsers()` piyasa emriyle ANINDA almıyor - sinyal fiyatının `PULLBACK_TARGET_PERCENT` (%0.08) altına gerçek bir LIMIT ALIŞ emri koyup `pending_limit_orders` tablosunda "beklemede" kaydediyor. Emir Binance'in kendi order book'unda doğal zamanında dolar - sunucu hiç aktif beklemez, bu mimari kuralla (sonsuz döngü/sleep yok) daha uyumlu. Fast Tracker (1dk, GPT'siz) her turda yeni `checkPendingLimitOrders()` ile bekleyen emirleri kontrol eder: dolduysa gerçek pozisyona (OCO + `active_trades`) dönüştürülür (`convertFilledPendingOrder()`, eski MARKET-buy-sonrası adımla BİREBİR aynı mantık), `PENDING_LIMIT_ORDER_TIMEOUT_MINUTES` (15dk) dolmadan gerçekleşmezse emir iptal edilip kayıt temizlenir - kısmi dolum (PARTIALLY_FILLED) varsa o kısım da gerçek bir pozisyon olarak kaydedilir (yok sayılmaz, "hayalet pozisyon" riskine düşülmez). "Tepeden alma" koruması BOZULMADI - 25 Temmuz'da (BANKUSDT #131) kaldırılan "Kaçış Supabı" kararı burada da geçerli, limit emri hâlâ gerçek bir geri çekilme şartı koşuyor, sadece bunu aktif beklemek yerine borsanın kendi mekanizmasıyla sağlıyor. **DB migration gerektirir** - `pending_limit_orders` yeni bir tablo, canlı MySQL'de elle oluşturulmalı (bkz. dağıtım notu).
- **Test durumu:** Yerelde `php -l` + `PendingLimitOrder` modelinin CRUD işlemleri gerçek yerel DB'ye karşı doğrulandı. Gerçek Binance LIMIT emir yerleştirme/dolum akışı gerçek API anahtarı ve gerçek para gerektirdiği için yerel ortamda test EDİLEMEDİ - canlıda ilk gerçek çalıştırmada yakından izlenmeli.

## [1.70.11] - 2026-07-27

### İyileştirme (sadece backtest/admin - canlı davranış DEĞİŞMEDİ)
- [RiskManagerService, BacktestService, BacktestController, scripts/backtest.php] **Deneysel "Zirve Mesafesi" filtresi eklendi (SADECE backtest'te, varsayılan kapalı).** ZAMAUSDT #186 zararından ("zirveden düşüşün ortasında alım") sonra tasarlanan filtre - fiyat son N günün (varsayılan 5) zirvesinden %X'ten (varsayılan 10) fazla aşağıdaysa aday elenir. `RiskManagerService::isFarBelowRecentPeak()` (yeni, `isNear24hHigh()`'ın SAF/stateless mantıksal tersi), hem `BacktestService::run()`'ın yeni `$applyRecentPeakFilter` parametresi hem admin panelindeki "Backtest" formundaki yeni onay kutusu hem de `scripts/backtest.php`'nin yeni 5. CLI argümanı üzerinden açılabilir. **Doğrulama sonucu (3 sembol karşılaştırması): filtre net fayda GÖSTERMEDİ** - ZAMAUSDT ve PENGUUSDT'de (kârlı dönemler) performansı kötüleştirdi, sadece zaten kayıpta olan SOLUSDT'de hafif iyileşme sağladı. Bu yüzden CANLIYA (AutoTradeController) TAŞINMADI - proje kültürü (kanıtsız eşik değişikliği yapılmaz) gereği, bu haliyle kanıt yetersiz.

## [1.70.10] - 2026-07-27

### Hata Düzeltme
- [dashboard/index.php] **"AI Kalkanı" (Görünmez Kalkan) paneli bazı müdahale türlerini ham kod adıyla gösteriyordu.** `INTERVENTION_TYPE_LABELS` haritası sadece `MTF_TUZAK`/`SATIS_DUVARI` için Türkçe etiket içeriyordu, `AiIntervention::record()`'ın kullandığı diğer 4 tür (`ANTI_FOMO_ZIRVE`, `ANTI_FOMO_RSI`, `PULLBACK_BEKLENMEDI`, `lot_size_guard`) eşleşmeyince olduğu gibi (`item.intervention_type`) ekrana düşüyordu - müşteri panelinde "ANTI_FOMO_ZIRVE" gibi iç kod adları görünüyordu. Dördü için de Türkçe etiket eklendi.

## [1.70.9] - 2026-07-27

### İyileştirme
- [AutoTradeController] **Deterministik Motor'un hacim şartı (`≥1.0x`) artık sadece bir sembolün İLK geçişinde uygulanıyor, Ardışık Çift Onay'ın 2. (teyit) turunda tekrar zorunlu tutulmuyor.** Canlıda tespit edildi: PENGUUSDT/PEPEUSDT/ZAMAUSDT/EULUSDT gibi adaylar hacim rakamı 1.0x sınırının etrafında tur-tur salınıp GEÇTİ/REDDEDİLDİ arasında gidip geliyordu - REDDEDİLDİ olduğu an `pending_signals` kaydı silindiği için "art arda 2 tur" şartı, asıl trend gerçekten sağlamken bile hiçbir zaman tamamlanamıyordu (27 Temmuz akşamı 3 farklı coin ilk turu geçip ikinci turu hiç tamamlayamadı). Skor≥70 ve MACD olumlu şartı hem ilk hem ikinci turda GEÇERLİ kalmaya devam ediyor - sadece hacmin geçici dalgalanması ikinci turu bozmasın diye bu TEK kriter, bir kez ("ilk görülme") doğrulanmış bir sembol için tekrar zorunlu tutulmuyor. AI modu davranışı DEĞİŞMEDİ (bu mantık sadece `decision_motor='deterministic'` iken `$deterministicPass` hesabını etkiler).

## [1.70.8] - 2026-07-27

### Hata Düzeltme
- [AutoTradeController] **"AI Monolog" (dashboard) ve admin "Son Bot Çalıştırmaları" paneli deterministic modda neredeyse hep boş/anlamsız kalıyordu.** `bot_logs.selected_symbol/selected_score`, tarama turundaki adaylar listesinin İLK elemanına (`candidateIndex === 0`) bakılarak dolduruluyordu - bu, AI modunda geçerliydi çünkü liste GERÇEK GPT skoruna göre önceden sıralanıyordu (ilk eleman = en iyi aday). Ama v1.70.4'te GPT çağrısı atlanınca (sıralama anında tüm skorlar yer tutucu/0 olduğu için) bu sıralama deterministic modda anlamsız hale gelmişti - "ilk sıradaki" aday artık rastgele/tarama sırasına göre seçiliyordu, genelde gerçek en iyi/alınan coin DEĞİL. Artık deterministic modda her aday için gerçek Deterministik Motor skoru hesaplanır hesaplanmaz "bu tura kadarki en yüksek skorlu aday" ayrıca takip edilip `$selected` buna göre güncelleniyor (kabul/red fark etmeksizin) - paneller artık gerçekten o turun en iyi adayını gösteriyor. AI modu davranışı DEĞİŞMEDİ.

## [1.70.7] - 2026-07-27

### İyileştirme
- [AutoTradeController] **Pullback Kalkanı hedefi %0.15'ten %0.08'e düşürüldü.** Canlıda gözlemlendi: Deterministik Motor'a geçince istikrarlı/düz yükselen coinler (PENGUUSDT örneği - 15+ dakika boyunca art arda skor 85-100 ile GEÇTİ dediği halde tek bir kez bile %0.15 geri çekilme yakalayamadı) sistematik olarak elenip hiç alınamıyordu. Bu, 20 Temmuz'daki aynı sorunun (o zaman %0.5→%0.15) devamı - deterministic modda çok daha fazla aday bu aşamaya ulaştığı için sorun daha belirgin hale geldi. **Bilinçli olarak YAPILMAYAN değişiklik:** bekleme süresi (12sn) UZATILMADI - deterministic modda aynı turda 50'ye kadar aday sırayla pullback bekleyebildiği için (eski AI modunun 10 sınırından çok daha fazla), süreyi uzatmak `set_time_limit(180)` aşımı riskini büyütürdü; sadece hedef küçültüldü. Pullback şartının KENDİSİ (25 Temmuz'da BANKUSDT #131 zararı sonrası kaldırılan "Kaçış Supabı" - şart tamamen atlanması) BOZULMADI, hâlâ gerçek bir geri çekilme zorunlu, sadece bar gerçekçi hale getirildi.

## [1.70.6] - 2026-07-27

### Hata Düzeltme
- [AutoTradeController] **"SON BOT TARAMASI"/"SON BOT ÇALIŞTIRMALARI" panelleri deterministic modda tüm skorları 0 gösteriyordu.** v1.70.4'te GPT çağrısı atlanınca `$analyses` yer tutucu (`score=0`) değerlerle dolduruluyordu, ama bu yer tutucu hiç güncellenmeden doğrudan `bot_logs.ai_scores`/`selected_score`'a yazılıyordu - panel her adayı "skor 0" gösteriyordu, gerçek Deterministik Motor skoru sadece `auto_trade.log`'da görünüyordu. Artık her aday için `TechnicalScoreEngine`'in gerçek skoru hesaplanır hesaplanmaz `$analyses`/`$candidate` geri yazılıyor - paneller artık motorun gerçek kararını (kabul/red fark etmeksizin) doğru skorla gösteriyor.

## [1.70.5] - 2026-07-27

### İyileştirme
- [DashboardController] **"AI Radar" widget'ı da GPT'den Deterministik Motor'a (TechnicalScoreEngine) geçirildi.** `fetchAiRadar()` artık `SentimentService`/OpenAI çağırmıyor - AutoTradeController'daki asıl alım kararını veren AYNI RSI/MACD/hacim formülünü (skor≥70, MACD olumlu, hacim≥1.0 → "AL" işareti) kullanıyor, böylece panelde gösterilen sinyal botun gerçek kararıyla tutarlı. Tek sembolün Binance/hesaplama hatası artık tüm Radar'ı iptal etmiyor (SentimentService'in eski fail-open davranışıyla aynı şekilde nötr sonuç döner). Not: bu, GPT çağrısı yerine sembol başına ek Binance klines çağrısı (RSI + teknik skor) getiriyor - ağ aralıklı kesildiği dönemlerde Radar'ın yenilenmesi daha yavaş/kesik olabilir, ama dış API/maliyet bağımlılığı tamamen kalkıyor. "Piyasa Nabzı" metni (fetchMarketPulse) HENÜZ GPT kullanmaya devam ediyor - bu ayrı, doğal dil üretimi gerektiren bir özellik, bu değişikliğin kapsamı dışında bırakıldı.

## [1.70.4] - 2026-07-27

### İyileştirme
- [AutoTradeController] **Deterministik mod artık gerçek anlamda GPT'siz: `decision_motor='deterministic'` iken GPT/OpenAI hiç çağrılmıyor.** v1.70.0'da tanıtılan "Gölge Mod" (deterministic modda bile GPT'nin arka planda çalışıp sadece karşılaştırma/log için sonuç üretmesi) kaldırıldı - 171 işlemlik doğrulamadan sonra bu karşılaştırma verisine olan ihtiyaç ortadan kalktı, buna karşın v1.70.2'deki genişletilmiş (50'ye kadar) aday havuzuyla birlikte gölgede yapılan GPT çağrısı sayısı/maliyeti/gecikmesi orantısız büyümüştü - bu da 26/27 Temmuz gecesi yaşanan worker tıkanması olayına katkıda bulunan dış API yükünü gereksiz yere artırıyordu. Deterministik moddayken adaylar artık `score=0` yer tutucusuyla işleniyor (asıl karara zaten hiç katılmıyordu), AI modunda davranış birebir aynı kalıyor.

## [1.70.3] - 2026-07-27

### Hata Düzeltme
- [BinanceService, BinanceFuturesService] **KRİTİK: Binance zaman aşımı süreleri CLAUDE.md kuralına (3sn bağlantı + 5sn toplam) aykırı şekilde 10sn/15sn'e çıkarılmıştı - bu, canlıda gerçek bir "sistem donması" olayına doğrudan katkıda bulundu.** Aynı gece (26-27 Temmuz) sunucu ile Binance arasında ağ gecikmesi yaşandı; her yavaş/asılı istek olması gerekenden 3 kat daha uzun süre (15sn) PHP-FPM/lsphp worker'ını işgal etti, bu da worker havuzunun hızla tükenip sitenin (dashboard, admin panel, cron uç noktaları dahil) tamamen yanıt vermez hale gelmesine yol açtı - 10 asılı süreç elle sonlandırılıp kilitler temizlenerek geçici olarak çözüldü, ama kök neden bu ihlaldi. Her iki servis de şimdi CLAUDE.md'nin zorunlu kıldığı 3sn/5sn'e geri döndürüldü. `RECV_WINDOW` (Binance'in kendi saat toleransı, 10000ms) BU DEĞİŞİKLİKTEN AYRI ve ETKİLENMEDİ - o, Temmuz başındaki gerçek bir -1021 recvWindow hatası için doğru bir düzeltmeydi, zaman aşımı süresiyle karıştırılmamalı. Bu değişiklik ağ sorununun KENDİSİNİ çözmez (o barındırma/altyapı seviyesinde) ama her bir asılı isteğin worker'ı ne kadar süre işgal edebileceğini sınırlayarak aynı yoğunlukta bir ağ sorununun yeniden tam bir "donma"ya yol açma riskini azaltır.

## [1.70.2] - 2026-07-26

### İyileştirme
- [AutoTradeController] **Deterministik Motor artık GPT'nin elemesiyle sınırlı değil, taranan havuzun tamamını değerlendirebiliyor.** `MAX_CANDIDATES_PER_RUN=10` sınırı, adayların GPT'nin kendi skor eşiğini (`globalMinThreshold`) geçmesi şartıyla birlikte uygulanıyordu - `decision_motor='deterministic'` iken bu, motorun kendi kararına hiç katılmayan bir skora göre daralma anlamına geliyordu (Gölge Modda hesaplanan AI skoru sadece log için var, karara girmiyor). Artık deterministik modda bu AI-skor eşiği hiç uygulanmıyor, taranan tüm havuz (yeni `DETERMINISTIC_MAX_CANDIDATES_PER_RUN=50`'ye kadar - "tüm Binance" değil, zaten hacme göre ön-filtrelenmiş aynı 50'lik dinamik havuz) doğrudan Deterministik Motor'un kendi kriterlerine (skor≥70, MACD olumlu, hacim≥1.0) açılıyor. AI modunda davranış birebir aynı kalıyor.

## [1.70.1] - 2026-07-26

### İyileştirme
- [AutoTradeController] **BTC Bağımsızlık İstisnası eklendi.** BTC son 24 saatte %-3'ten fazla düşünce, skoru ne olursa olsun TÜM adaylar reddediliyordu - ama bazı coinler BTC'den gerçekten bağımsız/ters hareket edebiliyor. Çeşitlendirme Filtresi'nde zaten var olan paylaşılan `MarketScanner::calculatePriceCorrelation()` (Pearson korelasyonu) fonksiyonu BTCUSDT'ye karşı da çalıştırılır - korelasyon sıfır veya negatifse (gerçekten bağımsız/ters hareket) o aday BTC düşüş filtresinden muaf tutulur, ayrıca loglanır. **Bilinçli olarak muhafazakar başlatıldı**: bu istisna geçmiş işlem verisiyle DOĞRULANAMADI (BTC düşüşü sırasında bugüne kadar hiç alım denenmedi, bu davranışa dair hiç veri yok) - eşik gevşek "az korelasyonlu" değil, sıfır/negatif korelasyon şartı.

## [1.70.0] - 2026-07-26

### Yeni Özellik
- [TechnicalScoreEngine, AutoTradeController, AdminController, admin/index.php, config/app.php] **Deterministik Motor (Gölge Mod) eklendi.** Gerçek veride (168 işlem) GPT'nin AI Karar Skoru'nun kendi içinde iyi kalibre olmadığı tespit edildikten sonra (80+ bandı 70-79'dan net olarak daha kötü çıktı), alım kararını TechnicalScoreEngine'in tamamen deterministik (RSI/MACD/hacim) formülüne bırakan alternatif bir motor eklendi. Admin panelden `decision_motor` ayarı (AI / Deterministik) seçilebilir - varsayılan `ai`, mevcut davranış hiç değişmez. Deterministik motorun kuralı (teknik skor ≥70 + MACD olumlu + hacim deltası ≥1.0) rastgele seçilmedi - `check_deterministic_motor_net.php` ile 171 gerçek kapanmış işlemin komisyon düşülmüş NET kâr/zararına karşı test edildi: bu üç koşulu birlikte geçen 42 işlem başabaş (+0.04 USDT) çıkarken, geçemeyen 125 işlem platformun toplam net zararının (-11.06 USDT) neredeyse tamamını oluşturuyordu. `TechnicalScoreEngine::calculateScore()` artık `macd_positive`/`volume_delta` alanlarını yapılandırılmış şekilde de döndürüyor (eskiden sadece `reason` metnine gömülüydü, skor hesaplama mantığına dokunulmadı). **Gölge Mod**: Deterministik motor seçiliyken bile GPT/SentimentService çağrısı normal akışında (aday listesi oluşturulurken) çalışmaya devam eder - hiçbir OpenAI/Groq maliyeti kısılmaz, sadece hangi skorun asıl alım kararını verdiği değişir. Loglarda "Deterministik Motor (AKTİF/Gölge): ... GEÇTİ/REDDEDİLDİ" satırıyla her iki motorun kararı yan yana izlenebilir.

## [1.69.4] - 2026-07-26

### İyileştirme
- [RiskProfileService] **İstatistiksel verilere dayalı risk optimizasyonu: giriş barajı artık risk profilinden BAĞIMSIZ, tüm kullanıcılar için tek ve sabit.** Gerçek işlem verisinde (168 kapanan işlem) 60-69 AI skor bandının her iki kullanıcıda da net zarar getirdiği (%33.3 kazanma oranı) tespit edildi - "Agresif" profilin düşük eşiği (45) bu zayıf bandı doğrudan aday havuzuna sokuyordu. Üç profilin de (Güvenli/Dengeli/Agresif) `ai_score_threshold` değeri **70**'e eşitlendi. Risk profili artık giriş kararını değil, SADECE pozisyon içindeki risk iştahını (Zarar Kes genişliği, eşzamanlı pozisyon limiti) yönetiyor - "Agresif" olmak artık "zayıf sinyale girmek" değil, "pozisyon açıldıktan sonra daha geniş SL/daha fazla eşzamanlı pozisyon kabul etmek" anlamına geliyor. `globalMinThreshold()` bu değişikliği otomatik yansıtır. Bu değişiklik ortak `user_api_keys.ai_score_threshold` sütunu üzerinden hem spot hem futures motorunu etkiler - mevcut kullanıcıların DB'deki dondurulmuş değerleri de (1.44.1 emsaliyle) elle backfill edilmesi gerekiyor, aksi halde sadece profilini yeniden seçen kullanıcıları etkiler.

## [1.69.3] - 2026-07-26

### İyileştirme
- [AutoTradeController] **Log çıktılarında "skor" kelimesi netleştirildi (sadece metin, karar mantığı DEĞİŞMEDİ).** Aynı log akışında AI/GPT'nin alım kararını veren skoru ile TechnicalScoreEngine'in tamamen bilgilendirme amaçlı (karara hiç girmeyen) skoru yan yana "skor" olarak yazdığı için gerçek bir işlemde kafa karışıklığına yol açtığı fark edildi (PEPEUSDT örneği: teknik motor 40 - zayıf - yazarken AI Karar Skoru 85 ile alım yapılmıştı). Artık AI'nin alım kararını veren skor tutarlı şekilde "AI Karar Skoru:" olarak, TechnicalScoreEngine'in sadece izleme amaçlı çıktısı ise "Teknik Gözlem Puanı (Sadece İzleme):" olarak loglanıyor. Hiçbir eşik, if/else veya loglama mekanizması değişmedi - sadece string netleştirmesi.

## [1.69.2] - 2026-07-26

### Yeni Özellik
- [ActiveTrade, AutoTradeController, database.sql] **Giriş anındaki RSI değerleri kalıcı olarak kaydediliyor.** ESPUSDT #180 post-mortem'inde, 15 dakikalık RSI soğuyup sert filtreyi geçerken 1 saatlik RSI'ın hâlâ eşiğe (75) yakın (73) olduğu ve bunun sadece bilgilendirme metninde "düşük ağırlık" ile geçiştirildiği fark edildi. Yeni `entry_rsi_1h`/`entry_rsi_15m` sütunları hiçbir filtre/karar mantığını DEĞİŞTİRMİYOR - sadece ileride "eşiğe yakın giren işlemler daha mı çok kaybediyor?" sorusuna gerçek veriyle (tahminle değil) cevap verebilmek için veri biriktiriyor. Yeterli veri birikince (ör. 20-30 işlem) gerçek bir eşik değişikliği değerlendirilebilir.

## [1.69.1] - 2026-07-26

### Hata Düzeltme
- [AutoTradeController] **KRİTİK: Fast Tracker ile ana cron aynı pozisyonları eş zamanlı mutabakata sokabiliyordu.** Canlı veride tespit edildi: ESPUSDT pozisyonları (#180/#181, her iki kullanıcı) veritabanında "kapandı" (`closed_manual`) işaretlendi ama Binance'te orijinal OCO emirleri hâlâ tamamen dolmamış/canlıydı - pozisyonlar gerçekte hiç kapanmamıştı, sistem sadece onları takip etmeyi bırakmıştı. Kök sebep: Fast Tracker (`fast_tracker` kilidi) ile ana tarama (`auto_trade` kilidi) kasıtlı olarak birbirinden BAĞIMSIZ tutulduğu için (Fast Tracker'ın ana taramanın GPT süresinden etkilenmemesi için) ikisi `reconcileActiveTrades()`'i aynı anda çağırıp aynı açık pozisyonları es zamanlı işleyebiliyordu. Yeni, kısa ömürlü `active_trades_reconcile` kilidi eklendi - artık iki çağıran asla aynı anda mutabakat yapamıyor, kilit meşgulse o tur sessizce atlanıp bir sonraki çağrıda devam ediyor. Etkilenen #180/#181 kayıtları elle `open` durumuna geri alındı.

## [1.69.0] - 2026-07-26

### Yeni Özellik
- [AutoTradeController, public/index.php, config/app.php] **Hızlı Pozisyon Takipçisi eklendi.** Canlı gözlemle doğrulandı: İzleyen Stop'un zirveden sonraki tepkisi, açık pozisyon kontrolünün ana taramayla (GPT/OpenAI çağrıları içerdiği için dakikalarca sürebilen, PAYLAŞILAN kilit altında) aynı cron turunda çalışmasından dolayı gecikiyordu - bir önceki taramanın kilidi açık kaldığı sürece o turun mutabakatı (reconcileActiveTrades) hiç başlayamıyordu. Yeni `/api/fast-tracker/run` uç noktası AYNI `reconcileActiveTrades()` metodunu (kod tekrarı yok) ama TAMAMEN BAĞIMSIZ kendi kilidiyle (`fast_tracker`) çağırır - GPT çağırmaz, sadece Binance fiyat sorgusu + İzleyen Stop/Kâr Al günceller. cPanel'de ayrı bir Cron Job olarak 1 dakikada bir tetiklenmesi önerilir, kendi ayrı `fast_tracker_token`'ı vardır.

### Hata Düzeltme
- [MarketScanner, AutoTradeController] **Kara liste Sosyal Radar'dan sızıyordu.** Canlı veride tespit edildi: BANKUSDT (kara listede olmasına rağmen) aynı gün 14 kez alındı - kök sebep, kara listenin sadece Ana Radar'ın (`scanTopMovers`) tarama akışına eklenmiş olması, Sosyal Radar'ın kendi ayrı aday kaynağının (`fetchTradableSocialRadarSymbols`) bu filtreyi hiç görmemesiydi. `MarketScanner::getBlacklistedSymbols()` public'e çevrilip TEK merkezi kaynak olarak her iki radara da bağlandı - yeni bir kara liste yapısı kurulmadı.

### İyileştirme
- [config/app.php] **Kara listeye SHIBUSDT eklendi.** Aynı gün gerçek verisinde 2 kez üst üste zararla kapandı, aşırı oynak "iğne" (wick) davranışı İzleyen Stop mesafesinden bağımsız erken kapanışa yol açıyordu. PEPEUSDT bilerek eklenmedi - aynı gün 4/4 kârla kapandı, veriyle çelişirdi.

## [1.68.0] - 2026-07-26

### Yeni Özellik
- [MarketScanner, config/app.php, AdminController, admin/index.php] **AI Avcı Kara Listesi eklendi.** Gerçek işlem geçmişi raporunda (133 kapanan işlem) BANKUSDT/DEXEUSDT/BTCUSDT/ETHUSDT'nin tutarlı şekilde zararlı çıktığı tespit edildi. Whitelist boş olduğu (kısıtlama olmadığı) için bu coinleri "beyaz listeden çıkarmak" mümkün değildi - yeni `market_scanner_blacklist` ayarı whitelist durumundan (dolu/boş) TAMAMEN BAĞIMSIZ, HER ZAMAN uygulanır. Varsayılan: `BANKUSDT,DEXEUSDT,BTCUSDT,ETHUSDT`, admin panelinden değiştirilebilir.
- [Order, AutoTradeController, DashboardController] **Gerçek komisyon matematiği (Brüt/Net PNL ayrımı) eklendi.** `calculatePnlSummary/calculateStrategyBreakdown/calculateSymbolBreakdown/calculateSymbolPerformance/calculatePeriodSummary/calculateScoreBandBreakdown` artık Binance standart oranına (Alış %0.1 + Satış %0.1) dayanan tahmini komisyonu düşülmüş GERÇEK net kârı hesaplıyor - "kazanan/kaybeden" ve win_rate tanımı da bu YENİ net değere göre belirleniyor (marjinal brüt kârlı bir işlem artık doğru şekilde net zarar sayılabiliyor). Gerçek komisyon verisi `orders.commission`'da kayıtlı ama çoğunlukla BNB/altcoin cinsinden (127 BNB, ~10 farklı altcoin, sadece 27 USDT) - hepsini USDT karşılığına çevirmek ek fiyat sorguları gerektirdiği için düz oran tercih edildi (BNB indirimli hesaplar için hafif muhafazakar kalır, net kâr asla olduğundan fazla gösterilmez). Pozisyon kapanış Telegram bildirimine "Brüt Sonuç / Borsa Kesintisi (tahmini) / NET SONUÇ" kırılımı eklendi. İzleyen Stop Kilit yüzdesi artık en az %0.3 (komisyon + güvenlik payı) olmak zorunda - daha düşük bir değer, komisyon sonrası net zarara/başabaşa düşebilecek bir "kâr kilitleme" bildirimini engellemek için reddediliyor.

### İyileştirme
- [AutoTradeController] **Dinamik Erken Kaçış eşiği 30'dan 15'e düşürüldü.** Gerçek veri (133 işlemin sadece 8'i, %6) bu mekanizmanın ana zarar kaynağı olmadığını gösterdi - tamamen kaldırmak (ilk talep edildiği gibi) gerçek bir güvenlik ağını gereksiz yere kaldırırdı. Bunun yerine daha az agresif hale getirildi: artık sadece GERÇEKTEN çökmüş (skor 15 altı) pozisyonlarda tetiklenir.

## [1.67.3] - 2026-07-25

### İyileştirme
- [dashboard/index.php] **Canlı Savaş Radarı varsayılan görünümü "Tüm Seviyeler"e çevrildi** - kullanıcı ilk açılışta risk resmini (Giriş/Hedef/Zırh/Tetik) bütünüyle görmek istedi. Grafik yüksekliği 320px'ten 480px'e çıkarıldı, fiyat ekseni kenar boşlukları daraltıldı (%20/%10 → %8/%8) - mum bölgesine mümkün olan en fazla piksel ayrıldı. Not: Giriş-Hedef arası (ör. 3000 puan) ile gerçek mum oynaklığı (ör. 150 puan) arasındaki fark AYNI doğrusal eksende matematiksel olarak sıkışmaya yol açar - bu, kullanıcıyla birlikte değerlendirilip kabul edilen bilinen bir sınırlama; mum detayı gerektiğinde "🕯️ Sadece Mumlar" butonu tam çözüm sağlıyor.

## [1.67.2] - 2026-07-25

### Hata Düzeltme
- [dashboard/index.php] **Canlı Savaş Radarı'nda mumlar "Sadece Mumlar" modunda bile hâlâ sıkışıktı** - önceki düzeltme (1.67.1) varsayılan görünümü Giriş+Zırh'a zorluyordu, ama Zarar Kes geniş bir yüzdede ayarlıysa (ör. %1.5+) bu ikisi bile güncel mumların doğal oynaklığından (ör. %0.4) çok daha geniş kalabiliyor, mumlar yine sıkışıyordu (kullanıcı ekran görüntüsüyle tekrar bildirdi). Varsayılan görünüm artık SAF mum grafiği - hiçbir seviye (Giriş dahil) zorlanmıyor, sadece gerçek fiyat hareketine göre ölçekleniyor, mumlar tam ekranı dolduruyor. "🔎 Tüm Seviyeler" butonu değişmedi - tıklanınca hâlâ 4 seviyenin (Giriş/Hedef/Zırh/Tetik) tümünü kapsayan geniş görünüme geçiliyor.

## [1.67.1] - 2026-07-25

### Hata Düzeltme
- [dashboard/index.php] **Canlı Savaş Radarı'nda mumlar sıkışıp okunmaz hale geliyordu** - 4 referans çizgisinin (Giriş/Hedef/Zırh/Tetik) tümünü aynı anda fiyat eksenine zorlamak, Hedef/Tetik güncel fiyattan uzaksa mumları ince bir şeride sıkıştırıp okunmaz yapıyordu (kullanıcı "yakınlaştırmam gerekiyor" diye bildirdi). Varsayılan görünüm artık SADECE Giriş+Zırh'ı (genelde güncel fiyata en yakın ikisi) zorluyor, mumlar net görünüyor. Yeni "🔎 Tüm Seviyeler" butonu (modal başlığında) tıklanınca Hedef/Tetik'i de kapsayacak şekilde geniş görünüme geçiyor, tekrar tıklayınca "🕯️ Sadece Mumlar" görünümüne döner.

## [1.67.0] - 2026-07-25

### Yeni Özellik
- [DashboardController, dashboard/index.php] **Canlı Savaş Radarı'na İzleyen Stop Tetik Seviyesi eklendi** - kullanıcı "grafikte tetikleyeceği yeri göremiyorum" diye sordu: fiyatın hangi seviyeye ulaşırsa Zarar Kes'in yukarı çekilmeye başlayacağı (Kademeli İzleyen Stop'un aktifleştiği nokta) grafikte hiç gösterilmiyordu. Artık mor noktalı "Tetik" çizgisi olarak ekleniyor - Duyuru Avcısı (sniper) pozisyonları için sabit %10, diğerleri için kullanıcının kendi dashboard ayarından okunan `trailing_trigger_percent` kullanılıyor. Tetik zaten geçilmişse (`trailing_stop_stage >= 1`) çizgi gösterilmez - artık "gelecek" bir seviye değil, mevcut Zırh(SL) çizgisi zaten o kilitlenmeyi yansıtıyor. Gerçek tarayıcı testiyle doğrulandı.

## [1.66.2] - 2026-07-25

### İyileştirme
- [dashboard/index.php] **Canlı Savaş Radarı lejantına gerçek fiyat değerleri eklendi** - grafik altındaki "— — Giriş / Hedef (TP) / Zırh (SL)" lejantı sadece etiket gösteriyordu, gerçek rakamlar yalnızca grafiğin sağ eksenindeki küçük etiketlerde vardı (kullanıcı ekran görüntüsüyle "veri gözükmüyor" diye bildirdi). Artık lejant "Giriş: $64.300 · Hedef (TP): $64.900 · Zırh (SL): $64.100" şeklinde gerçek değerleri gösteriyor - Zırh değeri, İzleyen Stop tarafından değiştirildiğinde (5sn'lik tick güncellemesinde) canlı olarak güncelleniyor. Kar Al Tavanı Kaldırılmış pozisyonlarda "🚀 Hedef: Sınırsız (∞)" gösteriliyor.

## [1.66.1] - 2026-07-25

### Hata Düzeltme
- [dashboard/index.php] **Canlı Savaş Radarı'nda Giriş/Hedef/Zırh çizgileri görünmüyordu** - canlı sunucuda test edilirken (kullanıcı ekran görüntüsüyle bildirdi) mumlar doğru çiziliyordu ama hiçbir referans çizgisi görünmüyordu. Kök neden: Lightweight Charts fiyat eksenini SADECE gerçek seri verisine (mumlara) göre otomatik ölçekliyor, `createPriceLine()` ile eklenen çizgileri bu hesaba KATMIYOR - Giriş/Hedef/Zırh güncel mum aralığından uzaksa ekseni genişletmiyor, çizgiler görünür alanın dışında kalıyordu. Önce `series.autoscaleInfoProvider` denendi ama Playwright testinde etkisiz kaldı; bunun yerine görünmez bir "hayalet" çizgi serisi eklenip Giriş/Hedef/Zırh değerlerine veri noktası konuldu - otomatik ölçekleme gerçek seri verisini dikkate aldığı için fiyat ekseni artık üç seviyeyi de kapsayacak şekilde genişliyor.

## [1.66.0] - 2026-07-25

### Yeni Özellik
- [BinanceService, DashboardController, dashboard/index.php] **Canlı Savaş Radarı eklendi** - müşteri dashboard'undaki her açık pozisyon kartına "📈 Canlı İzle" butonu eklendi, tıklanınca TradingView Lightweight Charts (CDN, `defer`) ile son 50 adet 1 dakikalık mum + Giriş/Hedef(TP)/Zırh(SL) yatay çizgileriyle bir modal açılıyor. Yeni `GET /api/dashboard/live-chart` uç noktası (sahiplik kontrolü ile - başka kullanıcının pozisyon ID'siyle grafiği görülemez) mum geçmişini SADECE modal ilk açıldığında bir kez döner. Canlı güncelleme için AYRI bir uç nokta/interval EKLENMEDİ - zaten var olan 5 saniyelik `fetchActiveTrades()` döngüsüne (`/api/dashboard/hunts`) "biniyor", böylece Binance'e ekstra istek yükü binmiyor. Modal kapanınca grafik `chart.remove()` ile temizlenip durum sıfırlanıyor. Gerçek tarayıcıda (Playwright) test edilirken 2 bug bulundu ve düzeltildi: (1) `createChart()` container hâlâ `hidden` (display:none) iken çağrılıyordu, 0×0 boyutlu canvas oluşturup grafiği tamamen boş bırakıyordu - sıra değiştirildi; (2) `chart.timeScale().fitContent()` eksikti. Not: bu ortamda (XAMPP/macOS) PHP'nin Binance'e giden HTTPS isteklerinde önceden var olan aralıklı bir SSL/timeout sorunu gözlemlendi (NewsService'te de aynı belirti var) - kodla ilgisi yok, mevcut 3sn/5sn timeout kuralları sayesinde sessizce takılmak yerine hızlıca hata veriyor.

## [1.65.2] - 2026-07-25

### İyileştirme
- [AutoTradeController] **Yeni pozisyon Telegram bildirimine "Kasanın %Y'si" bilgisi eklendi** - "Bütçe: 4.25$" yerine artık "Kullanılan Bütçe: 4.25$ (Kasanın %25'si)" gösteriliyor, böylece bakiye değiştikçe bütçenin neden farklı çıktığı doğrudan mesajdan anlaşılıyor. Sadece formatlama - `protectPositionWithOco()`'ya `$budgetPercent` parametresi eklendi, mevcut bütçe hesaplama mantığına dokunulmadı.

## [1.65.1] - 2026-07-25

### Hata Düzeltme
- [User, admin/index.php] **Admin panelindeki "BÜTÇE" sütunu yanıltıcıydı** - artık kullanılmayan eski `auto_trade_budget_usdt` (sabit dolar) kolonunu gösteriyordu, ama gerçek işlem boyutu uzun süredir `auto_trade_budget_percent` (bakiyenin yüzdesi, her işlemde güncel bakiyeye göre dinamik hesaplanır) ile belirleniyor - admin panelinde görülen rakamın gerçek işlem büyüklüğüyle hiçbir ilgisi yoktu. Sütun artık gerçekten kullanılan yüzdeyi (`%10.0` gibi) gösteriyor.

## [1.65.0] - 2026-07-25

### Yeni Özellik
- [ActiveTrade, AutoTradeController, database.sql] **İşlem İçi Zirve/Dip İzleme (Trade Diagnostics) eklendi** - "neden Zarar Kes ile kapandı" sorusuna artık GERÇEK fiyat verisiyle cevap veriliyor. Yeni `active_trades.highest_price_reached`/`lowest_price_reached` sütunları (mevcut `highest_price_seen`'den KASITLI ayrı - o sütun sadece İzleyen Stop Aşama 2'ye ulaşıldıktan SONRA dolar, BANKUSDT #131'de hiç dolmamıştı) pozisyon açık olduğu sürece HER cron turunda, trailing/kısmi kâr alma durumundan tamamen BAĞIMSIZ güncelleniyor - kendi hafif/imzasız Binance sorgusuyla (Pullback Kalkanı'ndaki aynı desen), mevcut imzalı istemcinin çağrı bütçesini tüketmiyor. Pozisyon kapandığında (kâr veya zarar, ikisinde de) müşteri Telegram bildirimine zirve/dip yüzdeleri ve bir analiz cümlesi ekleniyor ("...maksimum +%1.8 yükseldi, ancak hedefe ulaşamayıp -%2.0 ile Zarar Kes oldu" / hiç yükselmediyse "Tepeden alım şüphesi"), ayrıca ayrı bir `storage/logs/trade_diagnostics.log` dosyasına tam kayıt düşülüyor. `TradePostMortemService`'in AI yorumunun YERİNE değil, YANINA eklendi - biri yorum üretir, biri saf fiyat verisi sunar.

## [1.64.1] - 2026-07-25

### Hata Düzeltme
- [admin/index.php] **Devre Kesici "Kesiciyi Aç" butonu admin'in KENDİ hesabında hiç görünmüyordu** - 1.64.0'da yanlışlıkla rol/durum/silme gibi `$isSelf` (kendine dokunma) korumasının İÇİNE yerleştirilmişti. Bu koruma o diğer işlemler için mantıklı (kendini admin'likten atıp sistemden kilitlenmeyi önler) ama Devre Kesici'yi kendine açmanın böyle bir riski yok - admin'in kendi hesabı kilitlenirse başka bir admin'e ihtiyaç duymadan açabilmesi gerekir. Buton artık `$isSelf` kontrolünün dışına taşındı, diğer butonlar (durum/rol/şifre/silme) davranışı değişmeden kaldı.

## [1.64.0] - 2026-07-25

### Kırıcı Değişiklik / Kaldırma
- [AutoTradeController, PendingSignal] **Pullback Kaçış Supabı tamamen kaldırıldı.** BANKUSDT #131 canlı zararı, supabın dayandığı "kesintisiz yükseliş = güçlü trend" varsayımının yanlış olduğunu gösterdi (coin hiç gerilemeden yükseldi, supap alıma izin verdi, sonra tepe yapıp zararla kapandı) - ayrıca aynı gün eklenen Zirve Yakınlığı/RSI "tepeden alma" filtreleriyle doğrudan çelişiyordu. Artık momentum ne kadar güçlü görünürse görünsün, fiyat beklenen geri çekilmeyi (`PULLBACK_TARGET_PERCENT`) yapmadan bu tur ASLA alıma girilmez - istisna yok. `PULLBACK_MAX_CONSECUTIVE_FAILURES` sabiti ve `PendingSignal::recordPullbackFailure()` ölü kod olarak kaldırıldı. `pending_signals.pullback_fail_count` sütunu canlıda dokunulmadan bırakıldı (artık boş kalacak, kaldırılması ek risk taşıyan gereksiz bir migration olurdu).

### Yeni Özellik
- [ApiKey, AdminController, admin/index.php] **Devre Kesici için admin panelinden manuel açma butonu eklendi.** `ApiKey::resetLossStreak()` daha önce var olan ama hiçbir UI'dan çağrılamayan bir metoddu - artık kullanıcı tablosunda "DEVRE KESİCİ" sütunu (Kilitli/açık, bitiş tarihi tooltip'te) ve kilitliyken görünen "Kesiciyi Aç" butonu var. Yeni `POST /admin/users/reset-circuit-breaker` uç noktası, mevcut kullanıcı yönetimi action'larıyla (durum/rol/silme/şifre sıfırlama) AYNI desende (`AuthMiddleware::requireAdmin`, session flash mesajları).

## [1.63.4] - 2026-07-25

### Hata Düzeltme
- [AiIntervention, AutoTradeController] **Pullback Kaçış Supabı Telegram spam'i tamamen giderildi** - önceki daraltma (1.63.2, skor eşiği kontrolü) yeterli değildi: BANKUSDT'te bir pozisyon zararla kapanınca kullanıcı için AYNI coin'de otomatik 24 saatlik sembol soğuması başladı, diğer kullanıcı zaten soğumadaydı - yani skor eşiği geçilse bile alım artık kesinlikle imkansızdı, ama "Kaçış Supabı: BANKUSDT" mesajı yine de ~7 dakikada bir saatlerce gelmeye devam etti. Her olası engeli (soğuma, açık pozisyon, maks. pozisyon limiti vb.) tek tek taklit etmek yerine, `AiIntervention::record()` artık gerçekten yeni bir satır ekleyip eklemediğini (`bool`) döndürüyor - kendi 4 saatlik aynı-sembol/tür throttle penceresi zaten var olduğundan, Telegram bildirimi bu değere bağlandı: throttle penceresindeyse (son 4 saatte aynı sembol için zaten bildirim gitmişse) Telegram TEKRAR gönderilmez, sebep ne olursa olsun. `AiIntervention` kaydı kendi throttle'ıyla değişmeden devam ediyor.

## [1.63.3] - 2026-07-25

### İyileştirme
- [AutoTradeController] **Kademeli Kâr Alma eşiği erkene çekildi** - canlıda BANKUSDT (pozisyon #131) fiyatı girişten %1.99 yukarı çıkıp (Kâr Al mesafesinin %50'si) İzleyen Stop'un %2.0 tetiğini kıl payı kaçırdı, sonra tersine dönüp sıkılaştırılmış Zarar Kes'e çarpıp zararla kapandı - hiçbir kâr realize edilemedi. Eski Kademeli Kâr Alma eşiği (%3.0, %50 satış) bu profildeki hareketleri hiç yakalamıyordu. `PARTIAL_TAKE_PROFIT_TRIGGER_PERCENT` %3.0'dan %1.8'e, `PARTIAL_TAKE_PROFIT_SELL_RATIO` %50'den %35'e çekildi - artık daha erken ama daha küçük bir dilim güvenceye alınıyor, kalan %65 İzleyen Zırh'ın gerçek zamanlı kâr kilitleme mantığıyla sürmeye devam ediyor. Riski AZALTAN bir değişiklik (daha erken kısmi kâr = daha az "tüm pozisyonu geri verme" riski) ama çok küçük bütçelerde (~15-20 USDT) %35'lik dilimin Binance MIN_NOTIONAL altında kalıp bu turda atlanma ihtimali var (mevcut güvenlik kontrolü zaten bunu sessizce bir sonraki tura erteliyor, hata değil).

## [1.63.2] - 2026-07-25

### İyileştirme
- [AutoTradeController] **Pullback Kaçış Supabı Telegram bildirimi daraltıldı** - adayın skoru hiçbir otonom kullanıcının kendi `ai_score_threshold`'unu geçemiyorsa (dolayısıyla alım zaten baştan imkansızsa) admin'e Telegram bildirimi artık gönderilmiyor - canlıda BTCUSDT/ZAMAUSDT skoru 45 iken (kullanıcı eşiği 55) supabın yine de bildirim gönderdiği gözlemlendi, kullanıcı talebiyle gürültü azaltıldı. `AiIntervention` kaydı (dashboard'daki Görünmez Kalkan) skor durumundan bağımsız HER ZAMAN yazılmaya devam ediyor.

## [1.63.1] - 2026-07-25

### Hata Düzeltme
- [AutoTradeController] **Pullback Kaçış Supabı'nın hiçbir zaman gerçek alıma dönüşmeme hatası düzeltildi** - eski tasarımda supap tetiklendiğinde `PendingSignal::delete()` sonrası Ardışık Çift Onay'a "tur 1/2" olarak temiz bir sayfayla giriliyordu, ama bir sonraki turda Pullback Kalkanı yine başarısız olursa kod doğrudan `continue` ile atlayıp Ardışık Çift Onay'a hiç uğramıyordu - 3 başarısızlık sonra supap tekrar tetiklenip o "tur 1/2" ilerlemesini sıfırdan siliyordu. Sürekli gerilemeyen bir coin'de (BANKUSDT'ta canlı yakalandı) supap sonsuz döngüde tekrar tekrar tetiklenip admin'e Telegram bildirimi spam'i atıyor ama hiçbir zaman gerçek bir alıma dönüşmüyordu. Artık supap tetiklendiğinde 2. tur beklemesi de atlanıp alıma DOĞRUDAN bu turda devam ediliyor.

## [1.63.0] - 2026-07-25

### Yeni Özellik
- [DailySummaryService, DailySummaryController, Order] **Gece Yarısı Hesap Özeti eklendi** - her müşteriye, kendi bağladığı Telegram'dan, günde bir kez (cPanel Cron Job, 00:00) o günün özeti gönderilir: kapanan işlem sayısı/kazanma oranı, bugünkü net PNL, açık spot/futures pozisyon sayısı, güncel USDT bakiyesi (Binance'ten canlı çekilir). Yeni `GET /api/daily-summary/run` uç noktası (ayrı `daily_summary_token`, admin panelinden değiştirilebilir), diğer cron modülleriyle AYNI CronLock deseni ama TAMAMEN FARKLI sıklıkta (günde 1 kez). `Order::calculateTodayPNL()` bilinçli olarak TAKVİM GÜNÜ (CURDATE()) sınırları kullanır - devre kesicinin kayan 24 saatlik penceresinden farklı bir ihtiyaç. Bakiye çekme hariç her şey saf DB sorgusu; bir kullanıcının özeti hata verirse (ör. geçersiz API anahtarı) diğer kullanıcıları etkilemez, fail-open.

## [1.62.1] - 2026-07-25

### Değişiklik
- [AutoTradeController] **Pullback Kaçış Supabı tetiklendiğinde artık admin'e Telegram bildirimi gönderiliyor** (kullanıcı talebi) - bu karar noktası `huntForAllUsers()`'dan önce, tüm kullanıcılar için ortak çalıştığından (MTF Tuzağı/Satış Duvarı ile aynı desen) belirli bir müşteriye değil, sadece admin'e gider. `TelegramService::notifyAdmin()` fail-open - yapılandırma eksikse sessizce loglar, sistemi asla kilitlemez.

## [1.62.0] - 2026-07-25

### Yeni Özellik
- [AutoTradeController, PendingSignal, database.sql] **Pullback Kalkanı'na "Kaçış Supabı" eklendi - canlıda BANKUSDT'nin ardışık 5+ turda teknik skoru 100'e rağmen hiçbir zaman gerilememesi (kesintisiz, düz yükseliş) üzerine.** Aynı sembol art arda `PULLBACK_MAX_CONSECUTIVE_FAILURES` (3) kereden fazla Pullback Kalkanı'na takılırsa, bir sonraki turda gerileme şartı O TUR İÇİN atlanıp alıma devam edilir - "ilk denemede hâlâ korumalı, ısrarla güçlü çıkan adayları sonsuza kadar kaçırmıyoruz" dengesi. `pending_signals` tablosuna yeni `pullback_fail_count` sütunu eklendi (mevcut `pass_count`/Ardışık Çift Onay mekanizmasından TAMAMEN AYRI bir sayaç, karıştırılmamalı). Not: bu, RSI/Zirve Yakınlığı gibi backtest destekli bir karar değil, canlı gözleme dayalı bir tahmin - ileride veriyle ayarlanması gerekebilir.

## [1.61.0] - 2026-07-25

### Değişiklik
- [MarketScanner, FuturesTradingService] **Futures (SHORT) modülü artık AI Avcı'nın Piyasa Tarama Beyaz Listesi'nden bağımsız.** `MarketScanner::scanTopMovers()`'a `ignoreWhitelist` parametresi eklendi (varsayılan `false`, spot davranışı DEĞİŞMEDİ); `FuturesTradingService` bunu `true` ile çağırıyor. Gerekçe: whitelist spot için backtest'te kanıtlanmış YÜKSELİŞ adaylarına odaklanmak amacıyla seçildi, ama futures TAM TERSİNE DÜŞÜŞ adayı arıyor - aynı listeyi ikisine uygulamak futures'ın aday havuzunu anlamsızca daraltıyordu (whitelist daraltıldıkça futures logu tamamen sessiz kalmıştı).

## [1.60.0] - 2026-07-25

### Yeni Özellik
- [MarketScanner, AutoTradeController] **ATR (Average True Range) Bazlı Volatilite Çarpanı eklendi - İzleyen Stop'un "Sınırsız İzleme" mesafesi artık piyasanın anlık volatilitesine göre esniyor.** `MarketScanner::calculateAtr()` (1 saatlik mum, 14 periyot, `calculateRsi()` ile aynı konvansiyon) fiyatın yüzdesi olarak ATR döner. `AutoTradeController::applyTrailingStopIfEligible()` bunu referans bir değere (`%0.8`, ilk tahmin - henüz gerçek veriyle ayarlanmadı) oranlayıp 0.5x-2.0x aralığında sınırlı bir çarpan üretir; bu çarpan kullanıcının kendi `trailing_distance_percent` ayarını EZMEZ, sadece esnetir. Kasıtlı olarak dar kapsamlı: SADECE normal (sniper olmayan) pozisyonların sürekli izleme aşamasını etkiler - Wick Koruması, Kademeli Kâr Alma, breakeven mantığına ve Sniper pozisyonlarının sabit agresif mesafesine HİÇ dokunulmadı (ayrı ayrı kanıtlanmış mekanizmaların aynı anda değişip bir sorun çıktığında sebebin ayırt edilememesini önlemek için).

## [1.59.0] - 2026-07-24

### Yeni Özellik
- [scripts/optimize_tpsl.php] **Yeni CLI "TP/SL Optimizasyonu" betiği eklendi** - `optimize_thresholds.php`'nin 90 günlük/8 coinlik gerçek sonucunda hiçbir eşiğin komisyon (%0.2) + sabit %2.0/%1.5 TP/SL oranı yüzünden pozitife geçemediği tespit edilince, odak eşikten TP/SL oranına kaydırıldı. `technicalScoreThreshold` 70'te sabitlenir (24 Temmuz'un genel özet tablosunda en iyi sonucu veren tek değer, 65 değil - 65 aslında 60'tan bile kötüydü), TP [2.5, 3.0, 4.0, 5.0] × SL [1.0, 1.2, 1.5, 2.0] kombinasyonları (yalnızca TP>SL olanlar) 8 coin üzerinde taranıp aynı iki-tablolu (detay + kümülatif genel özet) rapor basılır. `php scripts/optimize_tpsl.php [GUN=90]` ile çalıştırılır.

## [1.58.1] - 2026-07-24

### Değişiklik
- [scripts/optimize_thresholds.php] **Varsayılan süre 30'dan 90 güne, coin havuzu 3'ten 8'e (BTC/ETH/SOL/BNB/XRP/ADA/AVAX/DOGE) genişletildi**, istatistiksel güvenilirliği artırmak ve farklı volatilite karakterlerini kapsamak için. Ayrıca her eşiğin TÜM coinler genelindeki toplam işlem sayısını ve kümülatif net PNL'ini gösteren yeni bir "Genel Özet" tablosu eklendi - "en iyi eşik hangisi" sorusuna tek coin bazında değil, havuz genelinde cevap verir.

### Hata Düzeltme
- [scripts/optimize_thresholds.php] Özet tablo `str_pad(): Argument #1 must be of type string, int given` hatasıyla çöküyordu - PHP'nin sayısal görünümlü string dizi anahtarlarını (`"55"`) otomatik `int`'e çevirme davranışı, `strict_types=1` altında `str_pad()`'i patlatıyordu. Aynı gün içinde yazılıp test edilirken yakalandı ve düzeltildi.

## [1.58.0] - 2026-07-24

### Yeni Özellik
- [scripts/optimize_thresholds.php] **Yeni CLI "Eşik Optimizasyonu" betiği eklendi** - `BacktestService::run()`'ı en hacimli 3 coin (BTCUSDT/ETHUSDT/SOLUSDT) × 5 `technicalScoreThreshold` senaryosu (filtresiz, 55, 60, 65, 70) için döngüyle çalıştırıp tek bir karşılaştırma tablosu basar (işlem sayısı/kazanan/kazanma oranı/net PNL). Gerçek AI skoru (`ai_score_threshold`) `BacktestService`'te mevcut olmadığı için (GPT tabanlı, backtest'te yok) en yakın test edilebilir eşdeğer olan `TechnicalScoreEngine`'in deterministik 1-100 puanı kullanılır. `php scripts/optimize_thresholds.php [GUN=30] [TP=2.0] [SL=1.5]` ile çalıştırılır - 16 Temmuz denetiminden kalan "mevcut eşiklerin gerçek veriyle hiç doğrulanmadığı" açık maddesini kapatan ilk somut araç.

## [1.57.0] - 2026-07-24

### Değişiklik
- [scripts/backtest.php] **CLI backtest betiği artık `BacktestService::run()`'ı doğrudan çağırıyor, kendi bağımsız/senkronsuz kural kopyasını kullanmıyor.** Eskiden bu script tamamen ayrı bir dosyaydı (~200 satır) ve kendi RSI eşiğini (70.0, `BacktestService`'in güncel 75.0'ından habersiz), kendi hacim/pump/BTC-trend filtrelerini taşıyordu; Zirve Yakınlığı kontrolü ve komisyon düşümü hiç yoktu. Artık admin panelindeki `BacktestController` ile AYNI kaynağı (`BacktestService` + `RiskManagerService::isNear24hHigh()`) kullanıyor - laboratuvar (CLI/admin backtest) ile saha (canlı) arasında bundan sonra üçüncü bir senkron-kayması riski kalmıyor, gelecekteki her kural değişikliği otomatik olarak buraya da yansır. Çıktıya komisyon ve net kümülatif getiri satırları eklendi.

## [1.56.2] - 2026-07-24

### Hata Düzeltme
- [DashboardController] **"Son Kritik Hatalar" panelindeki yanlış alarm giderildi: Duyuru Avcısı'nın rutin, kendiliğinden düzelen PRE_TRADING/BREAK tarama zaman aşımı artık kritik hata olarak gösterilmiyor.** `collectRecentCriticalErrors()` sadece anahtar kelime eşleşmesiyle (`başarısız` içermesi) çalıştığı için bu zaten `BinanceApiTimeoutException` ile zarif şekilde yönetilen, bir sonraki cron turunda kendiliğinden düzelen geçici durumu (bkz. `ListingSniperController`) yanlışlıkla kritik gösteriyordu. Birleşik `PRE_TRADING/BREAK` ifadesi taşıyan satırlar artık dışlanıyor - tek tek "PRE_TRADING" veya "BREAK" kelimeleri değil, ileride bu kelimelerden birini içeren gerçek bir kritik hatanın yanlışlıkla gizlenmesini önlemek için.

## [1.56.1] - 2026-07-24

### Hata Düzeltme
- [AutoTradeController, BacktestService, RiskManagerService] **Zirve Yakınlığı eşiği 98'den 99'a gevşetildi - bugün eklenen filtre, mevcut Pullback/Hacim/RSI/MTF zinciriyle birleşince deploy sonrası 4+ saat SIFIR alım yapılmasına yol açmıştı (bir önceki gün 47 işlemlik tempoya karşı, son 2 saatteki 454 reddin %36.3'ü tek başına bu filtreydi).** Canlı veriyle doğrulandı: normal bir yükseliş gününde birçok coin zaten günün büyük bölümünü kendi 24s zirvesinin %2'si içinde geçiriyor - bu "tepede" değil "güçlü trendde" olmak anlamına geliyor. `isNear24hHigh()` `>= threshold ise REDDET` mantığıyla çalıştığı için gevşetme eşiği YÜKSELTMEK (98→99) ile yapılır, düşürmekle değil - ilk denemede bu yön ters uygulanmıştı (98→96), kendi testimizle yakalanıp aynı gün içinde düzeltildi.

## [1.56.0] - 2026-07-24

### Yeni Özellik
- [Order, AdminController, Views/admin] **Admin paneline "AI Skor Bandına Göre Performans" modülü eklendi.** Tüm kullanıcıların kapanmış işlemlerini AI giriş skoruna göre ("< 50", "50-59", "60-69", "70-79", "80+") kümülatif olarak gruplayıp her bant için işlem sayısı, kazanma oranı ve gerçekleşen net PNL'i gösterir - bu oturumda elle çalıştırılan tanılama sorgusunun kalıcı hale getirilmiş hali, `ai_score_threshold` ayarını ileride veriye dayalı ayarlamak için. `Order::calculateScoreBandBreakdown()`, mevcut `calculateSymbolBreakdown()` JOIN desenini (`orders` buy/sell eşleşmesi) `active_trades.buy_order_id` üzerinden bir katman daha genişletir; `ai_entry_score` NULL olan (AI onayı beklemeyen Duyuru Avcısı/Akıllı Para kaynaklı) işlemler hariç tutulur.

## [1.55.0] - 2026-07-24

### Yeni Özellik
- [AutoTradeController, RiskManagerService, BacktestService] **Modüler "Zirve Yakınlığı" sert reddi eklendi: fiyat 24 saatlik zirvenin %98'ine veya üzerine ulaşmışsa AI skoru ne kadar yüksek olursa olsun alım artık kesinlikle açılmıyor.** Daha önce bu veri (position_percent_24h) sadece GPT'ye bağlam olarak gidiyor, hiçbir sert kapı oluşturmuyordu. Kontrol `RiskManagerService::isNear24hHigh()` adında DB/network bağımlılığı olmayan saf (stateless) bir fonksiyonla yapılıyor - hem canlı tarama hem de `BacktestService` aynı metodu çağırdığı için laboratuvar ile saha birebir tutarlı kalıyor. Reddedilen adaylar `AiIntervention`'a `ANTI_FOMO_ZIRVE` tipiyle kaydediliyor. Yerel testte VANAUSDT (%99.6), BTCUSDT (%98.5), ETHUSDT (%98.2) ve UUSDT (%100.0) gerçek Binance verisiyle doğrulandı.

### Hata Düzeltme
- [AutoTradeController, ApiKey] **"Agresif Momentum Baypası" tamamen kaldırıldı - global aday havuzu artık her zaman katı RSI limitleriyle çalışıyor.** Bu mekanizma, platformda risk_profile='aggressive' VEYA futures_trading_enabled=1 olan TEK bir kullanıcı bile varsa, TÜM kullanıcıların (muhafazakâr/dengeli dahil) paylaştığı global aday tarama fazında RSI tavanını (1sa: 75→85, 15dk: 70→80) gevşetiyordu - bu, yüksek AI skorlu adaylara özellikle daha gevşek bir aşırı-alım kapısı tanıyarak "tepeden alım" riskini artırıyordu ve daha önce tespit edilen "80+ skor bandının 70-79'dan daha kötü kazanma oranı" bulgusunun muhtemel kök nedeniydi. `AGGRESSIVE_RSI_OVERBOUGHT_THRESHOLD`, `AGGRESSIVE_PULLBACK_RSI_OVERBOUGHT_THRESHOLD`, `AGGRESSIVE_BYPASS_MIN_AI_SCORE` sabitleri ve `ApiKey::hasAggressivePostureUser()` metodu kaldırıldı.

## [1.54.1] - 2026-07-24

### Hata Düzeltme
- [AutoTradeController] **Ani Fitil (Wick) Koruması süresi 3 dakikadan 7 dakikaya çıkarıldı.** Aynı gün içinde 3 dakikaya düşürülen süre canlıda çok agresif çıktı: RIFUSDT (10.2 dk) ve BANKUSDT (3.6 dk) "Ani Volatilite" ile zarar kese çarptı - BANKUSDT vakası eski 15 dakikalık sürede bile korunurdu. 7 dakika, orijinal 15'in yarısından azını koruyarak şelale/çöküş senaryosunda geniş kalkanda aşırı uzun kalma riskini hâlâ azaltırken, BANKUSDT/ZAMAUSDT (5.7 dk) gibi çoğunlukla rastlanan iğne vakalarını yine kapsıyor. Günlük net PNL karşılaştırmasıyla (23 Temmuz -$0.32, 24 Temmuz -$1.36) doğrulanan gerçek bir canlı olaydan sonra yapıldı.

## [1.54.0] - 2026-07-24

### Değişiklik
- [AutoTradeController] **Ani Fitil (Wick) Koruması süresi 15 dakikadan 3 dakikaya düşürüldü.** Ani şelale/çöküş senaryolarında pozisyonun geniş (daha büyük zarar potansiyelli) kalkanda gereğinden uzun kalmasını önlemek için. Bilinen ödün: 3-15 dakika arasında gelen bir "iğne" artık genişletilmiş korumadan yararlanamaz, asıl (dar) Zarar Kes'e maruz kalır.

### Yeni Özellik
- [RiskManagerService/Dashboard] **Manuel Kill Switch (Acil Durum Anahtarı) eklendi.** Sistem Durumu modalına, kullanıcının/yöneticinin 3 zararı veya günlük zarar limitini beklemeden istediği an TÜM otonom yeni-işlem girişlerini (spot + futures) durdurabileceği bir anahtar geldi. `RiskManagerService::checkCircuitBreaker()`/`checkFuturesCircuitBreaker()`'ın EN BAŞINDA kontrol edilir - açık pozisyonların izlenmesi/korunması (İzleyen Stop, DCA-dışı reconcile akışı) bu kontrolden geçmediği için ETKİLENMEZ. `circuit_breaker_until`'den (otomatik, 24 saat sonra kendiliğinden açılır) bilinçli olarak ayrı ve süresizdir - sadece tekrar kapatılınca açılır.
- `user_api_keys.manual_kill_switch` kolonu eklendi.
- Yerel ortamda gerçek verilerle doğrulandı: bayrak aktifken `checkCircuitBreaker()` doğru şekilde engelliyor, kapatılınca aynı kullanıcı için normal akış geri dönüyor, `findAllForAutoTrade()`/`findAllForFuturesTrade()` sorgularına yeni kolon doğru eklendi (aksi halde bayrak sessizce yok sayılırdı).

### Not (kod denetimi, değişiklik değil)
- Devre kesici Telegram bildiriminin art arda 3-4 kez gönderilmesi şeklinde bildirilen sorun kod tabanında **tekrar denetlendi**: hem spot (`AutoTradeController.php`) hem futures (`FuturesTradingService.php`) tetikleme noktalarının ikisi de `ApiKey::hasSentCooldownNotifToday()` ile zaten günde en fazla 1 bildirime sınırlandırılmış durumda (bkz. `last_cooldown_notif` kolonu, önceki bir sürümde eklenmişti). Kod tabanında bu davranışa yol açabilecek başka bir çağrı noktası bulunamadı - halen tekrarlı bildirim görülüyorsa gerçek Telegram loglarıyla birlikte ayrıca incelenmeli.

## [1.53.0] - 2026-07-24

### Hata Düzeltme
- [FuturesTradingService] **Short taraması artık sadece en iyi tek adayı denemiyor, eşiği geçen TÜM adayları sırayla deniyor.** Eskiden AI skoru eşiği (≤20) geçen ilk (en "bearish") aday RSI/hacim filtresinden geçirilir, o elenirse o turda başka hiçbir aday denenmeden tur tamamen atlanırdı. Canlı olayda tespit edildi: DEXEUSDT 14+ saat boyunca sürekli en iyi aday olarak seçilip RSI filtresine (aşırı satılmış) takılıyordu - eşiği geçen başka bir aday çıksa bile hiçbir zaman denenemezdi. Artık bir aday RSI/hacim filtresine takılırsa (loglanarak) bir sonraki en iyi adaya geçiliyor, ta ki biri filtreleri geçsin ya da eşiği geçen aday kalmasın.
- Spot motorundaki (`AutoTradeController`) zaten kanıtlanmış "sırayla dene" desenine uyumlu hale getirildi - futures bu konuda spot'tan kasıtsız şekilde geride kalmıştı.

## [1.52.0] - 2026-07-24

### Yeni Özellik
- [AutoTradeController/Sembol Soğuması] **"Kanıtlanmış Kazanan İstisnası" eklendi.** Sembol soğuması (Zarar Kes/Dinamik Erken Kaçış sonrası kara liste) artık SADECE son kapanışa değil, o sembolün kullanıcıdaki tüm-zamanlar net kâr/zararına da bakıyor - bir sembolün en az 3 kapanmış işlemi varsa VE toplamda net kârlıysa, soğuma süresi tamamen atlanmaz ama normalin %25'ine iner (Zarar Kes: 24 saat → 6 saat, Dinamik Erken Kaçış: 12 saat → 3 saat). Canlı örnek: ZAMAUSDT 6 işlemden 5'i kârlı (+$0.82 net) olmasına rağmen tek bir "Ani Volatilite" kapanışı yüzünden 24 saat tam kilitleniyordu - artık 6 saat bekleyip normal taramaya dönecek.
- `Order::calculateSymbolPerformance()` eklendi (tek sembol için tüm-zamanlar işlem sayısı + net kâr, calculatePnlSummary()'deki AYNI doğrulanmış JOIN deseni).
- **Manuel soğuma kaldırma eklendi** - Sistem Durumu modalındaki "Sembol Soğuması" listesindeki her satıra bir "Atla" butonu geldi, müşteri onay penceresinden sonra kendi isteğiyle bir kilidi anında kaldırabiliyor. `SymbolCooldown::clear()` sadece o (kullanıcı, sembol) kilidini siler, geçmiş işlem kayıtlarına dokunmaz.
- Yerel ortamda gerçek verilerle doğrulandı: 3+ kârlı işlemli bir sembolün süresi doğru kısaldı (24→6 saat), zararlı/yetersiz-örnekli sembollerin süresi değişmedi (24 saat), manuel "Atla" ile bir kilit uçtan uca kaldırıldı.

## [1.51.0] - 2026-07-24

### Yeni Özellik
- [Dashboard/Sistem Durumu] **"Günlük Risk Limiti" göstergesi eklendi.** `RiskManagerService::checkCircuitBreaker()`'daki kayan-24 saatlik zarar / günlük limit oranını görselleştiren bir ilerleme çubuğu - hiçbir engelleme kararı vermez, sadece kullanıcıya devre kesicinin yumuşak (günlük zarar %) tarafına ne kadar yaklaştığını önceden gösterir. Özkaynak spot (bakiye + açık pozisyon maliyeti) ve futures (cüzdan + kilitli marj) birleştirilerek hesaplanır; API/borsa erişimi başarısız olursa sessizce "veri alınamadı" gösterir (fail-open, asla hata fırlatmaz).
- **"Sembol Soğuması" listesi eklendi** (aynı modalde) - `symbol_cooldowns` tablosundaki, kullanıcının hâlâ kilitli olan (zarar/erken çıkış sonrası "intikam alımı" kilidi) tüm paritelerini, kalan süreleriyle birlikte listeler. Önceden bu bilgi sadece DB'den elle sorgulanarak görülebiliyordu.
- `SymbolCooldown::findActiveForUser()` eklendi.
- Yerel ortamda gerçek DB verisiyle doğrulandı (aktif + süresi dolmuş soğuma satırları, doğru filtreleme); risk limiti göstergesinin Binance'e bağlı kısmı yerel ortamda geçerli bir API anahtarı olmadığı için sadece "zarif hata" (fail-open, null) yolu doğrulanabildi - canlıda gerçek bakiyeyle bir kez daha kontrol edilmeli.

## [1.50.0] - 2026-07-24

### Yeni Özellik
- [Dashboard] **"Sistem Durumu" widget'ı eklendi.** Navbar'da versiyon rozetinin yanına bir durum noktası + "SİSTEM" butonu geldi - tıklanınca her otonom modülün (AI Avcı/Duyuru Avcısı/Akıllı Para/Futures) gerçekten canlı olup olmadığını (son çalışma zamanı), bu kullanıcının devre kesici durumunu ve log dosyalarındaki son kritik hataları tek modalda gösterir. Bu oturum boyunca defalarca cPanel Terminal'e girip elle yapılan kontrolün dashboard'a taşınmış hali.
- `ListingSniperService`/`SmartMoneyTracker`/`FuturesTradingService::run()` artık her çağrıldıklarında (erken dönüşler dahil) koşulsuz bir `Setting` zaman damgası (`{modül}_last_run_at`) yazıyor - önceden bu modüllerin "gerçekten çalışıyor mu" sorusuna güvenilir bir DB sinyali yoktu, sadece olay bazlı (hata/tespit) log satırları vardı ki bu sessiz ama sağlıklı bir turu "durgun" gibi gösterirdi.
- **"Performans Analizi" modalı eklendi** ("Son İşlemler" panelindeki "ANALİZ" butonu) - hangi stratejinin (dipten_donus/golge_hacim/erken_momentum/whitelist/announcement_hunter) ve hangi paritenin gerçekten kazandırdığını/kaybettirdiğini gösterir. `Order::calculateStrategyBreakdown()`/`calculateSymbolBreakdown()`, `calculatePnlSummary()`'deki ZATEN doğrulanmış `parent_order_id` JOIN desenini yeniden kullanır.
- **"GEÇMİŞ" modalı eklendi** ("Son İşlemler" panelinde) - günlük/haftalık/aylık seçimli, seçilen dönemin tam özetini (toplam işlem/kazanma oranı/net kâr) ve tüm işlem listesini gösterir. Önceden "Son İşlemler" tablosu son 10 kayıtla sınırlıydı.
- Yerel ortamda gerçek round-trip test işlemleriyle (kâr/zarar karışık, farklı strateji/parite/tarih kombinasyonları) tüm yeni uç noktalar doğrulandı, test verileri temizlendi.

## [1.49.0] - 2026-07-24

### Yeni Özellik
- [Dashboard] **"Son İşlemler" paneline "GEÇMİŞ" butonu eklendi** - tıklanınca günlük/haftalık/aylık seçimli bir modal açılır, seçilen dönemin TAMAMINI (toplam işlem sayısı, kazanma oranı, net kâr/zarar özeti + o dönemdeki tüm işlemlerin listesi) gösterir. Mevcut "Son İşlemler" tablosu sadece son 10 kayıtla sınırlıydı, daha eskiye gitmenin bir yolu yoktu.
- `Order::calculatePeriodSummary()` ve `Order::findByUserInPeriod()` eklendi - `calculatePnlSummary()`'deki ZATEN doğrulanmış `parent_order_id` JOIN desenini (kademeli kâr almanın çift saymasına ve açık pozisyon anaparasının zarar gibi görünmesine karşı önceden düzeltilmiş) yeniden kullanır, tekerleği yeniden icat etmez.
- `/api/dashboard/order-history?period=daily|weekly|monthly` uç noktası eklendi.
- Yerel ortamda gerçek round-trip test işlemleriyle (bugün/3 gün önce/15 gün önce, kâr+zarar karışık) doğrulandı: her üç dönemin hem özet rakamları (toplam/kazanma oranı/net kâr) hem de işlem listesi kayan pencere sınırlarına birebir uyuyor.

## [1.48.0] - 2026-07-24

### Yeni Özellik
- [DashboardController/dashboard/index.php] **"Aktif Avlar" paneli artık sayfa yenilenmeden yeni açılan pozisyonları gösteriyor.** Panel her 5 saniyede bir `/api/dashboard/hunts`'ı çekiyordu ama sadece sayfa ilk yüklendiğinde sunucu tarafında render edilmiş kartların İÇERİĞİNİ (anlık fiyat/PNL/kalkan rozeti) güncelliyordu - sayfa açıldıktan SONRA açılan bir pozisyon için hiç yeni kart oluşturulmuyordu, kullanıcı ancak tam sayfa yenilemesiyle görebiliyordu (mobilde anaekrana eklenmiş kullanımda bu daha da belirgindi).
- `apiHunts()` yanıtına `pair`/`entry_price`/`take_profit_price` eklendi (kart iskeletini JS'te inşa edebilmek için - önceden sadece ilk yüklemede statik PHP ile basılıyordu). Yeni `syncHuntCards()` fonksiyonu her pollingde sunucu yanıtıyla DOM'u karşılaştırıp DOM'da olmayan pozisyonlar için sunucu tarafındaki kartla BİREBİR aynı şablonla yeni kart ekliyor, artık yanıtta olmayan (kapanmış) pozisyonların kartlarını kaldırıyor, "POZİSYON" sayacını ve boş-durum mesajını buna göre güncelliyor.
- Yerel ortamda gerçek bir test pozisyonu (BTCUSDT) açılıp API/HTML çıktısı doğrulandı: `data-hunt-card`/`id="huntsContainer"`/`id="huntsPositionCount"` doğru render edildi, `/api/dashboard/hunts` yeni alanları doğru döndürdü, pozisyon silindiğinde yanıt boş döndü (kart kaldırma mantığının dayandığı veri doğrulandı).

## [1.47.1] - 2026-07-23

### Hata Düzeltme
- [AutoTradeController] **Kademeli Kâr Alma, pozisyon %3 kâr eşiğini her geçtiğinde fatal hataya düşüyordu.** `applyPartialTakeProfitIfEligible()`, önceki bir refactor'da kaldırılmış `TRAILING_STOP_STAGES` sabitine hâlâ referans veriyordu (`ApiKey::getTrailingSettings()` ile dinamik tek-aşamalı sisteme geçilmişti ama bu tek çağrı noktası güncellenmemiş kalmıştı) - "Undefined constant" hatası `reconcileActiveTrades()`'in try/catch'inde sessizce yakalanıp loglansa da, o turda İzleyen Stop / Ani Fitil Koruması sıkılaştırması / DCA hep atlanıyordu. Canlıda RIFUSDT pozisyonu (#105) üzerinde tespit edildi (23:31 UTC+3): normal pozisyonlarda maksimum aşama artık her zaman sabit 1 - kaldırılan sabite hiç ihtiyaç yoktu, doğrudan koşullu sabit değere çevrildi.

### Yeni Özellik
- [RiskManagerService/ApiKey] **Devre kesicide "Ardışık Zarar Sıfırlama" (loss streak reset) admin müdahalesi eklendi.** Devre kesici, 24 saat içindeki son 3 kapanan işlemin hepsi zararlıysa tetiklenir; `circuit_breaker_until` alanını tek başına temizlemek KALICI bir çözüm değildi çünkü bir sonraki `checkCircuitBreaker()` çağrısı aynı 3 eski zararı yeniden bulup anında yeniden kilitliyordu (yeni bir kazanan işlemle akışı kırmak için hesabın açılması gerekiyor, ama devre kesici tam da o denemeyi engelliyordu - kısır döngü).
- `user_api_keys` tablosuna `loss_streak_reset_at` kolonu eklendi. `ApiKey::resetLossStreak()` bu alanı `NOW()` ile damgalayıp devre kesiciyi açar; `ActiveTrade::findRecentClosed()` / `ActiveFuturesTrade::findRecentClosed()` artık opsiyonel bir `$sinceTimestamp` parametresi alıp bu tarihten ÖNCEKİ kapanışları sayıma dahil etmiyor. Hiçbir geçmiş işlem kaydı (durum/zarar nedeni) değiştirilmiyor veya silinmiyor - sadece devre kesicinin "ardışık 3 zarar" sayımına hangi kapanışların dahil olacağı ileri alınıyor.
- Gerçek yerel veritabanıyla doğrulandı: 3 sahte ardışık zararlı işlem oluşturulup devre kesicinin doğru şekilde tetiklendiği, `resetLossStreak()` sonrası AYNI 3 eski zarara rağmen devre kesicinin artık engellemediği ve geçmiş kayıtların bozulmadığı test edildi.

## [1.46.0] - 2026-07-23

### Yeni Özellik
- [AutoTradeController] **"Ani Fitil (Wick) Koruması" eklendi.** LISTAUSDT/BANKUSDT/ZAMAUSDT gibi pek çok pozisyon, açılıştan sadece 1.5-13.9 dakika sonra "Ani Volatilite/iğne" ile Zarar Kes'e çarpıp kapanıyordu - gerçek bir trend dönüşü değil, anlık bir fiyat sıçramasıydı. İlk OCO artık kullanıcının gerçek Zarar Kes'inden HER ZAMAN daha geniş bir "Geniş Kalkan" ile kuruluyor (`max(kullanıcı_SL × 2, %3.0)` - asla kullanıcının ayarından dar olmaz), 15 dakika sonra `tightenStopLossIfEligible()` bunu asıl hedefe sıkılaştırıyor.
- Mevcut İzleyen Stop / Kısmi Kâr Alma mekanizmalarıyla çakışmaması için bilinçli öncelik sırası var: bu iki mekanizma zaten SL'e dokunmuşsa (`trailing_stop_stage != 0` veya `partial_tp_executed = 1`), sıkılaştırma kodu üzerine yazmaz, sadece bayrağı kapatır. `reconcileActiveTrades()` döngüsünde İzleyen Stop kontrolünden HEMEN SONRA çalışır ve pozisyonu ID üzerinden TAZE okur (aynı turda İzleyen Stop'un yaptığı güncellemeyi görebilsin diye).
- OCO iptal/yeniden-kur mekaniği için kod tekrarı yapılmadı - İzleyen Stop'un halihazırda kullandığı `replaceOcoWithNewStop()` fonksiyonu aynen yeniden kullanıldı.
- `active_trades` tablosuna `is_sl_tightened` kolonu eklendi (varsayılan 1 - mevcut açık pozisyonlar bu özellikten etkilenmez, sadece yeni açılan pozisyonlar 0 ile başlar).
- Gerçek yerel veritabanıyla doğrulandı: geniş kalkan formülü (%1.5→%3, %2→%4, %5→%10, %10→%20), `is_sl_tightened` alanının doğru yazılması, ve en kritik olan muhafız mantığı (zaten sıkılaştırılmış/İzleyen Stop devrede/henüz vakti gelmemiş durumlarında OCO'ya dokunmama) test edildi.

## [1.45.2] - 2026-07-22

### Hata Düzeltme
- [Dashboard] Nav bar'daki "PORTFÖY" toplamı sadece spot bakiye + spot pozisyonları sayıyordu, futures cüzdanı ve açık futures pozisyonunun anlık kâr/zararı hiç dahil edilmiyordu. `BinanceFuturesService::getWalletBalance()` eklendi (mevcut `getUsdtBalance()`'dan bilinçli olarak farklı - o "serbest/kullanılabilir" marjı döner, izole pozisyonlara kilitli marjı hariç tutar; yeni metod TOPLAM cüzdan bakiyesini döner). `apiPortfolio()` artık futures cüzdan bakiyesi + açık futures pozisyon(lar)ın unrealized PNL'ini toplama ekliyor.
- Canlı veriyle doğrulandı: `availableBalance` (serbest marj) açık bir DEXEUSDT pozisyonu varken gerçek toplam bakiyeden ~6.5 USDT (kilitli izole marj) eksik gösteriyordu - bu kilitli tutar artık `getWalletBalance()` ile doğru şekilde sayılıyor.
- Futures verisi alınamazsa (kullanıcı futures açmamış, API izni yok vb.) toplam sessizce spot-only'e döner (0 katkı, null DEĞİL) - mevcut spot-only davranış asla bozulmaz, sadece futures verisi mevcutsa üzerine eklenir.

## [1.45.1] - 2026-07-22

### Hata Düzeltme
- [Dashboard] Üst gezinme çubuğundaki "AÇIK POZİSYON" sayacı sadece spot pozisyonları sayıyordu, futures pozisyonlarını dahil etmiyordu - açık bir futures pozisyonu varken sayı gerçek toplamdan eksik gösteriliyordu. "Aktif Avlar" panelindeki sayaç zaten doğruydu (ikisini de topluyordu); üstteki rozet buna eşitlendi.

## [1.45.0] - 2026-07-22

### Yeni Özellik
- [MarketScanner/AutoTradeController/SentimentService] **"Çift Katmanlı Zirve Koruması"nın mikro (24 saatlik) katmanı eklendi.** Sistemde zaten 90 günlük zirveye yakınlığı kontrol eden bir makro katman vardı (`buildMacroTrendPromptFragment`); bu, 90 günlük rekora uzak ama SON birkaç saatte sert pompalanıp KENDİ günlük zirvesine yapışan coinleri (canlı örnek: LISTAUSDT - AI skoru 85 ile alındı, 13.9 dakika sonra Zarar Kes'e çarptı, post-mortem "Ani Volatilite/iğne" dedi - muhtemelen tam zirveden alınan bir düzeltmeydi) yakalayamıyordu.
- `MarketScanner::calculateDailyPeakPosition()` eklendi - Binance ticker'ının zaten döndürdüğü `highPrice`/`lowPrice`'ı kullanır, EK bir API çağrısı gerektirmez. `scanTopMovers()` ve `getTickerData()` (Sosyal Radar yolu) artık `high_24h`/`low_24h`/`position_percent_24h` de döndürüyor.
- **Önce prompt-içi bir "ANTI-FOMO KURALI" denendi, ANCAK canlı testte GÜVENİLMEZ çıktı**: model, "Günlük Zirve Bilgisi" bloğu prompt'ta göründüğü an - içindeki gerçek sayılara (pozisyon %30 olsa, değişim sadece %3 olsa BİLE) hiç bakmadan otomatik reddediyordu (yön-okuma hatasıyla aynı kategori sorun - bileşik eşik karşılaştırmasını güvenilir uygulayamıyor). Bu yüzden kural prompttan çıkarılıp `SentimentService::isAtDailyPeakFomoRisk()` ile PHP'de mekanik olarak uygulandı (aynı `MIN_VOLUME_FOR_AI_SCORING_USDT` guard mimarisi): pozisyon ≥85 VE değişim ≥15 ise model'e hiç sorulmadan `score=40`, `reason="Aşırı alım bölgesinde, düzeltme (pullback) riski yüksek (Sistem Reddi)"` dönüyor.
- Gerçek API çağrılarıyla doğrulandı: eşiği aşan senaryolar anında (0sn, model çağrılmadan) reddedildi; eşiğin hemen altındaki ve "düzeltme yapmış ama hacmi güçlü" senaryolar doğru şekilde modele gidip CESARET KURALI'ndan yüksek puan aldı (önceki prompt-only versiyonda bunlar da yanlışlıkla reddediliyordu).

## [1.44.3] - 2026-07-22

### Yeni Özellik
- [SentimentService] **Mekanik "Hard Hacim Filtresi" eklendi**: 24 saatlik hacmi (`quoteVolume`) 3.000.000 USDT'nin altında olan bir sembol artık AI'ye (OpenAI/Groq/Gemini) HİÇ sorulmadan, doğrudan `score=30`, `reason="Hacim çok düşük, likidite riski (Sistem Reddi)"` ile mekanik olarak reddediliyor. Amaç: 1.44.2'de tespit edilen "düşük hacimli ama yüksek yüzdeli pump'ı model yanlışlıkla güçlü sanıp yüksek puan verebiliyor" riskini, bu senaryoların model'e ulaşabildiği TEK yol olan Sosyal Radar/Pusu Kurtarma kaynaklı adaylarda (bunlarda MarketScanner'ın 5.000.000 eşiği gibi bir hacim ön-filtresi YOK) kaynağında kapatmak.
- Eşik bilinçli olarak MarketScanner'ın kurulu-coin eşiğinin (5.000.000) altında tutuldu - MarketScanner adayları zaten bunu aştığından bu guard onlar için no-op'tur, sadece hacim filtresi olmayan yolları etkiler. Ayrıca gereksiz AI sağlayıcı çağrılarını (ve maliyetini) de engelliyor.
- Gerçek API çağrısıyla doğrulandı: aynı +%40 pump verisiyle 80K USDT hacimde anında (0sn, ağ çağrısı yapılmadan) reddedildi; 3.5M USDT hacimde ise normal şekilde AI'ye gidip 85 puan aldı - guard sadece hedeflenen durumu etkiliyor.

## [1.44.2] - 2026-07-22

### Hata Düzeltme
- [SentimentService] **AI puanlama promptu dengelendi + gerçek bir yön-okuma hatası bulunup düzeltildi.** Canlı sunucuda REUSDT örneğinde (+%26.5/24s, 389M USDT hacim gibi ders kitabı bir kırılım) AI'nin sadece 45 puan verdiği tespit edildi - kök neden 1.44.0'daki promptun "kuşkuda kaldığında DÜŞÜK puan ver, asla iyimser tahmin yapma" ifadesinin çok baskın olmasıydı. Bu ifade yumuşatılıp yerine açık bir "CESARET KURALI" eklendi (güçlü %değişim + güçlü hacim → 60-85 arası ödüllendir), "TEMKİN KURALI" (yatay/düşük hacim → 40-50) olduğu gibi korundu.
- **Asıl kök neden ayrıca bulundu**: gpt-4o-mini, pozitif yüzde değişimlerini (işaretsiz gösterildiği için, ör. "%26.50 değişti") tutarlı şekilde "düşüş" olarak yanlış okuyordu (4/4 tekrarlı testte aynı hata, `reasonContradictsDirection()` güvenlik ağı bunu doğru yakalayıp gerekçe metnini düzeltiyordu ama HAM PUAN yine de modelin bu yanlış inancıyla düşük çıkıyordu - güvenlik ağı sadece metni düzeltir, puanı düzeltmez). Prompt artık sayıya açık `+/-` işareti VE PHP'de hesaplanan bir "YÖN: YÜKSELİŞ/DÜŞÜŞ" etiketi içeriyor - model artık işareti çıkarsamak zorunda değil. Aynı REUSDT verisiyle 4/4 tekrarda doğru yön + 85 puan doğrulandı.
- Bilinen sınırlama (kullanıcıya iletildi): "Cesaret kuralı" hacmi göreceli bıraktığından, çok düşük hacimli (ör. 80.000 USDT) ama yüksek yüzdeli bir "şüpheli pump" senaryosunda model hacmi yanlış "güçlü" sayıp yüksek puan verebiliyor - gerçek sistemde MarketScanner'ın min. hacim eşiği (~5.000.000 USDT) bu senaryoyu büyük ölçüde filtreler, ama Sosyal Radar/Pusu Kurtarma gibi bypass yollarından biri düşük hacimli bir adayı AI'ye ulaştırırsa risk kalır.

## [1.44.1] - 2026-07-22

### Değişiklik
- [RiskProfileService] **Risk Profili AI skor eşikleri, 1.44.0'daki "prop-trader" promptunun daha cimri puanlamasına uyacak şekilde aşağı çekildi**: Güvenli 70→65, Dengeli 60→55, Agresif 50→45. Yeni prompt gerçekten iyi fırsatlara dahi 60-65 bandında puan verecek şekilde tasarlandığından, eski eşikler AI'nin kendi "iyi fırsat" bandının üstünde kalıp botu fiilen susturuyordu.
- **Mevcut kullanıcıların dondurulmuş `ai_score_threshold` değerleri de geriye dönük senkronlandı** (bkz. `database.sql` veri migrasyonu) - bu değer sadece kullanıcı profilini YENİDEN SEÇTİĞİNDE yazıldığından, sabit değişikliği tek başına zaten aktif kullanıcıları etkilemezdi.
- Gerçek DB'ye karşı doğrulandı: `updateRiskProfile()` yeni sabitleri doğru yazıyor, mevcut satırlar backfill sonrası doğru eşiğe sahip, yeni promptun tipik bir skoru (58) artık Dengeli eşiğini (55) geçip işlem açabiliyor - eski eşikte (60) geçemezdi.

## [1.44.0] - 2026-07-22

### Değişiklik
- [SentimentService] **AI puanlama sistem talimatı (system prompt) "prop-trader" moduna geçirildi** - eskiden daha genel/"haber yorumcusu" tonundaki metin, sadece kârlılığa odaklanan acımasız bir analist kimliğine değiştirildi: fiyat hareketi/hacim anomalisi/kırılım/dönüş sinyaline odaklan, genel haber dedikodusuna prim verme, net fırsatlara 60+ puan ver, kararsız/yatay/pump-dump grafiklere 50 ve altı puan ver, gerekçeyi Binance AI tarzı kısa-teknik-aksiyon odaklı yaz. Karar eşiklerine (BUY_THRESHOLD, kullanıcı `ai_score_threshold`) dokunulmadı, sadece modelin ham puanı/üslubu etkilenir.
- Not: yeni 60+/50-altı kutuplaşması, önceki 70+/30-altı kutuplaşmasından daha dar - "Güvenli" risk profilinin eşiği 70 olduğu için AI'nin 60-65 bandında bulduğu "iyi fırsat"lar o kullanıcıları tetiklemeyebilir. Kullanıcının açık talimatı üzerine birebir uygulandı.

## [1.43.2] - 2026-07-22

### Yeni Özellik
- [Dashboard] **AI Radar, Haberler ve Aktif Avlar panellerine "X sn/dk önce güncellendi" etiketi eklendi** - "dashboard tam canlı değil" geri bildirimine karşı, panellerin arka planda gerçekten güncellendiğini görsel olarak kanıtlıyor. `markUpdated()`/`tickFreshnessLabels()` - her fetch (başarılı/başarısız fark etmez) kendi anahtarıyla zaman damgası bırakır, saniyede bir sayaç gibi ilerletilip panel başlığında gösterilir.
- Gerçek tarayıcı testiyle doğrulandı: etiketler yer tutucudan (—) çıkıp gerçek veri aldığı, 4 saniye beklendiğinde sayacın GERÇEKTEN ilerlediği ("az önce" → "7sn önce") görsel olarak teyit edildi.

## [1.43.1] - 2026-07-22

## [1.43.1] - 2026-07-22

### Hata Düzeltme
- [Dashboard] **AI Radar ve Haberler panelleri artık gerçekten canlı** - `fetchRadar()`/`fetchNews()` eskiden diğer tüm panellerin (bakiye, pozisyonlar, PNL) aksine periyodik `setInterval` listesinde değildi, sadece sayfa ilk açıldığında ve sekmeye geri dönülünce çalışıyordu. Artık 90sn/120sn'de bir otomatik yenileniyor - backend zaten önbellekli olduğu için (Radar 120sn, Haberler 15dk) bu ek OpenAI/RSS çağrısı tetiklemiyor, sadece önbellekten taze veri çekiyor.

## [1.43.0] - 2026-07-22

## [1.43.0] - 2026-07-22

### Hata Düzeltme (Kritik)
- [Kritik/Altyapı] **"run() genel hata: SQLSTATE[HY000]: General error: 2006 MySQL server has gone away" - canlı loglarda tespit edilen, taramanın tamamını iptal eden bağlantı kopması düzeltildi.** `Database::getInstance()` tek bir PDO bağlantısını (singleton) tüm istek ömrü boyunca yeniden kullanıyordu, hiçbir canlılık kontrolü yoktu. `AutoTradeController::run()` gibi uzun süren cron istekleri (`set_time_limit(180)`, her aday için 12 saniyelik Pullback Kalkanı beklemesi + Binance/OpenAI-Groq-Gemini API çağrıları) sıklıkla DB'ye hiç dokunmadan onlarca saniye geçiriyor - bu süre paylaşımlı hostingin MySQL `wait_timeout` değerini aşınca sunucu bağlantıyı sessizce kapatıyor, bir sonraki sorgu bu hatayla patlayıp TÜM turu iptal ediyordu.
  - `Database::ensureConnected()` eklendi: ucuz bir `SELECT 1` ile bağlantının canlı olup olmadığını kontrol eder, kopmuşsa sessizce yeniden kurar. Uzun bekleme/dış API çağrısı İÇEREN döngülerin başında çağrılır - `AutoTradeController`'da Pullback Kalkanı beklemesinin hemen sonrasında + hem `huntForAllUsers()` hem `reconcileActiveTrades()`'in her-kullanıcı/her-pozisyon döngüsünün başında; `FuturesTradingService`'de aynı desenle `shortForAllUsers()`/`reconcileOpenPositions()`'da.
  - Gerçek yerel ortamda doğrulandı: gerçek bir MySQL bağlantısı kuruldu, sunucu tarafında `KILL <connection_id>` ile GERÇEKTEN koparıldı (mock değil), koptuğu doğrudan doğrulandı (aynı "2006 MySQL server has gone away" hatası üretildi), `ensureConnected()` çağrıldıktan sonra farklı bir `CONNECTION_ID` ile gerçekten yeniden bağlandığı ve sorguların sorunsuz çalıştığı kanıtlandı; sağlam bir bağlantıda gereksiz yere yeniden bağlanmadığı da ayrıca doğrulandı.

## [1.42.2] - 2026-07-22

## [1.42.2] - 2026-07-22

### Hata Düzeltme
- [AI Motoru] **Gemini modeli tekrar güncellendi: `gemini-2.5-flash` → `gemini-flash-lite-latest`.** Bir önceki düzeltme (1.42.1) yetersiz çıktı - `ListModels` uç noktasının listelediği `gemini-2.5-flash` bile bu hesap için "no longer available to new users" (HTTP 404) döndü; demek ki ListModels'in bir modeli listelemesi, o hesabın ona gerçekten erişimi olduğunu garanti etmiyor. Bu sefer 8 aday model kullanıcının gerçek anahtarıyla TEK TEK gerçek bir `generateContent` çağrısıyla test edildi - sadece `gemini-flash-latest` ve `gemini-flash-lite-latest` çalıştı, diğerleri ya 429 (kota) ya da 404 (yeni hesaba kapalı) verdi. Daha ucuz/hızlı katman olan `lite` tercih edildi.
- **Not:** `-latest` sabit bir versiyon değil, Google'ın otomatik kaydırdığı bir takma ad (alias) - ileride altındaki model değişebilir. Ama bu hesap için gerçekten erişilebilir olan seçenekler şu an bunlardan ibaret; sabit bir versiyon numarası (`-001` gibi) tercih edilebilir olsaydı o kullanılırdı.

## [1.42.1] - 2026-07-22

## [1.42.1] - 2026-07-22

### Hata Düzeltme
- [AI Motoru] **Gemini modeli güncellendi: `gemini-1.5-flash` → `gemini-2.5-flash`.** Canlıda gerçek anahtarla test edilirken `gemini-1.5-flash`'in `v1beta` API sürümünde `HTTP 404 NOT_FOUND` ile kaldırılmış olduğu görüldü. Kullanıcının gerçek Gemini anahtarıyla Google'ın kendi `ListModels` uç noktası sorgulanıp güncel, "preview" olmayan (kararlı) bir flash-katmanı model seçildi - tahminle değil, gerçek API yanıtıyla doğrulandı.
- Gerçek canlı testte doğrulandı: OpenAI ve Groq (gerçek anahtarlarla) ilk denemede zaten başarılı çıktı - Groq gerçek bir skor+gerekçe (`78|Pozitif trend ve güçlü alıcı baskısı`) döndürdü, zincirin ikinci halkası olarak doğru çalıştığı kanıtlandı.

## [1.42.0] - 2026-07-22

## [1.42.0] - 2026-07-22

### Yeni Özellik
- [Kritik/AI Motoru] **`SentimentService`'e yedekli sağlayıcı zinciri eklendi: OpenAI → Groq → Gemini.** 1.41.0'da eklenen önbellek, aynı sembolün tekrar tekrar sorulmasını önlüyordu ama TEK bir sağlayıcının (OpenAI) günlük kotası yine de tükenirse sistem yine tamamen nötr (50) skora düşüyordu - bu değişiklik OpenAI başarısız/kota dolu olduğunda otomatik olarak Groq'a, o da başarısız olursa Gemini'ye geçerek TEK sağlayıcıya bağımlılığı ortadan kaldırıyor.
  - Admin panelinden opsiyonel olarak `groq_api_key`/`gemini_api_key` girilebiliyor (aynı "DB önce, dosya sonra" Ayar Deseni - bkz. CLAUDE.md). İkisi de boş bırakılırsa zincir sadece OpenAI'dan ibaret kalır, **eski davranış birebir korunur** (regresyon testiyle doğrulandı).
  - Groq, OpenAI ile bilinçli olarak AYNI Chat Completions istek/yanıt formatını kullanıyor (`buildProviderRequest()`'te `'openai'` formatı) - kod tekrarı yok. Gemini'nin formatı farklı (`contents`/`parts` + ayrı `system_instruction`) - `'gemini'` formatı olarak ayrı işleniyor.
  - Toplu (`analyzeMany`) tarafta da zincir korunuyor: bir gruptaki semboller ÖNCE OpenAI'ye es zamanlı gönderilir, O SAĞLAYICIDA başarısız olanlar (sadece onlar) grup halinde Groq'a, kalanlar Gemini'ye devredilir - tek tek değil, hep grup halinde.
  - Kapsam bilinçli olarak daraltıldı: sadece asıl alım kararını etkileyen puanlama yolu (`fetchSentimentScore`/`fetchBatchFromProvider`) zinciri kullanıyor; `explainLoss()`/`translateToTurkish()`/`generateMarketPulse()` (Trade Post-Mortem, haber çevirisi, piyasa nabzı) hâlâ sadece OpenAI kullanıyor - bunlar alım kararını doğrudan etkilemiyor, kapsam gereksiz büyümesin diye şimdilik dokunulmadı.
  - Gerçek testlerle doğrulandı: (1) boş anahtarlarla zincirin eskisi gibi sadece OpenAI'dan ibaret kaldığı (regresyon), (2) üçü de doluyken doğru sırada/formatta olduğu, (3) `buildProviderRequest()`'in her iki format için doğru gövde ürettiği, (4) `extractMessageContent()`'in her iki formatı doğru ayrıştırdığı (sentetik veriyle), (5) Groq ve Gemini'nin GERÇEK API uçlarına sahte anahtarla istek atılıp `401`/`400 "invalid API key"` yanıtlarının doğru yakalandığı (URL'lerin gerçekten doğru/ulaşılabilir olduğunun kanıtı, DNS/404 hatası değil), (6) gerçek OpenAI anahtarıyla uçtan uca `analyze()` çağrısının başarılı skor+gerekçe döndürdüğü (zincirin gereksiz yere ilerlemediği).
  - **Not:** Groq/Gemini anahtarları henüz eklenmedi (kullanıcı tarafında) - eklenene kadar sistem 1.41.0'daki gibi sadece OpenAI+önbellek ile çalışmaya devam ediyor, davranış değişmedi.

## [1.41.0] - 2026-07-22

## [1.41.0] - 2026-07-22

### Hata Düzeltme (Kritik)
- [Dashboard] **AI Radar'da `reason` alanı boş olan adaylar için "AI gerekçe eklemedi" yer tutucu metni eklendi** - satırların eşit yükseklikte kalması için görsel tutarlılık düzeltmesi.
- [Kritik/AI Motoru] **"Sistem neden alım yapmıyor?" RCA'sı: gerçek kök neden bulundu - `SentimentService`'in günlük OpenAI kotası (10.000 istek/gün) tükenmişti, TÜM sonraki puanlama çağrıları HTTP 429 alıp sessizce nötr (50) skora düşüyordu.** Nötr 50, hiçbir kullanıcı risk profilinin eşiğini (Güvenli 70/Dengeli 60/Agresif 50) geçemediği için sistem "hiç almıyormuş" gibi görünüyordu - aslında büyük ölçüde AI'ya hiç sorulamıyordu.
  - **Asıl israf kaynağı bulundu**: `analyze()`/`analyzeMany()` 4 BAĞIMSIZ cağıran taraf (`AutoTradeController`'ın ana taraması + Dinamik Kaçış pozisyon izleme + DCA kontrolü, `DashboardController`'ın AI Radar'ı, `FuturesTradingService`'in kendi taraması) tarafından HİÇBİR ortak önbellek olmadan kullanılıyordu - aynı sembol birkaç dakika içinde 3-4 kez bağımsız OpenAI çağrısıyla yeniden puanlanıyordu.
  - `SentimentService`'e paylaşılan, sembol başına 5 dakikalık bir önbellek eklendi (`SYMBOL_SCORE_CACHE_KEY`, tek bir JSON blob olarak `app_settings` üzerinde tutuluyor) - hangi çağıran taraf önce sorarsa OpenAI'ye o gider, aynı 5 dakikalık pencerede diğerleri onun sonucunu yeniden kullanır. Başarısız (nötr fallback) sonuçlar KASITLI olarak önbelleklenmez - aksi halde geçici bir hata/kota aşımı, önbellek süresi boyunca "gerçek skor" gibi diğer tüm çağıran taraflara da yayılırdı.
  - Eski (4 katı süre geçmiş) kayıtlar her kayıtta otomatik budanır - JSON blob sınırsız büyümez.
  - **Bulma süreci gerçek canlı veriyle yapıldı** (teorik değil): cPanel üzerinde canlı `app_settings`/`active_trades`/`user_api_keys` sorgulandı (devre kesici yok, açık pozisyon yok, bütçe sorunu yok - kullanıcının ilk 2 hipotezi elendi), gerçek AI Radar önbelleği dökülüp taranan 24 sembolden sadece 5'inin gerçek skor aldığı (kalan 19'u nötr 50) görüldü, gerçek `error_log`'da tam OpenAI hata gövdesi bulunup `"Rate limit reached for gpt-4o-mini... on requests per day (RPD): Limit 10000, Used 10000"` mesajıyla kesinleştirildi.
  - Gerçek yerel DB'ye karşı 5 senaryo test edildi: taze önbellek kullanımı, süresi geçmiş önbelleğin görmezden gelinmesi, `analyzeMany()`'de kısmi önbellek isabeti, önbelleğin doğru BİRLEŞTİRİLEREK (merge) kaydedilmesi (ilk implementasyondaki bir hata - döngü içinde tekrar tekrar kaydetmek PHP dizilerinin referans değil kopya geçmesi yüzünden sadece SON sembolü saklayıp öncekileri siliyordu, düzeltildi), eski kayıtların budanması.
  - **Not**: Günlük 10.000 istek kotasının kendisi OpenAI hesabının plan/faturalandırma sınırıdır, kod tarafından değiştirilemez - önbellek aynı sembole yapılan TEKRARLANAN istekleri önler ama toplam benzersiz sembol × tarama sıklığı yine de günlük kotayı aşarsa (özellikle çok sayıda kullanıcı/çok sayıda farklı sembol taranıyorsa) OpenAI hesabının kullanım kademesinin (tier) yükseltilmesi gerekebilir.

## [1.40.0] - 2026-07-22

## [1.40.0] - 2026-07-22

### Yeni Özellik / Mimari Değişiklik
- [Dashboard] **TradingView embed widget'larına (Ana Grafik, Teknik Analiz Özeti, Kayan Fiyat Bandı) bağımlılık tamamen kaldırıldı** - günler süren bir denetimde (config sadeleştirme, çerez temizleme, farklı cihaz/tarayıcı, canlı sunucuda `json_decode` ile doğrulama, header/CDN kontrolü) "ChartApi.Core: Protocol error. Reason=bad auth token" hatasının kod/tarayıcı tarafında hiçbir sebebi bulunamadı - TradingView.com doğrudan çalışırken SADECE bu domain'e gömülü (embedded) haldeyken başarısız oluyordu, muhtemelen paylaşımlı hosting IP'sinin TradingView tarafında bir kota/kısıtlamaya takılması. Başkasının altyapısına/auth politikasına bağımlı kalmamak için üçü de kendi kontrolümüzdeki bir sisteme taşındı.
  - **Yeni bağımsız grafik motoru**: `assets/js/lightweight-charts.min.js` - TradingView, Inc.'in ayrıca yayınladığı, Apache 2.0 lisanslı, kendi barındırdığımız (self-hosted) açık kaynak render kütüphanesi. Embed widget'lardaki auth/oturum mekanizmasıyla hiçbir ilgisi yok - sadece bize verilen veriyi çizer, TradingView'ın sunucularına hiçbir ağ isteği atmaz.
  - **Veri kaynağı**: Doğrudan Binance'in herkese açık (anahtarsız, auth gerektirmeyen) REST (`/api/v3/klines`, `/api/v3/ticker/24hr`) ve WebSocket (`wss://stream.binance.com:443/ws/{symbol}@kline_1h`) API'leri - üçüncü taraf oturum/auth katmanı olmadığı için aynı sınıf hata bir daha yaşanamaz.
  - **Ana Grafik**: `lwChart`/`loadChart()` - ilk yüklemede son 300 saatlik mumu REST'ten çeker, sonrasında WebSocket kline akışıyla canlı günceller. Sembol seçici/coin tıklama noktaları (`loadChart('BINANCE:XXXUSDT')` çağrı kalıbı) HİÇ değiştirilmeden geriye dönük uyumlu bırakıldı.
  - **Teknik Analiz Özeti**: TradingView'ın "Osilatörler + Hareketli Ortalamalar" oy birliği mantığının kendi kontrolümüzdeki karşılığı - aynı Binance mum verisinden istemci tarafında RSI(14)/SMA(20)/SMA(50)/MACD(12,26,9) hesaplanıp AL/SAT oyları toplanır, GÜÇLÜ AL/AL/NÖTR/SAT/GÜÇLÜ SAT ibareli bir gösterge (gauge) olarak çizilir.
  - **Kayan Fiyat Bandı**: Binance'in 24hr ticker uç noktasından 15 saniyede bir yenilenen, CSS `@keyframes` ile dikişsiz kayan özel bir şerit (`customTickerTrack`).
  - Tasarım tutarlılığı: yeni bileşenlerin hepsi mevcut sayfanın renk paletini (violet vurgu, emerald/rose yükseliş-düşüş), `font-mono-tech` tipografisini ve `glass-panel` kart stilini birebir kullanıyor.
  - Gerçek testlerle doğrulandı: RSI/SMA/EMA/MACD fonksiyonları bilinen referans değerlerine karşı (`node` ile) birim test edildi; Binance'in gerçek REST uç noktaları (`klines`, `ticker/24hr`) canlı olarak çağrılıp yanıt şekli kodun beklentisiyle birebir eşleştiği doğrulandı; yerel ortamda gerçek bir kullanıcı oturumu açılıp (Playwright ile) dashboard'a girildi, grafiğin gerçek mum verisiyle render olduğu (7 canvas katmanı), Teknik Analiz gauge'unun gerçek RSI/MACD/SMA değerleriyle bir AL-SAT verdisi ürettiği ve kayan bandın gerçek BTC/ETH/BNB/SOL/XRP fiyatlarını gösterdiği ekran görüntüsüyle teyit edildi.
  - **Bilinen sınırlama**: WebSocket canlı mum güncellemesi bu geliştirme ortamının (sandbox) ağ politikası nedeniyle yerel testte doğrulanamadı (`net::ERR_SSL_PROTOCOL_ERROR` - hem Node'un kendi WebSocket istemcisinde hem Playwright'ta aynı hata alındı, muhtemelen bu ortama özgü bir kısıtlama). Kod, Binance'in yıllardır değişmeyen, herkese açık kline stream şemasını (`msg.k.t/o/h/l/c`) kullanıyor - ilk yükleme (REST) tarafı gerçek veriyle tam doğrulandı, sadece canlı güncelleme kısmı gerçek bir tarayıcı/ağ ortamında ayrıca teyit edilmeli.

### Kaldırılanlar
- 3 adet TradingView `<script src="https://s3.tradingview.com/...">` embed etiketi ve bunlara bağlı `loadTechnicalWidget()` fonksiyonu (işlevi `loadChart()` içine taşındı, artık ayrı bir Binance çağrısı gerektirmiyor - aynı mum verisi hem grafik hem teknik analiz için tek seferde kullanılıyor).

## [1.39.1] - 2026-07-22

### Deneysel Değişiklik
- [Dashboard] **TradingView widget'larının (kayan bant, ana grafik, teknik analiz) JSON konfigürasyonları en sade hâline indirgendi** - canlıda gözlemlenen `ChartApi.Core: Protocol error. Reason=bad auth token` hatasına karşı ucuz/geri alınabilir bir test olarak yapıldı. `hide_top_toolbar`/`hide_legend`/`save_image`/`backgroundColor`/`gridColor`/`support_host`/`style`/`enable_publishing`/`isTransparent`/`showIntervalTabs`/`showSymbolLogo`/`displayMode` gibi kozmetik parametreler çıkarıldı, sadece `symbol`/`interval`/`theme`/`locale`/boyut alanları bırakıldı - hem sayfa ilk yüklendiğindeki statik `<script>` etiketlerinde hem `loadChart()`/`loadTechnicalWidget()`'ın dinamik yeniden yükleme fonksiyonlarında tutarlı şekilde.
- **Not (dürüstlük payı):** Bu hatanın kök nedeninin bu konfigürasyon parametreleri olduğuna dair teknik bir kanıt yok - kaldırılan parametrelerin hiçbiri TradingView'ın herkese açık/ücretsiz widget dokümantasyonunda "Pro hesap gerektirir" diye işaretli değil, auth token da zaten bu JSON'un içinde hiç taşınmıyor (TradingView'ın kendi script'i tarayıcı çerezi/session üzerinden yönetiyor). Bu değişiklik ucuz ve zararsız olduğu için deneme amaçlı yapıldı; asıl şüpheli hâlâ TradingView'ın kendi auth/rate-limit mekanizması ya da istemci tarafında (sistem saati, tarayıcı çerez/site verisi) bir şey.
- Gerçek yerel ortamda doğrulandı: `php -l` temiz, tüm 3 statik config geçerli JSON, ana inline JS bloğu (1120+ satır) `node --check` ile syntax hatasız.

## [1.39.0] - 2026-07-22

### Yeni Özellik / Güvenlik
- [Kritik] **BANKUSDT/ERAUSDT olayının ardından tüm kod tabanında acil "sessiz hata" (silent fail) denetimi yapıldı - 5 otonom modülde (AI Avcı, Futures, Duyuru Avcısı, Akıllı Para, Webhook) toplam 20+ noktada AYNI sistemik desen bulundu: Binance'te GERİ DÖNÜŞÜ OLMAYAN bir işlem (BUY/SELL/OCO/Zarar Kes değişimi) başarıyla gerçekleştikten SONRA çalışan DB yazma adımları korumasızdı, biri patlarsa döngünün genel catch'i sadece dosyaya tek satır log yazıp sessizce geçiyordu.
- Her etkilenen sınıfa (`AutoTradeController`, `FuturesTradingService`, `ListingSniperService`, `SmartMoneyTracker`, `WebhookController`) kendi `criticalPersist()` yardımcı metodu eklendi - bu noktalardan biri patlarsa artık OCO-başarısız senaryosuyla AYNI ciddiyette anında Telegram uyarısı (müşteri + admin) gidiyor, istisna da yutulmadan yeniden fırlatılıyor (mevcut akış/dış catch değişmiyor, SADECE garanti edilmiş bir alarm katmanı ekleniyor).
- `Order` modeline `existsByBinanceOrderId()` idempotenslik koruması eklendi - bir kapanış kaydının (Order::create başarılı + hemen sonraki markClosed hatası) retry'da İKİ KEZ yazılıp PNL istatistiklerini bozmasını önler; `AutoTradeController::finalizeSpotClose()` ve `FuturesTradingService::finalizeClosedTrade()` artık INSERT'ten önce kontrol ediyor.
- `FuturesTradingService`'e de (spot'taki `AutoTradeController` ile AYNI RCA'ya dayanan) `MAX_SAFE_ELAPSED_SECONDS_BEFORE_OPEN` (150sn) zaman payı koruması eklendi - kaldıraçlı SHORT açma çağrısından hemen önce, `set_time_limit(180)` sınırına yaklaşıldıysa yeni pozisyon hiç açılmıyor.
- **Mutabakat (reconciliation) denetimi**: `active_trades` tablosu AI Avcı/Duyuru Avcısı/Akıllı Para arasında paylaşıldığı için `AutoTradeController::reconcileActiveTrades()` üçünün de DB'ye YAZILMIŞ pozisyonlarını kapsıyor - asıl kalan kör nokta (DB'ye hiç yazılamamış bir Binance pozisyonunu Binance bakiyesinden keşfeden bir "yetim pozisyon" tarayıcısı yok) bilinçli olarak bu sürümün kapsamı dışında bırakıldı, ayrı bir mimari karar gerektirir.
- **Şema/veri tipi denetimi**: `database.sql` ile canlı/yerel MySQL şeması arasında gerçek bir sürüklenme bulunamadı (`orders.type` ENUM'undaki `stop_loss` değeri dahil, tüm migrasyonlar tutarlı) - başlangıçtaki "eksik kolon" teorisi yanlış çıkmıştı, gerçek kök neden ayrı olarak 1.38.4'te düzeltilen PHP zaman aşımı riskiydi.
- Gerçek yerel DB'ye karşı doğrulandı: her `criticalPersist()` gerçek bir FK ihlaliyle (var olmayan user_id) tetiklenip istisnanın doğru yakalanıp yeniden fırlatıldığı ve log dosyasına yazıldığı teyit edildi; `Order::existsByBinanceOrderId()` gerçek bir satır oluşturulup/silinerek doğrulandı; her iki zaman payı sabiti (150sn) `ReflectionClass` ile teyit edildi.
- **Bilinçli olarak ertelenen düşük öncelikli bulgular**: Duyuru Avcısı/Akıllı Para'da spot'taki gibi ayrı bir zaman aşımı payı yok (kendi `set_time_limit` pencereleri çok daha kısa - 25-40sn - risk oranı düşük); `SmartMoneyTracker` diğer modüllerin aksine kendi `storage/logs/*.log` dosyası yerine PHP'nin genel `error_log()`'unu kullanıyor (tutarlılık notu, davranış değişikliği değil).

## [1.38.4] - 2026-07-22

### Düzeltme
- [Kritik/AI Avcı] **BANKUSDT/ERAUSDT olayı: gerçek kök neden bulundu - şema sürüklenmesi DEĞİLDİ (canlı MySQL'de kontrol edildi, kolonlar zaten mevcuttu), PHP'nin YAKALANAMAYAN zaman aşımı fatal hatasıydı.**
  - `binance_errors.log`'da 07-17/07-18/07-21'de tekrarlayan `Connection timed out after 10002 milliseconds` kayıtları + `auto_trade.log`'da AI Avcı'nın tek bir taramada (scanTopMovers + her aday için Binance macro-trend + OpenAI + 12sn Pullback bekleme + BUY/OCO) `set_time_limit(180)` sınırına ne kadar yaklaşabildiği bir araya getirilince: paylaşımlı sunucuda Binance'e erişim yavaşladığında bu 180sn'lik sınıra TAM OLARAK bir Binance BUY başarılı olduktan hemen SONRA, DB kaydı/OCO/bildirimden ÖNCE çarpma riski var. Bu tür bir "PHP zaman aşımı fatal"i `catch (Throwable)` YAKALAYAMAZ - script anında ölür, hiçbir log satırı yazılmaz, hiçbir bildirim gitmez. Bu, olayın "hiçbir yerde iz yok" özelliğini tam açıklıyor.
  - `AutoTradeController`'a yeni `MAX_SAFE_ELAPSED_SECONDS_BEFORE_BUY` (150sn = 180sn - 30sn güvenlik payı) sınırı eklendi: geri dönüşü olmayan Binance BUY çağrısından hemen önce `run()` başlangıcından (`requestStartedAt`) bu ana kadar geçen süre kontrol ediliyor, payın dışına çıkıldıysa YENİ bir alıma hiç girilmiyor (mevcut açık pozisyonlar etkilenmez, aday bir sonraki cron turunda tekrar denenir).
  - 1.38.3'te eklenen post-buy try/catch + `notifyAdminAndCustomer()` uyarısı (yakalanabilir hatalar için) olduğu gibi korunuyor - iki savunma birlikte çalışıyor, ama asıl kritik olan yeni zaman payı kontrolüdür.
  - **Bilinçli olarak DOKUNULMADI**: `BinanceService::CONNECT_TIMEOUT_SECONDS`/`TIMEOUT_SECONDS` (10sn/15sn) - CLAUDE.md'deki 3sn/5sn kuralından SAPMIŞ görünüyor ama bu KASITLI: 1.19.1/1.30.x'te paylaşımlı hosting + Binance gecikmesi yüzünden bilinçli olarak yükseltilmişti (CHANGELOG'da belgeli). Bunu geri almak zaman aşımı riskini AZALTMAZ, sadece DAHA FAZLA gerçek Binance çağrısının erken/gereksiz başarısız olmasına yol açardı - CLAUDE.md'nin 3sn/5sn kuralı bu servis için ARTIK GÜNCEL DEĞİL, ayrı olarak not düşülmeli.
  - Gerçek yerel ortamda `ReflectionClass` ile `MAX_SAFE_ELAPSED_SECONDS_BEFORE_BUY` (150) ve `requestStartedAt` property'sinin doğru tanımlandığı doğrulandı; `php -l` ile sözdizimi kontrolü yapıldı.
  - **Henüz yapılmadı (kapsam dışı bırakıldı, kullanıcıya soruldu):** Binance'teki gerçek açık pozisyon/bakiyeyi DB'deki `active_trades` ile çapraz karşılaştıran bir "yetim pozisyon" mutabakat mekanizması yok - `reconcileActiveTrades()` sadece DB'de ZATEN var olan satırları kontrol ediyor, DB'ye hiç yazılamamış bir Binance pozisyonunu keşfedemez.

## [1.38.3] - 2026-07-22

### Düzeltme
- [Kritik/AI Avcı] **Binance'te alım gerçekleşip DB'ye hiç yazılmayan "hayalet pozisyon" senaryosu için acil uyarı eklendi** (bu senaryonun asıl kök nedeni 1.38.4'te düzeltildi - burada eklenen sadece YAKALANABİLİR hatalar için bir güvenlik ağı).
  - `AutoTradeController::processHuntingForUser()`'da Binance BUY başarılı olduktan SONRAKİ adım (`Order::create` + `protectPositionWithOco`) artık ayrı bir try/catch'e alındı - önceden bu adımlardan biri patlarsa döngünün genel catch'i sadece `auto_trade.log`'a tek satır yazıp sessizce bir sonraki aday coine geçiyordu, kimse fark etmiyordu.
  - Artık bu spesifik senaryoda (Binance'te para hareket etti ama sistem kaydı başarısız oldu) OCO-girilemedi durumuyla AYNI ciddiyette `notifyAdminAndCustomer()` tetikleniyor - coin, Binance order ID, miktar ve fiyat ile birlikte hem müşteriye hem admine anında Telegram uyarısı gidiyor.

## [1.38.2] - 2026-07-22

### Düzeltme
- [Mobil] **Risk profili rozeti ("⚖️ DENGELİ") mobilde geri getirildi** - bir önceki sürümde (1.38.1) üst çubuk taşmasını çözmek için bu metin mobilde tamamen gizlenmişti; bu YANLIŞ bir düzeltmeydi, kodun kendi eski yorumunda bile "mobilde de görünür, tek bakışta hangi modda çalıştığı bilgisi alınır" diye bilinçli bir özellik olarak belgelenmişti.
  - Doğru düzeltme: metni gizlemek yerine, sığmadığında ADMIN/AYARLAR/ÇIKIŞ butonlarının ALTINA kendi satırına kayması sağlandı (`flex-wrap` + `justify-end`).
  - Bunu yaparken iç içe flex-wrap'ta bilinen bir CSS tuzağına takıldım: sarmalayan (wrap) bir flex kutusu, kendi genişliğini varsayılan olarak "hiç sarmasaydı ne kadar olurdu" (max-content) şekilde hesaplar - bu da satıra kaydıktan SONRA bile taşmaya devam etmesine yol açtı. `max-w-full` eklenerek kutunun gerçekten mevcut genişliğe sığması, böylece içindeki elemanların doğru şekilde sarması sağlandı.
  - Ayırıcı çizgi (dikey `border-l`) artık sadece `sm+` ekranda gösteriliyor - mobilde kendi satırına kayınca bir önceki elemandan ayıran çizginin görsel anlamı kalmıyordu.
  - CSS yeniden derlendi (`npm run build:css`) - `flex-wrap`, `justify-end`, `gap-y-1.5`, `max-w-full` gibi sınıflar derlenmiş çıktıda yoktu.
  - Gerçek yerel ortamda iPhone SE (320px)/13 (390px)/13 Pro Max (428px) genişliklerinde hem ana sayfa hem Ayarlar modalında taşma OLMADIĞI ve rozetin gerçekten göründüğü ekran görüntüleriyle doğrulandı.

## [1.38.1] - 2026-07-22

### Düzeltme
- [Mobil] **İki gerçek mobil CSS hatası bulundu ve düzeltildi** - Playwright ile iPhone SE/13/13 Pro Max ekran genişliklerinde uçtan uca test edilerek tespit edildi (sadece kod okuyarak değil, gerçek render ile).
  - **Üst navigasyon taşması**: sağ üstteki risk profili rozeti (ör. "⚖️ DENGELİ") `whitespace-nowrap` yüzünden hiç kırılmıyordu, dar ekranlarda gövdeyi (`body`) viewport'tan ~12px taşırıyordu. Diğer alanların (e-posta gibi) zaten kullandığı desen tekrarlandı - rozet metni `hidden sm:inline` ile mobilde gizlendi, sadece durum noktası (yeşil nokta) kalıyor.
  - **"AÇIK POZİSYON"/"PORTFÖY" metin çakışması**: üst çubuktaki 6 istatistik kutusunun tümünde `whitespace-nowrap` mobildeki 3 sütunlu grid'te de aktifti - uzun etiketler (`AÇIK POZİSYON`) kendi sütun genişliğini aşıp bir sonraki sütunun üzerine biniyordu. `whitespace-nowrap` → `md:whitespace-nowrap` yapıldı (nowrap artık SADECE masaüstü tek-satır düzeninde geçerli, mobilde etiket 2 satıra sarabiliyor - zaten dikey istiflenmiş tasarıma uygun).
  - **CSS yeniden derlendi** (`npm run build:css`) - `md:whitespace-nowrap` derlenmiş çıktıda yoktu, bu ikisi arasında tek fark budur.
  - **"Veriler geç geliyor" hakkında not (kod değişikliği içermez):** Sayfa 9 ayrı `/api/dashboard/*` uç noktasını (bakiye, portföy, radar, pozisyonlar vb.) sayfa yüklendikten SONRA çekiyor - bu CLAUDE.md'de belgelenen bilinçli bir performans tercihi (sayfa anında açılır, veri sonra akar). Yerel testte her istek 300-1300ms sürdü (gerçek Binance API çağrıları) - gerçek bir mobil/hücresel bağlantıda bu gecikme WiFi'dan daha belirgin hissedilir, bu muhtemelen bir hata değil bu deseninin cellular ağda daha görünür olması. Kod tarafında bariz/güvenli bir "hızlandırma" değişikliği yapmadım - istenirse ayrı olarak ele alınabilir (ör. birleştirilmiş tek uç nokta, ama bu daha büyük bir mimari değişiklik).
  - Gerçek yerel ortamda üç ekran genişliğinde (320px/390px/428px) hem ana sayfa hem Ayarlar modalında `body.scrollWidth === window.innerWidth` doğrulandı, ekran görüntüleriyle teyit edildi.

## [1.38.0] - 2026-07-22

### Yeni Özellik
- [Arayüz] **Kar Al Tavanı Kaldırma rozeti artık sayfa yenilenmeden CANLI güncelleniyor**: bir önceki sürümdeki "sadece sayfa yüklenirken render edilir" sınırlaması kaldırıldı.
  - `DashboardController::apiHunts()` JSON yanıtına `take_profit_removed` alanı eklendi - `ActiveTrade::findOpenForUser()` zaten `SELECT *` yaptığı için ek sorgu gerekmedi.
  - `dashboard/index.php`: TP hücresi artık `data-trade-tp`/`data-tp-removed` ile sarmalandı; yeni `updateTakeProfitBadge()` JS fonksiyonu, mevcut `updateTradeProgress()` döngüsüne (Zarar Kes fiyatını canlı güncelleyen AYNI polling akışı) eklendi - geçiş tek yönlü (0→1, backend asla geri almaz) olduğu için `data-tp-removed` ile "zaten güncellendi mi" kontrolü yapılıp gereksiz DOM yazımından kaçınılıyor.
  - Gerçek yerel ortamda Playwright ile uçtan uca doğrulandı: (1) sunucu ilk render'da normal TP fiyatını gösterdi, (2) `/api/dashboard/hunts` JSON'ında `take_profit_removed` alanının gerçekten döndüğü teyit edildi, (3) sayfa **hiç yenilenmeden**, gerçek `updateTradeProgress()` fonksiyonu `take_profit_removed:true` içeren veriyle çağrılınca hücre canlı olarak "🚀 Sınırsız (∞)" rozetine dönüştü - ekran görüntüsüyle doğrulandı.
  - CSS değişikliği yok, yeniden derlemeye gerek yok.

## [1.37.0] - 2026-07-22

### Yeni Özellik
- [Arayüz] **Kar Al Tavanı Kaldırma, Dashboard'a yansıtıldı**: "Aktif Avlar" kartında bir pozisyonun `take_profit_removed` alanı 1 ise artık sabit TP fiyatı yerine mor "🚀 Sınırsız (∞)" rozeti gösteriliyor (üzerine gelince "Sabit tavan kaldırıldı, trend izleniyor" tooltip'i çıkıyor); 0 ise eskisi gibi normal TP fiyatı yazmaya devam ediyor.
  - Ayarlar modalındaki "İzleyen Stop (Trailing Stop)" kutusuna, bu stratejiyi açıklayan mor bir bilgi kutusu eklendi ("Dinamik Trend Avcısı Aktif...").
  - **Not (talep dışı ama önemli):** proje Bootstrap değil Tailwind CSS kullanıyor (`npm run build:css` ile derleniyor) - istek Bootstrap 5 diye gelmişti, sayfanın zaten kullandığı Tailwind diliyle (aynı mor/rozet deseni) tutarlı şekilde uygulandı.
  - Gerçek yerel ortamda Playwright ile giriş yapılıp iki senaryo (normal TP / Kar Al kaldırılmış) gerçek veriyle görsel olarak doğrulandı - rozet, tooltip ve bilgi kutusu ekran görüntüsüyle teyit edildi.
  - Kullanılan tüm Tailwind sınıfları derlenmiş `assets/css/tailwind.css`'te zaten mevcut (aynı dosyadaki mevcut rozet/uyarı desenleri yeniden kullanıldı) - **CSS yeniden derlemeye gerek yok**, doğrulandı.
  - **Bilinen sınırlama:** rozet sadece sayfa yüklenirken/yenilenirken sunucu tarafında render ediliyor - bir pozisyon sayfa açıkken canlı olarak Sınırsız İzleme'ye geçerse (SL fiyatı gibi) anlık güncellenmiyor, sayfa yenilenince görünür. İsterseniz `/api/dashboard/hunts` uç noktasına da eklenip canlı güncellenebilir.

## [1.36.0] - 2026-07-22

### Yeni Özellik
- [Risk Azaltan/Kâr Artıran] **Sabit Kar Al tavanı, Sınırsız İzleme'de kaldırıldı**: kazanan işlemlerin dağılım analizi, Sınırsız İzleme aşamasına ulaşan pozisyonların bile hep aynı sabit Kar Al fiyatında (~%5) tavan yaptığını ortaya çıkardı - `replaceOcoWithNewStop()` Zarar Kes'i yükseltirken Kar Al'a hiç dokunmuyordu, OCO'nun TP bacağı trend devam etse bile önce tetikleniyordu.
  - `active_trades`'e `take_profit_removed` (TINYINT, varsayılan 0) kolonu eklendi. `orders.type` ENUM'una `stop_loss` eklendi (artık OCO'suz tekil Zarar Kes emirleri de kayıt altına alınabiliyor).
  - `BinanceService`'e `placeStopLossOrder()` (tekil, OCO'suz Zarar Kes emri - `placeOCOOrder()`'daki AYNI "limit değil piyasa emri" tercihiyle) ve `cancelOrder()` (tekil emir iptali) eklendi.
  - `ActiveTrade::applyTakeProfitRemoval()` (yeni): `oco_order_list_id`/`take_profit_order_id`'i kalıcı olarak NULL'a çeker, `stop_loss_order_id` artık tekil emri işaret eder, `take_profit_removed=1` ve `trailing_stop_stage=2` işaretlenir.
  - `applyContinuousTrailing()` (Sınırsız İzleme): Kar Al hâlâ devredeyken (`take_profit_removed=0`) İLK anlamlı Zarar Kes güncellemesinde artık `removeTakeProfitCeiling()` çağrılıyor - mevcut OCO tamamen iptal edilip YERİNE SADECE tek yönlü bir Zarar Kes emri konuyor. Zaten Kar Al'sız modda olan pozisyonlarda her turda `replaceStopOnlyOrder()` ile devam ediliyor - **artık "Zarar Kes Kar Al'ı geçemez" güvenlik sınırı YOK**, trend ne kadar sürerse Zarar Kes de o kadar yükseliyor.
  - `trailing_distance_percent` bilinçli olarak DEĞİŞTİRİLMEDİ (kullanıcı talebi) - önce tavansız ortamda sistemin ne kadar kâr sürebildiği gözlemlenecek.
  - `reconcileActiveTrades()`: `oco_order_list_id === null` artık tek başına "korumasız, atla" anlamına gelmiyor - `take_profit_removed=1` iken bu normal bir durum. Yeni `reconcileTakeProfitRemovedTrade()` yolu, OCO grup durumu yerine tekil Zarar Kes emrinin durumuna bakıyor. Kademeli Kar Alma/DCA bu modda geçerli değil (DCA sadece zarar bölgesinde tetiklenir, bu pozisyon zaten kârda - ikisi mimari olarak asla aynı anda geçerli olamaz).
  - Kapanış mantığı (`Order::create` + gerçek PNL'e göre status + soğuma + Post-Mortem + bildirim) `finalizeSpotClose()` adında tek bir ortak metoda çıkarıldı - hem klasik OCO kapanışı hem yeni SL-only kapanışı aynı, tek doğrulanmış yoldan geçiyor.
  - `attemptEarlyExitOnAiCollapse()` (Dinamik Kaçış) de güncellendi - Kar Al kaldırılmış pozisyonlarda artık OCO değil tekil Zarar Kes emrini iptal ediyor.
  - Fiyatlar Binance'in PRICE_FILTER tick size'ına yuvarlanmadan gönderilirse emir reddedilir - `removeTakeProfitCeiling()`/`replaceStopOnlyOrder()` bunu `replaceOcoWithNewStop()` ile AYNI şekilde uyguluyor.
  - Gerçek yerel DB testleriyle doğrulandı: `applyTakeProfitRemoval()`'ın tüm alanları (oco/tp NULL, sl_order/sl_price/highest/stage/flag) doğru yazdığı, sonraki bir "izleme" güncellemesinin de doğru çalıştığı, `orders.type='stop_loss'`'in yeni ENUM'a kabul edildiği doğrulandı.

## [1.35.0] - 2026-07-22

### Yeni Özellik
- [Veri Kalitesi] **Binance işlem komisyonu artık kaydediliyor**: bugüne kadar hiçbir PNL hesabı komisyonu düşmüyordu - gösterilen "kâr" rakamları aslında BRÜT'tü.
  - `orders` tablosuna `commission`/`commission_asset` (NULL, `ai_entry_score` ile aynı desen - geriye dönük doldurma yok, sadece bundan sonraki emirlerde dolu) kolonları eklendi.
  - `BinanceService::extractFillCommission()` (yeni, saf/test edilebilir fonksiyon): MARKET/LIMIT emirlerin POST yanıtındaki `fills[]`'ten EK API çağrısı olmadan komisyonu çıkarır - giriş (BUY), Erken Kaçış satışı, Kademeli Kâr Alma satışı ve DCA alımı bu yolu kullanıyor. `placeOrder()` artık `newOrderRespType=FULL` gönderiyor (fills[]'in garanti dönmesi için).
  - `BinanceService::getCommissionForOrder()`/`sumTradeCommissions()` (yeni): OCO bacak kapanışlarında (`getOrderStatus()` komisyon döndürmediği için) `myTrades`'ten SONRADAN öğrenir - `reconcileActiveTrades()`'teki ana kapanış yoluna eklendi.
  - Toplam 8 gerçek (FILLED) `Order::create()` noktası güncellendi (`AutoTradeController` içinde 5, `SmartMoneyTracker`/`ListingSniperService`/`WebhookController`'da birer tane) - FAILED kayıtlara bilinçli olarak dokunulmadı (gerçekleşmeyen emrin komisyonu olmaz).
  - **Bilinçli mimari karar**: `active_trades`/`active_futures_trades`'e AYRI bir "toplam komisyon" kolonu EKLENMEDİ - `stop_loss_price`'ın İzleyen Stop tarafından ezilmesiyle yaşanan "ikinci kaynak" riskini tekrar yaratırdı. Bir pozisyonun toplam komisyonu, ona bağlı TÜM `orders` satırlarının (ilk alış + DCA + kısmi/nihai satış) toplanmasıyla hesaplanacak - `orders` tek doğru kaynak olarak kalıyor.
  - Gerçek yerel DB testleriyle doğrulandı: `extractFillCommission()`/`sumTradeCommissions()` sentetik Binance yanıtlarıyla (tek fill, çoklu fill toplama, eşleşmeyen orderId) test edildi; `Order::create()`'in commission alanlarını doğru yazdığı ve verilmediğinde NULL kaldığı doğrulandı.
  - **Henüz yapılmadı (bilinçli):** `Order::calculatePnlSummary()`'ye komisyon düşme mantığı eklenmedi - geçmiş verilerde komisyon yok, şimdi eklemek yanıltıcı "Net Kâr = Brüt Kâr - 0" gösterirdi. Yeterli yeni veri birikince (aynı `ai_entry_score` bekleme deseni) eklenmeli.

## [1.34.0] - 2026-07-22

### Düzeltme
- [İstatistik] **Dashboard win_rate/PNL özetinden 3 erken dönem (6-7 Temmuz) uç-değer işlem kalıcı olarak dışlandı**: bu 3 işlem (ortalama -%10, diğer 21 gerçek zararın ~6 katı) platformun `strategy_bucket` takibi ve İzleyen Stop/Zarar Kes mimarisi olgunlaşmadan önceki ilk günlerine ait - tek başına platform ortalamasını -%1.68'den -%2.73'e çekiyordu.
  - `Order::calculatePnlSummary()`'ye `b.created_at >= STATS_CUTOFF_AT` ('2026-07-08 00:00:00') filtresi eklendi.
  - **Bilinçli olarak `strategy_bucket IS NOT NULL` filtresi KULLANILMADI**: `WebhookController` kaynaklı işlemler `strategy_bucket`'ı hiçbir zaman set etmiyor - NULL kontrolü, gelecekteki geçerli webhook işlemlerini de sessizce istatistik dışı bırakırdı. Tarih kesimi sadece bu 3 geçmiş işlemi dışlıyor, sonraki hiçbir gerçek işlemi (webhook dahil) etkilemiyor.
  - `calculateRolling24hPNL()` (devre kesici) bilinçli olarak DEĞİŞTİRİLMEDİ - 24 saatlik kayan pencereye bu kadar eski işlemler zaten hiçbir zaman girmiyor, risk-kritik koda gereksiz dokunulmadı.
  - Gerçek yerel DB testiyle doğrulandı: kesimden önceki bir işlem `calculatePnlSummary()` sonucundan tamamen düştü, kesimden sonraki işlem doğru sayıldı.

## [1.33.0] - 2026-07-22

### Düzeltme
- [Kritik] **Pozisyon durumu (status) artık hangi OCO/emir bacağının değil, GERÇEK PNL'in işaretine göre belirleniyor**: canlı veri analizinde 46 kapalı spot işlemin 8'inin (%17) yanlış etiketlendiği tespit edildi - İzleyen Stop, Zarar Kes seviyesini girişin ÜSTÜNE çektiğinde (kâr kilitleme), sonradan o seviye tetiklense bile eski mantık bunu "closed_loss" sayıyordu, oysa pozisyon gerçekte kârda kapanmıştı. Gerçek kazanma oranının %30.4 değil %43.5 olduğu bu şekilde ortaya çıktı.
  - `AutoTradeController::reconcileActiveTrades()`: `$isProfit` artık `$exitPrice >= $entryPrice` karşılaştırmasından hesaplanıyor - hangi bacağın (`take_profit_order_id`/`stop_loss_order_id`) dolduğu artık sadece log metninde mekanik açıklama amaçlı kullanılıyor (`$filledLegType`), karar için KULLANILMIYOR.
  - `FuturesTradingService::finalizeClosedTrade()`: aynı bug SHORT tarafında da vardı - metod artık `$isProfit` parametresi almıyor, kendi içinde `$exitPrice <= $entryPrice` (SHORT: fiyat düştükçe kâr) ile hesaplıyor. İki çağıran nokta (`reconcileNativeProtectedTrade`, `reconcileSelfMonitoredTrade`) güncellendi.
  - Yan etki (düzeltme): Sembol soğuması (`SymbolCooldown`) artık sadece GERÇEKTEN zararla kapanan işlemlerde tetikleniyor - İzleyen Stop'un kâr kilitlediği bir pozisyon (SL bacağı tetiklense bile fiyat kârda) artık yanlışlıkla soğumaya girmiyor.
  - Geçmiş yanlış etiketlenmiş kayıtlar tek seferlik bir SQL UPDATE ile düzeltildi (hem `active_trades` hem `active_futures_trades`, gerçek çıkış fiyatı `orders` tablosundan alınarak) - kod değişikliği kalıcı çözüm, UPDATE sadece birikmiş geçmiş veriyi temizledi.
  - Gerçek yerel DB testleriyle doğrulandı: hem yanlış etiketlenmiş hem doğru etiketlenmiş sentetik kayıtlarla, düzeltmenin sadece gerçekten yanlış olan satırı değiştirdiği, tekrar çalıştırıldığında idempotent kaldığı ve SPOT/FUTURES yön simetrisinin (LONG: çıkış≥giriş kâr, SHORT: çıkış≤giriş kâr) doğru çalıştığı doğrulandı.

## [1.32.0] - 2026-07-22

### Yeni Özellik
- [Veri Kalitesi] **Giriş anındaki Kar Al/Zarar Kes fiyatları artık kalıcı olarak saklanıyor**: `take_profit_price`/`stop_loss_price` kolonları İzleyen Stop çalıştıkça UPDATE ile üzerine yazıldığı için, kapanmış bir pozisyonun "hangi SL% ile açıldığı" geriye dönük olarak asla doğru hesaplanamıyordu (önceki analizde negatif/anlamsız SL% çıkmasının kök nedeni buydu).
  - `active_trades` ve `active_futures_trades` tablolarına `initial_take_profit_price`/`initial_stop_loss_price` (NULL, `ai_entry_score` ile aynı desen - geriye dönük doldurma YOK, sadece bundan sonra açılan pozisyonlarda dolu) kolonları eklendi.
  - `ActiveTrade::create()`/`ActiveFuturesTrade::create()` bu iki kolonu giriş anındaki değerlerle dolduruyor; hiçbir UPDATE metodu (`applyTrailingStop`, `applyDcaFill`, `applyPartialTakeProfit`) bu kolonlara dokunmuyor - kalıcılık garantisi buradan geliyor.
  - Gerçek DB testiyle doğrulandı: kayıt oluşturulup İzleyen Stop simüle edildi, `stop_loss_price` değişirken `initial_stop_loss_price`/`initial_take_profit_price` DEĞİŞMEDEN kaldı.

## [1.31.0] - 2026-07-21

### Yeni Özellik
- [Risk Azaltan] **Ardışık Çift Onay, zamana dayalı mimariden tur tabanlı mimariye geçirildi**: canlı loglarda AYNI semptom üç kez tekrar gözlemlendi - `CronLock` devredeyken bir tarama sabit pencereyi (60sn→120sn) aşarsa bir sonraki cron tetiklemesi atlanıyor, gerçek tarama aralığı 60-185sn arasında tutarsız çıkıyordu, hiçbir sabit saniye eşiği kalıcı çözüm olmadı. Artık "ardışık" tanımı saniyeye değil, `pending_signals.pass_count` ile sayılan art arda İKİ BAŞARILI TARAMA TURUNA dayanıyor - sunucu/API ne kadar yavaşlarsa yavaşsın kendini otomatik ayarlıyor.
  - `pending_signals`'a yeni `pass_count` (TINYINT UNSIGNED, varsayılan 1) kolonu eklendi.
  - Bir sembol tüm filtrelerden 2. kez ART ARDA geçerse (kaç saniye sürdüğü ÖNEMSİZ) alım onaylanır. Aynı sembol beklerken herhangi bir sert filtreden (RSI 1h/15dk, hacim, MTF, tahta, Pullback) GEÇEMEZSE "trend bozuldu" sayılıp kaydı ANINDA silinir - 6 ret noktasının hepsine `PendingSignal::delete()` eklendi.
  - `PENDING_SIGNAL_MAX_AGE_SECONDS` artık bir onay tetikleyicisi DEĞİL - sadece 15 dakikalık bir GÜVENLİK AĞI (aday havuzundan tamamen düşüp bir daha hiç değerlendirilmeyen sinyallerin tabloyu şişirmesini önler). Yeni `PENDING_SIGNAL_REQUIRED_PASSES = 2` sabiti eklendi.
  - Gerçek DB testleriyle doğrulandı: 300 saniye önce oluşturulmuş bir sinyal bile artık başarıyla 2. tura ulaşıp onaylanabiliyor (eski mimaride bu "bayat" sayılıp sıfırlanırdı) - mekanizma artık gerçekten saniyeden bağımsız.

## [1.30.4] - 2026-07-21

### Düzeltme
- [Ayar] **LOT_SIZE (Adım Yuvarlama) Kalkanı %0.5'ten %1.5'e gevşetildi**: canlı loglarda düşük bütçe + yüksek birim fiyatlı (ETH/BTC gibi) coinlerde "%0.79 aşıyor" gibi red mesajlarıyla geçerli sinyaller engelleniyordu. `LotSizeGuardService::DEFAULT_MAX_SLIPPAGE_TOLERANCE_PERCENT` gevşetildi - bu, PAYLAŞILAN tek bir kontrol olduğu için AI Avcı, Futures, Duyuru Avcısı, Akıllı Para dört modülü BİRDEN etkiler. Gerçek verilerle test edildi: %0.79 civarı fire artık geçiyor, sınıfın kendi örneğindeki gerçekten tehlikeli %11'lik fire hâlâ reddediliyor.
- **Not (kod değişikliği içermez):** "Açık pozisyon limiti (1/1)" şikayeti incelendi - canlı veride hayalet/askıda bir kayıt YOK. `user#6` (Güvenli profil, `max_active_trades=1`) gerçekten 1 açık SOLUSDT pozisyonuna sahip (gerçek Binance OCO/TP/SL emirleriyle), limit doğru çalışıyor. Bu yüzden pozisyon limiti YÜKSELTİLMEDİ - Güvenli profilin çekirdek güvenlik ayarını gereksiz yere gevşetmek yanlış olurdu.

## [1.30.3] - 2026-07-21

### Düzeltme
- [Ayar] **Ardışık Çift Onay'ın "sonsuz sıfırlanma" döngüsü çözüldü**: canlı loglarla doğrulandı - `PENDING_SIGNAL_MAX_AGE_SECONDS`'ı `SCAN_INTERVAL_SECONDS` (60sn) ile BİREBİR eşit tutmak yanlıştı. `CronLock` devredeyken bir tarama 60sn'yi aşarsa bir sonraki cron tetiklemesi "meşgul" diye tamamen atlanıyor, gerçekte çalışan bir sonraki tarama bir tur sonrasına (~118-120sn) kayıyor. 60sn'lik pencereyle aynı sembol (ör. ETHUSDT, RSI eşiğin sınırında dolanırken) HER SEFERİNDE "ilk görülme"ye sıfırlanıp asla ikinci onaya ulaşamıyordu.
  - `PENDING_SIGNAL_MAX_AGE_SECONDS` 60'tan **120**'ye yükseltildi - artık `SCAN_INTERVAL_SECONDS`'la eşit olmak ZORUNDA değil, kasıtlı olarak ondan büyük, gerçek gözlemlenen tarama aralığına (118sn'ye kadar) nefes payı tanıyor.
  - `SCAN_INTERVAL_SECONDS` (60) DEĞİŞMEDİ - sadece bekleyen sinyalin ne kadar süre "taze" sayılacağı gevşetildi, ağır taramanın kendisi hâlâ en fazla 60sn'de bir çalışıyor.
  - Reflection ile canlı log'daki gerçek 62sn ve 118sn'lik aralıkların artık pencere içinde kaldığı doğrulandı.

## [1.30.2] - 2026-07-21

### Düzeltme
- [Ayar] **Agresif Momentum Baypası'nın AI skoru eşiği 85'ten 70'e kalibre edildi**: Canlı loglarda doğrulandı - bu sistemde GPT pratikte NADİREN 75 üzerinde skor üretiyor (tipik dağılım 45-65 bandı), 85 eşiği hiçbir zaman tetiklenmiyordu, baypas (v1.29.0) kâğıt üzerinde vardı ama fiilen hiç devreye giremiyordu. 70, `globalMinThreshold()`'un (agresif profil, 50) belirgin üzerinde kalarak "sadece gerçekten güçlü sinyal" niyetini korur, ama artık sistemin gerçek skor dağılımıyla eşleşir.
  - **Görünürlük**: baypas devreye girdiğinde (1h RSI, 15dk RSI, Pullback kapılarının üçünde de) artık log satırının başında belirgin `[Agresif Baypas Tetiklendi - AI Skoru: X]` etiketi var - canlı loglarda kolayca `grep` edilebilir, daha önceki dağınık cümle içindeki bahsi netleştirir.
  - Gerçek log verisiyle (ETHUSDT örneği, AI skoru 74) hem yeni eşiğin artık kapsadığı hem log formatının doğru render edildiği doğrulandı.

## [1.30.1] - 2026-07-21

### Düzeltme
- [Kararlılık] **Duyuru Avcısı (Listing Sniper) "API gecikmeleri" HTTP 500 kaynağı bulunup düzeltildi**: `ListingSniperService::run()`'ın EN BAŞINDA, hiçbir try/catch olmadan çağrılan `MarketScanner::fetchTradableUsdtSymbols()` (`/api/v3/exchangeInfo` - tüm borsa bilgisini içeren büyük bir payload) yanlışlıkla varsayılan `TIMEOUT_SECONDS` (5sn) ile çağrılıyordu - dosyada zaten tanımlı `LARGE_PAYLOAD_TIMEOUT_SECONDS` (15sn) sadece 24s ticker uç noktasına uygulanmıştı, bu daha büyük uç noktaya hiç uygulanmamıştı. Artık `fetchTradableUsdtSymbols()` VE `fetchExchangeInfoStatuses()` (aynı büyük uç nokta) 15sn kullanıyor - kökten çözüm, zaman aşımı artık çok daha nadir gerçekleşecek.
  - **Kalan (nadir) zaman aşımı durumları için zarif yanıt**: yeni `App\Services\BinanceApiTimeoutException` (`RuntimeException`'dan türer, geriye dönük uyumlu) - `MarketScanner::fetchPublicJson()` ve `BinanceService::request()`'in cURL hata dalında fırlatılır. `ListingSniperController` bunu genel `Throwable` yakalayıcısından ÖNCE ayrıca yakalar: artık `HTTP 500` yerine `HTTP 200 + {"status":"error","message":"API Timeout"}` döner - bir sonraki cron turunda (Duyuru Avcısı için ~1dk sonra) kendiliğinden düzelir, monitoring'de yanlış alarm oluşturmaz.
  - Gerçek Binance API'sine kasıtlı 1 saniyelik zaman aşımıyla istek atılarak `BinanceApiTimeoutException`'ın doğru fırlatıldığı, normal (15sn) süreyle 460 gerçek sembolün başarıyla çekildiği ve gerçek eş zamanlı iki HTTP isteğinde kilidin (bkz. v1.30.0) doğru çalıştığı canlı olarak doğrulandı.
  - Ayrıca: `BinanceService.php`'de gerçek değerle (15sn, 5 değil) uyuşmayan bayat bir yorum düzeltildi.
- **Not**: Kilit (Mutex) entegrasyonu `listing-sniper` için zaten v1.30.0'da eklenmişti (`auto_trade`/`futures_trade`/`smart_money` ile aynı anda) - bu sürümde yeniden yazılmadı, sadece canlı iki eş zamanlı istekle doğrulandı.

## [1.30.0] - 2026-07-21

### Yeni Özellik
- [Risk Azaltan] **Cron Kilidi (Overlap Koruması)**: AI Avcı'nın tarama sıklığı 60sn'ye düşürülüp cPanel Cron Job'ı da `*/1`'e çekilince, bir taramanın (özellikle Pullback beklemeleri yüzünden) 60sn'yi aşıp bir sonraki istekle ÇAKIŞMASI (aynı coine çift işlem açılması dahil) riski gerçek hale geldi. Yeni `cron_locks` tablosu + `App\Models\CronLock` (`acquire()`/`release()`) ile TÜM otonom cron modülleri (`auto_trade`, `futures_trade`, `listing_sniper`, `smart_money`) artık kendi BAĞIMSIZ kilidiyle korunuyor - bir modülün kilidi diğerini bloklamaz.
  - **Atomiklik**: gerçek garanti `PRIMARY KEY(lock_name)` kısıtlamasından gelir - iki eş zamanlı istek aynı anda `INSERT` denerse InnoDB'nin satır kilidi sayesinde SADECE biri başarılı olur, diğeri `duplicate key` hatası alır (bu, "kilit meşgul" anlamına gelir). Gerçek eş zamanlı iki PHP sürecinin yarıştığı bir senaryoyla (barrier-dosyası tekniğiyle) doğrulandı - sıralı çağrılarla test etmek yetmezdi, gerçek yarış koşulu kurulup TEK bir işlemin kilidi aldığı, diğerinin anında bloklandığı teyit edildi.
  - **Kendi kendini onarma**: kilit alınırken (housekeeping adımı) 180 saniyeyi (`set_time_limit(180)` ile AYNI değer) aşmış "takılı" bir kilit varsa otomatik silinir - bir önceki çalıştırma çökse/zaman aşımına uğrasa bile sistem kalıcı olarak kilitli KALMAZ.
  - Saat hesaplaması SQL'in kendi `NOW()`'iyle (`TIMESTAMPDIFF`) yapılır, PHP `time()`/`strtotime()` KARŞILAŞTIRMASI YAPILMAZ (`PendingSignal`/`ApiKey::hasSentCooldownNotifToday` ile AYNI saat dilimi önlemi).
  - Kilitliyken gelen yeni istek `HTTP 200` + `scan_skipped: true` ile ANINDA sonlanır (hata değil, beklenen/normal bir durum olarak ele alınır - mevcut throttle-skip yanıt desenine tutarlı).

## [1.29.0] - 2026-07-21

### Yeni Özellik
- [Ayar] **Agresif Momentum Baypası**: "Spot + Futures (Agresif)" modunun aşırı korumacı davranıp fırsat kaçırdığı (`PULLBACK_BEKLENMEDİ`/`ANTI_FOMO_RSI` ile art arda red) geri bildirimi üzerine, `AutoTradeController`'a koşullu bir gevşetme kapısı eklendi. Üç koşul AYNI ANDA sağlandığında (1. platformda en az 1 agresif duruşlu kullanıcı - `ApiKey::hasAggressivePostureUser()`: `risk_profile='aggressive'` VEYA `futures_trading_enabled=1`; 2. hacim artıyor; 3. GPT skoru ≥85) üç sert kapı gevşer: 1h RSI tavanı 75→85, 15dk Anti-FOMO RSI tavanı 70→80, Pullback bekleme penceresi tamamen atlanır (doğrudan trende dahil olunur).
  - **Bilinçli tasarım kararı:** sabitler DOĞRUDAN gevşetilmedi (bu, Güvenli/Dengeli kullanıcıların korumasını da zayıflatırdı) - mevcut "Pusu (Ambush) Kurtarma" mekanizmasıyla AYNI felsefeyle bağımsız bir 2. kapı eklendi. **Bilinen ödün:** aday havuzu paylaşılan tek bir yapı olduğu için (`globalMinThreshold()`'un zaten kabul ettiği AYNI ödün), havuz bir kez gevşeyince kendi eşiğini AYRICA geçen bir Güvenli/Dengeli kullanıcı da aynı adaya erişebilir - per-user eşik/devre kesici/stop-loss kuralları DEĞİŞMEDİ, sadece paylaşılan havuz etkilenir.
  - Yeni `ApiKey::hasAggressivePostureUser()` - `run()` başına BİR KEZ çağrılır, tekrarlı sorgu önlenir.
- [Ayar] **Ardışık Çift Onay penceresi 300sn'den 60sn'ye düşürüldü**, `SCAN_INTERVAL_SECONDS` de AYNI ORANDA 300'den 60'a çekildi (ikisi HER ZAMAN eşit kalmalı, aksi halde teyit mekanizması hiçbir zaman ikinci turu yakalayamaz - bkz. kod yorumu). **Operasyonel adım (kod dışı):** cPanel Cron Job'ın da `*/5` yerine `*/1` (her 1 dakika) çalışacak şekilde ELLE güncellenmesi gerekiyor, aksi halde bu değişikliğin hiçbir etkisi olmaz.
  - **Bilinen riskler (izlenmeli, bu görevin kapsamı dışında bırakıldı):** OpenAI çağrı sıklığı/maliyeti ~5 kat artar; `MAX_CANDIDATES_PER_RUN` (10) kadar adayın sırayla `PULLBACK_WAIT_SECONDS` (12sn) bekleyebileceği düşünülünce ardışık cron çalıştırmalarının çakışması (bir önceki tur bitmeden yenisinin başlaması) riski artar.

## [1.28.1] - 2026-07-20

### Düzeltme
- [Ayar] **Pullback Kalkanı hedefi %0.5'ten %0.15'e düşürüldü**: Ardışık Çift Onay canlıda ilk kez izlenirken, saatlerce ART ARDA HİÇBİR adayın (bazıları 90-100 AI skoruyla) alım aşamasına ulaşamadığı gözlemlendi - hepsi aynı noktada, `PULLBACK_TARGET_PERCENT` filtresinde eleniyordu (12sn içinde %0.5 gerileme bekleniyordu, bu "ilk kırmızı mum" niyetine göre fazla büyük bir hedefti). `PULLBACK_WAIT_SECONDS` (12sn) BİLEREK değiştirilmedi - `MAX_CANDIDATES_PER_RUN` kadar adayın sırayla bekleyebileceği düşünülünce süreyi uzatmak `set_time_limit(180)` sınırına yaklaşma riski taşırdı, hedefi küçültmek aynı gevşetmeyi çalışma süresine dokunmadan sağlıyor.
  - Not: bu, Ardışık Çift Onay'ın (v1.28.0) kendisinde bir hata DEĞİLDİR - o mekanizma henüz hiç tetiklenmemişti çünkü kod akışı ona hiç ulaşamıyordu.

## [1.28.0] - 2026-07-20

### Yeni Özellik
- [Risk Azaltan] **Ardışık Çift Onay (Double Scan Approval)**: "Erken Panik/Hatalı Giriş" sorununu azaltmak için `AutoTradeController`'ın giriş refleksi değiştirildi - bir sembol tüm sert teknik filtrelerden (RSI/Hacim/MTF/Tahta) geçse bile artık İLK görüldüğü turda anında alınmıyor. Yeni `pending_signals` tablosuna (global, kullanıcıdan bağımsız - `ai_interventions`'daki `user_id=null` deseniyle aynı mantık) kaydediliyor; aynı sembol 300 saniye (`PENDING_SIGNAL_MAX_AGE_SECONDS`, `SCAN_INTERVAL_SECONDS` ile kasıtlı olarak aynı) içindeki BİR SONRAKİ taramada da taze bir GPT skoruyla aynı filtrelerden geçerse "ikinci onay" sayılıp alım yapılıyor. 300 saniyeyi aşan bekleyen sinyaller "bayat" sayılıp sıfırdan sıfırlanıyor.
  - Yeni `App\Models\PendingSignal`: `findBySymbol()`, `create()`, `delete()`, `pruneStale()`.
  - Garbage Collector: `run()`'ın en başında, throttle/tarama atlanmasından BAĞIMSIZ olarak HER cron turunda `PendingSignal::pruneStale()` çalışır - tablo küçük kaldığı (semboller UNIQUE, en fazla `MAX_CANDIDATES_PER_RUN` satır) için `BotLog`'un saatlik throttle'ına ihtiyaç yok.
  - Mevcut "Pullback Bekleme Penceresi" (fiyat geri çekilmesi, aynı istek içinde ~12sn) ile KARIŞTIRILMAMALI - o fiyat içindir ve tek bir istek içinde biter; bu yeni mekanizma AI skorunun kendisi içindir ve iki AYRI cron çalıştırması arasında DB'de kalıcıdır.
  - Gerçek DB satırlarıyla test edildi (ilk görülme, UNIQUE(symbol) bütünlüğü, bayat sinyal temizliği, GC eşik sınırı).
  - **Test sırasında bulunan ve düzeltilen saat dilimi hatası:** İlk sürümde sinyal yaşı PHP'nin `time() - strtotime($first_seen_at)` ile hesaplanıyordu - yerel ortamda PHP `date.timezone` (Europe/Berlin) ile MySQL `session time_zone` (SYSTEM) arasında 1 saatlik fark tespit edildi, bu da yaş hesaplamasını saatlerce yanlış çıkarıp 300sn'lik teyit penceresini sessizce genişletebiliyordu. `ApiKey::hasSentCooldownNotifToday()`'deki AYNI önlem tekrarlanarak düzeltildi: yaş artık PHP'de DEĞİL, `PendingSignal::findBySymbol()`'un SQL tarafında (`TIMESTAMPDIFF(SECOND, first_seen_at, NOW())`) hesaplanıyor.

## [1.27.0] - 2026-07-20

### Yeni Özellik
- [Kullanıcı Deneyimi] **İzleyen Stop (Trailing Stop) parametreleri veritabanına ve Dashboard'a taşındı**: `AutoTradeController`/`FuturesTradingService` içindeki sabit `TRAILING_STOP_STAGES`/`FUTURES_TRAILING_*` class const'ları kaldırıldı, yerine `user_api_keys` tablosunda kullanıcı bazlı, Dashboard'dan düzenlenebilir 8 kolon eklendi (spot: `trailing_stop_enabled`/`trailing_trigger_percent`/`trailing_lock_percent`/`trailing_distance_percent`, futures: `futures_trailing_stop_enabled`/`futures_trailing_trigger_percent`/`futures_trailing_lock_percent`/`futures_trailing_distance_percent`) - varsayılanlar eski sabit değerlerle birebir aynı, mevcut kullanıcıların davranışı migration anında değişmez.
  - **Spot/Futures kasıtlı olarak AYRI kolon setleri**: futures kaldıraç/likidasyon riski nedeniyle spot'tan daha sıkı kalibre edildiği için (bkz. v1.26.0), tek bir paylaşılan kolon seti bu farkı yok ederdi.
  - Yeni `ApiKey::getTrailingSettings()` (hafif, api_key/secret şifre çözme yükü OLMADAN - cron'un her açık pozisyon için her turda çağırdığı sorgu), `ApiKey::updateTrailingSettings()` ve `ApiKey::updateFuturesTrailingSettings()` eklendi.
  - Her iki modülde de `trailing_stop_enabled=false` ise `applyTrailingStopIfEligible()` en başta erken döner, sabit TP/SL emrine hiç dokunulmaz - Duyuru Avcısı'nın kendi sabit `SNIPER_TRAILING_STOP_STAGES` mekanizması bu bayraktan ETKİLENMEZ (kapsam dışı bırakıldı, ayrı bir mekanizma).
  - Dashboard formları: spot alanları mevcut "AI Avcı Ayarları" formuna, futures alanları mevcut "Gelişmiş Modüller" formuna (sadece "Spot + Futures" modu seçiliyken görünür) eklendi - mevcut form/kaydetme akışlarıyla tutarlı tutuldu.
  - **Düzeltilen bir tuzak (görevde istenmemiş, geliştirme sırasında bulundu):** futures trailing alanları formda SADECE futures modu açıkken render edildiği için, kullanıcı futures modu kapalıyken "Gelişmiş Modüller"i kaydederse bu alanlar POST'ta hiç bulunmuyordu - `sniper_budget_percent` ile aynı korumayı (sadece POST edilmişse güncelle) uygulamadan, her kayıtta futures trailing ayarları sessizce varsayılana sıfırlanırdı.

## [1.26.0] - 2026-07-20

### Yeni Özellik
- [Risk Azaltan] **Futures (KISA) İzleyen Stop entegrasyonu**: Spot tarafındaki İzleyen Stop raporunda tespit edilen en büyük boşluk kapatıldı - `FuturesTradingService`'e spot ile aynı iki-aşamalı (sabit tetik+kilit, sonra sınırsız izleme) mekanizma eklendi, SHORT için ayna simetrisiyle (kâr fiyat düştükçe oluşur). Yeni sabitler: `FUTURES_TRAILING_TRIGGER_PERCENT` (%1.0), `FUTURES_TRAILING_LOCK_PERCENT` (%0.2), `FUTURES_TRAILING_DISTANCE_PERCENT` (%1.0) - spot'tan bilinçli olarak daha sıkı, kaldıraç/likidasyon riski gözetilerek. `active_futures_trades`'e `trailing_stop_stage`/`lowest_price_seen` kolonları eklendi.
  - **Emir tipi kararı — native `TRAILING_STOP_MARKET` yerine cron tabanlı iptal+yeniden kur:** Binance'in native trailing emri tek parametreli (callback rate) sürekli takip yapar, mevcut mimarinin (ve bu görevde istenen) "önce sabit eşikte kilitle, SONRA izle" iki-aşamalı tasarımını desteklemez. Ayrıca `FuturesTradingService` zaten hibrit bir modelde (native TP/SL bazı kontratlarda reddedilip kendi-izleme moduna düşebiliyor) - üçüncü bir emir tipi daha eklemek bu karmaşıklığı katlardı. Bunun yerine spot'ta kanıtlanmış "iptal et + yeniden kur" deseni, futures'ın MEVCUT hibrit mutabakat döngüsüne (zaten her cron turunda çalışıyor) entegre edildi - yeni bir cron/servis gerekmedi.
  - **Kritik güvenlik kararı (görevde istenmemiş, kod incelemesinde bulundu):** Yeni Zarar Kes emri girilemezse SADECE Zarar Kes'i NULL bırakıp Kâr Al emrine dokunmamak, mevcut `isNativeProtected` kontrolünü (`OR` mantığı) yanıltıp pozisyonu "native korumalı" sanıp SADECE Kâr Al'ı kontrol eden dala düşürür, Zarar Kes tarafı hiç kontrol edilmeden pozisyon fiilen korumasız kalırdı. Bunun önüne geçmek için bu durumda Kâr Al emri de iptal edilip pozisyon TAMAMEN kendi-izleme moduna düşürülüyor (`protectShortPosition()`'daki "ikisi de dolu ya da ikisi de NULL" kuralına uygun).
  - Log formatı istendiği gibi: "Futures Kâr Kilitlendi: [Sembol]". Gerçek DB satırlarıyla test edildi (native/kendi-izleme geçişleri, dip fiyat güncellemesi, SHORT matematiği).

## [1.25.0] - 2026-07-20

### Yeni Özellik
- [Veri Bütünlüğü] **`active_trades.ai_entry_score`**: girişi onaylayan GPT/AI skoru artık pozisyonun kendi satırında kalıcı olarak saklanıyor - öncesinde SADECE `bot_logs`'a, sembol+zaman YAKLAŞIK eşleşmesiyle yazılıyordu (kesin foreign key yoktu, bugünkü "Öğrenilmiş Dersler Raporu"ndaki AI skoru analizi bu yüzden yaklaşıktı). Pusu (ambush) kurtarma telafi puanı dahil, girişi gerçekten onaylayan NİHAİ skor kaydediliyor.

### Hata Düzeltme
- [Kritik] `TradePostMortemService` analizinin (veya `ActiveTrade::setLossReason()`'ın kendisinin) başarısız olduğu durumlarda `loss_reason` sessizce `NULL` kalıyordu - bugünkü Öğrenilmiş Dersler Raporu'nda 28 zarardan 9'unun sebepsiz çıkmasının kök nedenlerinden biri buydu. Artık hata durumunda da "Bilinmeyen Neden - Log İncelenmeli" açık bir yer tutucu yazılıyor, sütun bir daha asla sessizce boş kalmıyor.
- [Kritik] `SentimentService::explainLoss()` - OpenAI bazen boş/anlamsız bir tamamlama döndürüyordu; bu bir hata/exception SAYILMADIĞI için try/catch bunu hiç yakalamıyordu ve boş string (`''`) `loss_reason` olarak kaydedilebiliyordu. Boş sonuç artık aynı fallback metnine düşüyor.

## [1.24.0] - 2026-07-20

### Yeni Özellik
- [Risk Azaltan] **LOT_SIZE Güvenlik Kalkanı Futures'a genişletildi**: spot tarafında kullanılan `LotSizeGuardService` artık `FuturesTradingService::openShort()`'taki tek giriş noktasına (bu modülün v1 kapsamı zaten SADECE SHORT - LONG girişi yok) da bağlandı. Hedeflenen miktar kaldıraçlı notional (`marj × kaldıraç / fiyat`) üzerinden hesaplanıyor - kaldıraç fire'ın oransal etkisini küçültmez, likidasyon riski zaten var olduğu için aynı %0.5 disiplini burada da uygulanıyor. `safe=false` dönerse giriş emri atlanıyor, hem `logFutures()`'a hem `ai_interventions` tablosuna ("Görünmez Kalkan") kaydediliyor. Gerçek kaldıraçlı senaryolarla test edildi (marj=50/5x → fire=%23.24, engellendi; marj=500/10x → fire=%0.22, izin verildi).

## [1.23.1] - 2026-07-20

### Hata Düzeltme
- [Kritik] Dashboard üst çubuğundaki "TAMAMLANAN" sayacı (`Order::countByUserAndStatus()`) ham `orders` satır sayısını kullanıyordu - 1 ALIŞ + 1 SATIŞ'tan oluşan TEK bir kapanmış pozisyonu 2 ayrı işlem gibi gösteriyordu. Sayfa ilk açıldığında gösterilen "GÜNLÜK PNL" da (`Order::calculateDailyPNL()`) aynı sınıftan bir hata taşıyordu - henüz KAPANMAMIŞ bir pozisyonun alış tutarını o günün zararıymış gibi sayabiliyordu (10 Temmuz'da `calculateRolling24hPNL`'de düzeltilen aynı hata deseni, bu iki metodda düzeltilmemiş kalmıştı). İkisi de artık zaten doğru olan `Order::calculatePnlSummary()`'nin tek kaynağını kullanıyor - "TAMAMLANAN" artık gerçekten kapanmış pozisyon sayısı. Kazanma Oranı (Win Rate) zaten doğruydu (16 Temmuz'da düzeltilmişti), bu değişiklik onu etkilemedi. Artık kullanılmayan `countByUserAndStatus()`/`calculateDailyPNL()` metodları kaldırıldı. Gerçek DB satırlarıyla test edildi (1 kapanmış + 1 açık pozisyon: sayaç doğru 1 gösterdi, açık pozisyonun maliyeti günlük kâra karışmadı).

## [1.23.0] - 2026-07-20

### Yeni Özellik
- [Risk Azaltan] **Anti-FOMO / Geri Çekilme (Pullback) Kalkanı**: "tepeden alma" (AI onayı tam kısa vadeli zirvede gelip bot anında girip hemen düzeltmeye yakalanması) sorununa karşı iki yeni katman eklendi. (1) **RSI (15dk) Aşırı Alım Kapısı**: `TechnicalScoreEngine`'in zaten hesapladığı 15dk RSI artık sadece skoru etkileyen yumuşak bir katman değil, AI skoru ne kadar yüksek olursa olsun (mevcut 1 saatlik `RSI_OVERBOUGHT_THRESHOLD`'dan tamamen bağımsız) 70'i geçtiğinde alımı kesin olarak engelleyen sert bir kapı - ek bir Binance çağrısı gerekmiyor, zaten hesaplanmış değer dışarı açıldı. (2) **Pullback Bekleme Penceresi**: sinyal onaylandıktan sonra piyasa emriyle anında girmek yerine, sınırlı bir süre (12 saniye, `PULLBACK_WAIT_SECONDS`) boyunca fiyatın %0.5 (`PULLBACK_TARGET_PERCENT`) geri çekilmesi beklenir; pencere içinde çekilmezse bu tur pas geçilir (yeni bir DB durumu/reconcile adımı eklenmedi - mimari kurala uygun, ListingSniperService'teki sınırlı-süreli bekleme deseniyle aynı). İkisi de "Görünmez Kalkan" (`ai_interventions`) tablosuna kaydediliyor. Gerçek Binance verisiyle test edildi (rsi_15m alanı doğrulandı, public fiyat sorgusu gerçek API anahtarı gerektirmeden çalıştı, pullback matematiği doğrulandı).

## [1.22.0] - 2026-07-20

### Yeni Özellik
- [Kritik/Risk Azaltan] **LOT_SIZE (Adım Yuvarlama) Güvenlik Kalkanı**: 20 Temmuz'da canlıda gerçekleşen bir olaydan sonra eklendi - 5.76 USDT'lik bir BTCUSDT pozisyonu, İzleyen Zırh kârı kilitlemiş olmasına rağmen, tek bir `floorToStep()` yuvarlamasıyla miktarının %11'ini "fire" olarak kaybedip zararla kapandı. Yeni `LotSizeGuardService::evaluate()` - hedeflenen (yuvarlanmamış) miktar ile Binance LOT_SIZE kuralına göre yuvarlanmış gerçek miktar arasındaki farkı (%) hesaplayıp %0.5'i (`DEFAULT_MAX_SLIPPAGE_TOLERANCE_PERCENT`) aşan işlemleri ALIM emri gönderilmeden ÖNCE iptal ediyor - AI Avcı, Duyuru Avcısı ve Akıllı Para Kopyalayıcı'nın üçünde de (gerçek Binance ALIM emri atan tüm modüller) devreye alındı. İptal edilen her işlem hem modülün kendi logına hem `ai_interventions` tablosuna ("Görünmez Kalkan") kaydediliyor. Gerçek BTC olayının rakamlarıyla test edildi (fire=%11.11, artık engelleniyor); normal büyüklükteki işlemler etkilenmiyor (fire=%0).

## [1.21.1] - 2026-07-16

### Güvenlik
- [Kritik] `app/Views/dashboard/index.php`'deki ~6 client-side render fonksiyonunda DOM XSS açıkları giderildi - PHP tarafında tutarlı uygulanan `htmlspecialchars()` konvansiyonu, `/api/dashboard/*` uç noktalarından gelip doğrudan `innerHTML`'e yazılan değerlere (Trade Post-Mortem'in AI ürettiği `loss_reason`, ham Binance `error_message`, AI Radar'ın `reason` metni, RSS haber başlığı/linki, bot log `notes`) hiç uygulanmamıştı. Yeni JS yardımcıları (`escapeHtml`, `safeHref`, `escapeJsAttr`) eklendi ve tüm ilgili noktalara uygulandı - gerçek render edilmiş sayfa üzerinde (node ile JS sözdizimi + kaçış mantığı) doğrulandı.

## [1.21.0] - 2026-07-16

### Hata Düzeltme
- [Kritik] `MarketScanner::calculateTechnicalScore()`'a beslenen 15dk/5dk mum serileri, aynı dosyadaki `isVolumeIncreasing()`/`calculateVolumeDelta()`'nın 10 Temmuz'da aldığı "henüz kapanmamış son mumu at" düzeltmesinden yoksundu - RSI/MACD "Pusu" (ambush) tespiti bazen henüz oluşmakta olan bir mumun anlık fiyat titremesine göre yanlışlıkla tetiklenip, GPT'nin zaten reddettiği bir adayı gerçek parayla ALINACAK şekilde "kurtarabiliyordu". Gerçek Binance verisiyle doğrulandı (son mumun kapanma zamanı gerçekten gelecekteydi) ve düzeltildi.
- [Kritik] Duyuru Avcısı'nda çift alım riski giderildi: `KnownSymbol::markSniperExecuted()` gerçek alım YAPILDIKTAN SONRA çağrılıyordu ve atomik bir "kilit" değildi - cakışan iki cron çalıştırması (tight-poll döngüsü nedeniyle bir önceki hâlâ çalışırken bir sonraki başlayabiliyor) aynı yeni listelenen coini ikisi de görüp ikisi de gerçek Binance alım emri gönderebiliyordu. Yeni `KnownSymbol::claimSniperExecution()` alım denemesinden HEMEN ÖNCE atomik bir `UPDATE...WHERE sniper_status='pending'` ile tek bir sürecin kazanmasını garanti ediyor. Gerçek DB satırlarıyla (iki eşzamanlı claim simülasyonu) doğrulandı.
- [Güvenlik] `storage/logs/*.log` (Binance hata detayları dahil) kimlik doğrulamasız herkese açık indirilebiliyordu - kök `.htaccess` sadece `app/`/`config/`/`database.sql`'i engelliyordu, `storage/` listede yoktu. Eklendi, gerçek HTTP isteğiyle doğrulandı (403).
- [Güvenlik] Bir admin bir kullanıcıyı banladıktan/pasife aldıktan SONRA bile, o kullanıcının ZATEN açık oturumu geçerli kalmaya devam ediyordu (`AuthMiddleware::handle()` ve `DashboardController::requireAjaxAuth()` sadece session'da `user_id` olup olmadığına bakıyordu) - "banlama" fiilen bir acil-durdurma kontrolü olarak çalışmıyordu. Artık her istekte veritabanından taze durum okunuyor, `active` değilse oturum anında sonlandırılıyor. Gerçek HTTP testiyle (giriş → banla → aynı çerezle istek → `/login`'e yönlendirme) doğrulandı.
- [Güvenlik] Oturum çerezinde `cookie_secure` bayrağı hiç ayarlanmıyordu - canlı ortam HTTPS olsa bile çerez düz HTTP üzerinden de gönderilebilir durumdaydı. Artık istek gerçekten HTTPS ise otomatik açılıyor (yerel XAMPP/HTTP geliştirme ortamını bozmadan).

### Not (ertelenen, düşük öncelikli bulgular)
- CSRF token'ları hiçbir state-changing endpoint'te yok - `SameSite=Lax` kısmen koruyor ama tam koruma değil. Kapsamlı bir değişiklik (tüm formlar+controller'lar), bu turda ertelendi.
- `AiIntervention::record()` throttle kontrolü atomik değil (TOCTOU) - etkisi sadece kozmetik (dashboard widget'ında olası yinelenen satır), fon riski yok.
- `user_api_keys.futures_trading_enabled`/`dca_enabled` kolonlarında indeks yok - performans notu, düşük öncelik.

## [1.20.2] - 2026-07-16

### Hata Düzeltme
- [Kritik] `Order::calculateRolling24hPNL()` ve `calculatePnlSummary()`'de Kademeli Kâr Alma'nın (v1.20.0) tetiklediği çift-sayım hatası giderildi: bir ALIŞ artık iki SATIŞ kaydına (kısmi + nihai) sahip olabildiği için eski `parent_order_id` JOIN'i aynı alış maliyetini iki kez düşüp kârlı işlemleri devasa zarar gibi gösteriyordu - bu, paylaşılan devre kesicinin (`RiskManagerService`) günlük zarar limitini yanlış tetikleyip kullanıcıyı sahte bir sebeple 24 saatliğine kilitleyebilir, dashboard'daki PNL/kazanma oranını da bozardı. Satışlar artık önce `parent_order_id`'ye göre gruplanıp toplanıyor, alış maliyeti sadece bir kez düşülüyor.
- [Kritik] `FuturesTradingService::openShortPosition()` - pozisyon **açılış** yolunda, Binance'in bazen `executedQty>0` iken `cumQuote=0` döndüğü (zaten kapanış yolunda 15 Temmuz'da SYNUSDT olayıyla tespit edilip düzeltilmiş) bilinen davranışa karşı hiçbir koruma yoktu - bu durumda giriş fiyatı sıfır hesaplanıp Kar Al/Zarar Kes hedeflerini de sıfıra düşürüyor, pozisyon bir sonraki cron turunda hayali bir zararla anında kapatılabiliyordu. Kapanış yolundaki aynı düzeltme (mark fiyatına geri düşme) açılışa da uygulandı.
- [Orta] Flaş Çöküş Koruması (v1.20.0) aktifken `BotLog::create()` çağrısı atlanıyordu - mevcut BTC düşüş filtresinin aksine, dashboard'daki "Son Bot Taraması" paneli uzun süren bir flaş çöküş sırasında botun çalışmadığı izlenimi verebiliyordu (fon etkisi yok, gözlemlenebilirlik).
- [Orta] Flaş Çöküş Koruması artık `ListingSniperService` ve `SmartMoneyTracker`'a da bağlandı - ikisi de daha önce hiçbir piyasa rejimi korumasına sahip değildi (Duyuru Avcısı özellikle risklidir: AI onayı beklemeden anında alım yapar, bir flaş çöküş sırasında yeni/ince likiditeli bir coin sabit %2 Zarar Kes'e neredeyse garanti çarpardı).

## [1.20.1] - 2026-07-16

### Hata Düzeltme
- [Kritik] Kademeli Kâr Alma'nın (v1.20.0) İzleyen Zırh ile etkileşiminde kenar durum hatası giderildi: bir pozisyon Asama 1'e (+%1.5 breakeven kilidi) hiç uğramadan doğrudan +%3 kısmi kâr eşiğine sıçrarsa `trailing_stop_stage` hâlâ 0 kalıyordu - bir sonraki turda `applyDiscreteTrailingStage()` bunu fark etmeden, kısmi kârda YENİ kurulmuş (iyi) Zarar Kes'i eski/düşük Aşama 1 hedefine (giriş+%0.3) GERİ ÇEKMEYE çalışabiliyordu (o kod yolu mevcut seviyeyle karşılaştırma yapmıyor). `ActiveTrade::applyPartialTakeProfit()` artık `trailing_stop_stage`'i kısmi kâr anında peşinen maksimum ayrık aşamaya ilerletiyor ve `highest_price_seen`'i `GREATEST()` ile (asla geriye gitmeyecek şekilde) güncelliyor - bir sonraki tur doğrudan sürekli izlemeye (Aşama 3) geçiyor.

## [1.20.0] - 2026-07-16

### Yeni Özellik
- [Quant] **Kademeli Kâr Alma (Partial Take Profit)**: AI Avcı pozisyonları artık +%3 kâra ulaştığında miktarın yarısını MARKET emriyle satıp gerçek kârı cebe indiriyor - `active_trades.partial_tp_executed` (yeni kolon) bunun bir pozisyonda SADECE BİR KEZ tetiklenmesini sağlıyor. Kalan yarı için Zarar Kes mevcut seviyeden aşağı inmeyecek + güncel fiyatın hemen altına çekilmiş, Kâr Al ise pratikte ulaşılamayacak bir güvenlik tavanına (giriş+%100) taşınmış yeni bir OCO kuruluyor - gerçek "trend bitene kadar sür" davranışı zaten var olan İzleyen Zırh'ın Zarar Kes yükseltme mekanizmasından geliyor. Riski azaltan bir değişiklik (mevcut İzleyen Zırh hiçbir zaman gerçek kâr realize etmiyordu, sadece Zarar Kes'i yukarı çekiyordu).
- [Quant] **Çeşitlendirme (Korelasyon) Filtresi**: Yeni bir alım denenmeden önce, kullanıcının açık pozisyonlarından herhangi biriyle adayın son 48 saatlik GETİRİ serisi arasındaki Pearson korelasyon katsayısı hesaplanıyor (`MarketScanner::calculatePriceCorrelation()`) - %85 veya üzeri yüksek pozitif korelasyonda alım atlanıyor, böylece eşzamanlı pozisyonların hepsinin aynı yönde hareket eden coinlerde toplanması (yanlış çeşitlendirme hissi) engelleniyor. Korelasyon hesaplanamazsa (veri/API hatası) fail-open, bir alımı asla yanlışlıkla engellemiyor.
- [Quant] **Flaş Çöküş Koruması**: `RiskManagerService::checkFlashCrash()` - BTC son 1 saatte %5'ten fazla düştüyse (mevcut -%3/24 saatlik yavaş rejim filtresinden BAĞIMSIZ, çok daha kısa vadeli ve sert bir eşik) AI Avcı'nın tüm yeni tarama/OpenAI puanlama/alım adımı o turda tamamen atlanıyor - mevcut açık pozisyonlar (Zarar Kes/İzleyen Zırh) etkilenmeden yönetilmeye devam ediyor. Kullanıcı bazlı değil, DB'ye kilit yazılmıyor - BTC toparlanınca bir sonraki turda otomatik açılıyor. Şimdilik sadece AI Avcı'ya (spot) bağlandı; Futures/Sniper/Akıllı Para için ayrı bir entegrasyon adımı gerekir.

## [1.19.1] - 2026-07-16

### Hata Düzeltme
- [Kritik] Binance imzalı isteklerinde tekrarlayan `-1021` ("Timestamp for this request is outside of the recvWindow") hataları giderildi - `BinanceService` ve `BinanceFuturesService`'te `RECV_WINDOW` 5000'den 10000'e çıkarıldı ve yeni `getServerTimeOffsetMs()` her cron çalışmasında Binance'in gerçek sunucu saatini (`/api/v3/time` / `/fapi/v1/time`) bir kez çekip yerel saatle farkını imzalanan `timestamp`'e uyguluyor - paylaşımlı hosting sunucu saatinin kaymasından bağımsız hale geldi.

## [1.19.0] - 2026-07-15

### Değişiklik
- [Mimari] İşlem sıklığının hâlâ düşük bulunması üzerine üç eşik daha bir kademe gevşetildi: `RiskProfileService::PROFILES.ai_score_threshold` (Güvenli 80→70, Dengeli 70→60, Agresif 60→50), `AutoTradeController::RSI_OVERBOUGHT_THRESHOLD` (70→75) ve `MAX_CANDIDATES_PER_RUN` (5→10, artık Agresif'in maks. eşzamanlı pozisyon sayısının üzerinde - amaç birden fazla kullanıcının aynı turda doyurulabilmesi). Bu, gerçek riski artıran bilinçli bir tercih - kullanıcı onayıyla uygulandı.

## [1.18.4] - 2026-07-15

### Hata Düzeltme
- [Kritik] 1.18.3'teki erken-yanıt düzeltmesi etkisizdi çünkü `fastcgi_finish_request()` sadece PHP-FPM'de çalışır - geçici bir tanılama betiğiyle (`fpm_check.php`, kullanılıp hemen silindi) canlı sunucunun PHP-FPM değil **LiteSpeed (LSAPI)** kullandığı tespit edildi. `AutoTradeController::run()` artık LiteSpeed'in kendi eşdeğeri `litespeed_finish_request()`'i de deniyor (`fastcgi_finish_request` yoksa) - istemciye erken yanıt gönderme mekanizması bu sunucuda artık gerçekten çalışacak.

## [1.18.3] - 2026-07-15

### Hata Düzeltme
- [Kritik] cron-job.org'un History kaydında tespit edildi: harici tetikleyicinin kendi istemci-taraflı zaman aşımı (ücretsiz planda 30sn, artırılamıyor) bazen ağır taramanın (25 aday x OpenAI) süresini aşıp bağlantıyı "timeout" ile kesiyordu - `reconcileActiveTrades()` tamamlanmış olsa bile tarama kısmı yarıda kalabiliyordu. `AutoTradeController::run()` artık throttle kontrolünden hemen sonra, PHP-FPM altındaysa `fastcgi_finish_request()` ile istemciye ANINDA bir yanıt gönderip bağlantıyı kapatıyor - script sunucu tarafında (`set_time_limit(180)` içinde) arka planda çalışmaya devam ediyor, istemci artık beklemediği için asla zaman aşımına uğramıyor. Fonksiyon mevcut değilse (FPM dışı bir SAPI) sessizce atlanır, eski senkron davranış korunur.

## [1.18.2] - 2026-07-15

### Hata Düzeltme
- [Kritik] Sosyal Radar'dan (CoinGecko trending) gelen adaylara gerçek piyasa verisi (fiyat değişimi/hacim) hiç gitmiyordu - `SentimentService` bu yüzden onlar için "veri yok" genel promptuna düşüyor, GPT de düşük bilgiyle farklı coinler için aynı/neredeyse aynı skor ve kalıp gerekçe üretiyordu (ör. aynı turda 4 farklı coin hepsi skor 75, ikisi birebir aynı cümle). `MarketScanner::getTickerData()` eklendi - `scanTopMovers()`'ın zaten tek seferde çektiği ~2000+ paritelik önbellekten TEK bir sembolün gerçek 24s fiyat/hacim verisini EK bir API çağrısı yapmadan döner. `AutoTradeController::run()` artık Sosyal Radar kaynaklı (MarketScanner'ın kendi havuzunda olmayan) sembollere de bu gerçek veriyi besliyor - artık onlar da ETHUSDT gibi sayısal kanıtlı, birbirinden farklı skor/gerekçe alıyor.

## [1.18.1] - 2026-07-15

### Değişiklik
- [Kritik] `SocialRadarService`'in veri kaynağı CryptoPanic'ten **CoinGecko**'nun `/search/trending` uç noktasına taşındı - CryptoPanic Nisan 2026'da ücretsiz planını tamamen kaldırdı. Yeni kaynak tamamen ücretsiz (anahtarsız/keyless de çalışır), ticari kullanıma izinli (basit atıf şartıyla) ve zaten hazır bir "trend/ilgi patlaması" listesi döndürdüğü için eski taban/çoklayıcı (baseline/spike-multiplier) hesabı kaldırıldı - liste doğrudan aday olarak kullanılıyor. `AutoTradeController::fetchTradableSocialRadarSymbols()` hiç değişmedi (sadece `symbol` kolonunu okuyor, arayüz aynı kaldı). `config/app.php`, `AdminController` ve admin panelindeki `cryptopanic_api_key` alanı `coingecko_api_key` ile değiştirildi - bu anahtar OPSİYONELDİR, boş bırakılırsa modül anahtarsız (keyless) çalışmaya devam eder, sadece daha düşük bir hız sınırına tabi olur.
- [Hata Düzeltme] Gerçek API testinde CoinGecko'nun artık açıklayıcı bir `User-Agent` başlığı olmadan istekleri 403 ile reddettiği tespit edildi (`php -l` bunu yakalayamazdı, sadece gerçek çağrı gösterdi) - `CURLOPT_USERAGENT` eklendi.

## [1.18.0] - 2026-07-15

### Yeni Özellik
- [Mimari] "Şarjör Optimizasyonu": AI Avcı artık bir turda AI eşiğini (60) geçen SADECE en yüksek skorlu tek adayı değil, `MAX_CANDIDATES_PER_RUN` (5) adede kadar TÜM uygun adayı sırayla dener - her aday yine kendi RSI/hacim trendi/MTF/emir defteri filtrelerinden VE `huntForAllUsers()`'in per-user kontrollerinden (bakiye, maks. eşzamanlı pozisyon, devre kesici, sembol soğuması) taze olarak geçmek zorunda, bu yüzden riskli bir "hepsini al" moduna dönüşmüyor - sadece "Agresif" profilin izin verdiği eşzamanlı pozisyon kapasitesi artık tek bir turda birden fazla fırsatla doldurulabiliyor. Bir adayın değerlendirilmesi sırasında geçici bir API hatası artık SADECE o adayı atlıyor, tüm turu iptal etmiyor (eskiden tek noktadan hata tüm turu düşürürdü).

### Hata Düzeltme
- [Kritik] Zarar Kes (OCO) bacağı artık `STOP_LOSS_LIMIT` (sabit %0.5 toleranslı LIMIT emri) yerine düz **`STOP_LOSS`** (piyasa) emri olarak gönderiliyor - 15 Temmuz'da NEARUSDT'de yaşanan "İzleyen Zırh kâr kilitlemişti ama pozisyon slippage'a gitti" olayının kök nedeniydi: volatil bir düşüşte fiyat %0.5 toleransı aşarsa LIMIT emri hiç dolmuyor, pozisyon fiilen korumasız kalıyordu. `BinanceService::placeOCOOrder()`'ın `stopLimitPrice` parametresi artık opsiyonel (varsayılan null) - verilmezse Binance bacağı otomatik olarak piyasa emri sayıyor. `AutoTradeController`, `ListingSniperService` ve `SmartMoneyTracker`'daki TÜM (7) çağrı noktası güncellendi.
- [Mimari] `SmartMoneyTracker`'ın izlediği cüzdanlar (Wintermute, DWF Labs, a16z, Vitalik Buterin, Justin Sun) doğru yapılandırılmış olsa da haftalarca hiç sinyal üretmemişti - kısmen `MAX_TX_PER_WALLET` (20) çok dar bir pencereydi (yüksek hacimli bir cüzdanın son 20 token işlemi saatler içinde tükenip çoğunlukla GİDEN işlemlerden oluşabiliyordu, GELEN bir transfer bu pencereye hiç girmeden kayabiliyordu) - 50'ye çıkarıldı. Ayrıca `public/error_log`'da tekrarlayan "Etherscan API HTTP 0" (bağlantı seviyesinde başarısızlık, `api.openai.com`'da bulunanla aynı sınıf sorun) tespit edildi - bu sunucu/hosting seviyesinde bir konu, kod değişikliği gerekmiyor.

### Doğrulama
- Sosyal Radar (`SocialRadarService`) ve Akıllı Para (`SmartMoneyTracker`) modüllerinin kodu zaten tam olarak "Önce DB, sonra dosya" desenine uygun şekilde hazırdı - CryptoPanic API anahtarı eklenmesi dışında kod değişikliği gerekmedi.

## [1.17.4] - 2026-07-15

### Hata Düzeltme
- [Kritik] Trade Post-Mortem'in ℹ️ ikonu "Son İşlemler" satırında hover ile bir `title` tooltip'i gösteriyordu, ama satırın kendi `onclick`'i (Order Detail modalı) tıklamayı her zaman yakaladığı için (ve dokunmatik cihazlarda hover zaten çalışmadığı için) kullanıcı pratikte hiçbir zaman zarar sebebini göremiyordu. `Order::findByIdForUser()` artık `findRecentByUser()` ile aynı `active_trades` LEFT JOIN'ini yapıyor, `apiOrderDetail()` yanıtına `loss_reason` eklendi, ve Order Detail modalı (`renderOrderDetail()`) artık zarar sebebini "ℹ️ ZARAR ANALİZİ (POST-MORTEM)" başlıklı belirgin bir kutuda gösteriyor.

## [1.17.3] - 2026-07-15

### Güvenlik
- [Kritik] Kök dizindeki `check_balance.php` ve `radar_check.php` tanılama betikleri (görevleri çoktan tamamlanmış, ilgili olay çözülmüştü) sunucudan kalıcı olarak silindi - artık CLI korumasıyla bile olsa gereksiz yere barındırılmıyorlar.
- [Kritik] `scripts/backtest.php` da doğrudan URL ile çalıştırılabildiği tespit edildi (kök `.htaccess` sadece `app/`, `config/`, `database.sql`'i engelliyor, `scripts/`'i değil) - `audit_v15.php` ile aynı CLI bariyeri eklendi.
- [Doğrulama] `smart-money` cron uç noktasının (`SmartMoneyController`) zaten diğer tüm modüllerle aynı `hash_equals()` tabanlı token koruması kullandığı teyit edildi, kod değişikliği gerekmedi.

## [1.17.2] - 2026-07-15

### Güvenlik
- [Kritik] Kök dizindeki `check_balance.php` (kullanıcı #6'nın gerçek Binance bakiyesini/açık emirlerini sızdırabiliyordu) ve `radar_check.php` (her çağrıda gerçek/ücretli bir OpenAI isteği tetikliyordu) tanılama betiklerine, `audit_v15.php`'de zaten kullanılan `if (php_sapi_name() !== 'cli') { die(...); }` güvenlik bariyeri eklendi - artık ikisi de sadece cPanel Terminal/SSH'tan çalıştırılabiliyor, tarayıcıdan erişilirse hiçbir veritabanı/API işlemi tetiklenmeden anında duruyor.

## [1.17.1] - 2026-07-14

### Değişiklik
- [Mimari] Hacim Trendi Filtresi (`MarketScanner::isVolumeIncreasing`) esnetildi: pencere 8 saatten (4s+4s) **6 saate** (3s+3s) daraltıldı ve %0 olan tolerans **%15**'e çıkarıldı - eskiden son yarı hacmi eski yarıdan bir MİKTAR bile düşük olsa (ör. WLDUSDT, AI skoru 60 eşiğini rahatça geçmişken) eleniyordu; artık sadece net/belirgin bir hacim çöküşü (>%15 düşüş) elenir. `BacktestService.php`'deki mekanik simülasyon da aynı 6 saat/%15 mantığına güncellendi (iki servis birbirinden sapmamalı).

## [1.17.0] - 2026-07-14

### Yeni Özellik
- [Trade Post-Mortem] Zararla kapanan pozisyonlar artık sadece PNL yazıp geçilmiyor, kök nedeni tespit edilip kaydediliyor - yeni `TradePostMortemService` sırayla Hız Kontrolü (<15dk'da Zarar Kes'e çarptıysa "ani volatilite"), Lider Etkisi (kapanış anında BTC son 15dk'da %1'den fazla düştüyse "BTC dalgalanmasına yakalandı") ve Zırh Durumu (İzleyen Zırh aktifken yine de zararla kapandıysa "komisyon/slippage") kurallarını dener; hiçbiri eşleşmezse `SentimentService::explainLoss()` ile tek cümlelik bir AI yorumu ister (o da fail-open'dır, OpenAI erişilemezse genel bir metne düşer). `active_trades.loss_reason` sütununda saklanıyor, Dashboard'daki "Son İşlemler" tablosunda zararlı satırların yanında ℹ️ ikonuyla (mouse-over tooltip) gösteriliyor.

## [1.16.1] - 2026-07-14

### Değişiklik
- [Mimari] `bot_logs` tablosunun otomatik silme süresi (`BotLog::PRUNE_RETENTION_DAYS`) 15 günden **60 güne** çıkarıldı - sistem 60 günlük bir performans/PNL/AI karar analizi testine girdiği için geçmiş taramaların (hangi coin ne skor aldı, neden seçildi/elendi) bu süre dolmadan silinmemesi gerekiyordu.

## [1.16.0] - 2026-07-14

### Yeni Özellik
- [Dashboard] AI Avcı ayarları formu (aç/kapa şalteri + bütçe/TP/SL/günlük zarar limiti) artık sayfa hiç yenilenmeden AJAX (Fetch API) ile kaydediliyor; sonuç sağ altta beliren yumuşak bir toast bildirimiyle gösteriliyor - `DashboardController` içine `isAjaxRequest()`/`respondFormResult()` eklendi, `X-Requested-With` header'ı gönderen istekler JSON alır, göndermeyen eski `<form>` gönderimleri (JS kapalıysa) eskisi gibi Session-flash + redirect'e düşer.
- [Dashboard] Açık pozisyon kartlarında İzleyen Zırh (Trailing Stop) durumu artık canlı gösteriliyor (🛡️ Pasif/Aktif rozeti + güncel Zarar Kes fiyatı) - `apiHunts()` yanıtına `trailing_stop_stage`/`stop_loss_price` eklendi.
- [Dashboard] Aktif pozisyon ve futures pozisyon polling aralığı 15 saniyeden 5 saniyeye düşürüldü, daha canlı bir "komuta merkezi" hissi için.

## [1.15.7] - 2026-07-14

### Değişiklik
- [Mimari] AI Avcı'nın alım kararı için gereken minimum AI skoru (`RiskProfileService::PROFILES.ai_score_threshold`) her risk profili için ~10 puan düşürüldü: Güvenli 90→80, Dengeli 80→70, Agresif 70→60 - tarama artık 5 dakikada bir çalıştığı (bkz. 1.15.6) ve İzleyen Zırh çok daha erken/sıkı kâr kilitlediği (bkz. 1.15.3) için botun "mükemmel" fırsatı beklerken kilitlenmesi yerine "yeterince iyi" adayları da daha sık denemesi hedeflendi.

## [1.15.6] - 2026-07-14

### Değişiklik
- [Mimari] AI Avcı taraması gerçekte hiç 5 dakikalık `SCAN_INTERVAL_SECONDS` eşiğine takılmıyordu, çünkü cPanel Cron Job'ı 15 dakikada bir tetikleniyordu (throttle'dan 3 kat daha gevşek) - cron `*/5` dakikaya çekilerek taramanın kod hiç değişmeden, mevcut maliyet tavanıyla (5 dk) birebir eşleşecek şekilde 3 kat sıklaştırılması sağlandı. `AutoTradeController.php`'ye bu eşleşmenin bilinçli olduğunu belirten bir yorum eklendi.

## [1.15.5] - 2026-07-14

### Değişiklik
- [Güvenlik] Dashboard'daki "Günlük Maks. Zarar Limiti" ayarının üst sınırı %100'den **%50**'ye çekildi (hem `DashboardController::saveAutoTradeSettings()` sunucu taraflı doğrulama hem de formdaki `max` özniteliği) - canlıda bir kullanıcının bu değeri %100'e ayarladığı, bu yüzden devre kesicinin günlük zarar kontrolünün pratikte hiçbir zaman devreye giremediği (tüm bakiye tükenene kadar koruma sağlamadığı) tespit edildi.

## [1.15.4] - 2026-07-14

### Hata Düzeltme
- [Kritik] Canlı sunucuda `active_trades.breakeven_triggered` sütununun eksik olması, mutabakat (`reconcileActiveTrades`) sırasında `SQLSTATE[42S22]: Unknown column` hatasına ve TIAUSDT pozisyonunda (#9) OCO bacağının belirlenememesine yol açıyordu - eksik migration canlıya uygulanmadı, `app/Models/ActiveTrade.php` zaten bu sütuna hiç referans vermiyor (yerini `trailing_stop_stage` almış), canlıda çalışan kod dosyasının güncel yerelle senkron olmadığı tespit edildi.

### Değişiklik
- [Mimari] Zarar Kes (SL) ile kapanan pozisyonlara özel sembol soğuması 12 saatten **24 saate** çıkarıldı (`SYMBOL_COOLDOWN_STOP_LOSS_HOURS`) - SXTUSDT'de gözlemlenen, standart soğuma bitince tarama tarafından aynı coinin tekrar seçilip aynı dar bantta arka arkaya SL'e çarpması döngüsünü kırmak için. Dinamik Erken Kaçış'ın soğuma süresi (12 saat) değişmedi, bu daha yumuşak bir mekanizma olduğu için ayrı tutuldu.

## [1.15.3] - 2026-07-14

### Değişiklik
- [Mimari] AI Avcı (spot) pozisyonlarında İzleyen Zırh (Trailing Stop) artık kullanıcının seçtiği Kâr Al (%) değerinden bağımsız, sabit ve daha erken bir orana bağlı: eskiden 2 aşamalı (+%2.5→giriş+%0.5, +%4.0→giriş+%2.0) olan aktivasyon tek aşamaya indirildi (+%1.5→giriş+%0.3, komisyonu karşılayan minimum breakeven payı), ardından Sınırsız İzleme mesafesi %2'den %1'e sıkılaştırıldı - Kâr Al %10/%20 gibi yüksek seçilse bile zırh artık çok daha erken uyanıp kârı kilitliyor.

## [1.15.2] - 2026-07-13

### Hata Düzeltme
- [Kritik] Duyuru Avcısı (Otonom Sniper), AI Avcı ile AYNI `auto_trade_budget_percent` sütununu paylaşıyordu - sniper AI onayı beklemeden körlemesine alım yaptığı için bu, kullanıcının AI Avcı'ya ayırdığı yüksek bütçe oranının (ör. %98) yanlışlıkla sniper'a da uygulanmasına yol açan kritik bir risk mimarisi hatasıydı. `user_api_keys` tablosuna TAMAMEN BAĞIMSIZ `sniper_budget_percent` sütunu eklendi (varsayılan %5). `ApiKey::updateSniperBudgetPercent()` yeni metodu, `ListingSniperService::buyAndProtect()`, `findAllForListingSniper()` sorgusu ve dashboard'daki "Sniper Bütçesi" input'u artık bu ayrı sütunu kullanıyor - iki modülün risk profili birbirinden tamamen bağımsız.

## [1.15.1] - 2026-07-13

### Eklendi
- [Arayüz] "Gelişmiş Modüller" bölümündeki mevcut "Duyuru Avcısı" (`listing_sniper_enabled`) kartına, AI Avcı ile paylaşılan `auto_trade_budget_percent` sütununu gösteren/güncelleyen bir "Sniper Bütçesi" input'u eklendi - kullanıcı artık "AI Avcı Ayarları" formuna gitmeden, sniper kartından bütçe yüzdesini doğrudan ayarlayabiliyor. `ApiKey::updateBudgetPercent()` yeni, tek-amaçlı metodu iki formun (`saveAutoTradeSettings`/`saveAdvancedModules`) birbirinin diğer alanlarına (ör. modül toggle'ları, kâr al/zarar kes) dokunmadan bu paylaşılan sütunu bağımsız güncelleyebilmesini sağlıyor.

## [1.15.0] - 2026-07-13

### Eklendi
- [Mimari] "%100 Otonom Listing Sniper" - PRE_TRADING/BREAK erken tespit katmanı: `known_symbols`'a eklenen `status`/`expected_start_time`/`sniper_status` sütunlarını kullanan yeni bir akış, Binance'in bir sembolü TRADING listesine ÇIKARMASINDAN (mevcut diff tabanlı tespitten) ÇOK ÖNCE, exchangeInfo'da PRE_TRADING/BREAK durumuna geçtiği anı yakalıyor (`KnownSymbol::claimPending`, `MarketScanner::fetchExchangeInfoStatuses`). Sadece daha önce hiç bilinmeyen (bootstrap/TRADING geçmişi olmayan) tamamen yeni semboller izlemeye alınıyor - var olan bir coinin bakım amaçlı BREAK'e girmesi asla yanlışlıkla "yeni listeleme" sayılmıyor.
- [Mimari] `ListingSniperService::run()` artık izlenen (sniper_status=pending) hedefler için cPanel'i çökertmeyecek şekilde zaman sınırlı (25sn tavan, `set_time_limit`) sıkı bir poll döngüsü (`pollPendingTargets`) çalıştırıyor: her ~1 saniyede bir `MarketScanner::fetchSingleSymbolStatus` ile hafif bir sorgu atıp durumun TRADING'e geçtiği anı, bir sonraki cron tetiklemesini (1 dakikaya kadar uzak olabilir) beklemeden yakalıyor. 6 saatten uzun süredir TRADING'e geçmeyen hedefler `failed` olarak işaretlenip izlemeden çıkarılıyor.
- [Mimari] "Dinamik Zırh": Duyuru Avcısı (`announcement_hunter`) pozisyonları artık normal AI Avcı pozisyonlarından çok daha erken ve sıkı bir Kademeli İzleyen Stop kullanıyor (+%10 kârda Zarar Kes +%5 kâr noktasına çekiliyor, ardından Sınırsız İzleme zirvenin %2 altını takip ediyor) - mevcut `reconcileActiveTrades()`/`applyTrailingStopIfEligible()` akışı ve normal pozisyonların +%2.5/+%4.0 eşikleri değişmeden korunuyor, sadece pozisyonun kaynak `strategy_bucket`'ine göre farklı eşik seti seçiliyor.

## [1.14.3] - 2026-07-13

### Eklendi
- [Optimizasyon] Stablecoin kara listesine `radar_check.php` ile canlı yakalanan `USD1` eklendi (v1.14.2'nin ilk listesinde yoktu), ayrıca `USDE`, `PYUSD`, `FRAX`, `LUSD` proaktif olarak eklendi.

## [1.14.2] - 2026-07-13

### Eklendi
- [Optimizasyon] Stablecoin/fiat çiftleri (USDC, FDUSD, TUSD, BUSD, DAI, USDP, USDD, GUSD, EUR, TRY, GBP, AEUR) artık tarama havuzuna hiç girmiyor. `radar_check.php` ile tespit edildi: `USDCUSDT` yüksek hacmi yüzünden Top-N havuzunda hep en üst sıralarda çıkıp gerçek bir altcoin'in yerini işgal ediyordu - dolar karşısında pratikte hiç hareket etmediği için asla gerçek bir alım fırsatı olamaz. Filtre `isEligibleSymbol()`'a eklendi, mevcut kaldıraçlı token (UP/DOWN/BULL/BEAR) filtresiyle aynı noktada, havuz oluşturulmadan önce çalışıyor.

## [1.14.1] - 2026-07-12

### Hata Düzeltme
- [Kritik] "Risk Profili" kartlarının (Güvenli/Dengeli/Agresif), kullanıcının "AI Avcı Ayarları" formundan elle girdiği özel bir Zarar Kes yüzdesini hiçbir uyarı olmadan profilin sabit değerine sıfırlaması ("sessiz ezme") düzeltildi. Artık mevcut Zarar Kes değeri 3 standart profil değerinden hiçbirine denk gelmiyorsa (özelleştirilmişse), bir risk kartına tıklandığında önce onay isteniyor: kullanıcı kabul ederse profilin değeri uygulanıyor, reddederse SADECE diğer alanlar (AI eşiği, maks. işlem sayısı) güncellenip özel Zarar Kes değeri aynen korunuyor.

## [1.14.0] - 2026-07-10

### Eklendi
- [Mimari] Kademeli Kâr Kilitleme'ye "Aşama 3 (Sınırsız İzleme)" eklendi: pozisyon Aşama 2'ye (+%4.0) ulaştıktan sonra, fiyat yükselmeye devam ettikçe Zarar Kes seviyesi görülen en yüksek fiyatın daima %2 altında tutulacak şekilde sürekli güncelleniyor (`highest_price_seen`). Kâr Al (%6) hâlâ değişmeyen bir tavan; fiyat oraya ulaşmadan geri dönerse artık sabit +%2 yerine son görülen zirveye göre hesaplanan, çok daha yüksek bir kâr noktasında kapanıyor. Gereksiz OCO değişikliğini (ve kısa süreli korumasızlık penceresini) önlemek için Zarar Kes en az %0.3 iyileşmedikçe emir yenilenmiyor - yeni zirve yine de her turda kaydediliyor. Mevcut OCO/Binance güvenlik mimarisine (borsa-taraflı koruma, `active_trades`, devre kesici, AI Kalkanı) hiç dokunmadan, ayrı bir cron/tablo olmadan entegre edildi.

## [1.13.2] - 2026-07-10

### Hata Düzeltme
- [Kritik] `isVolumeIncreasing()` (Hacim Trendi sert filtresi) ve `calculateVolumeDelta()` (scalping hacim sıçraması sinyali), henüz kapanmamış/oluşmakta olan GÜNCEL mumu da hesaba dahil ediyordu (Binance'in `endTime` verilmeden klines dönme davranışı gereği). Bu yarım mum, gerçek piyasa durumundan bağımsız olarak hacim karşılaştırmasını sistematik şekilde yanlış yöne çekiyordu - `radar_check.php` ile canlı yakalandı: BTC/ETH dahil taranan 5 coinin 5'i de aynı anda bu filtrede elenmişti. Artık bir fazla mum çekilip en sondaki (kapanmamış) mum atılıyor, karşılaştırma sadece tamamen kapanmış mumlar arasında yapılıyor. Bu, botun gün boyunca gereğinden fazla "hacim artmıyor" diyerek işlem kaçırmasının olası ana nedeniydi.

## [1.13.1] - 2026-07-10

### Eklendi
- [ADMIN] `radar_check.php` — sunucuda terminalden tek seferlik çalıştırılan "Canlı Radar Teşhis" aracı eklendi (`php radar_check.php [N]`). O anki tarama havuzundan hacme göre ilk N coini gösterir, her birinin RSI/Hacim Trendi/MTF(4H)/Emir Defteri sert filtrelerinden hangi sebeple geçtiğini veya elendiğini yazar; tüm filtreleri geçen bir aday varsa gerçek AI skorunu (eşikle karşılaştırmalı) sorar. Hiçbir alım/satım yapmaz, veritabanına yazmaz - salt okunur. Eşik değerlerini `AutoTradeController`'ın kendi sabitlerinden canlı okur, asla eskimez.

## [1.13.0] - 2026-07-10

### Değiştirildi
- [Mimari] Tek aşamalı "İzleyen Stop" (break-even) mekanizması, "Kademeli Dinamik Kâr Kilitleme"ye yükseltildi: pozisyon %2.5 kâra ulaştığında Zarar Kes giriş fiyatının %0.5 üzerine, %4.0 kâra ulaştığında %2.0 üzerine çekiliyor - Kâr Al (%6) hedefine hiç dokunmadan fiyat geri dönse bile masada kâr bırakılmıyor. Fiyat iki eşiği aynı anda geçerse (iki cron turu arasında hızlı hareket) gereksiz ara adım atlanıp doğrudan en uygun aşamaya geçiliyor. Aşamalar asla geriye gitmiyor. Log: "Kâr Kilitlendi: PARİTE için Stop-Loss %N seviyesine çekildi."

## [1.12.4] - 2026-07-10

### Değiştirildi
- [Log] "Bütçe asgari işlem limitinin altında" durumu artık genel "AI-hunt işlenirken hata" kutusuna gömülü kalmıyor, kendi açık ve kolayca aranabilir log satırına sahip: `Bakiye Yetersiz: Kullanıcı #X PARİTE için hesaplanan bütçe (Y USDT) Binance minimum limitinin (5.00 USDT) altında...`. Küçük bakiyeli hesaplarda bütçe yüzdesi düşürüldüğünde bu durumun fark edilmesi kolaylaşıyor.

## [1.12.3] - 2026-07-10

### Eklendi
- [Güvenlik] `reconcileActiveTrades()`'e Binance işlem geçmişi (`myTrades`) yedek doğrulaması eklendi: OCO'nun hangi bacağının (Kâr Al/Zarar Kes) gerçekleştiği `getOrderStatus()` ile net belirlenemediğinde, sistem artık hemen `closed_manual`'a düşüp vazgeçmiyor - Binance'in kendi gerçek işlem kayıtlarından (bilinen take_profit/stop_loss orderId'leriyle eşleştirerek) gerçek kapanışı bulmayı dener. 10 Temmuz'da TIAUSDT pozisyonunda yaşanan ve manuel veritabanı düzeltmesi gerektiren veri kaybının bir daha yaşanmaması için eklendi.

## [1.12.2] - 2026-07-10

### Eklendi
- [ADMIN] `BinanceService::getOpenOrders()` eklendi - bir sembolde (veya hesap genelinde) o an gerçekten açık olan emirleri Binance'ten sorgular. vlknmkdn@hotmail.com hesabındaki TIA bakiyesinin neden "kilitli" göründüğünü araştırırken eklendi; `getOcoOrderStatus()`'tan farklı olarak belirli bir orderListId'ye bağımlı değil, mutabakat/tanılama senaryolarında bağımsız bir doğrulama katmanı sağlıyor.

## [1.12.1] - 2026-07-10

### Hata Düzeltme
- [Panel] `apiPortfolio()` uç noktasında, Binance API'ye erişilemediğinde (ör. geçici IP kısıtlaması) `positions_value` ve `unrealized_pnl` alanlarının yanlışlıkla `0.00` dönmesi düzeltildi - gerçekte "bilinmiyor" olan bu değerler artık `null` dönüyor, panel de bunu sahte bir "$0.00 kâr/zarar" yerine "—" ile gösteriyor. Ayrıca "Aktif Avlar" panelindeki pozisyon kartları artık API'ye erişilemediğinde sonsuza kadar "fiyat yükleniyor..." yazısında takılı kalmıyor, "fiyat alınamadı" durumuna geçiyor. Bakiye bağlantı mesajı "API Bağlantı Bekleniyor" ifadesine hizalandı.

### Not
- MTF (Çoklu Zaman Dilimi) ve Emir Defteri (Depth) filtrelerinin API ban'ına sebep olduğu iddiası incelendi: kod, bu iki çağrının zaten sadece tek bir (RSI/hacim filtrelerinden geçmiş) adayda, 5 dakikalık tarama throttle'ına bağlı çalıştığını doğruluyor - "tüm coinler için çalışma" teşhisi koda uymuyor. Asıl API yükü kaynağının netleştirilmesi için cPanel cron job aralıkları ve varsa Binance'in döndürdüğü tam hata/ban mesajı incelenmeli.

## [1.12.0] - 2026-07-10

### Eklendi
- [Panel] "Görünmez Kalkan" raporu eklendi: MTF Trend Filtresi veya Emir Defteri Duvar Analizi bir alımı reddettiğinde, artık sadece `auto_trade.log`'a değil, yeni `ai_interventions` tablosuna da müşteri-yüzlü bir özet yazılıyor (ör. "4 saatlik ana trend düşüşte olduğu için olası bir tuzak tespit edildi, işlem iptal edildi"). Aynı sembol+sebep için 4 saatlik bir throttle var, spam kayıt oluşmuyor. Dashboard'da AI Radar panelinin içine, AI Monolog'un hemen altına "🛡️ AI Kalkanı" adında yeni bir şerit eklendi - son 5 engellenen tuzağı listeliyor, dakikada bir güncelleniyor.

## [1.11.2] - 2026-07-10

### Eklendi
- [Güvenlik] Alım kararına (Entry Criteria) 2 yeni sert filtre eklendi ("sahte yükseliş"/fakeout tuzaklarına karşı):
  - **Çoklu Zaman Dilimi (MTF) Trend Filtresi**: seçilen coin 4 saatlik grafikte EMA200'ün altındaysa ("ana trend düşüşte"), AI skoru 75+ olsa bile alım reddediliyor - kısa vadeli (15m) hacim patlaması "Ölü Kedi Sıçraması" (dead cat bounce) tuzağı sayılıyor. Log: "MTF Reddi: ... 4H Ana trend düşüş yönünde".
  - **Emir Defteri (Order Book) Duvar Analizi**: Binance Depth API üzerinden mevcut fiyatın %3 üzerindeki satış (ask) hacmi, %3 altındaki alış (bid) hacminin 3 katından fazlaysa "Satış Duvarı" tespit edilip alım reddediliyor. Log: "Tahta Reddi: ... %3 yukarıda Nx Satış Duvarı tespit edildi".
  - Her iki filtre de mevcut RSI/BTC-düşüş/hacim trendi/pusu mekanizmalarına dokunmadan, aynı sıralı hard-filter zincirine eklendi; veri alınamazsa (API hatası/yetersiz geçmiş) fail-open çalışıyor, asla yanlışlıkla bir alımı engellemiyor.

## [1.11.1] - 2026-07-10

### Eklendi
- [Mimari] Sembol Bazlı Soğuma (Symbol-Specific Cooldown) eklendi: "intikam işlemi" (revenge trading) riskine karşı, bir pozisyon Zarar Kes veya Dinamik Erken Kaçış ile kapandığında o kullanıcı için o SPESİFİK coin 12 saat kara listeye alınıyor - AI skoru dakikalar içinde tekrar yükselse bile bot aynı coine hemen geri girmiyor. Devre kesiciden farklı olarak sadece o (kullanıcı, sembol) çiftini etkiliyor; botun geri kalanı ve diğer kullanıcılar hiç etkilenmiyor. Aynı coin tekrar zararla kapanırsa soğuma süresi baştan başlıyor (`symbol_cooldowns` tablosu).

## [1.11.0] - 2026-07-10

### Eklendi
- [Mimari] Spot (AI Avcı) modülüne "Dinamik Kaçış Protokolü" eklendi:
  - **Erken Kaçış**: Açık pozisyonun AI skoru periyodik olarak (5 dakikada bir, ayrı bir throttle ile - OpenAI maliyetini kontrollü tutmak için) yeniden kontrol ediliyor. Skor "kritik çöküş" eşiğinin (<30) altına düşerse, bot Zarar Kes seviyesini beklemeden mevcut OCO emrini iptal edip pozisyonu anında piyasa fiyatından (Market Sell) kapatıyor. Olay `auto_trade.log`'a "Erken Kaçış: AI Skoru 30'un altına düştüğü için pozisyon kapatıldı" olarak yazılıyor, müşteriye Telegram bildirimi gidiyor.
  - **İzleyen Stop (Break-Even)**: Pozisyon %1.5 kâra ulaştığında, Zarar Kes seviyesi otomatik olarak giriş fiyatına (break-even) çekiliyor - eski OCO iptal edilip aynı Kâr Al fiyatıyla, sadece Zarar Kes'i giriş fiyatına taşıyan yeni bir OCO yerleştiriliyor. Bu, pozisyon başına sadece bir kez tetikleniyor (`active_trades.breakeven_triggered`).
  - Her iki mekanizma da mevcut RSI/BTC-düşüş/hacim trendi sert filtrelerinden tamamen bağımsız, sadece AÇIK pozisyonları etkiler; yeni alım kararlarına dokunmaz. Binance API hatalarında (OCO iptal/market satış başarısız) pozisyon asla "sessizce" korumasız bırakılmıyor - admin+müşteriye anında acil Telegram bildirimi gidiyor.

## [1.10.5] - 2026-07-10

### Hata Düzeltme
- [Kritik/Gizlilik] AI Monolog panelinde çapraz kullanıcı veri sızıntısı düzeltildi: `bot_logs` tablosu tüm kullanıcılar arasında paylaşılan tek bir tarama kaydı olduğu için, `positions_opened` (o turda pozisyon açılan TOPLAM kullanıcı sayısı) her kullanıcının panelinde "POZİSYON AÇILDI" olarak gösteriliyordu - başka bir kullanıcının açtığı pozisyon da dahil. Artık her satır, görüntüleyen kullanıcının KENDİ sipariş geçmişiyle çapraz kontrol ediliyor (`Order::hasFilledBuyNear`); "POZİSYON AÇILDI" yazısı SADECE gerçekten o kullanıcı için geçerliyse gösteriliyor, aksi halde nötr tarama bilgisi ("aday: X, skor: Y") gösteriliyor. Admin panelindeki aynı sayaç (tüm sistemi görmesi beklenen, ayrı yetkili bir ekran olduğu için) değiştirilmedi.

## [1.10.4] - 2026-07-10

### Hata Düzeltme
- [Kritik/Güvenlik] Devre kesicinin günlük zarar hesaplaması (`Order::calculateRolling24hPNL`), gerçekleşmiş (realized) kâr/zararı değil, ham "SATIŞ toplamı - ALIŞ toplamı" nakit akışı farkını hesaplıyordu. Bu yüzden YENİ açılan bir pozisyonun anaparası (henüz eşleşen bir satış olmadığı için) doğrudan "zarar" sayılıyor, örneğin 13.51 USDT'lik yeni bir alım devre kesiciyi anında tetikleyip hesabı 24 saat kilitleyebiliyordu. Artık SADECE gerçekten kapanmış (parent_order_id ile eşleşen ALIŞ+SATIŞ çiftlerinin) net farkı sayılıyor; açık pozisyona bağlanan anapara asla günlük zarar toplamına dahil edilmiyor. Panelin "Günlük/Haftalık/Aylık Kâr-Zarar" widget'ını besleyen `calculatePnlSummary()` fonksiyonunda da AYNI hata bulunup düzeltildi.

## [1.10.3] - 2026-07-10

### Hata Düzeltme
- [Kritik/Güvenlik] Devre kesicinin "ardışık 3 zarar" tespiti, kapanan işlemlerin ZAMANINI hiç dikkate almıyordu. Günler/haftalar önce olmuş 3 eski zararlı işlem, hesap o tarihten beri hiç yeni işlem yapmamış olsa bile "güncel seri" sayılıp, kilit her açıldığında (24 saat dolunca ya da manuel açıldığında) hesabı YENİ hiçbir işlem olmadan anında yeniden kilitliyordu - hesap fiilen süresiz kilitli kalabiliyordu. `ActiveTrade`/`ActiveFuturesTrade::findRecentClosed()` artık sadece son 24 saat içinde kapanan işlemleri "güncel seri" sayıyor; daha eski zararlar kendiliğinden devre dışı kalıyor.

## [1.10.2] - 2026-07-09

### Hata Düzeltme
- [Panel] AI Radar kartındaki "EŞİK > 80" yazısı sabit (hardcoded) metindi, kullanıcının seçtiği risk profiline göre artık dinamik gösteriliyor (Agresif: 70, Dengeli: 80, Güvenli: 90).
- [Log] `huntForAllUsers()` içinde kullanıcı bazlı AI skor eşiği, maksimum açık pozisyon limiti VE "bu paritede zaten açık pozisyon var" kontrollerinin atladığı durumlar artık `auto_trade.log`'a yazılıyor (üçü de eskiden tamamen sessizdi) - "skor eşiği geçti ama alım olmadı" gibi durumların kanıtla teşhis edilebilmesi için.

## [1.10.1] - 2026-07-09

### Hata Düzeltme
- [Admin Panel] "Piyasa Tarama Beyaz Listesi" alanı boş bırakılıp kaydedildiğinde eski değerin veritabanında SESSİZCE değişmeden kalması düzeltildi. Bu alan diğer ayar alanlarıyla aynı "boş gönderilirse dokunma" mantığını paylaşıyordu - oysa bu alanın kendi placeholder metni ("Boş = kısıtlama yok") admin'e onu boşaltarak kaldırabileceğini vaat ediyordu. Artık boş gönderim de kasıtlı bir "listeyi temizle" komutu olarak kaydediliyor (izlenen balina cüzdanları alanıyla aynı mantık).

## [1.10.0] - 2026-07-09

### Değiştirildi
- [Mimari] AI Avcı (Spot) tarama havuzu "Karma Radar" (3 sabit fiyat-değişim aralığına bölünmüş golge_hacim/dipten_donus/erken_momentum stratejileri) yerine "Dinamik Hacim Havuzu"na geçirildi: artık piyasanın en likit **Top 50** USDT paritesi (kaldıraçlı token'lar hariç, 24 saatlik hacmi en az **15.000.000 USDT**) fiyat değişim yönünden bağımsız olarak tarama havuzuna alınıyor. Ön-filtreleme kaldırıldığı için "tepeden alım" koruması artık tamamen RSI sert filtresi + TechnicalScoreEngine'in 5dk/15dk pusu (ambush) tespitine dayanıyor. Admin panelindeki "Piyasa Tarama Beyaz Listesi" ayarı hâlâ çalışıyor (doldurulursa öncelik ondadır); boş bırakılırsa yeni dinamik havuz devreye girer.

## [1.9.0] - 2026-07-09

### Değiştirildi
- [Mimari] AI Avcı (Spot) sinyal motoru "1 Saatlik Trend Takibi" modelinden "5/15 Dakikalık Scalping (Vur-Kaç)" modeline geçirildi. TechnicalScoreEngine'in MA20/MACD/RSI teyidi artık 15 dakikalık grafikten hesaplanıyor (eskiden 1 saatlikti), 1 saatlik RSI ise düşük ağırlıklı bir bağlam sinyaline indirgendi. Saatlik hacim artış/azalış kontrolü yerine, son 15 dakikadaki hacim sıçramasını (delta) ölçen çok daha hassas bir sinyal eklendi.

### Eklendi
- [Sinyal] "Dipten Dönüş Pusu" tespiti: 5 dakikalık grafikte RSI'ın aşırı satımdan (RSI<30) dönüp yukarı kestiği VE MACD'nin aynı anda erken bir "AL" sinyali verdiği (ikisi BİRLİKTE görülmeden tetiklenmez) durumlarda, GPT skoru global barajı normal yoldan geçemeyen ama en fazla 15 puan altında kalan tek bir aday için bağımsız bir 2. onay kapısı devreye giriyor ve skoruna telafi puanı ekleniyor. Mevcut sert korumalar (RSI≥70 alım engeli, BTC %-3 düşüş filtresi, hacim trendi teyidi) bu kurtarılan aday için de değişmeden aynen uygulanmaya devam ediyor - pusu bu korumaların yerine değil, önüne eklenen ek bir katmandır.

## [1.8.1] - 2026-07-09

### Hata Düzeltme
- [Bildirim] Devre kesici soğuma süresi aktifken cron her döndüğünde (15 dakikada bir) aynı "Devre Kesici Tetiklendi" Telegram mesajının tekrar tekrar gönderilmesi engellendi. Kullanıcı kilitli kaldığı sürece artık günde en fazla 1 bildirim gidiyor; soğuma süresi dolduğunda susturucu otomatik sıfırlanıyor ve bir sonraki kilitlenmede bildirim tekrar akmaya başlıyor. Hem Spot (AI Avcı) hem Futures modülü için düzeltildi.

## [1.8.0] - 2026-07-09

### Eklendi
- [Güvenlik] Devre kesici için zaman bazlı soğuma (cooldown) süresi eklendi. Ardışık zarar kes tetiklendiğinde artık sadece "otomatik işlem" bayrağı değil, 24 saatlik bağımsız bir kilit (`circuit_breaker_until`) de devreye giriyor — bu bayrak tarayıcı önbelleği veya tekrarlanan form kaydı gibi bilinmeyen bir yolla erken tekrar açılsa bile, soğuma süresi dolmadan otonom işlem kesinlikle yapılamıyor. Dashboard'da soğuma aktifken kullanıcıya net bir uyarı gösteriliyor.

## [1.7.0] - 2026-07-08

### Değiştirildi
- [Mimari] Strateji etiketi (strategy_bucket) artık geçici tarama loglarından (bot_logs) tahmin edilmek yerine, doğrudan kalıcı sipariş kaydına (orders) yazılıyor ve okunuyor. "İşlem Detayı" paneli artık hiçbir tahmin/eşleştirme yapmadan, tek bir indeksli sütun okumasıyla anında sonuç veriyor. Bu değişiklik, ileride "hangi strateji gerçekten kazandırdı?" sorusunu doğru ve kalıcı verilerle cevaplayabilmemiz için altyapıyı sağlamlaştırıyor.

## [1.6.4] - 2026-07-08

### Eklendi
- [Panel] Duyuru Avcısı (Listing Sniper) modülünün açtığı pozisyonlar artık "İşlem Detayı" panelinde "Duyuru Avcısı" strateji etiketiyle işaretleniyor (bu modül zaten aktif ve çalışır durumdaydı; eklenen kısım sadece panel görünürlüğüdür).

## [1.6.3] - 2026-07-08

### Hata Düzeltme
- [Hata Düzeltme] AI Avcı beyaz liste (whitelist) okuma ve döngü eşleşme hatası giderildi, çoklu coin taraması aktif edildi.

## [1.6.2] - 2026-07-07

### Düzeltildi
- İşlem Detayı modalında, aynı pozisyonun Alış ve Satış kayıtlarında farklı strateji etiketi görünmesi düzeltildi. Etiket artık her zaman pozisyonun ilk açıldığı (Alış) andan okunuyor; Satış anı ile Alış anı arasında çalışmış başka bir tarama turuyla karışmıyor.

### Değiştirildi
- [Ayar] İşlem başına maksimum bütçe kullanım limiti %50'den %100'e çıkarılarak All-in opsiyonu açıldı.

## [1.6.1] - 2026-07-07

### Eklendi
- [Arayüz] Mikro değerli coinler için dinamik ondalık gösterimi eklendi ve Son İşlemler tablosuna detaylı PNL analiz görünümü entegre edildi.

## [1.6.0] - 2026-07-07

### Eklendi
- [Modül] Müşteriler için sıfır likidasyon riskli 'Sadece Spot' işlem modu şalteri eklendi. Çift Motor (Spot/Futures) izolasyonu sağlandı.

## [1.5.2] - 2026-07-07

### Düzeltildi
- AI Avcı (Spot) taramalarına OpenAI bütçesini ve sunucuyu korumak amacıyla 5 dakikalık throttle (sınırlandırma) eklendi.

## [1.5.1] - 2026-07-07

### Eklendi
- Loglamaya strateji etiketleri (strategy_bucket) eklendi.

## [1.5.0] - 2026-07-07

### Eklendi
- [Algoritma] MarketScanner veri çekme mantığı değiştirilerek Karma Radar (Hybrid Scanner) sistemine geçildi. GPT'ye artık sadece yükselenler değil; Akümülasyon (Gölge Hacim) ve Dipten Dönüş stratejilerine uygun 25 özel aday sunuluyor.

## [1.4.2] - 2026-07-07

### Düzeltildi
- **AI Radar Hızlandırıldı (Kalıcı Çözüm)**: OpenAI istekleri artık tek tek sırayla değil, küçük gruplar (5'erli) hâlinde eş zamanlı (concurrent) gönderiliyor. Bu sayede 25 adaylık bir tarama, sunucu testinde ~30 saniyeden ~15 saniyeye indi ve paylaşımlı sunucunun PHP zaman aşımı sınırına yaklaşma riski büyük ölçüde ortadan kalktı. Önceki sürümdeki zaman aşımı artırma (set_time_limit) düzeltmesi de yedek güvenlik önlemi olarak korunuyor.

## [1.4.1] - 2026-07-07

### Düzeltildi
- **AI Radar "Bağlantı Yok" Hatası**: Tarama kapasitesinin 25'e çıkarılmasıyla artan ardışık API çağrısı süresi, paylaşımlı sunucunun varsayılan PHP zaman aşımını (genelde 30sn) aşıp isteğin yarıda kesilmesine yol açıyordu. AI Radar, AI Avcı ve KISA (futures) tarama uç noktalarına, bu istekler özelinde daha yüksek bir zaman aşımı sınırı eklendi.

## [1.4.0] - 2026-07-07

### Eklendi
- **AI Scanner Tarama Kapasitesi Genişletildi**: AI Avcı taramasının aday coin sayısı 10'dan 25'e çıkarıldı ve API istekleri optimize edildi; sistemin doğru fırsatı (skoru yüksek, henüz pompalanmamış coinleri) bulma ihtimali artarken, Binance Klines ve OpenAI çağrıları arasına eklenen küçük bekleme sayesinde "Rate Limit" hatası riski de azaltıldı.
- [ADMIN] Tarama kapasitesi artık admin panelinden ("AI Avcı — Tarama Kapasitesi") değiştirilebiliyor.

## [1.3.0] - 2026-07-07

### Eklendi
- **Binance API Kurulum Rehberi**: Ayarlar panelindeki "Borsa API Ayarları" bölümüne, kullanıcılar için dinamik IP gösterimli Binance API Kurulum Rehberi eklendi. Adım adım (API oluşturma, izinler, IP kısıtlaması) rehber; sunucunun gerçek dış IP adresini otomatik gösterip tek tıkla panoya kopyalatıyor, böylece müşteriler API anahtarlarını doğru ve güvenli (IP kısıtlamalı, çekim izni olmadan) oluşturabiliyor.

## [1.2.2] - 2026-07-07

### Eklendi
- [ADMIN] **Changelog'da Admin/Müşteri Ayrımı**: Sadece admin panelini ilgilendiren maddeler `[ADMIN]` etiketiyle işaretlenip dashboard'daki "Neler Yeni?" penceresinden otomatik gizleniyor; CHANGELOG.md dosyasının kendisi (tam geçmiş) değişmiyor, sadece müşteriye gösterilen görünüm filtreleniyor.

## [1.2.1] - 2026-07-07

### Eklendi
- [ADMIN] **Admin Panelinde Risk Onay Takibi**: Kayıtlı Kullanıcılar tablosuna "Risk Onayı" sütunu eklendi; her kullanıcının Risk Bildirimi'ni onaylayıp onaylamadığı ve onayladıysa tam tarih/saati (`risk_accepted_at`) tek bakışta görülebiliyor.

## [1.2.0] - 2026-07-07

### Eklendi
- **Müşteri Onboarding ve Risk Onay Sistemi**: Risk bildirimini henüz onaylamamış kullanıcılar için dashboard'un tam ortasında kapatılamaz bir "Hoş Geldiniz ve Risk Bildirimi" modalı eklendi; zorunlu "Okudum, anladım ve kabul ediyorum" onay kutusu işaretlenmeden buton pasif kalır. Onay, `users.is_risk_accepted` alanında kalıcı olarak saklanır ve bir daha gösterilmez. Onay verilmeden API anahtarı kaydetme, AI Avcı ayarları ve gelişmiş modül ayarları gibi işlemleri değiştirmeye çalışan istekler sunucu tarafında da reddedilir (sadece arayüz kısıtlaması değil).

## [1.1.0] - 2026-07-06

### Eklendi
- **Yapay Zeka Makro Trend Hafızası**: AI Avcı (spot) ve Futures (kısa) karar motoruna, Binance Klines üzerinden 90 günlük dinamik trend analizi eklendi. GPT artık bir coinin 3 aylık zirvesine ne kadar yakın olduğunu görüp buna göre puanlıyor; sadece anlık 24 saatlik harekete bakıp "tepeden alım" (FOMO) riski taşıyan kararlar vermesi engellendi.
- **Karar Kutuplaştırması (AI Polarization)**: GPT'nin piyasa durgunken güvenli limana (40-60 puan bandı, özellikle 45) sığınıp kararsız kalması engellendi; artık net bir taraf seçmeye ("Kesinlikle Uzak Dur" 0-40 ya da "Fırsat Var" 70-100) yönlendiriliyor.
- **Gelişmiş Bot Logları**: `bot_logs` tablosuna `trade_type` (spot/futures) ve `input_data` (GPT'ye gönderilen ham fiyat/hacim/makro trend verisi) kolonları eklendi; artık hem spot hem futures taramaları ayrı ayrı, girdi verisiyle birlikte kalıcı olarak kaydediliyor (ileride backtest/analiz için).
- **Otomatik Temizlik (Pruning) Politikası**: `bot_logs` tablosunun süresiz büyümesini önlemek için 15 günden eski kayıtları otomatik silen bir mekanizma eklendi.
- **Sürüm Takibi & Changelog**: Bu dosya ve dashboard'daki "Neler Yeni?" penceresi eklendi.

### Önceki İyileştirmeler (bu sürüme dahil)
- Binance Futures (KISA/short) modülü baştan sona kuruldu: hibrit TP/SL koruması (borsa-taraflı STOP_MARKET/TAKE_PROFIT_MARKET öncelikli, reddedilirse kendi izleme mekanizmasına düşen yedek sistem).
- Arayüz açık temaya (Apple'dan ilham alan tasarım) geçirildi, mobil uyumluluk iyileştirildi.
- Hatalı işlemlerin gerçek Binance hata mesajını veritabanına kaydetmesi sağlandı (şeffaflık).
- Dashboard'a Teknik Analiz Özeti widget'ı ve canlı işlem geçmişi hata detayları eklendi.
- [ADMIN] Admin panelinden kullanıcı silme ve şifre sıfırlama özellikleri eklendi.
- Kullanıcının kendi hesap bilgilerini (ad/e-posta/şifre) güncelleyebilmesi eklendi.
