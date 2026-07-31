<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Models\ActiveTrade;
use App\Models\ApiKey;
use App\Models\BotLog;
use App\Models\CronLock;
use App\Models\KnownSymbol;
use App\Models\Order;
use App\Models\AiIntervention;
use App\Models\PendingLimitOrder;
use App\Models\PendingSignal;
use App\Models\Setting;
use App\Models\SymbolCooldown;
use App\Models\User;
use App\Services\BinanceApiTimeoutException;
use App\Services\BinanceService;
use App\Services\LotSizeGuardService;
use App\Services\MarketScanner;
use App\Services\RiskManagerService;
use App\Services\RiskProfileService;
use App\Services\SentimentService;
use App\Services\SocialRadarService;
use App\Services\TelegramService;
use App\Services\TradePostMortemService;
use RuntimeException;
use Throwable;

// cPanel Cron Job tarafindan periyodik olarak tetiklenir (ör. her 15 dakikada bir)
// Sonsuz donguye/sleep()'e ihtiyac duymaz: her tetiklemede tarar, karar verir, islem yapar ve kapanir
final class AutoTradeController
{
    // Binance'in spot piyasalarda genel olarak kabul ettigi asgari islem tutari (USDT)
    private const MIN_ORDER_BUDGET_USDT = 5.0;

    // Binance islem ucreti genelde alinan coin uzerinden kesilir; koruma emrinde executedQty'nin
    // tamamini degil, kucuk bir ucret payi dusulmus guvenli bir miktari kullanarak "yetersiz bakiye" riskini onler
    private const FEE_SAFETY_MARGIN = 0.999;

    // BTC'nin son 24 saatte bu yuzdeden fazla dustugu durumlarda yeni alim ACILMAZ - bir altcoin'in
    // kendi skoru ne kadar iyi gorunurse gorunsun, BTC'nin cektigi genel bir dususte "iyi" bir sinyal
    // bile yanlis cikabilir (cogu altcoin BTC ile yuksek korelasyonlu hareket eder)
    private const BTC_DOWNTREND_THRESHOLD_PERCENT = -3.0;

    // RSI (14 periyot, 1 saatlik) bu esigin USTUNDEYSE coin "asiri alinmis" sayilir ve alim
    // ACILMAZ - AI skoru ne kadar iyi olursa olsun, gercek fiyat hareketine dayali bu ikinci
    // teknik dogrulama AI'nin tek karar verici olmasini engeller. 15 Temmuz'da (islem sikligi
    // dusuk bulunup RiskProfileService esikleri + MAX_CANDIDATES_PER_RUN ile BIRLIKTE) 70'ten
    // 75'e gevsetildi - ETHUSDT gibi coinlerin saatlerce RSI 71-75 araliginda tikanip
    // reddedilmesi gozlemlendi, bu bant artik gecerli sayiliyor
    private const RSI_OVERBOUGHT_THRESHOLD = 75.0;

    // 24 Temmuz'da eklendi: fiyat 24 saatlik zirvenin bu yuzdesine (veya ustune) ulasmissa coin
    // "zirveye yapismis" sayilir ve AI skoru ne kadar yuksek olursa olsun alim ACILMAZ - eskiden
    // bu veri (position_percent_24h) sadece GPT'ye baglam olarak gidiyordu, sert bir KAPI degildi.
    // Kontrol RiskManagerService::isNear24hHigh() SAF fonksiyonuyla yapilir - BacktestService de
    // AYNI metodu cagirarak gecmis veride birebir tutarli simulasyon yapabilir.
    // 98.0'dan 99.0'a GEVSETILDI (24 Temmuz, ayni gun): canli veride dogrulandi - bu filtre +
    // zaten var olan Pullback Kalkani/Hacim trendi/RSI/MTF zinciriyle BIRLESINCE, deploy sonrasi
    // 4+ saat ZERO alim yapildi (bir onceki gun 47 islemlik bir tempoya karsi). Son 2 saatteki
    // 454 reddin %36.3'u (165) TEK BASINA bu filtreydi - normal bir yukselis gununde bircok coin
    // zaten gunun buyuk bolumunu kendi 24s zirvesinin %2'si icinde geciriyor, bu "tepede" degil
    // "guclu trendde" demek. YON ONEMLI: isNear24hHigh() ">= threshold ise REDDET" mantigiyla
    // calisir, o yuzden gevsetmek esigi YUKSELTMEK (98->99) anlamina gelir, DUSURMEK degil - deger
    // ne kadar yuksekse zirveye o kadar YAPISIK olmasi gerekir ki reddedilsin. %1'lik pay, gercek
    // "zirveye yapisik" durumlari yine yakalarken bu asiri-red durumunu gidermesi beklenen bir orta nokta
    private const NEAR_24H_HIGH_THRESHOLD_PERCENT = 99.0;

    // --- Anti-FOMO / Geri Çekilme (Pullback) Kalkanı ---
    // 20 Temmuz'da bilincli olarak eklendi: mevcut RSI_OVERBOUGHT_THRESHOLD SADECE 1 saatlik
    // (daha yavas/genis) RSI'i sert filtreler - AI skoru onayi genelde tam da KISA VADELI (15dk)
    // zirvede geldigi icin, bot piyasa emriyle ANINDA girip hemen ardindan bir duzeltmeye
    // yakalanabiliyordu ("tepeden alma"). Bu, TechnicalScoreEngine'in ZATEN hesapladigi 15dk
    // RSI'i (eskiden SADECE skoru etkileyen yumusak bir katmandi) sert bir KAPI'ya cevirir -
    // AI skoru ne kadar yuksek olursa olsun, 15dk RSI bu esigin USTUNDEYSE alim KESINLIKLE yapilmaz
    private const PULLBACK_RSI_OVERBOUGHT_THRESHOLD = 70.0;

    // --- Deterministik Motor (Gölge Mod) ---
    // 26 Temmuz'da eklendi: GPT/AI Karar Skoru'nun kendi icinde iyi kalibre olmadigi (80+ bandinin
    // 70-79'dan NET olarak daha kotu ciktigi, calculateScoreBandBreakdown ile canli veride tespit
    // edildi) gerekcesiyle, TechnicalScoreEngine'in tamamen deterministik (RSI/MACD/hacim) sinyalini
    // ALTERNATIF bir karar motoru olarak sunar. Esikler KEYFI DEGIL - check_deterministic_motor_net.php
    // ile 171 gercek kapanmis islemin GERCEK (komisyon dusulmus) net kar/zararina karsi test edildi:
    // bu uc kosulu birlikte gecen 42 islem ~basabas (+0.04 USDT) cikarken, gecemeyen 125 islem
    // platformun toplam net zararinin (-11.06 USDT) neredeyse tamamini olusturuyordu - yani motor
    // "daha cok kazandirmiyor", GENIS capli tekrarlayan zararlari eliyor. Panelden 'decision_motor'
    // ayari 'ai' (varsayilan, davranis DEGISMEZ) veya 'deterministic' secilebilir - AI, deterministic
    // seciliyken bile HER ZAMAN calismaya devam eder (Golge Mod: SentimentService cagrisi zaten
    // yukarida, aday listesi olusturulurken yapiliyor, bu motor sadece hangi skorun ASIL alim
    // kararini verdigini degistirir, GPT cagrisini KISMAZ/DURDURMAZ)
    private const DETERMINISTIC_MOTOR_MIN_SCORE = 70;
    private const DETERMINISTIC_MOTOR_MIN_VOLUME_DELTA = 1.0;

    // 27 Temmuz'da eklendi: Ardışık Çift Onay'ın teyit turunda skor İLK turdan DÜŞMÜŞ VE hâlâ
    // asgari eşiğe (DETERMINISTIC_MOTOR_MIN_SCORE) yakınsa (bu marj kadar üstünde) alım atlanır -
    // ZECUSDT #189 (27 Temmuz canlı zarar) ilk turda skor 95, teyit turunda 70'e (tam eşikte) düşmüştü,
    // yine de "2 tur geçti" diye alındı. SADECE "düşüyor" şartı YETERSİZ - hacim_delta'nın 27 Temmuz
    // sabahı yasadigi ayni "dogal salinim" sorununu (PENGUUSDT/PEPEUSDT/ZAMAUSDT) tekrar yaratmamak
    // icin marjin ustundeki (rahat gecen) skorlarda kucuk bir gerileme YOK SAYILIR, sadece esige
    // YASLANMIS VE gerileyen bir teyit reddedilir
    private const PENDING_SIGNAL_WEAK_CONFIRM_MAX_SCORE = 80;

    // Limit alis fiyati, sinyal fiyatinin bu yuzde ALTINA konur - "tepeden alma" riskini azaltir
    // (yesil mumda degil, ilk kucuk geri cekilmede dolar). 20 Temmuz'da 0.5'ten 0.15'e, 27 Temmuz
    // sabahi 0.15'ten 0.08'e dusuruldu (o zamanki mekanizma AKTIF BEKLEME/polling'di). 27 Temmuz
    // AKŞAMI mekanizmanin KENDISI degisti: artik aktif beklemiyoruz, GERCEK bir LIMIT ALIS emri
    // konup Binance'in kendi order book'unda dogal zamaninda dolmasi bekleniyor (bkz.
    // huntForAllUsers() -> PendingLimitOrder) - bu yuzden PULLBACK_WAIT_SECONDS/POLL_INTERVAL
    // artik YOK (aktif poll'a gerek kalmadi), sadece PENDING_LIMIT_ORDER_TIMEOUT_MINUTES var.
    // 25 Temmuz'da (BANKUSDT #131) pullback sartini TAMAMEN atlayan bir "Kacis Supabi" kaldirilmisti -
    // o karar burada da BOZULMADI, limit emri hala gercek bir geri cekilme sart kosuyor, sadece
    // bunu aktif bekleyerek degil, borsanin kendi mekanizmasiyla saglıyor
    private const PULLBACK_TARGET_PERCENT = 0.08;

    // Bir kullanicinin bekleyen limit alis emri bu sureyi asip HALA dolmadiysa iptal edilir (bkz.
    // checkPendingLimitOrders(), Fast Tracker'dan 1dk'da bir cagrilir). Cok kisa olursa eski aktif-
    // bekleme sorununu (yetersiz sure) tekrar yaratir, cok uzun olursa piyasa kosullari degismisken
    // (ör. BTC ani dususe gecmisken) eski bir sinyalin gec doldurulmasi riskini artirir - 15 dk,
    // Ardisik Cift Onay'in kendi turleri (~1-2 dk araliklarla) ile kiyaslandiginda "birkac tur daha
    // bekle" dengesini kurar
    private const PENDING_LIMIT_ORDER_TIMEOUT_MINUTES = 15;

    // 31 Temmuz'da eklendi: bir bekleyen limit alis emri hic dolmadan iptal edilirse (kullanicinin
    // Binance uzerinden ELLE iptali DAHIL - checkPendingLimitOrders() bunu her Fast Tracker turunde
    // ayrica tespit eder) bu sembole KISA bir soguma uygulanir. Eskiden hicbir soguma yoktu: kullanici
    // bakiyesi kilitlendigi icin emri elle iptal ediyor, ama coin hala AI Avci'nin giris sartlarini
    // karsiliyorsa bir sonraki tarama turunde AYNI pariteye tekrar emir konuyor, kullanici "iptal
    // ediyorum tekrar atiyor" dongusune giriyordu. Zarar Kes'in 24 saatlik sogumasindan (gercek bir
    // zarar sonrasi) BILEREK cok daha kisa - burada henuz gerceklesmis bir islem/zarar yok, sadece
    // giris denemesi yarim kaldi
    private const PENDING_LIMIT_ORDER_CANCEL_COOLDOWN_HOURS = 1;

    // 31 Temmuz'da eklendi: musteri talebi - Izleyen Stop/Kademeli Kar Alma'nin otomatik satis
    // mantigina HICBIR DOKUNUS olmadan, pozisyon karina gore KADEMELI olarak MUSTERIYE bilgi amacli
    // Telegram bildirimleri gonderilir ("isterse kendisi Simdi Kapat butonuyla manuel cikar"
    // senaryosu, v1.76.0). Ilk esik START_PERCENT'te (+%1), sonrasi her STEP_PERCENT'te bir (+%2,
    // +%3, ...) tekrarlanir - fiyat iki cron turu arasinda birkac esigi birden atlarsa (ör. %0.5'ten
    // %4'e siçrarsa) ARA esikler icin ayri ayri bildirim gonderilmez, sadece GUNCEL yuzde ile TEK
    // bir bildirim gider (bkz. checkRiseAlert - rise_alert_last_percent HER ZAMAN o anki tam yuzdeye
    // atlanir, kacirilan ara kademeler geriye donuk doldurulmaz)
    private const RISE_ALERT_START_PERCENT = 1;
    private const RISE_ALERT_STEP_PERCENT = 1;

    // 31 Temmuz'da eklendi (Volkan #243 BANKUSDT canli olayi): koruma emri (OCO/tekil Zarar Kes)
    // hic girilememis bir pozisyon icin admin+musteriye kac saatte bir TEKRAR uyari gonderilir -
    // bkz. alertIfUnprotected() yorumu. Cok kisa olursa spam olur, cok uzun olursa gunlerce fark
    // edilmeme riski (yasanan olayin ta kendisi) geri doner
    private const UNPROTECTED_ALERT_REPEAT_HOURS = 6;

    // "Pusu" (ambush) kurtarma: GPT skoru global baraji GECEMEYEN ama en fazla bu kadar puan
    // altinda kalan (ör. baraj 70 ise, 55-70 arasi) TEK bir aday icin, 5dk/15dk grafikte net bir
    // "RSI dipten donus + MACD erken AL" sinyali varsa bagimsiz 2. onay kapisindan gecirilir.
    // Barajdan bunun UZERINDE uzak olan hicbir aday bu yolla ASLA secilmez
    private const AMBUSH_NEAR_MISS_BAND = 15;

    // Pusu onaylandiginda GPT skoruna eklenen telafi puani - AMBUSH_NEAR_MISS_BAND'dan BUYUK
    // olmasi, bant icindeki HERHANGI bir adayin (en kotu ihtimalle baraj-15 puanda olsa bile)
    // onay sonrasi barajin USTUNE cikmasini garanti eder
    private const AMBUSH_SCORE_BONUS = 20;

    // Ardisik Binance Klines cagrilari (calculateMacroTrend) arasindaki ufak bekleme - tarama
    // kapasitesi 10'dan 25'e cikarilinca istekler ayni saniyede yigilip rate limit riski artiyordu
    private const KLINES_REQUEST_DELAY_MICROSECONDS = 100_000; // 0.1 saniye

    // "Sarjor Optimizasyonu": bir turda AI esigini (globalMinThreshold) gecen adaylardan en
    // fazla bu kadari sirayla denenir (hepsi degil) - boylece piyasa cok sayida guclu sinyal
    // verse bile tek turda sinirsiz Binance/RiskManager API cagrisi birikmez (set_time_limit(180)
    // icinde kalinir). Her aday yine de KENDI RSI/hacim/MTF/tahta filtresinden VE
    // huntForAllUsers()'in per-user kontrollerinden (bakiye, maks pozisyon, devre kesici,
    // soguma) TAZE olarak gecmek zorundadir - bu sinir sadece "kac aday denensin" ust sinaridir,
    // guvenlik kontrollerinin YERINE gecmez. 15 Temmuz'da (islem sikligi dusuk bulunup) 5'ten
    // 10'a cikarildi - Agresif profilin maks eszamanli pozisyon sayisinin (5) USTUNDE, cunku
    // artik birden fazla kullanicinin (farkli max_active_trades limitleriyle) AYNI turda
    // doyurulabilmesi hedefleniyor
    private const MAX_CANDIDATES_PER_RUN = 10;

    // 26 Temmuz'da eklendi: MAX_CANDIDATES_PER_RUN=10 sinirinin GERCEK sebebi AI/GPT maliyeti +
    // API cagri yuku degildi - "Sarjor Optimizasyonu" yorumunda da yaziyor, asil sebep
    // set_time_limit(180) icinde kalmak ve gereksiz Binance/RiskManager cagrisi biriktirmemek.
    // Ama globalMinThreshold (AI skor esigi) ile 10'a indirme adimi ozellikle AI'nin kendi
    // elemesiydi - decision_motor='deterministic' iken bu eleme hic uygulanmiyor (bkz. asagidaki
    // kullanim), TechnicalScoreEngine'in maliyeti (sadece Binance klines, GPT YOK) cok daha dusuk
    // oldugu icin motor DYNAMIC_POOL_SIZE'e (50) kadar tüm havuzu degerlendirebilir - "tum Binance"
    // degil, zaten hacme gore on-filtrelenmis ayni 50'lik havuz, sadece AI'nin ust siniri kaldirilir
    private const DETERMINISTIC_MAX_CANDIDATES_PER_RUN = 50;

    // --- Dinamik Kaçış Protokolü ---
    // Acik pozisyonun AI skoru bu esigin ALTINA duserse ("kritik cokus"), bot Zarar Kes'i beklemez -
    // OCO'yu iptal edip pozisyonu ANINDA piyasa fiyatindan (MARKET) kapatir. Giris anindaki skorla
    // (ör. 75) AYNI olcekte (1-100) oldugu icin dogrudan karsilastirilabilir
    // 26 Temmuz'da 30'dan 15'e dusuruldu: gercek islem gecmisi raporunda (133 kapanan islem) bu
    // mekanizma SADECE 8 kez (%6) tetiklenmisti - ana zarar kaynagi degildi, TAMAMEN kaldirmak
    // (kullanicinin ilk talebi) gercek bir guvenlik agini bosuna kaldirirdi. Esik dusurulerek daha
    // SEYREK/SADECE gercekten cokmus (skor 15 alti - "kritik cokus"un cokusu) durumlarda tetiklenir,
    // orta yol tercih edildi - tamamen kaldirmak yerine daha az agresif hale getirildi
    private const EARLY_EXIT_AI_SCORE_THRESHOLD = 15;

    // Acik pozisyonlarin AI skorunu YENIDEN kontrol etmek her seferinde YENI bir OpenAI cagrisi
    // (ucretli) gerektirir - ana tarama throttle'iyla (SCAN_INTERVAL_SECONDS) AYNI mantik, ama
    // AYRI bir sayaçla: pozisyon izleme, yeni aday taramasindan BAGIMSIZ bir sikilikta calisabilmeli
    private const POSITION_MONITOR_INTERVAL_SECONDS = 300;
    private const POSITION_MONITOR_SETTING_KEY = 'spot_position_monitor_last_run';

    // 27 Temmuz'da eklendi: barındırma sağlayıcısının veri merkezi DDoS koruması nedeniyle
    // Binance'e bağlanılamadığında (bkz. BinanceApiTimeoutException) mutabakat sessizce log'a
    // yazıp geçiyordu - kullanıcı saatlerce fark etmiyordu, elle terminal kontrolüyle bulundu.
    // KOD BU KESİNTİYİ ÇÖZEMEZ (altyapı sorunu) ama artık GÖRÜNÜR hale getirilir: kesinti bu
    // eşiği (dakika) aşarsa admin'e TEK BİR kritik Telegram uyarısı gider (streak boyunca
    // tekrarlanmaz - spam etmez), bağlantı düzelince de "düzeldi" mesajı ayrıca gönderilir
    private const BINANCE_CONNECTIVITY_ALERT_THRESHOLD_MINUTES = 5;
    private const BINANCE_CONNECTIVITY_FIRST_FAILURE_SETTING_KEY = 'binance_connectivity_first_failure_at';
    private const BINANCE_CONNECTIVITY_ALERT_SENT_SETTING_KEY = 'binance_connectivity_alert_sent';

    // Izleyen Stop (Sabit Orana Bagli Aktivasyon): esik BILINCLI olarak kullanicinin sectigi
    // take_profit_percent'ten TAMAMEN BAGIMSIZDIR - TP %6 da olsa %20 de olsa zirh AYNI
    // tetik/kilit noktasinda uyanir, cunku amac "TP'ye ulasana kadar bekle" degil, kucuk bir kar
    // an ELE GECER GECMEZ pozisyonu breakeven'in hemen ustune cekmektir. Tetik/kilit/izleme
    // yuzdeleri artik SABIT DEGIL - 20 Temmuz'dan itibaren ApiKey::getTrailingSettings() ile
    // kullanici bazli DB'den okunur (bkz. applyTrailingStopIfEligible()). Tek asama var
    // (eskiden 2 asamaliydi); esik gecilir gecilmez applyContinuousTrailing() devralir. Bu kontrol
    // AI skoruna bagli DEGILDIR, sadece anlik fiyati kontrol eder (OpenAI maliyeti yok) - HER cron
    // turunda calisabilir

    // Zarar Kes, mevcut seviyesine gore en az bu kadar (yuzde puani) iyilesmedikce OCO YENIDEN
    // KURULMAZ - aksi halde fiyat her ufak tirmandiginda (ör. cron her calistiginda) gereksiz
    // iptal/yeniden-kur dongusu (ekstra Binance agirligi + kisa sureli korumasizlik penceresi)
    // olusurdu. En yuksek fiyat (highest_price_seen) yine de HER turda guncellenir/kaydedilir,
    // sadece OCO degisikligi bu esige ulasilana kadar inanilmaz sik tekrarlanmaz
    private const TRAILING_STOP_MIN_IMPROVEMENT_PERCENT = 0.3;

    // --- Ani Fitil (Wick) Koruması ---
    // 23 Temmuz'da eklendi: LISTAUSDT/BANKUSDT/ZAMAUSDT gibi pek çok pozisyon, açılıştan sadece
    // 1.5-13.9 dakika sonra "Ani Volatilite/iğne" ile Zarar Kes'e çarpıp kapanıyordu - gerçek bir
    // trend dönüşü değil, anlık bir fiyat sıçramasıydı (bkz. loss_reason). İlk OCO artık kullanıcının
    // GERÇEK Zarar Kes'inden HER ZAMAN daha geniş bir "Geniş Kalkan" ile kuruluyor (max() ile asgari
    // genişlik garanti edilir, asla kullanıcının ayarından DAR olmaz), WICK_SHIELD_MINUTES sonra
    // reconcileActiveTrades() içindeki tightenStopLossIfEligible() bunu asıl hedefe sıkılaştırır -
    // TABİİ Kİ o ana kadar İzleyen Stop veya Kısmi Kâr Alma zaten SL'e dokunmamışsa (bkz. o metodun
    // yorumu - iki mekanizmanın SL üzerinde çakışmaması için bilinçli bir öncelik sırası var)
    private const WICK_SHIELD_MULTIPLIER = 2.0;
    private const WICK_SHIELD_MIN_PERCENT = 3.0;
    // 24 Temmuz'da 15'ten 3'e, sonra AYNI GÜN canlı kanıtla 7'ye çekildi: 3 dakika COK agresif
    // cikti - ayni gun icinde RIFUSDT (10.2 dk) ve BANKUSDT (3.6 dk) "Ani Volatilite" ile zarar
    // kes'e carpti, BANKUSDT vakasi ESKI 15 dakikalik surede bile korunurdu. 7 dakika, orijinal
    // 15'in yarisindan azini koruyarak (selale/cokus senaryosunda genis kalkanda asiri uzun kalma
    // riskini hala azaltir) BANKUSDT/ZAMAUSDT (5.7 dk) gibi COGUNLUKLA rastlanan igne vakalarini
    // yine kapsar - RIFUSDT gibi 10+ dakikada gelen DAHA NADIR igneler hala korumasiz kalir, bu
    // bilinen/kabul edilmis bir odun
    private const WICK_SHIELD_MINUTES = 7;

    // 29 Temmuz'da eklendi: OCO "relationship of the prices" hatasıyla reddedildiğinde (COTIUSDT
    // canlı olayı - fiyat alım ile OCO gönderimi arasında bandın dışına çıkmıştı) çalışan Acil Durum
    // Protokolü'nün "şelale" eşiği - bkz. protectPositionWithOco() yorumu. Guncel fiyat, Zarar Kes
    // seviyesinin bu marj kadar DAHA da altındaysa bu bir igne degil gercek bir sert dususdur,
    // ANINDA piyasadan satilir. Marj kasitli var: projedeki "Ani Fitil Korumasi" felsefesiyle
    // (WICK_SHIELD_MULTIPLIER) tutarli olsun diye - SL'e degen HER fiyat aninda panik satisi
    // tetiklemez, sadece gercekten onu da asan bir dusus tetikler
    private const EMERGENCY_WATERFALL_MARGIN_PERCENT = 1.0;

    // --- ATR Bazlı Volatilite Çarpanı ---
    // 25 Temmuz'da eklendi: SADECE İzleyen Stop'un "Sınırsız İzleme" (continuous trailing) mesafesini
    // (trailing_distance_percent) piyasanın anlık volatilitesine göre esnetir - kullanıcının kendi
    // ayarını EZMEZ, ona bir ÇARPAN uygular. Wick Koruması (yukarıdaki WICK_SHIELD_*) ve Kademeli Kâr
    // Alma/breakeven mantığına KASITLI OLARAK dokunulmaz (bkz. görev talebi) - aksi halde ayrı ayrı
    // ayarlanmış/kanıtlanmış mekanizmalar aynı anda değişip bir sorun çıktığında hangisinin sebep
    // olduğu ayırt edilemezdi. MarketScanner::calculateAtr() SAF/stateless degil (Binance'ten canli
    // kline ceker) ama DB'ye yazmaz, hata durumunda null doner (fail-open - ATR alinamazsa carpan
    // uygulanmadan kullanicinin sabit mesafesi degismeden kullanilir)
    //
    // Referans (notr) ATR yuzdesi: kripto majorlerinde tipik 1 saatlik ATR araligi ~%0.5-1.0 - bu
    // deger "ortalama" kabul edilip carpan=1.0 (degisiklik yok) buna karsilik gelir. Gercek veriyle
    // hicbir zaman ayarlanmadi (bilincli - RSI/WICK_SHIELD gibi olay-kanitli degil, ilk tahmin), ileride
    // gercek sonuclarla ayarlanmasi gerekebilir
    private const ATR_PERIOD = 14;
    private const ATR_REFERENCE_PERCENT = 0.8;

    // Carpan bu araligin disina asla cikmaz - ATR verisi gecici olarak anormal (ör. bir haber
    // spike'i) donerse mesafenin sifira cokup pozisyonu aninda kapatmasini veya asiri genisleyip
    // korumasiz kalmasini onler
    private const ATR_MULTIPLIER_MIN = 0.5;
    private const ATR_MULTIPLIER_MAX = 2.0;

    // --- Kademeli Kâr Alma (Partial Take Profit) ---
    // Pozisyon giristen bu kadar yukaridayken (TRAILING_STOP_STAGES'ten BAGIMSIZ, ayri bir esik)
    // miktarin PARTIAL_TAKE_PROFIT_SELL_RATIO kadari MARKET satilip gercek kar cebe indirilir.
    // 16 Temmuz'da bilincli bir tercih olarak eklendi: mevcut İzleyen Zirh SADECE Zarar Kes'i
    // yukari ceker, hicbir zaman GERCEKTEN kar realize etmez - fiyat zirveden Zarar Kes'e geri
    // donerse (ki oynak bir piyasada sik olur) TUM pozisyon o ana kadar kaybedilir. Bu, riski
    // artiran degil AZALTAN bir degisiklik (pozisyonun bir kismini erken güvenceye alir)
    // 25 Temmuz'da düşürüldü (canlı BANKUSDT #131 olayı): pozisyon %1.99'a kadar çıkıp (TP
    // mesafesinin %50'si) İzleyen Stop'un %2.0 tetiğini KIL PAYI kaçırdı, sonra tersine dönüp
    // sıkılaştırılmış Zarar Kes'e çarpıp zararla kapandı - eski %3.0 eşiği bu profildeki
    // hareketleri hiç yakalamıyordu. %1.8'e çekilip satış oranı %50'den %35'e düşürüldü: erken
    // ve KÜÇÜK bir dilim güvenceye alınır, kalan %65 (100.0 hedefe kadar) İzleyen Zırh'ın gerçek
    // zaman kar kilitleme mantığıyla sürmeye devam eder - amaç "daha erken ama daha küçük" almak
    private const PARTIAL_TAKE_PROFIT_TRIGGER_PERCENT = 1.8;
    private const PARTIAL_TAKE_PROFIT_SELL_RATIO = 0.35;

    // Kismi satistan SONRA kalan yarinin OCO'sunda Kar Al bacagi ARTIK pratik bir hedef degil -
    // Binance OCO iki bacak da zorunlu kildigi icin, gercekci olarak asla ulasilmayacak kadar
    // uzak (giris + %100) bir "guvenlik agi" tavani konur. Gercek "trend bitene kadar sur"
    // davranisi zaten var olan applyContinuousTrailing()'in Zarar Kes yukseltme mekanizmasindan gelir
    private const PARTIAL_TAKE_PROFIT_RUNNER_TARGET_PERCENT = 100.0;

    // --- Çeşitlendirme (Korelasyon) Filtresi ---
    // Bir kullanicinin ZATEN acik olan pozisyonlarindan herhangi biriyle bu adayin son
    // CORRELATION_LOOKBACK_HOURS saatlik GETIRI serisi arasindaki Pearson korelasyonu bu esigi
    // (veya UZERINI) gecerse alim REDDEDILIR - amac "5 eszamanli pozisyon acabiliyoruz ama hepsi
    // ayni yonde hareket eden coinlerse gercek cesitlendirme saglanmiyor" riskini azaltmak. 16
    // Temmuz'da bilincli bir tercih olarak eklendi - riski AZALTAN bir kisitlama (yeni bir alim
    // firsatini engelleyebilir, ama asla mevcut bir pozisyonu etkilemez/kapatmaz)
    private const CORRELATION_LOOKBACK_HOURS = 48;
    private const CORRELATION_REJECT_THRESHOLD = 0.85;

    // --- BTC Bağımsızlık İstisnası ---
    // 26 Temmuz'da eklendi: asagidaki BTC_DOWNTREND_THRESHOLD_PERCENT filtresi BTC dusustayken
    // TUM adaylari (skoru ne olursa olsun) reddediyordu - ama bazi coinler BTC'den GERCEKTEN
    // BAGIMSIZ/TERS hareket edebiliyor. calculatePriceCorrelation() (Cesitlendirme Filtresi'nde
    // ZATEN var olan, PAYLASILAN Pearson korelasyon fonksiyonu) BTCUSDT'ye karsi da calistirilir -
    // korelasyon bu esigin ALTINDAYSA (yani coin BTC'den bagimsiz/ters hareket ediyorsa) o aday
    // BTC dususu filtresinden MUAF tutulur. ONEMLI: bu, gecmis islem verisiyle DOGRULANAMADI (BTC
    // dususu sirasinda simdiye kadar HICBIR alim denenmedi, yani "dusuk korelasyonlu coin BTC
    // duserken de kazandirir mi" sorusuna dair hic veri yok) - bilincli olarak MUHAFAZAKAR (0.0,
    // yani SIFIR veya NEGATIF korelasyon sart, "az korelasyonlu" yetmez) baslatildi, ayrica
    // loglanir ki ileride ayri izlenebilsin
    private const BTC_INDEPENDENCE_CORRELATION_THRESHOLD = 0.0;

    // --- Dinamik Zırh (Duyuru Avcısı'na ozel agresif izleyen stop) ---
    // ListingSniperService sabit %20 Kar Al / %2 Zarar Kes ile acar - normal AI Avci pozisyonlarindan
    // (TRAILING_STOP_STAGES) COK DAHA erken ve COK DAHA siki kar kilitler, cunku yeni listelenen bir
    // coinin ilk dakikalardaki sicramasi genelde en oynak (ve en cabuk geri donen) andir. Tek asama:
    // +%10 karda Zarar Kes +%5 kar noktasina cekilir - bu asamadan sonra applyContinuousTrailing()
    // devreye girer (ayni ortak kod yolu, sadece SNIPER_TRAILING_STOP_DISTANCE_PERCENT ile)
    private const SNIPER_TRAILING_STOP_STAGES = [
        1 => ['trigger_percent' => 10.0, 'lock_percent' => 5.0],
    ];

    // Asama 1 (+%10 -> +%5) kilitlendikten SONRA, fiyat yukselmeye devam ettikce Zarar Kes'i en
    // yuksek gorulen fiyatin bu kadar ALTINDA tutar - normal pozisyonlarin %2'lik izleme mesafesiyle
    // AYNI, ama cok daha erken (Asama 2 beklenmeden) devreye girdigi icin sniper pozisyonlari
    // Kar Al'a (%20) varmadan cok daha once "sinirsiz izleme"ye gecmis olur
    private const SNIPER_TRAILING_STOP_DISTANCE_PERCENT = 2.0;

    // 27 Temmuz'da eklendi: normal (sniper olmayan) pozisyonlarda eskiden TEK asama vardi (kullanicinin
    // DB'deki trailing_trigger_percent/trailing_lock_percent'i) - o asama kilitlendikten SONRA dogrudan
    // Sinirsiz Izleme'ye geciliyordu. Canli veri analizi (27 Temmuz, 23 kapanan islemin %70'i pozisyon
    // kapandiktan SONRAKI 12 saat icinde %2.2'yi asip bazilari %30-44'e kadar gitmisti) TEK asamanin
    // yetersiz oldugunu gosterdi: coin hedefe (%5 TP) ulasamasa bile SADECE ilk (dusuk) asamada
    // kilitlenip kaliyordu. Bu iki EK asama (kullanicinin DB'deki 1. asamasindan SONRA, array'e
    // eklenerek) kademeli olarak DAHA YUKSEK kar kilitler - coin ne kadar ileri giderse o kadar
    // fazlasi korunur, TEK sabit mesafeyle (ya hep dar ya hep genis) mumkun olmayan bir denge saglar
    private const NORMAL_TRAILING_STOP_STAGE_2 = ['trigger_percent' => 4.0, 'lock_percent' => 2.5];
    private const NORMAL_TRAILING_STOP_STAGE_3 = ['trigger_percent' => 6.0, 'lock_percent' => 4.0];

    // Sembol bazli soguma (kara liste): bir pozisyon Zarar Kes veya Dinamik Erken Kaçış ile
    // kapandiginda, o kullanici icin o SPESIFIK sembol bu kadar saat "yasakli" olur - AI skoru
    // ne kadar hizli toparlanirsa toparlansin ("intikam islemi" / revenge trading riski). Devre
    // kesiciden FARKLI: SADECE bu (kullanici, sembol) ciftini etkiler, botun geri kalanini durdurmaz
    private const SYMBOL_COOLDOWN_HOURS = 12;

    // Zarar Kes (SL) ile kapanan pozisyonlara OZEL, daha UZUN soguma: canli ortamda SXTUSDT gibi
    // dar bir fiyat bandinda sikisan coinlerin, standart 12 saatlik soguma bitince tarama tarafindan
    // TEKRAR en yuksek skorlu aday secilip ayni bantta arka arkaya SL'e carpmasi (komisyon +
    // tekrarlanan zarar) gozlemlendi. Dinamik Erken Kacis (SYMBOL_COOLDOWN_HOURS=12, zaten AI
    // skoru cokerken ERKEN cikip zarari sinirlayan daha "yumusak" bir mekanizma) BUNDAN ETKILENMEZ,
    // sadece gercek Zarar Kes tetiklenmesi bu daha uzun sureyi kullanir
    private const SYMBOL_COOLDOWN_STOP_LOSS_HOURS = 24;

    // Kanitlanmis Kazanan Istisnasi: bir sembol bu kullanici icin tum-zamanlar NET KARLIYSA (ve
    // tek sansli bir kazancin yanilgisina dusmemek icin en az bu kadar KAPANMIS islemi varsa),
    // yukaridaki iki soguma suresi TAMAMEN atlanmaz ama bu oranla KISALTILIR - "bu coin gecmiste
    // gercekten kazandirdi, son olay muhtemelen bir istisna" varsayimiyla daha kisa bir bekleme
    // yeterli sayilir. SIFIRA indirilmez (o an tetikleyen olay - SL/erken cikis - hala gercek bir
    // sinyal, gecmis performans onu tamamen gecersiz kilmaz)
    private const PROVEN_WINNER_MIN_TRADES = 3;
    private const PROVEN_WINNER_COOLDOWN_MULTIPLIER = 0.25;

    // Bir soguma tetiklenmeden HEMEN once cagrilir: sembolun bu kullanicidaki gecmisi "kanitlanmis
    // kazanan" esigini geciyorsa kisaltilmis, gecmiyorsa TAM sureyi doner - SymbolCooldown::setCooldown()
    // her iki cagri noktasinda (Zarar Kes + Dinamik Erken Kacis) AYNI bu fonksiyondan gecer
    private function resolveSymbolCooldownHours(int $userId, string $pair, int $baseHours): int
    {
        $performance = Order::calculateSymbolPerformance($userId, $pair);

        if ($performance['total_trades'] >= self::PROVEN_WINNER_MIN_TRADES && $performance['net_profit'] > 0) {
            return max(1, (int) round($baseHours * self::PROVEN_WINNER_COOLDOWN_MULTIPLIER));
        }

        return $baseHours;
    }

    // Coklu Zaman Dilimi (MTF) Trend Filtresi: 4 saatlik grafikte fiyat EMA200'un ALTINDAYSA
    // ("ana trend dususte"), kisa vadeli (15m) bir hacim patlamasi "Olu Kedi Sicramasi" (dead cat
    // bounce) tuzagi sayilir - AI skoru ne kadar yuksek olursa olsun (75+ bile) alim REDDEDILIR
    private const MTF_EMA_PERIOD = 200;
    private const MTF_TREND_INTERVAL = '4h';

    // Emir Defteri (Order Book) Duvar Analizi: mevcut fiyatin bu yuzde kadar UZERINDEKI SATIS
    // hacmi, AYNI oranda ALTINDAKI ALIS hacminin bu kat sayisindan fazlaysa "Satis Duvari"
    // tespit edilmis sayilir ve alim REDDEDILIR - fiyatin bu seviyeye carpip geri donme riski yuksektir
    private const ORDER_BOOK_WALL_PERCENT = 3.0;
    private const ORDER_BOOK_WALL_RATIO = 3.0;

    // Acik pozisyon mutabakati (reconcileActiveTrades) HER cron turunda calisir - ucuz, GPT
    // gerektirmez. Asil AGIR islem olan tarama (Binance Klines + 25 adaylik OpenAI puanlamasi) ise
    // cron ne kadar sik tetiklenirse tetiklensin bu araliktan daha sik calismaz. 21 Temmuz'da
    // "agresif avci" talebiyle 300sn'den 60sn'ye dusuruldu. cPanel Cron Job'i BILINCLI olarak TAM
    // BU ARALIKLA (1 dakika, */1) eslesecek sekilde AYRICA guncellenmelidir (kod disi, elle yapilan
    // bir adim) - bu, OpenAI cagri sikligini/maliyetini ~5 kat artirir
    private const SCAN_INTERVAL_SECONDS = 60;
    private const LAST_SCAN_SETTING_KEY = 'spot_last_scan_at';

    // --- Ardışık Çift Onay (Double Scan Approval) ---
    // 20 Temmuz'da eklendi - "Erken Panik/Hatalı Giriş" sorununu azaltmak icin: bir sembol tum agir
    // teknik filtrelerden (RSI/Hacim/MTF/Tahta) gecse bile ILK gorulduğu turda ANINDA alinmaz,
    // pending_signals tablosuna kaydedilir.
    //
    // 21 Temmuz'da ZAMANA DAYALI (first_seen_at + sabit saniye penceresi: once 300, sonra 60, sonra
    // 120) mimariden TUR TABANLI mimariye gecirildi - canli loglarda AYNI semptom UC KEZ tekrar
    // gozlemlendi: CronLock (bkz. CRON_LOCK_NAME) devredeyken bir tarama sabit pencereyi asarsa bir
    // SONRAKI cron tetiklemesi "mesgul" diye ATLANIYOR, gercek tarama araligi 60-185sn arasinda
    // TUTARSIZ cikiyordu - hicbir sabit saniye esigi (60/120/...) kalici bir cozum degildi. Artik
    // "ardisik" tanimi SANIYEYE degil, art arda İKİ BAŞARILI TARAMA TURUNA (PENDING_SIGNAL_REQUIRED_PASSES)
    // dayanir - sunucu/API ne kadar yavaslarsa yavassin kendini otomatik ayarlar, bir daha bu
    // sabiti elle kalibre etmemiz gerekmez. AYNI sembol bir SONRAKI turda herhangi bir sert
    // filtreden GECEMEZSE (trend bozuldu) satir ANINDA silinir (bkz. 6 ret noktasindaki
    // PendingSignal::delete() cagrilari) - "yarim kalmis" bir onay sessizce beklemede kalmaz
    private const PENDING_SIGNAL_REQUIRED_PASSES = 2;

    // first_seen_at ARTIK bir onay tetikleyicisi DEGIL - SADECE bir GUVENLIK AGI (GC): bir sinyal
    // aday havuzundan tamamen dusup (ör. GPT skoru esigin altina indi) bir daha HIC yeniden
    // degerlendirilmezse (ne onaylanir ne de bir filtrede reddedilip silinir), tablo SISMESIN diye
    // bu sureden eski satirlar temizlenir. 15 dakika BILINCLI olarak COK genis tutuldu - artik
    // "ardisik" tanimini bu sayi BELIRLEMIYOR (pass_count belirliyor), sadece cron cokmesi/uzun
    // sure calismama gibi UC durumlara karsi bir tampon
    private const PENDING_SIGNAL_MAX_AGE_SECONDS = 900;

    // --- Cron Kilidi (Overlap Koruması) ---
    // 21 Temmuz'da eklendi: SCAN_INTERVAL_SECONDS 60sn'ye dusunce, bir taramanin (ozellikle
    // MAX_CANDIDATES_PER_RUN kadar adayin sirayla PULLBACK_WAIT_SECONDS bekleyebilecegi
    // dusunulunce) 60sn'yi asip bir sonraki cron istegiyle CAKISMASI (ayni coine cift islem
    // acilmasi dahil) riski dogdu. bkz. CronLock modeli - timeout set_time_limit(180) ile AYNI
    // deger, "PHP zaten bu sureden fazla calisamaz, oyleyse kilit de bu sureden fazla takili
    // KALAMAZ" mantigiyla secildi
    private const CRON_LOCK_NAME = 'auto_trade';
    private const CRON_LOCK_TIMEOUT_SECONDS = 180;

    // Hizli Pozisyon Takipcisi (runFastTracker) icin TAMAMEN BAGIMSIZ kilit - CronLock modeli zaten
    // "her modul kendi lock_name'iyle kilitlenir, biri digerini bloklamaz" seklinde tasarlandigindan
    // (bkz. CronLock yorumu) bu ayri isim ana taramanin (scanTopMovers + OpenAI, dakikalarca surebilir)
    // kilidini asla bekletmez. Timeout, 1 dakikalik cron araligindan KISA tutuldu (50sn) - bir onceki
    // calistirma herhangi bir sebeple takilirsa bir sonraki tetikleme cok uzun beklemeden devam edebilsin
    private const FAST_TRACKER_CRON_LOCK_NAME = 'fast_tracker';
    private const FAST_TRACKER_CRON_LOCK_TIMEOUT_SECONDS = 50;

    // 26 Temmuz'da canli tespit edildi: Fast Tracker (kendi BAGIMSIZ kilidiyle) ve ana cron AYNI
    // ANDA reconcileActiveTrades()'i cagirabiliyor - ikisi de AYNI acik pozisyonlari (ör. ESPUSDT
    // #180/#181) es zamanli Binance'e sorup OCO durumunu isleyebiliyordu, bu da yanlis "ALL_DONE/
    // closed_manual" sonucuna yol acti (pozisyonlar Binance'te GERCEKTEN hala acikti - orijinal OCO
    // hic dokunulmamis, dolmamis haldeydi). CRON_LOCK_NAME/FAST_TRACKER_CRON_LOCK_NAME kasitli
    // BAGIMSIZ birakildi (Fast Tracker'in ana taramanin GPT suresinden etkilenmemesi icin), ama
    // reconcileActiveTradesInternal()'in KENDISI iki cagiran arasinda ATOMIK/karsilikli dislayici
    // olmali - bu YENI, kisa omurlu kilit SADECE bunu saglar. Timeout kisa (30sn) tutuldu - mutabakat
    // GPT icermez, normalde saniyeler icinde biter
    private const RECONCILE_LOCK_NAME = 'active_trades_reconcile';
    private const RECONCILE_LOCK_TIMEOUT_SECONDS = 30;

    // 22 Temmuz'da canli tespit edildi: BANKUSDT/ERAUSDT "hayalet pozisyon" olayi - Binance'te alim
    // GERCEKLESTI ama DB'ye/Dashboard'a/Telegram'a hic yansimadi, storage/logs'a TEK SATIR bile
    // dusmedi. Kok neden yakalanabilir bir Throwable DEGIL: set_time_limit(180) ile calisan bu script
    // (scanTopMovers + her aday icin macro trend + OpenAI + 12sn Pullback bekleme + Binance
    // BUY/OCO cagrilari) paylasimli hostingde Binance'e erisim yavasladiginda (bkz. binance_errors.log
    // 'Connection timed out after 10002 milliseconds' patlamalari) PHP'nin KENDI zaman asimi
    // sinirina (180sn, YAKALANAMAZ - hicbir catch bloguna dusmez) carpma riski tasiyor. Eger bu limit
    // TAM OLARAK bir Binance BUY basarili olduktan SONRA, DB kaydi/OCO/bildirimden ONCE vurursa
    // pozisyon Binance'te acik kalir ama sistem onu hic bilmez. Cozum: geri donusu OLMAYAN bir
    // Binance BUY cagrisi yapmadan HEMEN once kalan sureyi kontrol et, guvenli payin (30sn) altina
    // dustuysek YENI bir alima hic girme - mevcut acik pozisyonlar etkilenmez, bu aday bir sonraki
    // cron turunde tekrar denenir
    private const MAX_SAFE_ELAPSED_SECONDS_BEFORE_BUY = self::CRON_LOCK_TIMEOUT_SECONDS - 30;

    private readonly TelegramService $telegram;
    private readonly RiskManagerService $riskManager;
    private readonly TradePostMortemService $postMortem;
    private float $requestStartedAt = 0.0;

    public function __construct()
    {
        $this->telegram = new TelegramService();
        $this->riskManager = new RiskManagerService();
        $this->postMortem = new TradePostMortemService();
    }

    public function run(): void
    {
        header('Content-Type: application/json');

        $this->requestStartedAt = microtime(true);

        if (!$this->isTokenValid()) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Yetkisiz istek: geçersiz otomasyon token\'ı.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Cron Kilidi: onceki calistirma (Pullback beklemeleri yuzunden) hala devam ediyorsa bu
        // istek ANINDA sonlandirilir - bkz. CronLock modeli/CRON_LOCK_NAME yorumu
        if (!CronLock::acquire(self::CRON_LOCK_NAME, self::CRON_LOCK_TIMEOUT_SECONDS)) {
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'scan_skipped' => true,
                'reason' => 'Önceki işlem hâlâ devam ediyor (kilit aktif) - bu istek anında sonlandırıldı.',
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        try {
            // Tarama kapasitesi 10'dan 25'e cikarilinca, her aday icin ardisik Binance Klines +
            // OpenAI cagrisinin toplam suresi paylasimli hostinglerin varsayilan PHP
            // max_execution_time'ini (genelde 30sn) asabiliyor - cron isteği yarida kesilip
            // taramanin tamamlanmamasina yol acabiliyordu. Bu istek ozelinde ust siniri yukseltir
            set_time_limit(180);

            // Ardışık Çift Onay - Güvenlik Ağı (GC): HER cron turunun basinda (throttle/tarama
            // atlanmasindan BAGIMSIZ) PENDING_SIGNAL_MAX_AGE_SECONDS'tan (15 dk) eski, aday havuzundan
            // tamamen dusup bir daha HIC degerlendirilmemis satirlari siler - ARTIK bir onay
            // tetikleyicisi DEGIL (bkz. PENDING_SIGNAL_REQUIRED_PASSES yorumu), sadece tablo SISMESIN
            // diye. Tablo en fazla MAX_CANDIDATES_PER_RUN kadar satir tuttugu icin (sembol UNIQUE)
            // BotLog'un aksine saatlik throttle GEREKMEZ, ucuz bir DELETE'tir
            PendingSignal::pruneStale(self::PENDING_SIGNAL_MAX_AGE_SECONDS);

            // 1) Once acik pozisyonlarin (OCO) borsadaki guncel durumunu kontrol et (mutabakat) -
            // bu HER turda calisir, throttle'dan ETKILENMEZ (ucuz, GPT gerektirmez)
            $reconciledCount = $this->reconcileActiveTrades();

            // Agir taramayi (Binance Klines + OpenAI) sinirlar - son taramadan bu yana yeterli
            // sure gecmediyse, cron sik tetiklense bile burada sessizce sonlanir
            if (!$this->shouldRunScan()) {
                http_response_code(200);
                echo json_encode([
                    'status' => 'success',
                    'scan_skipped' => true,
                    'reason' => sprintf('Tarama son %d saniye içinde zaten çalıştı (throttle).', self::SCAN_INTERVAL_SECONDS),
                    'reconciled_trades' => $reconciledCount,
                ], JSON_UNESCAPED_UNICODE);

                return;
            }

            Setting::set(self::LAST_SCAN_SETTING_KEY, (string) time());

            // 15 Temmuz'da cron-job.org'un "History" kaydinda tespit edildi: harici tetikleyicinin
            // KENDI istemci-tarafi zaman asimi (ucretsiz planda 30sn, degistirilemiyor) bazen agir
            // taramanin (25 aday x OpenAI) suresini asiyor, istemci "timeout" sayip baglantiyi
            // kesiyordu - reconcileActiveTrades() zaten tamamlanmis olsa bile bir sonraki (OpenAI'li)
            // kisim yarida kalabiliyordu. `fpm_check.php` ile canlida dogrulandi: bu sunucu PHP-FPM
            // DEGIL, LiteSpeed (LSAPI) kullaniyor - o yuzden fastcgi_finish_request() yerine
            // LiteSpeed'in kendi esdegeri litespeed_finish_request() gerekiyor. Hangisi varsa
            // istemciye HEMEN bir yanit gonderilip baglanti kapatilir - script SUNUCU TARAFINDA
            // (set_time_limit(180) icinde) calismaya devam eder, istemci artik beklemedigi icin
            // ASLA zaman asimina ugramaz. Ikisi de yoksa (baska bir SAPI) sessizce atlanir, eski
            // (senkron) davranis aynen korunur - fail-open
            if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) {
                http_response_code(200);
                echo json_encode([
                    'status' => 'accepted',
                    'message' => 'Tarama kabul edildi, sunucu tarafında arka planda devam ediyor.',
                    'reconciled_trades' => $reconciledCount,
                ], JSON_UNESCAPED_UNICODE);

                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } else {
                    litespeed_finish_request();
                }
            }

            // 2) Piyasayi tara, en cok hareketlenen coinleri bul
            $scanner = new MarketScanner();

            // 2a) Flaş Çöküş Koruması: BTC son 1 saatte sert bir çöküş yaşadıysa tarama/OpenAI
            // puanlama/yeni alım TAMAMEN atlanır (hem gereksiz OpenAI maliyeti hem risk önlenir) -
            // mevcut açık pozisyonlar zaten YUKARIDA (reconcileActiveTrades) kendi Zarar Kes/İzleyen
            // Zırh korumasıyla yönetilmeye devam etti, SADECE yeni giriş durduruluyor. Kullanıcı
            // bazlı DEĞİLDİR, DB'ye kilit yazılmaz - BTC toparlanınca bir sonraki turda otomatik açılır
            $flashCrashReason = $this->riskManager->checkFlashCrash();

            if ($flashCrashReason !== null) {
                $this->logAutomationError("Flaş Çöküş Koruması: {$flashCrashReason}");

                // Mevcut BTC dususu filtresi (asagida) taramayi atlarken bile BotLog::create()'e
                // DUSER, boylece Dashboard'daki "Son Bot Taraması" paneli guncel kalir - bu erken
                // return o cagriya HIC ulasmadan cikiyordu, uzun surebilecek bir flas cokus
                // sirasinda panel "bot olmus" gibi bayat gorunuyordu (fon etkisi yok, sadece
                // gözlemlenebilirlik). Ayni cagriyi burada da yapiyoruz
                BotLog::create(
                    scannedSymbols: [],
                    aiScores: [],
                    selectedSymbol: null,
                    selectedScore: null,
                    positionsOpened: 0,
                    notes: "Flaş Çöküş Koruması: {$flashCrashReason}",
                    tradeType: 'spot'
                );

                http_response_code(200);
                echo json_encode([
                    'status' => 'success',
                    'scan_skipped' => true,
                    'reason' => $flashCrashReason,
                    'reconciled_trades' => $reconciledCount,
                ], JSON_UNESCAPED_UNICODE);

                return;
            }

            $topMovers = $scanner->scanTopMovers();

            // 3) Yeni listelenen pariteleri tespit et (Binance'in resmi exchangeInfo'suyla kendi kendine)
            $newListingsCount = $this->detectNewListings($scanner);

            // 3b) Sosyal Radar: sosyal/haber "anilma sikligi" aniden firlayan coinleri ek aday olarak ekle -
            // bunlar da AYNI SentimentService onayindan gecmeden asla alinamaz, sadece aday havuzunu genisletir
            $marketScannerSymbols = array_column($topMovers, 'symbol');
            $socialRadarSymbols = $this->fetchTradableSocialRadarSymbols($scanner);
            $candidateSymbols = array_values(array_unique(array_merge($marketScannerSymbols, $socialRadarSymbols)));

            // MarketScanner'in zaten hesapladigi gercek fiyat degisimi/hacmi, sembol => veri
            // eslemesi olarak SentimentService'e tasinir (Sosyal Radar'dan gelen semboller icin
            // bu veri yok, o durumda eski sembol-bazli prompta geri dusulur)
            $marketDataMap = [];
            $isFirstMover = true;
            foreach ($topMovers as $mover) {
                if (!$isFirstMover) {
                    usleep(self::KLINES_REQUEST_DELAY_MICROSECONDS);
                }
                $isFirstMover = false;

                $marketDataMap[$mover['symbol']] = [
                    'priceChangePercent' => $mover['priceChangePercent'],
                    'quoteVolume' => $mover['quoteVolume'],
                    // Karma Radar'in bu adayi hangi stratejiden sectigi (golge_hacim/dipten_donus/
                    // erken_momentum) - bot_logs.input_data icinde kalici olarak saklanir, ileride
                    // "hangi strateji kazandirdi?" analizinde kullanilir
                    'strategy_bucket' => $mover['strategy_bucket'] ?? null,
                    // 24 Temmuz'da eklendi: RiskManagerService::isNear24hHigh() hard-reject kontrolu
                    // icin - MarketScanner zaten fetch24hrTickers() cevabinda tasiyordu, ek Binance
                    // cagrisi gerekmez
                    'lastPrice' => $mover['lastPrice'] ?? null,
                ];

                // Gunluk (24s) Zirve Korumasi - Cift Katmanli Zirve Korumasi'nin MIKRO katmani
                // (bkz. MarketScanner::calculateDailyPeakPosition). Ek Binance cagrisi gerekmez,
                // scanTopMovers() zaten hesaplamis olabilir
                foreach (['high_24h', 'low_24h', 'position_percent_24h'] as $dailyPeakKey) {
                    if (isset($mover[$dailyPeakKey])) {
                        $marketDataMap[$mover['symbol']][$dailyPeakKey] = $mover[$dailyPeakKey];
                    }
                }

                // 3 aylik makro trend (veritabanina kaydedilmez, aninda Binance'ten cekilir) - GPT'nin
                // sadece 24s harekete bakip 3 aylik zirveye/dirence yakin bir coine yanlislikla
                // yuksek skor vermesini (FOMO/tepeden alim riski) onlemek icin
                $macroTrend = $scanner->calculateMacroTrend($mover['symbol']);

                if ($macroTrend !== null) {
                    $marketDataMap[$mover['symbol']] += $macroTrend;
                }
            }

            // 3c) Sosyal Radar'dan (CoinGecko trending) gelip MarketScanner'in Top-N havuzunda
            // ZATEN olmayan semboller icin de gercek 24s fiyat/hacim verisi eklenir - EK bir
            // Binance API cagrisi gerekmez (MarketScanner::getTickerData() zaten scanTopMovers()'in
            // cektigi onbellekten okur). 15 Temmuz'da tespit edildi: bu veri eksikligi yuzunden
            // SentimentService bu adaylar icin "veri yok" genel promptuna duruyordu, GPT de dusuk
            // bilgiyle farkli coinler icin ayni/benzer skor+gerekceye (ör. hepsi 75) yoneliyordu
            foreach ($socialRadarSymbols as $socialSymbol) {
                if (isset($marketDataMap[$socialSymbol])) {
                    continue; // zaten MarketScanner'in kendi havuzunda, veri mevcut
                }

                $tickerData = $scanner->getTickerData($socialSymbol);

                if ($tickerData !== null) {
                    $marketDataMap[$socialSymbol] = $tickerData;
                }
            }

            // decision_motor, adaylar analiz EDILMEDEN once okunur (asagidaki GPT cagrisini atlayip
            // atlamama karari buna bagli) - per-aday dongusu icinde ayni degiskeni TEKRAR okumaz
            // (tur ortasinda ayar degisirse tutarsizlik olmasin diye TEK bir okuma)
            $decisionMotor = $this->getDecisionMotor();

            // 4) 27 Temmuz'da degisti: decision_motor='deterministic' iken GPT/OpenAI hic cagrilmaz
            // (eskiden Golge Modda maliyet/karsilastirma icin cagriliyordu - bkz. CHANGELOG). 171
            // islemlik dogrulamadan sonra Golge Mod'un tek faydasi olan karsilastirma verisi ihtiyaci
            // ortadan kalkti, buna karsin genisletilmis (50'ye kadar) aday havuzuyla birlikte GPT
            // cagri sayisi/maliyeti/gecikmesi asiri artmisti - bu da 26/27 Temmuz gecesi yasanan
            // worker tikanmasi olayina katkida bulunan dis API yukunu gereksiz yere buyutuyordu.
            // $analyses asagidaki dongularin AYNI sekilde calismaya devam etmesi icin yer tutucu
            // (score=0, is_buy_signal=false) degerlerle doldurulur - skoru hicbir yerde karara
            // katilmaz (deterministic modda esik filtrelemesi zaten tamamen atlanir, bkz. asagisi)
            if ($decisionMotor === 'deterministic') {
                $analyses = array_map(static fn (string $symbol): array => [
                    'symbol' => $symbol,
                    'score' => 0,
                    'reason' => 'Deterministik mod: GPT atlandı (maliyet optimizasyonu)',
                    'is_buy_signal' => false,
                ], $candidateSymbols);
            } else {
                $sentiment = new SentimentService();
                $analyses = $sentiment->analyzeMany($candidateSymbols, $marketDataMap);
            }

            // Her adaya, SADECE Sosyal Radar'dan gelip mevcut tarama sonuclarinda olmayan semboller icin
            // 'social_radar' kaynak etiketi ekle - huntForAllUsers bunu opt-in kontrolu icin kullanir
            foreach ($analyses as &$analysis) {
                $analysis['source'] = in_array($analysis['symbol'], $marketScannerSymbols, true)
                    ? 'market_scanner'
                    : 'social_radar';
            }
            unset($analysis);

            usort($analyses, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

            // 4b) Genel piyasa rejimi filtresi: BTC belirgin dususteyse, hicbir altcoin sinyali
            // ne kadar iyi gorunurse gorunsun bu turda yeni alim ACILMAZ - cogu altcoin BTC ile
            // yuksek korelasyonlu hareket eder, BTC'nin cektigi bir dususte "iyi" bir sinyal bile yanilir
            $btcChangePercent = $scanner->getBtcPriceChangePercent();
            $btcDowntrend = $btcChangePercent <= self::BTC_DOWNTREND_THRESHOLD_PERCENT;

            $globalMinThreshold = RiskProfileService::globalMinThreshold(); // 45 (aggressive)

            // $decisionMotor yukarida (GPT cagrisi/atlamasi oncesi) zaten okundu, burada TEKRAR okunmaz
            $eligibilityMaxCandidates = $decisionMotor === 'deterministic'
                ? self::DETERMINISTIC_MAX_CANDIDATES_PER_RUN
                : self::MAX_CANDIDATES_PER_RUN;

            // Sarjor Optimizasyonu: eskiden sadece EN YUKSEK skorlu TEK aday secilip digerleri
            // o tur hic degerlendirilmezdi. Artik esigi (globalMinThreshold) gecen TUM adaylar
            // (MAX_CANDIDATES_PER_RUN'a kadar) toplanir, asagida sirayla denenir - "Agresif"
            // profilin izin verdigi eszamanli pozisyon kapasitesi (ör. 5) artik tek bir turda
            // birden fazla firsatla doldurulabilir. decision_motor='deterministic' iken globalMinThreshold
            // (AI skoru) HIC uygulanmaz - bkz. DETERMINISTIC_MAX_CANDIDATES_PER_RUN yorumu
            $eligibleCandidates = [];

            if ($btcDowntrend) {
                $this->logAutomationError(sprintf(
                    'BTC düşüş filtresi: BTC son 24 saatte %%%.2f değişti (eşik %%%.2f), bu turda yeni alım atlandı.',
                    $btcChangePercent,
                    self::BTC_DOWNTREND_THRESHOLD_PERCENT
                ));

                // BTC Bağımsızlık İstisnası: esigi gecen adaylar arasinda BTC'ye karsi GERCEKTEN
                // negatif/sifir korelasyonlu olanlar (yani BTC duserken bile bagimsiz/ters hareket
                // edenler) bu filtreden MUAF tutulur - bkz. BTC_INDEPENDENCE_CORRELATION_THRESHOLD
                // yorumu. Cesitlendirme Filtresi'ndeki AYNI paylasilan fonksiyon (calculatePriceCorrelation)
                // kullanilir, ikinci bir korelasyon hesaplama mantigi YAZILMAZ
                foreach ($analyses as $analysis) {
                    if ($decisionMotor !== 'deterministic' && $analysis['score'] < $globalMinThreshold) {
                        continue;
                    }

                    $btcCorrelation = $scanner->calculatePriceCorrelation(
                        $analysis['symbol'],
                        'BTCUSDT',
                        self::CORRELATION_LOOKBACK_HOURS
                    );

                    if ($btcCorrelation !== null && $btcCorrelation <= self::BTC_INDEPENDENCE_CORRELATION_THRESHOLD) {
                        $this->logAutomationError(sprintf(
                            'BTC Bağımsızlık İstisnası: %s BTC ile düşük/negatif korelasyonlu (%.2f), BTC düşüş filtresinden MUAF tutuldu.',
                            $analysis['symbol'],
                            $btcCorrelation
                        ));

                        $eligibleCandidates[] = $analysis;

                        if (count($eligibleCandidates) >= $eligibilityMaxCandidates) {
                            break;
                        }
                    }
                }
            } else {
                foreach ($analyses as $analysis) {
                    // AI modunda: kümülatif en düşük eşiği (aggressive: 45) geçen HER coin (üst
                    // sınıra kadar) toplanır. Deterministik modda: GPT hiç çağrılmadığı için
                    // $analysis['score'] yer tutucudur (0) - eşik filtrelemeden TÜM havuz açılır.
                    // Her kullanıcının kendi eşiği huntForAllUsers() içinde ayrıca kontrol edilir.
                    if ($decisionMotor === 'deterministic' || $analysis['score'] >= $globalMinThreshold) {
                        $eligibleCandidates[] = $analysis;

                        if (count($eligibleCandidates) >= $eligibilityMaxCandidates) {
                            break;
                        }
                    }
                }
            }

            // 4b-2) YENİ - Pusu (ambush) kurtarma: bu turda GPT barajını normal yoldan geçen HİÇBİR
            // coin YOKSA, en yüksek skorlu adayın (analyses zaten azalan sıralı) skoru baraja
            // YETERİNCE yakınsa (en fazla AMBUSH_NEAR_MISS_BAND puan altında), 5dk/15dk grafikte net
            // bir "RSI dipten dönüş + MACD erken AL" sinyali arar. Sinyal varsa o adayı kurtarıp
            // skoruna telafi puanı ekler - GPT'nin barajdan ÇOK uzak bulduğu bir coini bu yolla
            // ASLA geçirmez. Aşağıdaki RSI(1h≥70)/hacim trendi SERT filtreleri, kurtarılan aday
            // için de DEĞİŞMEDEN çalışmaya devam eder - pusu bu korumaların YERİNE değil, GPT
            // barajının ÖNÜNE eklenen bağımsız bir 2. onay kapısıdır
            if ($eligibleCandidates === [] && !$btcDowntrend && $analyses !== []) {
                $bestNearMiss = $analyses[0];
                $nearMissFloor = $globalMinThreshold - self::AMBUSH_NEAR_MISS_BAND;

                if ($bestNearMiss['score'] >= $nearMissFloor && $bestNearMiss['score'] < $globalMinThreshold) {
                    try {
                        $ambushCheck = $scanner->calculateTechnicalScore(
                            $bestNearMiss['symbol'],
                            (float) ($marketDataMap[$bestNearMiss['symbol']]['priceChangePercent'] ?? 0.0),
                            null
                        );
                    } catch (Throwable $e) {
                        $ambushCheck = null;
                    }

                    if ($ambushCheck !== null && ($ambushCheck['ambush_detected'] ?? false) === true) {
                        $this->logAutomationError(sprintf(
                            'Pusu (ambush) teyidi: %s AI Karar Skoru: %d (baraj %d altında, %d puan bandı içinde), 5dk/15dk dipten dönüş + erken MACD AL sinyaliyle telafi edildi.',
                            $bestNearMiss['symbol'],
                            $bestNearMiss['score'],
                            $globalMinThreshold,
                            self::AMBUSH_NEAR_MISS_BAND
                        ));

                        $bestNearMiss['score'] = min(100, $bestNearMiss['score'] + self::AMBUSH_SCORE_BONUS);
                        $eligibleCandidates[] = $bestNearMiss;
                    }
                }
            }

            // 4c-4e) Eşiği geçen HER aday sırayla aynı sert filtre zincirinden (RSI/hacim/MTF/
            // emir defteri) geçirilir ve hayatta kalırsa huntForAllUsers() denenir - bir önceki
            // adayın alınıp alınmadığından BAĞIMSIZ, her aday kendi RSI/hacim/MTF/tahta durumunu
            // TAZE olarak kontrol eder. Sadece İLK adayın (en yüksek skorlu) sonucu $selected/
            // $rsiValue/$volumeIncreasing/$technicalScore olarak asagida bot_logs'a/JSON yanitina
            // yansitilir (geriye donuk uyumluluk - dashboard'daki "SON BOT TARAMASI" paneli tek
            // bir temsili sonuc bekliyor), ama TÜM adaylar icin alim asagida ayrica denenir
            $selected = null;
            $rsiValue = null;
            $volumeIncreasing = null;
            $technicalScore = null;
            $processedUsers = 0;

            // Deterministik modda $analyses'teki skorlar yer tutucu (0) - asagidaki dongude her
            // aday icin gercek Deterministik Motor skoru hesaplaninca bu haritayla $analyses'e GERI
            // yazilir ki bot_logs.ai_scores/selected_score (admin "SON BOT ÇALIŞTIRMALARI" ve
            // dashboard "SON BOT TARAMASI" panelleri) hep "0" gostermesin - 27 Temmuz'da eklendi
            $analysesIndexBySymbol = $decisionMotor === 'deterministic'
                ? array_flip(array_column($analyses, 'symbol'))
                : [];

            // 27 Temmuz'da eklendi: deterministic modda $eligibleCandidates ARTIK skora gore
            // sirali DEGIL (analiz anindaki tum skorlar yer tutucu/0 oldugu icin usort() bu turda
            // etkisiz kaldi) - asagidaki "$candidateIndex === 0" ile secim yapan eski AI-modu mantigi
            // (skora gore ONCEDEN siralanmis listenin ilk elemani = en iyi aday varsayimina dayanir)
            // deterministic modda artik GECERSIZ, rastgele/tarama sirasina gore bir aday sececekti -
            // bu yuzden $selected/$rsiValue/$volumeIncreasing/$technicalScore icin deterministic
            // modda GERCEK en yuksek skorlu adayi ayrica takip ediyoruz (kabul/red fark etmeksizin,
            // bkz. asagidaki "Teknik Gözlem Puanı" sonrasindaki guncelleme). AI modunda BU DEGISKENLER
            // KULLANILMAZ, eski davranis (candidateIndex===0) aynen devam eder
            $bestDeterministicScore = -1;

            foreach ($eligibleCandidates as $candidateIndex => $candidate) {
                $symbol = $candidate['symbol'];

                try {
                    $candidateRsi = $scanner->calculateRsi($symbol);
                    $candidateVolumeIncreasing = $scanner->isVolumeIncreasing($symbol);

                    // Global aday havuzu HER ZAMAN katı RSI limitleriyle çalışır - skor/hacim ne
                    // olursa olsun tavan gevşetilmez (eskiden burada "Agresif Momentum Baypası" ile
                    // skoru/hacmi yüksek adaylar için tavan 85'e çıkıyordu; paylaşılan havuzu
                    // etkilediği için muhafazakâr/dengeli kullanıcıların da tepeden alım riskine
                    // maruz kalmasına yol açtığı tespit edilip 24 Temmuz'da tamamen kaldırıldı)
                    if ($candidateRsi !== null && $candidateRsi >= self::RSI_OVERBOUGHT_THRESHOLD) {
                        $this->logAutomationError(sprintf(
                            'RSI filtresi: %s için RSI %.1f (aşırı alınmış, eşik %.1f) - bu turda alım atlandı.',
                            $symbol,
                            $candidateRsi,
                            self::RSI_OVERBOUGHT_THRESHOLD
                        ));

                        // Ardışık Çift Onay: bu sembol daha önce beklemedeyse (pass 1/2), trend
                        // bozulmuş demektir - kayıt ANINDA silinir, bir dahaki sefere sıfırdan başlar
                        PendingSignal::delete($symbol);

                        if ($candidateIndex === 0) { $rsiValue = $candidateRsi; $volumeIncreasing = $candidateVolumeIncreasing; }
                        continue;
                    }

                    $candidateHigh24h = (float) ($marketDataMap[$symbol]['high_24h'] ?? 0);
                    $candidateLastPrice = (float) ($marketDataMap[$symbol]['lastPrice'] ?? 0);

                    if ($candidateLastPrice > 0.0 && $this->riskManager->isNear24hHigh($candidateLastPrice, $candidateHigh24h, self::NEAR_24H_HIGH_THRESHOLD_PERCENT)) {
                        $this->logAutomationError(sprintf(
                            'Zirve Yakınlığı filtresi: %s fiyatı (%s) 24 saatlik zirvenin (%s) %%%.1f üzerinde (eşik %%%.1f) - bu turda alım atlandı.',
                            $symbol,
                            $this->formatPrice($candidateLastPrice),
                            $this->formatPrice($candidateHigh24h),
                            $candidateHigh24h > 0.0 ? ($candidateLastPrice / $candidateHigh24h) * 100.0 : 0.0,
                            self::NEAR_24H_HIGH_THRESHOLD_PERCENT
                        ));

                        AiIntervention::record(
                            null,
                            $symbol,
                            'ANTI_FOMO_ZIRVE',
                            sprintf(
                                'Fiyat 24 saatlik zirveye çok yakın (%%%.1f, eşik %%%.1f) olduğu için "tepeden alma" riskine karşı işlem iptal edildi.',
                                $candidateHigh24h > 0.0 ? ($candidateLastPrice / $candidateHigh24h) * 100.0 : 0.0,
                                self::NEAR_24H_HIGH_THRESHOLD_PERCENT
                            )
                        );

                        PendingSignal::delete($symbol);

                        if ($candidateIndex === 0) { $rsiValue = $candidateRsi; $volumeIncreasing = $candidateVolumeIncreasing; }
                        continue;
                    }

                    if (!$candidateVolumeIncreasing) {
                        $this->logAutomationError(sprintf(
                            'Hacim trendi filtresi: %s için son saatlerdeki hacim artmıyor - bu turda alım atlandı.',
                            $symbol
                        ));

                        PendingSignal::delete($symbol);

                        if ($candidateIndex === 0) { $rsiValue = $candidateRsi; $volumeIncreasing = $candidateVolumeIncreasing; }
                        continue;
                    }

                    $isBelowTrend = $scanner->isPriceBelowLongTermTrend(
                        $symbol,
                        self::MTF_TREND_INTERVAL,
                        self::MTF_EMA_PERIOD
                    );

                    if ($isBelowTrend === true) {
                        $this->logAutomationError(sprintf(
                            'MTF Reddi: %s için 4H Ana trend düşüş yönünde (fiyat EMA%d altında) - bu turda alım atlandı.',
                            $symbol,
                            self::MTF_EMA_PERIOD
                        ));

                        // "Görünmez Kalkan" raporu: musteri-yuzlu, teknik jargon olmadan yazilmis ozet -
                        // MTF/Tahta filtreleri GLOBAL (kullanicidan bagimsiz) karar noktalari olduklari
                        // icin user_id=null (bkz. AiIntervention tablo yorumu)
                        AiIntervention::record(
                            null,
                            $symbol,
                            'MTF_TUZAK',
                            "4 saatlik ana trend düşüşte olduğu için olası bir tuzak (Ölü Kedi Sıçraması) tespit edildi, işlem iptal edildi."
                        );

                        PendingSignal::delete($symbol);

                        if ($candidateIndex === 0) { $rsiValue = $candidateRsi; $volumeIncreasing = $candidateVolumeIncreasing; }
                        continue;
                    }

                    $orderBookAnalysis = $scanner->analyzeOrderBookWall(
                        $symbol,
                        self::ORDER_BOOK_WALL_PERCENT,
                        self::ORDER_BOOK_WALL_RATIO
                    );

                    if ($orderBookAnalysis !== null && $orderBookAnalysis['wall_detected'] === true) {
                        $this->logAutomationError(sprintf(
                            'Tahta Reddi: %s için %%%.0f yukarıda %.1fx Satış Duvarı tespit edildi (satış: %.2f, alış: %.2f) - bu turda alım atlandı.',
                            $symbol,
                            self::ORDER_BOOK_WALL_PERCENT,
                            $orderBookAnalysis['ratio'],
                            $orderBookAnalysis['ask_volume'],
                            $orderBookAnalysis['bid_volume']
                        ));

                        AiIntervention::record(
                            null,
                            $symbol,
                            'SATIS_DUVARI',
                            sprintf(
                                'Mevcut fiyatın %%%.0f üzerinde %.1fx büyüklüğünde bir Satış Duvarı tespit edildi, işlem iptal edildi.',
                                self::ORDER_BOOK_WALL_PERCENT,
                                $orderBookAnalysis['ratio']
                            )
                        );

                        PendingSignal::delete($symbol);

                        if ($candidateIndex === 0) { $rsiValue = $candidateRsi; $volumeIncreasing = $candidateVolumeIncreasing; }
                        continue;
                    }

                    // Tum sert filtreleri gecti - TechnicalScoreEngine, decision_motor='ai' iken esas
                    // olarak bilgilendirme/izleme amaçlıdır (log'a yazılır), AMA rsi_15m alani asagidaki
                    // Anti-FOMO Freni tarafindan HER ZAMAN sert bir karar noktasi olarak da kullanilir.
                    // decision_motor='deterministic' iken ise bu motorun 'score' alani asil alim
                    // kararini da verir - bkz. asagidaki Deterministik Motor Gecidi
                    $candidateTechnicalScore = null;

                    try {
                        $candidateTechnicalScore = $scanner->calculateTechnicalScore(
                            $symbol,
                            (float) ($marketDataMap[$symbol]['priceChangePercent'] ?? 0.0),
                            $candidateRsi
                        );

                        if ($candidateTechnicalScore !== null) {
                            $this->logAutomationError(sprintf(
                                'Teknik Gözlem Puanı (Sadece İzleme): %s için skor %d - %s',
                                $symbol,
                                $candidateTechnicalScore['score'],
                                $candidateTechnicalScore['reason']
                            ));
                        }
                    } catch (Throwable $e) {
                        $candidateTechnicalScore = null;
                    }

                    // Deterministic modda "bu tura kadar en yuksek skorlu aday" GUNCELLENIR - kabul/red
                    // fark etmeksizin, candidateIndex'ten BAGIMSIZ. AI Monolog/bot_logs.selected_symbol
                    // artik boylece deterministic modda da GERCEKTEN o turun en iyi adayini yansitir
                    if ($decisionMotor === 'deterministic'
                        && $candidateTechnicalScore !== null
                        && (int) $candidateTechnicalScore['score'] > $bestDeterministicScore
                    ) {
                        $bestDeterministicScore = (int) $candidateTechnicalScore['score'];
                        $selected = $candidate;
                        $selected['score'] = $bestDeterministicScore;
                        $selected['reason'] = $candidateTechnicalScore['reason'];
                        $rsiValue = $candidateRsi;
                        $volumeIncreasing = $candidateVolumeIncreasing;
                        $technicalScore = $candidateTechnicalScore;
                    }

                    // Deterministik Motor Gecidi: asil karari hangi motorun verdigini belirler.
                    // 27 Temmuz'dan itibaren decision_motor='deterministic' iken GPT/SentimentService
                    // HIC cagrilmiyor (bkz. yukaridaki $analyses yer tutucu blogu ve CHANGELOG) -
                    // yani artik gercek bir "Golge Mod" karsilastirmasi yok, sadece TechnicalScoreEngine'in
                    // kendi skoru asil karari veriyor. $decisionMotor burada TEKRAR okunmaz -
                    // $eligibleCandidates olusturulurken (yukarida) zaten TEK seferde okunup ayni tur
                    // boyunca kullanilir
                    // Ardışık Çift Onay icin bu sembolun bekleyen bir kaydı var mi (asagida TEKRAR
                    // sorgulanmaz, bu deger reddedilir) - 27 Temmuz'da BURAYA tasindi ki hacim sartini
                    // SADECE ILK gecişte uygulayabilelim (bkz. asagidaki $deterministicPass yorumu)
                    $pendingSignal = PendingSignal::findBySymbol($symbol);

                    // 27 Temmuz'da degisti: hacim delta sarti (>=1.0x) SADECE bu sembolun ILK
                    // gecisinde uygulanir. Canli veride tespit edildi: PENGUUSDT/PEPEUSDT/ZAMAUSDT
                    // gibi adaylar hacim rakami 1.0x sinirinin etrafinda tur-tur salinip GEÇTİ/REDDEDİLDİ
                    // arasinda gidip geliyordu - REDDEDİLDİ oldugu an pending_signals kaydi silindigi
                    // icin "art arda 2 tur" sarti hicbir zaman tamamlanamiyordu (asil trend gercekten
                    // saglamken bile). Skor>=70 ve MACD olumlu sarti ILK VE IKINCI turda da GECERLI
                    // kalmaya devam ediyor - sadece hacmin GECICI dalgalanmasi ikinci turu bozmasin
                    // diye bu TEK kriter, zaten bir kez ("ilk gorulme") dogrulanmis bir sembol icin
                    // tekrar zorunlu tutulmuyor
                    $deterministicPass = $candidateTechnicalScore !== null
                        && $candidateTechnicalScore['score'] >= self::DETERMINISTIC_MOTOR_MIN_SCORE
                        && $candidateTechnicalScore['macd_positive'] === true
                        && ($pendingSignal !== null
                            || $candidateTechnicalScore['volume_delta'] === null
                            || $candidateTechnicalScore['volume_delta'] >= self::DETERMINISTIC_MOTOR_MIN_VOLUME_DELTA);

                    $this->logAutomationError(sprintf(
                        'Deterministik Motor (%s): %s için %s (teknik skor %s, MACD %s, hacim %s)',
                        $decisionMotor === 'deterministic' ? 'AKTİF' : 'Gölge',
                        $symbol,
                        $deterministicPass ? 'GEÇTİ' : 'REDDEDİLDİ',
                        $candidateTechnicalScore['score'] ?? '?',
                        ($candidateTechnicalScore['macd_positive'] ?? null) === true ? 'olumlu' : (($candidateTechnicalScore['macd_positive'] ?? null) === false ? 'olumsuz' : 'bilinmiyor'),
                        ($candidateTechnicalScore['volume_delta'] ?? null) !== null ? sprintf('%.1fx', $candidateTechnicalScore['volume_delta']) : 'bilinmiyor'
                    ));

                    // Yer tutucu (0) skoru gercek Deterministik Motor skoruyla degistir - hem bu
                    // dongudeki $candidate (asagida $selected'e kopyalanabilir) hem de disaridaki
                    // $analyses (bot_logs.ai_scores icin) GUNCELLENIR ki paneller "0" gostermesin
                    if ($decisionMotor === 'deterministic' && $candidateTechnicalScore !== null) {
                        $candidate['score'] = (int) round($candidateTechnicalScore['score']);
                        $candidate['reason'] = $candidateTechnicalScore['reason'];
                        $candidate['is_buy_signal'] = $deterministicPass;

                        if (isset($analysesIndexBySymbol[$symbol])) {
                            $analyses[$analysesIndexBySymbol[$symbol]]['score'] = $candidate['score'];
                            $analyses[$analysesIndexBySymbol[$symbol]]['reason'] = $candidate['reason'];
                            $analyses[$analysesIndexBySymbol[$symbol]]['is_buy_signal'] = $deterministicPass;
                        }
                    }

                    if ($decisionMotor === 'deterministic' && !$deterministicPass) {
                        PendingSignal::delete($symbol);

                        if ($candidateIndex === 0) { $rsiValue = $candidateRsi; $volumeIncreasing = $candidateVolumeIncreasing; $technicalScore = $candidateTechnicalScore; }
                        continue;
                    }

                    // AI modunda mevcut $candidate['score'] (GPT) aynen kullanilir - davranis DEGISMEZ.
                    // Deterministik modda ise buraya kadar ulasildiysa $deterministicPass KESIN true'dur,
                    // asil karari veren skor olarak TechnicalScoreEngine'in kendi skoru kullanilir
                    $effectiveScore = $decisionMotor === 'deterministic'
                        ? (int) $candidateTechnicalScore['score']
                        : (int) $candidate['score'];

                    // Anti-FOMO Freni #1 - RSI (15dk) Asiri Alim Kapisi: yukaridaki 1 saatlik
                    // RSI_OVERBOUGHT_THRESHOLD'dan BAGIMSIZ, AI skoru ne kadar yuksek olursa olsun
                    // (ör. 90) 15dk RSI bu esigin USTUNDEYSE alim KESINLIKLE yapilmaz. rsi_15m
                    // hesaplanamadiysa (ör. yetersiz mum verisi - $candidateTechnicalScore null)
                    // fail-open: bu EK kontrol atlanir, 1h RSI sert filtresi zaten yukarida uygulandi
                    $candidateRsi15m = $candidateTechnicalScore['rsi_15m'] ?? null;

                    if ($candidateRsi15m !== null && $candidateRsi15m >= self::PULLBACK_RSI_OVERBOUGHT_THRESHOLD) {
                        $this->logAutomationError(sprintf(
                            'Anti-FOMO Freni (15dk RSI): %s için RSI %.1f (aşırı alınmış, eşik %.1f) - bu turda alım atlandı.',
                            $symbol,
                            $candidateRsi15m,
                            self::PULLBACK_RSI_OVERBOUGHT_THRESHOLD
                        ));

                        AiIntervention::record(
                            null,
                            $symbol,
                            'ANTI_FOMO_RSI',
                            sprintf(
                                '15 dakikalık RSI (%.1f) aşırı alım bölgesinde (eşik %.1f) olduğu için "tepeden alma" riskine karşı işlem iptal edildi.',
                                $candidateRsi15m,
                                $rsi15mCeiling
                            )
                        );

                        PendingSignal::delete($symbol);

                        if ($candidateIndex === 0) { $rsiValue = $candidateRsi; $volumeIncreasing = $candidateVolumeIncreasing; $technicalScore = $candidateTechnicalScore; }
                        continue;
                    }

                    // 27 Temmuz'da KALDIRILDI: eskiden burada Anti-FOMO Freni #2 (Pullback Kalkanı)
                    // sinirli bir sure (12sn) AKTIF BEKLEYIP fiyatin gerilemesini bekliyordu. Canli
                    // veride (PENGUUSDT, ZECUSDT - 27 Temmuz) tespit edildi: kesintisiz/duz yukselen
                    // coinler bu kisa pencerede HICBIR ZAMAN yeterli gerileme gostermiyordu, motor
                    // dogru karar verse bile hic alim yapamiyordu. Cozum: aktif bekleme yerine
                    // GERCEK bir bekleyen LIMIT ALIS emri (bkz. huntForAllUsers() -> PendingLimitOrder) -
                    // Binance'in kendi order book'unda dogal zamaninda dolar, sunucu HIC beklemez.
                    // Bu ayrica mimari kuralla (sonsuz donguye/sleep'e izin yok) daha uyumlu - eski
                    // 12sn'lik blokaj zaten bu kurala en yakin istisnaydi. "Tepeden alma" korumasi
                    // ARTIK KALKMADI: limit fiyati zaten sinyal fiyatinin altina konur (ayni PULLBACK_
                    // TARGET_PERCENT mantigi), sadece DOLMASI icin artik 12sn degil, PENDING_LIMIT_
                    // ORDER_TIMEOUT_MINUTES kadar dogal zaman taniniyor - bkz. huntForAllUsers() yorumu
                    $strategyBucket = $marketDataMap[$symbol]['strategy_bucket'] ?? null;

                    // Ardışık Çift Onay: tüm sert filtrelerden geçen bir sinyal burada ANINDA
                    // alınmaz - bkz. PENDING_SIGNAL_REQUIRED_PASSES yorumu (yukarıda tanım satırı).
                    // TUR TABANLI: saniye SAYILMAZ, art arda kaç BAŞARILI tarama turu geçtiği sayılır.
                    // $pendingSignal yukarida ($deterministicPass hesaplanmadan once) zaten okundu,
                    // burada TEKRAR sorgulanmaz

                    if ($pendingSignal === null) {
                        // İlk görülme: sadece kaydet (pass_count=1), bu turda alım YAPILMAZ - teyit
                        // bir sonraki BAŞARILI taramaya bırakılır
                        PendingSignal::create($symbol, $effectiveScore);
                        $this->logAutomationError(sprintf(
                            'Ardışık Çift Onay: %s ilk kez tüm filtrelerden geçti (%s: %d, tur 1/%d), teyit için bir sonraki tura bırakıldı.',
                            $symbol,
                            $decisionMotor === 'deterministic' ? 'Deterministik Motor Skoru' : 'AI Karar Skoru',
                            $effectiveScore,
                            self::PENDING_SIGNAL_REQUIRED_PASSES
                        ));
                    } else {
                        $newPassCount = (int) $pendingSignal['pass_count'] + 1;

                        if ($newPassCount >= self::PENDING_SIGNAL_REQUIRED_PASSES) {
                            // Zayıflayan Teyit Freni: skor ilk turdan düşmüş VE hâlâ asgari eşiğe
                            // yakınsa (bkz. PENDING_SIGNAL_WEAK_CONFIRM_MAX_SCORE yorumu) bu "art arda
                            // 2 tur geçti" teknik olarak doğru olsa da momentum zayıflıyor demektir - alım atlanır
                            $isWeakeningConfirmation = $effectiveScore < (int) $pendingSignal['first_seen_score']
                                && $effectiveScore <= self::PENDING_SIGNAL_WEAK_CONFIRM_MAX_SCORE;

                            if ($isWeakeningConfirmation) {
                                $this->logAutomationError(sprintf(
                                    'Zayıflayan Teyit Freni: %s art arda %d. kez geçti ama teyit turunda skor zayıfladı (ilk %s: %d, bu tur %s: %d, eşik %d) - alım atlandı.',
                                    $symbol,
                                    $newPassCount,
                                    $decisionMotor === 'deterministic' ? 'Deterministik Motor Skoru' : 'AI Karar Skoru',
                                    $pendingSignal['first_seen_score'],
                                    $decisionMotor === 'deterministic' ? 'Deterministik Motor Skoru' : 'AI Karar Skoru',
                                    $effectiveScore,
                                    self::PENDING_SIGNAL_WEAK_CONFIRM_MAX_SCORE
                                ));

                                AiIntervention::record(
                                    null,
                                    $symbol,
                                    'ZAYIF_TEYIT',
                                    sprintf(
                                        'İlk taramada skor %d iken teyit turunda %d\'e düşüp asgari eşiğe (%d) yaslandığı için momentum zayıflaması riskine karşı işlem iptal edildi.',
                                        $pendingSignal['first_seen_score'],
                                        $effectiveScore,
                                        self::DETERMINISTIC_MOTOR_MIN_SCORE
                                    )
                                );
                            } else {
                                // Gerekli tur sayısına ulaşıldı: aynı sembol art arda BAŞARILI
                                // taramalardan geçti (kaç saniye sürdüğü ÖNEMSİZ) - alım onaylanır
                                $this->logAutomationError(sprintf(
                                    'Ardışık Çift Onay: %s art arda %d. kez tüm filtrelerden geçti (ilk %s: %d, bu tur %s: %d) - alım deneniyor.',
                                    $symbol,
                                    $newPassCount,
                                    $decisionMotor === 'deterministic' ? 'Deterministik Motor Skoru' : 'AI Karar Skoru',
                                    $pendingSignal['first_seen_score'],
                                    $decisionMotor === 'deterministic' ? 'Deterministik Motor Skoru' : 'AI Karar Skoru',
                                    $effectiveScore
                                ));

                                $processedUsers += $this->huntForAllUsers($symbol, $candidate['source'], $effectiveScore, $strategyBucket, $candidateRsi, $candidateRsi15m, $decisionMotor);
                            }

                            PendingSignal::delete($symbol);
                        } else {
                            // Teorik olarak PENDING_SIGNAL_REQUIRED_PASSES=2 iken bu dala HİÇ
                            // düşülmez (pass_count her zaman >=1'den başlar, ilk tekrarda >=2 olur) -
                            // ileride bu sabit >2'ye çıkarılırsa diye güvenli bırakıldı
                            PendingSignal::incrementPassCount($symbol);
                            $this->logAutomationError(sprintf(
                                'Ardışık Çift Onay: %s tur %d/%d - teyit devam ediyor.',
                                $symbol,
                                $newPassCount,
                                self::PENDING_SIGNAL_REQUIRED_PASSES
                            ));
                        }
                    }

                    if ($candidateIndex === 0) {
                        $selected = $candidate;
                        $rsiValue = $candidateRsi;
                        $volumeIncreasing = $candidateVolumeIncreasing;
                        $technicalScore = $candidateTechnicalScore;
                    }
                } catch (Throwable $e) {
                    // Bir adayin degerlendirilmesi sirasinda gecici bir API/ag hatasi olursa
                    // (ör. Binance klines cagrisi basarisiz), SADECE bu aday atlanir - eskiden
                    // (tek adayli donemde) boyle bir hata TUM turu iptal ederdi, artik diger
                    // adaylarin denenmesini engellemiyor
                    $this->logAutomationError("Aday değerlendirilirken hata ({$symbol}): " . $e->getMessage());
                }
            }

            // 5) Bu calistirmanin yapisal ozetini bot_logs'a kaydet - input_data, GPT'ye gonderilen
            // ham piyasa verisini (fiyat degisimi, hacim, 90 gunluk makro trend) de icerir, ileride
            // "GPT bu karari verirken piyasa nasildi?" backtest/analizi icin
            BotLog::create(
                scannedSymbols: array_column($topMovers, 'symbol'),
                aiScores: $analyses,
                selectedSymbol: $selected['symbol'] ?? null,
                selectedScore: $selected['score'] ?? null,
                positionsOpened: $processedUsers,
                tradeType: 'spot',
                inputData: $marketDataMap
            );

            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'scanned' => array_column($topMovers, 'symbol'),
                'sentiment_scores' => $analyses,
                'selected_coin' => $selected['symbol'] ?? null,
                'selected_score' => $selected['score'] ?? null,
                'processed_users' => $processedUsers,
                'reconciled_trades' => $reconciledCount,
                'new_listings_detected' => $newListingsCount,
                'btc_change_percent' => $btcChangePercent,
                'btc_downtrend_filter_active' => $btcDowntrend,
                'rsi' => $rsiValue,
                'volume_increasing' => $volumeIncreasing,
                'technical_score' => $technicalScore,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            $this->logAutomationError('run() genel hata: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Sunucu hatası oluştu.'], JSON_UNESCAPED_UNICODE);
        } finally {
            // Kilit HER ihtimalde (basari/istisna) serbest birakilir - aksi halde bir sonraki
            // calistirma CRON_LOCK_TIMEOUT_SECONDS dolana kadar gereksiz yere bekler
            CronLock::release(self::CRON_LOCK_NAME);
        }
    }

    // Hizli Pozisyon Takipcisi: 26 Temmuz'da eklendi - musteri gozlemiyle (İzleyen Stop'un zirveden
    // sonra gec tepki verip kari "masada birakmasi") dogrulandi: ana run()'daki reconcileActiveTrades()
    // cagrisi TEKNIK OLARAK zaten GPT gerektirmeyen ucuz bir adim, ama run()'un PAYLASILAN
    // CRON_LOCK_NAME kilidi altinda calistigi icin bir onceki taramanin (scanTopMovers + OpenAI,
    // fastcgi_finish_request() sonrasi bile sunucu tarafinda dakikalarca surebilir) kilidi hala
    // acik oldugu surece bu turun reconcileActiveTrades()'i HIC BASLAYAMAZ - run()'un en basindaki
    // CronLock::acquire() basarisiz olup butun istegi (mutabakat dahil) atlar. Bu yeni uc nokta,
    // reconcileActiveTrades()'i AYNI SEKILDE (kod tekrari YOK) ama KENDI BAGIMSIZ kilidiyle
    // cagirarak, ana taramanin ne kadar surdugunden TAMAMEN etkilenmeyen, tutarli sikta (cPanel'de
    // 1 dakikalik ayri bir Cron Job ile) bir Izleyen Stop/Kar Al kontrolu saglar. GPT/OpenAI
    // cagirmaz - reconcileActiveTrades() icindeki Dinamik Kacis Protokolu bile kendi
    // POSITION_MONITOR_INTERVAL_SECONDS throttle'ina tabi, bu uc noktanin sikligindan bagimsizdir
    public function runFastTracker(): void
    {
        header('Content-Type: application/json');

        if (!$this->isFastTrackerTokenValid()) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Yetkisiz istek: geçersiz hızlı takip token\'ı.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!CronLock::acquire(self::FAST_TRACKER_CRON_LOCK_NAME, self::FAST_TRACKER_CRON_LOCK_TIMEOUT_SECONDS)) {
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'skipped' => true,
                'reason' => 'Önceki hızlı takip çalıştırması hâlâ devam ediyor (kilit aktif).',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $reconciledCount = $this->reconcileActiveTrades();
            // 27 Temmuz'da eklendi: eski aktif-bekleme Pullback Kalkanı'nın yerini alan bekleyen
            // limit emirlerini kontrol eder - bkz. checkPendingLimitOrders() yorumu. reconcileActiveTrades()
            // ile AYNI (Fast Tracker'ın tek dış kilidi) korumanın altında, ayrı bir kilit gerekmez
            $filledLimitOrders = $this->checkPendingLimitOrders();

            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'reconciled_trades' => $reconciledCount,
                'filled_limit_orders' => $filledLimitOrders,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            $this->logAutomationError('runFastTracker() genel hata: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Sunucu hatası oluştu.'], JSON_UNESCAPED_UNICODE);
        } finally {
            CronLock::release(self::FAST_TRACKER_CRON_LOCK_NAME);
        }
    }

    // isTokenValid() ile AYNI desen (Setting-first-then-config), sadece ayri bir token anahtari
    // kullanir - ana auto_trade_token'dan BAGIMSIZ tutulur ki bu uc nokta cPanel'de cok daha sik
    // (1 dk) tetiklenirken bile ana tarama token'iyla karismasin
    private function isFastTrackerTokenValid(): bool
    {
        // bkz. AutoFuturesTradeController::isTokenValid() yorumu - CLI/crontab bypass'i AYNI ilke
        if (PHP_SAPI === 'cli') {
            return true;
        }

        $expectedToken = Setting::get('fast_tracker_token');

        if ($expectedToken === null) {
            $config = require __DIR__ . '/../../config/app.php';
            $expectedToken = (string) $config['fast_tracker_token'];
        }

        $providedToken = (string) ($_GET['token'] ?? '');

        return $providedToken !== '' && hash_equals($expectedToken, $providedToken);
    }

    // isFastTrackerTokenValid() ile AYNI desen (Setting-first-then-config). Donen deger 'ai' ya da
    // 'deterministic' - taninmayan/bos bir deger (ör. DB'de hic satir yoksa veya elle bozuksa)
    // GUVENLI VARSAYILANA (ai, mevcut/bilinen davranis) fail-open duser - asla sessizce
    // "deterministic" moduna GECMEZ, sadece admin bilerek secerse aktif olur
    private function getDecisionMotor(): string
    {
        $motor = Setting::get('decision_motor');

        if ($motor === null) {
            $config = require __DIR__ . '/../../config/app.php';
            $motor = (string) ($config['decision_motor'] ?? 'ai');
        }

        return $motor === 'deterministic' ? 'deterministic' : 'ai';
    }

    // FuturesTradingService::shouldRunScan() ile BIREBIR ayni mantik: son taramadan bu yana
    // SCAN_INTERVAL_SECONDS gecmediyse false doner - cron (ör. 1 dk) tetiklense bile tarama
    // bu araliktan daha sik calismaz
    private function shouldRunScan(): bool
    {
        $lastScanAt = Setting::get(self::LAST_SCAN_SETTING_KEY);

        if ($lastScanAt === null) {
            return true;
        }

        return (time() - (int) $lastScanAt) >= self::SCAN_INTERVAL_SECONDS;
    }

    // shouldRunScan() ile AYNI desen, ama YENI aday taramasindan tamamen BAGIMSIZ bir sayaç -
    // acik pozisyon izleme (Dinamik Kaçış Protokolü) kendi sikliginda calisabilmeli
    private function shouldRunPositionMonitor(): bool
    {
        $lastRunAt = Setting::get(self::POSITION_MONITOR_SETTING_KEY);

        if ($lastRunAt === null) {
            return true;
        }

        return (time() - (int) $lastRunAt) >= self::POSITION_MONITOR_INTERVAL_SECONDS;
    }

    // Acik pozisyonlardaki BENZERSIZ sembolleri TEK TEK (sembol basina TEK OpenAI cagrisi) puanlar -
    // ayni sembolu tutan birden fazla kullanici varsa cagri PAYLASILIR, tekrar tekrar sorulmaz.
    // Basarisiz olan bir sembol icin null doner - cagiran taraf bu durumda o pozisyona DOKUNMAZ
    // (fail-safe: API hatasi asla yanlislikla bir pozisyonu kapatmaya yol acmamali)
    // @return array<string, int|null> sembol => AI skoru (1-100) | null (hata/veri yok)
    private function scoreOpenPositionSymbols(array $openTrades): array
    {
        $uniquePairs = array_values(array_unique(array_map(
            static fn (array $trade): string => (string) $trade['pair'],
            $openTrades
        )));

        $scores = [];
        $sentiment = new SentimentService();

        foreach ($uniquePairs as $pair) {
            try {
                $analysis = $sentiment->analyze($pair);
                $scores[$pair] = (int) $analysis['score'];
            } catch (Throwable $e) {
                $scores[$pair] = null;
                $this->logAutomationError("Pozisyon izleme: {$pair} için AI skoru alınamadı - " . $e->getMessage());
            }
        }

        return $scores;
    }

    // Token, URL parametresinde (?token=...) gonderilir; cPanel Cron Job'un dogrudan wget/curl ile
    // tetikleyebilmesi icin GET destekler (webhook_token'dan ayri, farkli bir gizli anahtardir)
    private function isTokenValid(): bool
    {
        // bkz. AutoFuturesTradeController::isTokenValid() yorumu - CLI/crontab bypass'i AYNI ilke
        if (PHP_SAPI === 'cli') {
            return true;
        }

        // Once admin panelinden (DB) girilen token'a bak, yoksa config/app.php'deki degere don
        $expectedToken = Setting::get('auto_trade_token');

        if ($expectedToken === null) {
            $config = require __DIR__ . '/../../config/app.php';
            $expectedToken = (string) $config['auto_trade_token'];
        }

        $providedToken = (string) ($_GET['token'] ?? '');

        return $providedToken !== '' && hash_equals($expectedToken, $providedToken);
    }

    // Sosyal Radar'in tespit ettigi spike sembollerini, Binance'te GERCEKTEN islem goren
    // pariteler olup olmadigini dogrulayarak doner - dogrulanmamis bir sembol asla adaya girmez
    private function fetchTradableSocialRadarSymbols(MarketScanner $scanner): array
    {
        try {
            $socialRadar = new SocialRadarService();
            $spikes = $socialRadar->detectSpikes();

            if ($spikes === []) {
                return [];
            }

            $tradableSymbols = $scanner->fetchTradableUsdtSymbols();
            $spikeSymbols = array_unique(array_column($spikes, 'symbol'));
            $candidates = array_values(array_intersect($spikeSymbols, $tradableSymbols));

            // 26 Temmuz'da eklendi: Ana Radar'daki (scanTopMovers) AYNI kara liste (bkz.
            // MarketScanner::getBlacklistedSymbols() yorumu) burada da uygulanir - Sosyal Radar'in
            // kendi ayri aday kaynagi olmasi, kara listeyi de ayri ayri uygulamayi gerektiriyor
            $blacklist = $scanner->getBlacklistedSymbols();

            if ($blacklist === []) {
                return $candidates;
            }

            return array_values(array_filter(
                $candidates,
                static fn (string $symbol): bool => !in_array($symbol, $blacklist, true)
            ));
        } catch (Throwable $e) {
            $this->logAutomationError('Sosyal Radar taraması hatası: ' . $e->getMessage());

            return [];
        }
    }

    // Secilen coin icin, AI Avci ozelligini acmis tum kullanicilarda: sabit butce ile ALIS yapar,
    // basarili olursa hemen ardindan Kar Al + Zarar Kes'i TEK OCO emrinde birlestirip pozisyonu korur.
    // $source==='social_radar' ise, SADECE social_radar_enabled=1 olan kullanicilar islenir -
    // Sosyal Radar'dan gelen bir sinyal, bu modulu hic acmamis bir kullaniciyi asla etkilemez
    private function huntForAllUsers(string $pair, string $source = 'market_scanner', int $score = 80, ?string $strategyBucket = null, ?float $rsi1h = null, ?float $rsi15m = null, string $decisionMotor = 'ai'): int
    {
        $scoreLabel = $decisionMotor === 'deterministic' ? 'Deterministik Motor Skoru' : 'AI Karar Skoru';

        $autoTradeUsers = ApiKey::findAllForAutoTrade();
        $processedCount = 0;

        foreach ($autoTradeUsers as $userKey) {
            $userId = (int) $userKey['user_id'];

            // bkz. Database::ensureConnected() yorumu - her kullanici icin Binance/AI saglayici
            // cagrilari birikince baglanti kopmus olabilir, sonraki DB yazimindan once dogrulanir
            Database::ensureConnected();

            if ($source === 'social_radar' && (int) ($userKey['social_radar_enabled'] ?? 0) !== 1) {
                continue; // bu sinyal Sosyal Radar kaynakli, kullanici bu modulu acmamis
            }

            // Per-user risk profili: bu kullanicinin AI skor esigini gecemeyen sinyalleri atla.
            // NOT: ai_score_threshold, risk_profile ile AYNI ATOMIK UPDATE'te yazilir (bkz.
            // ApiKey::updateRiskProfile) - yani "Agresif" seciliyse bu deger HER ZAMAN 70'tir,
            // ayri bir senkronizasyon adimi yoktur. Bu yuzden bu satirin atladigi her durum
            // ARTIK loglaniyor (eskiden sessizdi) - "skor esigi gecti ama alim olmadi" gibi
            // gizemli durumlarin (bkz. SENTUSDT RCA) kanitla teshis edilebilmesi icin
            $userThreshold = (int) ($userKey['ai_score_threshold'] ?? 80);
            if ($score < $userThreshold) {
                $this->logAutomationError(sprintf(
                    'Kullanıcı #%d: %s %s: %d, kullanıcının kendi eşiği %d altında kaldığı için atlandı.',
                    $userId,
                    $pair,
                    $scoreLabel,
                    $score,
                    $userThreshold
                ));
                continue;
            }

            // Per-user maksimum acik pozisyon limiti - DOLMUS pozisyonlar + HENUZ DOLMAMIS bekleyen
            // limit emirleri BIRLIKTE sayilir (31 Temmuz'da eklendi, bkz. PendingLimitOrder::
            // countForUser() yorumu - eskiden sadece dolmus pozisyonlar sayildigi icin ayni turda
            // birden fazla pariteye pending emir konulup hepsi doldugunda gercek acik pozisyon sayisi
            // limitin cok uzerine cikabiliyordu, canli veride zirve 8'e kadar cikmisti)
            $maxTrades = (int) ($userKey['max_active_trades'] ?? 3);
            $openCount = ActiveTrade::countOpenForUser($userId) + PendingLimitOrder::countForUser($userId);

            if ($openCount >= $maxTrades) {
                $this->logAutomationError(sprintf(
                    'Kullanıcı #%d: %s için alım atlandı - açık pozisyon limiti doldu (%d/%d, bekleyen emirler dahil).',
                    $userId,
                    $pair,
                    $openCount,
                    $maxTrades
                ));
                continue;
            }

            // Devre kesicinin gunluk zarar yuzdesini artik SABIT butce yerine TOPLAM ozkaynaga
            // (bakiye + acik pozisyonlarin maliyeti) gore hesaplayabilmesi icin bu bilgi
            // circuit breaker kontrolunden ONCE gerekiyor
            try {
                $binance = new BinanceService($userKey['api_key'], $userKey['secret_key']);
                $usdtBalance = $this->getAssetBalance($binance, 'USDT');
                $openTrades = ActiveTrade::findOpenForUser($userId);
                $openPositionsCost = 0.0;

                foreach ($openTrades as $trade) {
                    $openPositionsCost += (float) $trade['entry_price'] * (float) $trade['quantity'];
                }

                $totalEquity = $usdtBalance + $openPositionsCost;
            } catch (Throwable $e) {
                $this->logAutomationError("Kullanıcı #{$userId}: bakiye/özkaynak hesaplanamadı - " . $e->getMessage());
                continue;
            }

            $blockReason = $this->riskManager->checkCircuitBreaker($userId, $userKey, $totalEquity);

            if ($blockReason !== null) {
                $this->logAutomationError("Kullanıcı #{$userId} devre kesici: {$blockReason}");

                // Kullanici kilitli kaldigi surece cron her dondugunde (15 dk'da bir) ayni
                // Telegram mesaji tekrar tekrar atilmasin diye gunde en fazla 1 bildirim gonderilir
                if (!ApiKey::hasSentCooldownNotifToday($userId)) {
                    $this->notifyAdminAndCustomer(
                        $userId,
                        "⛔ [NexaTrade] Devre Kesici Tetiklendi\nSebep: {$blockReason}"
                    );
                    ApiKey::markCooldownNotifSent($userId);
                }

                continue;
            }

            if (ActiveTrade::hasOpenPositionForPair($userId, $pair)) {
                $this->logAutomationError(sprintf(
                    'Kullanıcı #%d: %s için alım atlandı - bu paritede zaten açık bir pozisyon var.',
                    $userId,
                    $pair
                ));
                continue;
            }

            // 27 Temmuz'da eklendi: bu kullanici+parite icin zaten bekleyen (henuz dolmamis) bir
            // limit alis emri varsa ikinci bir tane daha KONULMAZ - hasOpenPositionForPair()'in
            // "bekleyen emir" karsiligi, bkz. PendingLimitOrder yorumu
            if (PendingLimitOrder::existsForUserAndPair($userId, $pair)) {
                $this->logAutomationError(sprintf(
                    'Kullanıcı #%d: %s için alım atlandı - bu paritede zaten bekleyen bir limit emri var.',
                    $userId,
                    $pair
                ));
                continue;
            }

            // Çeşitlendirme (Korelasyon) Filtresi: kullanicinin acik pozisyonlarindan biriyle bu
            // aday yuksek korele hareket ediyorsa ("ayni sepete tum kursunlari sikma") atlanir.
            // $openTrades yukarida (ozkaynak/devre kesici hesabi icin) zaten cekilmisti, burada
            // TEKRAR sorgulanmaz. Bos ise (hic acik pozisyon yoksa) kontrol zaten anlamsiz, atlanir
            if ($openTrades !== []) {
                $correlated = $this->findHighlyCorrelatedOpenPosition(new MarketScanner(), $pair, $openTrades);

                if ($correlated !== null) {
                    $this->logAutomationError(sprintf(
                        'Kullanıcı #%d: %s için alım atlandı - açık %s pozisyonuyla yüksek korelasyon (%.2f, eşik %.2f).',
                        $userId,
                        $pair,
                        $correlated['pair'],
                        $correlated['correlation'],
                        self::CORRELATION_REJECT_THRESHOLD
                    ));
                    continue;
                }
            }

            // Sembol bazli soguma (kara liste): bu kullanici bu coini yakin zamanda Zarar Kes veya
            // Erken Kaçış ile kapattiysa, AI skoru ne kadar yuksek olursa olsun ("intikam islemi"
            // riski) bu turda ATLANIR - SADECE bu (kullanici, sembol) cifti icin, devre kesici gibi
            // TUM botu durdurmaz, diger kullanicilari veya diger coinleri hic etkilemez
            $symbolCooldownUntil = SymbolCooldown::getCooldownUntil($userId, $pair);

            if ($symbolCooldownUntil !== null) {
                $this->logAutomationError(sprintf(
                    'Kullanıcı #%d: %s için alım atlandı - sembol soğuma süresinde (%s tarihine kadar).',
                    $userId,
                    $pair,
                    $symbolCooldownUntil
                ));
                continue;
            }

            // Bakiye Yetersiz: yuzdeye gore hesaplanan butce, Binance'in asgari islem tutarinin
            // (MIN_ORDER_BUDGET_USDT) altinda kalirsa alim hic denenmez. Bu ARTIK genel bir
            // "AI-hunt islenirken hata" olarak gomulmuyor - kendi ACIK, kolayca aranabilir log
            // satirina sahip, cunku bu (10 Temmuz'da fark edildigi gibi) cok sik karsilasilan,
            // teshis edilmesi gereken bir durum: kucuk bakiyeli hesaplarda yuzde dusurulunce
            // butce sessizce asgari limitin altina dusebiliyor
            $budgetPercent = (float) ($userKey['auto_trade_budget_percent'] ?? 10.0);
            // Butce artik sabit bir USDT tutari degil, GUNCEL bakiyenin bir yuzdesi - hesap
            // buyudukce/kucul dukce otomatik olcekler, sabit tutar gibi bakiyeyi kazara yutmaz
            $budget = $usdtBalance * ($budgetPercent / 100);

            if ($budget < self::MIN_ORDER_BUDGET_USDT) {
                $this->logAutomationError(sprintf(
                    'Bakiye Yetersiz: Kullanıcı #%d %s için hesaplanan bütçe (%.2f USDT) Binance minimum limitinin (%.2f USDT) altında (bakiye: %.2f USDT, oran: %%%.1f) - bu turda alım atlandı.',
                    $userId,
                    $pair,
                    $budget,
                    self::MIN_ORDER_BUDGET_USDT,
                    $usdtBalance,
                    $budgetPercent
                ));
                continue;
            }

            try {
                $takeProfitPercent = (float) $userKey['take_profit_percent'];
                $stopLossPercent = (float) $userKey['stop_loss_percent'];
                $maxPortfolioRiskPercent = (float) ($userKey['max_portfolio_risk_percent'] ?? 30.0);

                // Toplam portfoy risk tavani: acik pozisyonlarin toplam maliyeti + bu yeni islem,
                // toplam ozkaynagin (bakiye + acik pozisyon maliyeti) belirlenen yuzdesini asiyorsa
                // - eszamanli pozisyon SAYISI limiti dolmamis olsa bile - yeni alim ACILMAZ
                // ($totalEquity yukarida devre kesici kontrolunden once zaten hesaplanmisti)
                $projectedExposure = $openPositionsCost + $budget;
                $projectedExposurePercent = $totalEquity > 0 ? ($projectedExposure / $totalEquity) * 100 : 0.0;

                if ($totalEquity > 0 && $projectedExposurePercent > $maxPortfolioRiskPercent) {
                    throw new RuntimeException(sprintf(
                        'Toplam portföy risk tavanı aşılıyor: açık pozisyonlar + yeni işlem %%%.1f (tavan %%%.1f)',
                        $projectedExposurePercent,
                        $maxPortfolioRiskPercent
                    ));
                }

                $price = $binance->getPrice($pair);

                if ($price <= 0) {
                    throw new RuntimeException("Geçerli fiyat alınamadı: {$pair}");
                }

                try {
                    $filters = $binance->getSymbolFilters($pair);
                    $stepSize = $filters['step_size'] > 0 ? $filters['step_size'] : 0.0001;
                    $tickSize = $filters['tick_size'] > 0 ? $filters['tick_size'] : 0.00000001;
                } catch (Throwable $e) {
                    $stepSize = 0.0001;
                    $tickSize = 0.00000001;
                }

                // 27 Temmuz'da degisti: piyasa emriyle ANINDA almak yerine, sinyal fiyatinin
                // PULLBACK_TARGET_PERCENT kadar ALTINA gercek bir LIMIT ALIS emri konur - bkz.
                // PULLBACK_TARGET_PERCENT ve PENDING_LIMIT_ORDER_TIMEOUT_MINUTES yorumlari (eski
                // aktif-bekleme/polling mekanizmasinin tamamen degistigi yer)
                $limitPrice = $this->floorToStep($price * (1 - self::PULLBACK_TARGET_PERCENT / 100), $tickSize);

                if ($limitPrice <= 0) {
                    throw new RuntimeException("Hesaplanan limit fiyatı sıfır veya negatif çıktı: {$pair}");
                }

                // LOT_SIZE (Adim Yuvarlama) Guvenlik Kalkani: 20 Temmuz'da canli tespit edilen
                // BTC olayindan sonra eklendi - dusuk butce + yuksek birim fiyatli bir varlikta
                // floorToStep() pozisyonun oransal olarak buyuk bir kismini "fire" olarak yiyip
                // İzleyen Zırh dahi kar kilitlese bile pozisyonu zararla kapanmaya zorlayabiliyordu.
                // Miktar artik ANLIK $price yerine LIMIT fiyatina gore hesaplanir (emir o fiyattan
                // dolacak, gercek maliyet o olacak)
                $lotSizeGuard = LotSizeGuardService::evaluate($budget / $limitPrice, $stepSize);

                if (!$lotSizeGuard['safe']) {
                    $this->logAutomationError(sprintf(
                        'İşlem İptal Edildi: %s için LOT_SIZE yuvarlama kaybı (%%%.2f) güvenlik sınırını (%%%.1f) aşıyor.',
                        $pair,
                        $lotSizeGuard['fire_percent'],
                        LotSizeGuardService::DEFAULT_MAX_SLIPPAGE_TOLERANCE_PERCENT
                    ));

                    AiIntervention::record(
                        $userId,
                        $pair,
                        'lot_size_guard',
                        sprintf(
                            'LOT_SIZE yuvarlama kaybı (%%%.2f) güvenlik sınırını (%%%.1f) aştığı için alım iptal edildi.',
                            $lotSizeGuard['fire_percent'],
                            LotSizeGuardService::DEFAULT_MAX_SLIPPAGE_TOLERANCE_PERCENT
                        )
                    );

                    continue;
                }

                $quantity = $lotSizeGuard['floored_quantity'];

                if ($quantity <= 0) {
                    throw new RuntimeException('Hesaplanan miktar sıfır veya negatif çıktı.');
                }

                // bkz. MAX_SAFE_ELAPSED_SECONDS_BEFORE_BUY yorumu: geri donusu olmayan Binance emir
                // cagrisindan HEMEN once - script zaten guvenli payin disina ciktiysa (kalan ~30sn
                // icinde PHP'nin yakalanamayan zaman asimina carpma riski varsa) YENI bir emre hic
                // girilmez, mevcut aciklar etkilenmeden bir sonraki cron turunde tekrar denenir
                $elapsedSeconds = microtime(true) - $this->requestStartedAt;

                if ($elapsedSeconds > self::MAX_SAFE_ELAPSED_SECONDS_BEFORE_BUY) {
                    throw new RuntimeException(sprintf(
                        'Güvenli zaman payı aşıldı (%.1fsn geçti, limit %dsn) - olası PHP zaman aşımı riskine karşı bu turda yeni alıma girilmedi.',
                        $elapsedSeconds,
                        self::MAX_SAFE_ELAPSED_SECONDS_BEFORE_BUY
                    ));
                }

                $orderResult = $binance->placeOrder($pair, 'BUY', 'LIMIT', $quantity, $limitPrice);

                if (!$orderResult['success']) {
                    Order::create([
                        'user_id' => $userId,
                        'pair' => $pair,
                        'side' => 'BUY',
                        'type' => 'LIMIT',
                        'quantity' => $quantity,
                        'price' => $limitPrice,
                        'total' => $budget,
                        'binance_order_id' => null,
                        'status' => 'FAILED',
                        'error_message' => $orderResult['error'],
                        'strategy_bucket' => $strategyBucket,
                    ]);

                    throw new RuntimeException('Limit alış emri başarısız: ' . $orderResult['error']);
                }

                $orderId = $orderResult['order_id'];

                if ($orderId === null) {
                    throw new RuntimeException('Limit alış emri Binance tarafından kabul edildi ama order ID dönmedi.');
                }

                // Bu noktadan itibaren Binance'in order book'unda GERCEK bir emir var (henuz para
                // hareket etmedi, emir dolmayi bekliyor) - asagidaki DB kaydi patlarsa emir "yetim"
                // kalir, Fast Tracker onu asla kontrol edemez/iptal edemez. Ayni ciddiyette acil
                // uyari (huntForAllUsers'in eski MARKET-buy senaryosundaki "hayalet pozisyon"
                // ilkesiyle AYNI, ama burada henuz dolmamis bir emir icin)
                try {
                    PendingLimitOrder::create([
                        'user_id' => $userId,
                        'pair' => $pair,
                        'binance_order_id' => $orderId,
                        'limit_price' => $limitPrice,
                        'quantity' => $quantity,
                        'budget' => $budget,
                        'budget_percent' => $budgetPercent,
                        'take_profit_percent' => $takeProfitPercent,
                        'stop_loss_percent' => $stopLossPercent,
                        'strategy_bucket' => $strategyBucket,
                        'score' => $score,
                        'rsi_1h' => $rsi1h,
                        'rsi_15m' => $rsi15m,
                    ]);

                    $this->logAutomationError(sprintf(
                        'Kullanıcı #%d: %s için limit alış emri konuldu (fiyat: %s, sinyalden %%%.2f aşağıda) - dolması bekleniyor (en fazla %d dk).',
                        $userId,
                        $pair,
                        $this->formatPrice($limitPrice),
                        self::PULLBACK_TARGET_PERCENT,
                        self::PENDING_LIMIT_ORDER_TIMEOUT_MINUTES
                    ));

                    $processedCount++;
                } catch (Throwable $e) {
                    $this->logAutomationError(
                        "KRİTİK: Kullanıcı #{$userId} için {$pair} limit alış emri Binance'te KONULDU ".
                        "(orderId: {$orderId}, fiyat: {$limitPrice}) ama sistem kaydı başarısız oldu: " . $e->getMessage()
                    );

                    $this->notifyAdminAndCustomer(
                        $userId,
                        "🚨 ACİL: Limit Emri Binance'te Konuldu ama Sistem Kaydı Başarısız!\n" .
                        "Coin: {$pair}\n" .
                        "Binance Order ID: {$orderId}\n" .
                        "Fiyat: {$this->formatPrice($limitPrice)}\n\n" .
                        'Bu emir Dashboard\'da GÖRÜNMEYEBİLİR ve sistem tarafından takip edilmiyor. ' .
                        'Lütfen borsa hesabını manuel olarak kontrol edin (gerekirse elle iptal edin).'
                    );
                }
            } catch (Throwable $e) {
                $this->logAutomationError("Kullanıcı #{$userId} AI-hunt işlenirken hata: " . $e->getMessage());
                continue;
            }
        }

        return $processedCount;
    }

    // Cesitlendirme Filtresi: $openTrades icindeki her paritenin adayla korelasyonunu sirayla
    // hesaplar, esigi (CORRELATION_REJECT_THRESHOLD) GECEN ILK eslesmede erken doner (tum listeyi
    // gezmeye gerek yok). MarketScanner::calculatePriceCorrelation() fail-open oldugu icin
    // (veri/API hatasinda null doner) bu fonksiyon da dogal olarak fail-open'dir - korelasyon
    // HESAPLANAMAYAN bir parite asla yanlislikla "yuksek korele" sayilmaz
    // @return array{pair: string, correlation: float}|null
    private function findHighlyCorrelatedOpenPosition(MarketScanner $scanner, string $candidatePair, array $openTrades): ?array
    {
        foreach ($openTrades as $trade) {
            $openPair = (string) $trade['pair'];

            if ($openPair === $candidatePair) {
                continue;
            }

            $correlation = $scanner->calculatePriceCorrelation($candidatePair, $openPair, self::CORRELATION_LOOKBACK_HOURS);

            if ($correlation !== null && $correlation >= self::CORRELATION_REJECT_THRESHOLD) {
                return ['pair' => $openPair, 'correlation' => $correlation];
            }
        }

        return null;
    }

    // Alimin hemen ardindan Kar Al (%takeProfitPercent) + Zarar Kes (%stopLossPercent) hedeflerini
    // TEK OCO emrinde birlestirip gonderir. Basariliysa active_trades'e acik pozisyon olarak kaydeder
    private function protectPositionWithOco(
        BinanceService $binance,
        int $userId,
        string $pair,
        int $buyOrderId,
        float $entryPrice,
        float $boughtQuantity,
        float $takeProfitPercent,
        float $stopLossPercent,
        float $budget,
        float $budgetPercent,
        ?string $strategyBucket = null,
        ?int $aiEntryScore = null,
        ?float $entryRsi1h = null,
        ?float $entryRsi15m = null
    ): void {
        try {
            $filters = $binance->getSymbolFilters($pair);
            $stepSize = $filters['step_size'] > 0 ? $filters['step_size'] : 0.0001;
            $tickSize = $filters['tick_size'] > 0 ? $filters['tick_size'] : 0.00000001;
        } catch (Throwable $e) {
            $stepSize = 0.0001;
            $tickSize = 0.00000001;
        }

        $takeProfitPrice = $this->floorToStep($entryPrice * (1 + $takeProfitPercent / 100), $tickSize);

        // bkz. WICK_SHIELD_MULTIPLIER yorumu - ilk OCO kullanicinin GERCEK SL'inden degil, gecici
        // olarak genisletilmis bir "Genis Kalkan"dan kurulur (asla kullanicinin ayarindan DAR olmaz)
        $wideShieldPercent = max($stopLossPercent * self::WICK_SHIELD_MULTIPLIER, self::WICK_SHIELD_MIN_PERCENT);
        $stopTriggerPrice = $this->floorToStep($entryPrice * (1 - $wideShieldPercent / 100), $tickSize);

        $ocoQuantity = $this->floorToStep($boughtQuantity * self::FEE_SAFETY_MARGIN, $stepSize);

        if ($ocoQuantity <= 0) {
            $this->logAutomationError("Kullanıcı #{$userId}: OCO miktarı sıfır çıktı, koruma emri girilmedi ({$pair}).");
            return;
        }

        // stopLimitPrice verilmiyor (null) - Zarar Kes bacagi duz STOP_LOSS (piyasa) emri olarak
        // tetiklenir, boylece volatil bir dususte limit emrin dolmadan fiyatin kacmasi (slippage)
        // riski ortadan kalkar (bkz. BinanceService::placeOCOOrder yorumu)
        $ocoResult = $binance->placeOCOOrder($pair, 'SELL', $ocoQuantity, $takeProfitPrice, $stopTriggerPrice);

        if (!$ocoResult['success']) {
            // Alim basarili ama koruma emri girilemedi: pozisyon korumasiz acik kaldi, manuel mudahale gerekebilir
            Order::create([
                'user_id' => $userId,
                'pair' => $pair,
                'side' => 'SELL',
                'type' => 'OCO',
                'quantity' => $ocoQuantity,
                'price' => $takeProfitPrice,
                'total' => round($ocoQuantity * $takeProfitPrice, 8),
                'binance_order_id' => null,
                'parent_order_id' => $buyOrderId,
                'status' => 'FAILED',
                'error_message' => $ocoResult['error'],
                'strategy_bucket' => $strategyBucket,
            ]);

            $this->logAutomationError(
                "KRİTİK: Kullanıcı #{$userId} için {$pair} alındı ama OCO (Kar Al/Zarar Kes) emri girilemedi: " . $ocoResult['error']
            );

            // 27 Temmuz'da eklendi: eskiden bu noktada ActiveTrade::create() HİÇ çağrılmıyordu -
            // pozisyon sistemde hiçbir iz bırakmadan "kayboluyordu". Canlıda tespit edildi (ZECUSDT,
            // saat senkronu -1021 hatası): hasOpenPositionForPair() bu satırı hiç görmediği için
            // aynı kullanıcı+parite bir sonraki turda TEKRAR alınabiliyordu (art arda 2-3 kez aynı
            // coin alındı). Artık oco_order_list_id/take_profit_order_id/stop_loss_order_id NULL
            // olarak "açık ama korumasız" kaydedilir - reconcileActiveTradesInternal()'daki mevcut
            // "if (oco_order_list_id === null) continue" koruması sayesinde bir sonraki tur bunu
            // yanlışlıkla "kapandı" saymaz, sadece atlar (aynı DCA-başarısız-OCO deseniyle AYNI ilke)
            $activeTradeId = ActiveTrade::create([
                'user_id' => $userId,
                'pair' => $pair,
                'buy_order_id' => $buyOrderId,
                'quantity' => $ocoQuantity,
                'entry_price' => $entryPrice,
                'take_profit_price' => $takeProfitPrice,
                'stop_loss_price' => $stopTriggerPrice,
                'oco_order_list_id' => null,
                'take_profit_order_id' => null,
                'stop_loss_order_id' => null,
                'status' => 'open',
                'ai_entry_score' => $aiEntryScore,
                'is_sl_tightened' => 0,
                'entry_rsi_1h' => $entryRsi1h,
                'entry_rsi_15m' => $entryRsi15m,
            ]);

            // 29 Temmuz'da eklendi: Acil Durum Protokolü (Triage) - "relationship of the prices"
            // hatasına ÖZEL, MIN_NOTIONAL gibi diğer OCO hatalarını ETKİLEMEZ (o ayrı bir dust/miktar
            // sorunu). Bu hata SADECE fiyatin alim ile OCO gonderimi arasinda banttan cikmasi
            // durumunda olusur - yani GUNCEL fiyati kontrol edip NEREDE oldugumuzu anlayabiliriz:
            // zaten Kar Al'i gecmisse kari cebe indir, zaten sert bir selale ile Zarar Kes'in de
            // altina dusmusse sermayeyi kurtar - ikisinde de asagidaki "tek basina Zarar Kes emri
            // dene" adimini BEKLEMEDEN dogrudan piyasadan satilir (COTIUSDT canli olayindan sonra
            // eklendi - bkz. konusma gecmisi)
            $emergencyClosed = false;

            if (str_contains($ocoResult['error'], 'relationship of the prices')) {
                try {
                    $currentPrice = (new BinanceService('', ''))->getPrice($pair);
                } catch (Throwable $e) {
                    $currentPrice = 0.0;
                }

                $waterfallThreshold = $stopTriggerPrice * (1 - self::EMERGENCY_WATERFALL_MARGIN_PERCENT / 100);
                $priceAlreadyPastTp = $currentPrice > 0 && $currentPrice >= $takeProfitPrice;
                $priceInWaterfall = $currentPrice > 0 && $currentPrice <= $waterfallThreshold;

                if ($priceAlreadyPastTp || $priceInWaterfall) {
                    try {
                        $marketSellResult = $binance->placeOrder($pair, 'SELL', 'MARKET', $ocoQuantity);

                        if ($marketSellResult['success']) {
                            $raw = $marketSellResult['raw'] ?? [];
                            $executedQty = (float) ($raw['executedQty'] ?? $ocoQuantity);
                            $cumulativeQuote = (float) ($raw['cummulativeQuoteQty'] ?? 0);
                            $exitPrice = $executedQty > 0 ? $cumulativeQuote / $executedQty : $currentPrice;
                            $exitTotal = $cumulativeQuote > 0 ? $cumulativeQuote : $exitPrice * $executedQty;

                            $this->finalizeSpotClose(
                                $binance,
                                [
                                    'id' => $activeTradeId,
                                    'user_id' => $userId,
                                    'pair' => $pair,
                                    'entry_price' => $entryPrice,
                                    'buy_order_id' => $buyOrderId,
                                    'highest_price_reached' => null,
                                    'lowest_price_reached' => null,
                                ],
                                $exitPrice,
                                $executedQty,
                                $exitTotal,
                                $marketSellResult['order_id'] !== null ? (int) $marketSellResult['order_id'] : null,
                                'market_emergency'
                            );

                            $this->logAutomationError(sprintf(
                                'ACİL DURUM PROTOKOLÜ: Kullanıcı #%d %s - OCO fiyat bandı reddi sonrası %s tespit edildi (güncel: %s, Kâr Al: %s, Zarar Kes: %s) - piyasadan anında satıldı, çıkış: %s.',
                                $userId,
                                $pair,
                                $priceAlreadyPastTp ? 'fiyat Kâr Al seviyesini geçmiş' : sprintf('şelale düşüşü (Zarar Kes\'in %%%.1f altı)', self::EMERGENCY_WATERFALL_MARGIN_PERCENT),
                                $this->formatPrice($currentPrice),
                                $this->formatPrice($takeProfitPrice),
                                $this->formatPrice($stopTriggerPrice),
                                $this->formatPrice($exitPrice)
                            ));

                            $this->notifyAdminAndCustomer(
                                $userId,
                                sprintf(
                                    "🚨 Acil Durum Protokolü Devrede\nCoin: %s\nOCO reddedildi (fiyat bandın dışına çıktı), pozisyon %s ile anında piyasadan kapatıldı.\nÇıkış: %s",
                                    $pair,
                                    $priceAlreadyPastTp ? 'kârda' : 'şelale düşüşünde',
                                    $this->formatPrice($exitPrice)
                                )
                            );

                            $emergencyClosed = true;
                        } else {
                            $this->logAutomationError("ACİL DURUM PROTOKOLÜ: Kullanıcı #{$userId} {$pair} - piyasa satışı da başarısız: " . ($marketSellResult['error'] ?? 'bilinmeyen hata'));
                        }
                    } catch (Throwable $e) {
                        $this->logAutomationError("ACİL DURUM PROTOKOLÜ: Kullanıcı #{$userId} {$pair} - piyasa satışı sırasında istisna: " . $e->getMessage());
                    }
                }
            }

            if ($emergencyClosed) {
                return;
            }

            // 28 Temmuz'da eklendi: COTIUSDT canlı olayı (2 kullanıcı aynı anda) - OCO'nun "relationship
            // of the prices" hatasıyla reddedilmesinin tipik sebebi, alım ile OCO gönderimi arasındaki
            // saniyelerde fiyatın zaten entryPrice'a göre hesaplanan Kar Al/Zarar Kes bandının DIŞINA
            // çıkmış olması (hızlı hareket eden düşük hacimli coin). Yukarıdaki Acil Durum Protokolü
            // devreye girmediyse (fiyat hâlâ TP/SL aralığında, sadece küçük bir iğne), GÜNCEL fiyattan
            // hesaplanmış TEK BAŞINA bir Zarar Kes emri denenir - OCO'dan farklı olarak Binance'in
            // "relationship" kısıtı yok, tek koşul stopPrice güncel fiyatın altında olması. Başarılı
            // olursa pozisyon en azından asagi yonde korunur (Kar Al otomasyonu bu turda yok, sonraki
            // Izleyen Stop/DCA turlarinda normal akisa doner) - hicbir koruma denemeden tamamen
            // ciplak birakmaktan HER ZAMAN daha iyidir
            $fallbackProtected = false;

            try {
                $currentPrice = (new BinanceService('', ''))->getPrice($pair);

                if ($currentPrice > 0) {
                    $freshStopPrice = $this->floorToStep($currentPrice * (1 - $wideShieldPercent / 100), $tickSize);

                    if ($freshStopPrice < $currentPrice) {
                        $fallbackResult = $binance->placeStopLossOrder($pair, 'SELL', $ocoQuantity, $freshStopPrice);

                        if ($fallbackResult['success']) {
                            ActiveTrade::applyTakeProfitRemoval(
                                $activeTradeId,
                                $freshStopPrice,
                                $fallbackResult['order_id'] !== null ? (int) $fallbackResult['order_id'] : null,
                                null
                            );
                            $fallbackProtected = true;
                        }
                    }
                }
            } catch (Throwable $e) {
                // Yedek deneme de basarisiz - asagida zaten "korumasiz" uyarisi gidecek, ek islem gerekmez
            }

            // Pozisyon korumasiz acikta kaldi - saniyeler icinde hem musteri hem admin mudahale edebilsin diye anlik uyari
            $this->notifyAdminAndCustomer(
                $userId,
                $fallbackProtected
                    ? ("⚠️ OCO Emri Başarısız, Yedek Zarar Kes Devrede\n" .
                        "Coin: {$pair}\n" .
                        "Hata: {$ocoResult['error']}\n\n" .
                        'Kâr Al otomasyonu bu pozisyon için YOK, ama güncel fiyattan tek başına bir Zarar Kes emri girildi - pozisyon aşağı yönde korunuyor. Yine de borsa hesabınızı kontrol etmeniz önerilir.')
                    : ("🚨 ACİL: OCO Emri Girilemedi, Pozisyon Korumasız!\n" .
                        "Coin: {$pair}\n" .
                        "Hata: {$ocoResult['error']}\n\n" .
                        'Lütfen borsa hesabını manuel olarak kontrol edin.')
            );

            return;
        }

        $activeTradeId = ActiveTrade::create([
            'user_id' => $userId,
            'pair' => $pair,
            'buy_order_id' => $buyOrderId,
            'quantity' => $ocoQuantity,
            'entry_price' => $entryPrice,
            'take_profit_price' => $takeProfitPrice,
            'stop_loss_price' => $stopTriggerPrice,
            'oco_order_list_id' => $ocoResult['order_list_id'],
            'take_profit_order_id' => $ocoResult['take_profit_order_id'],
            'stop_loss_order_id' => $ocoResult['stop_loss_order_id'],
            'status' => 'open',
            'ai_entry_score' => $aiEntryScore,
            'is_sl_tightened' => 0,
            'entry_rsi_1h' => $entryRsi1h,
            'entry_rsi_15m' => $entryRsi15m,
        ]);

        ActiveTrade::addFillRecord($activeTradeId, $buyOrderId, $ocoQuantity, $entryPrice, 'initial');

        $entryMessage = "🎯 [NexaTrade] Yeni Pozisyon Açıldı!\n" .
            "Coin: {$pair} | Giriş Fiyatı: {$this->formatPrice($entryPrice)} | Kullanılan Bütçe: {$this->formatPrice($budget)}$ (Kasanın %{$this->formatPercentTrim($budgetPercent)}'si)\n\n" .
            "🛡️ Koruma Aktif — Kâr Al: {$this->formatPrice($takeProfitPrice)} | Zarar Kes: {$this->formatPrice($stopTriggerPrice)} " .
            "(fitil koruması: ilk " . self::WICK_SHIELD_MINUTES . " dk geniş tutulur, sonra asıl hedefe sıkılaştırılır)";

        // Musteri talebi (31 Temmuz): "neye gore aldi, teknik bilgi versin" - bkz. buildTechnicalContext()
        // yorumu (giris anindaki GUNCEL teknik durumun anlik goruntusu, tarama turunun arsivlenmis
        // gerekcesinin AYNISI olmayabilir). AI modundaysa GPT'nin kendi skoru da (varsa) eklenir
        $technicalContext = $this->buildTechnicalContext($pair);

        if ($technicalContext !== null) {
            $entryMessage .= "\n🔍 Teknik durum (skor {$technicalContext['score']}/100): {$technicalContext['reason']}";
        }

        if ($aiEntryScore !== null) {
            $entryMessage .= "\n🤖 AI Karar Skoru: {$aiEntryScore}/100";
        }

        $this->notifyCustomer($userId, $entryMessage);
    }

    // Binance'in resmi exchangeInfo listesini onceki taramada kaydedilenle karsilastirir
    // Ilk calistirmada (tablo bosken) tum mevcut pariteler taban olarak kaydedilir, "yeni" sayilmaz;
    // sonraki her calistirmada listede daha once olmayan pariteler "yeni listelenen" olarak isaretlenir
    private function detectNewListings(MarketScanner $scanner): int
    {
        try {
            $liveSymbols = $scanner->fetchTradableUsdtSymbols();

            if (KnownSymbol::count() === 0) {
                KnownSymbol::insertMany($liveSymbols, isBootstrap: true);
                return 0;
            }

            $knownSymbols = KnownSymbol::findAllSymbols();
            $newSymbols = array_values(array_diff($liveSymbols, $knownSymbols));

            if ($newSymbols !== []) {
                KnownSymbol::insertMany($newSymbols);
            }

            return count($newSymbols);
        } catch (Throwable $e) {
            $this->logAutomationError('Yeni listeleme taraması hatası: ' . $e->getMessage());
            return 0;
        }
    }

    // 27 Temmuz'da eklendi: eski aktif-bekleme (12sn polling) tabanli Pullback Kalkani'nin yerini
    // alan bekleyen LIMIT ALIS emirlerini kontrol eder - bkz. PendingLimitOrder yorumu. SADECE
    // Fast Tracker'dan (1dk, GPT-siz) cagrilir, ayri bir kilit gerektirmez (Fast Tracker'in kendi
    // FAST_TRACKER_CRON_LOCK_NAME kilidi zaten es zamanli iki calismayi engeller, ana cron run()
    // bu tabloya hic dokunmaz). Her satir icin: DOLDUYSA gercek pozisyona donusturulur (protect
    // PositionWithOco ile AYNI yol, huntForAllUsers'daki eski MARKET-buy sonrasi adimla BIREBIR
    // ayni), iptal/suresi dolmus/reddedilmisse temizlenir, hala bekliyorsa (suresi dolmadiysa)
    // dokunulmaz
    private function checkPendingLimitOrders(): int
    {
        $pendingOrders = PendingLimitOrder::findAll();
        $convertedCount = 0;

        foreach ($pendingOrders as $pending) {
            $pendingId = (int) $pending['id'];
            $userId = (int) $pending['user_id'];
            $pair = (string) $pending['pair'];
            $orderId = (int) $pending['binance_order_id'];

            Database::ensureConnected();

            try {
                $apiKey = ApiKey::findByUser($userId)[0] ?? null;

                if ($apiKey === null) {
                    // Kullanicinin API anahtari silinmis/degismis olabilir - emri takip etmenin
                    // anlami yok, kaydi temizle (Binance'teki emir kendi TTL'iyle GTC oldugu icin
                    // sonsuza kadar acik kalabilir, ama bu ARTIK sistemin sorumlulugunda degil -
                    // ayni "elle kontrol edin" ilkesi PendingLimitOrder olusturma hatasindaki gibi)
                    $this->logAutomationError("Bekleyen limit emri #{$pendingId} ({$pair}): Kullanıcı #{$userId} için API anahtarı bulunamadı, kayıt temizlendi.");
                    PendingLimitOrder::delete($pendingId);
                    continue;
                }

                $binance = new BinanceService($apiKey['api_key'], $apiKey['secret_key']);
                $orderStatus = $binance->getOrderStatus($pair, $orderId);
                $status = strtoupper((string) ($orderStatus['status'] ?? ''));

                if ($status === 'FILLED') {
                    $this->convertFilledPendingOrder($binance, $pending, $orderStatus);
                    PendingLimitOrder::delete($pendingId);
                    $convertedCount++;
                    continue;
                }

                if (in_array($status, ['CANCELED', 'EXPIRED', 'REJECTED'], true)) {
                    // Emir Binance tarafinda (ör. elle iptal, borsa kurallari) zaten sona ermis -
                    // bizim iptal etmemize gerek yok, sadece kaydi temizle. Kisa bir soguma da
                    // uygulanir (bkz. PENDING_LIMIT_ORDER_CANCEL_COOLDOWN_HOURS yorumu) - aksi halde
                    // kullanicinin ELLE iptal ettigi bir emir, coin hala sinyal veriyorsa bir sonraki
                    // turde hemen tekrar acilirdi
                    $this->logAutomationError("Bekleyen limit emri: {$pair} (Kullanıcı #{$userId}) Binance'te {$status} durumunda - kayıt temizlendi, kısa soğuma uygulandı.");
                    SymbolCooldown::setCooldown($userId, $pair, self::PENDING_LIMIT_ORDER_CANCEL_COOLDOWN_HOURS, 'Bekleyen limit emri dolmadan sona erdi (' . $status . ')');
                    PendingLimitOrder::delete($pendingId);
                    continue;
                }

                // Hala NEW veya PARTIALLY_FILLED - suresi dolmadiysa dokunma, bir sonraki turda
                // tekrar kontrol edilecek. age_minutes PendingLimitOrder::findAll()'da MySQL'in
                // KENDI saatiyle (NOW()) zaten hesaplanmis geliyor - bkz. o metodun yorumu (ZAMAUSDT
                // #97 canli olayi: PHP strtotime()/time() ile hesaplanan eski versiyon, VPS'te PHP/
                // MySQL saat dilimi farki yuzunden emri SONSUZA KADAR "yeni" saniyordu)
                $ageMinutes = (int) ($pending['age_minutes'] ?? 0);

                if ($ageMinutes < self::PENDING_LIMIT_ORDER_TIMEOUT_MINUTES) {
                    continue;
                }

                // Sure doldu - emri iptal et. KISMEN dolmus olabilir (PARTIALLY_FILLED): iptal
                // yaniti o ana kadar gerceklesen miktari da dondurur - eger gercekten bir miktar
                // alindiysa bunu YOK SAYAMAYIZ (gercek para hareket etti), ayni "hayalet pozisyon"
                // riskine dusmemek icin kismi dolum da gercek bir pozisyona donusturulur
                $cancelResult = $binance->cancelOrder($pair, $orderId);

                if (!$cancelResult['success']) {
                    $this->logAutomationError("Bekleyen limit emri: {$pair} (Kullanıcı #{$userId}) süresi doldu ama iptal başarısız oldu: " . ($cancelResult['error'] ?? 'bilinmiyor') . ' - bir sonraki turda tekrar denenecek.');
                    continue;
                }

                $executedQty = (float) ($cancelResult['raw']['executedQty'] ?? 0);

                if ($executedQty > 0) {
                    $this->logAutomationError("Bekleyen limit emri: {$pair} (Kullanıcı #{$userId}) süresi dolup iptal edildi ama kısmen ({$executedQty}) dolmuştu - gerçek (kısmi) pozisyon olarak kaydediliyor.");
                    $this->convertFilledPendingOrder($binance, $pending, $cancelResult['raw']);
                } else {
                    $this->logAutomationError(sprintf(
                        'Bekleyen limit emri: %s (Kullanıcı #%d) süresi doldu (%d dk), hiç dolmadan iptal edildi, kısa soğuma uygulandı.',
                        $pair,
                        $userId,
                        self::PENDING_LIMIT_ORDER_TIMEOUT_MINUTES
                    ));
                    SymbolCooldown::setCooldown($userId, $pair, self::PENDING_LIMIT_ORDER_CANCEL_COOLDOWN_HOURS, 'Bekleyen limit emri süresi dolup hiç dolmadan iptal edildi');
                }

                PendingLimitOrder::delete($pendingId);
            } catch (Throwable $e) {
                $this->logAutomationError("Bekleyen limit emri #{$pendingId} ({$pair}) kontrol edilirken hata: " . $e->getMessage());
            }
        }

        return $convertedCount;
    }

    // checkPendingLimitOrders()'in FILLED (tam veya sureli-iptal sonrasi kismi) durumunda ortak
    // kullandigi donusum adimi - huntForAllUsers'daki eski MARKET-buy-sonrasi adimla BIREBIR AYNI
    // mantik (Order::create + protectPositionWithOco), sadece tetikleyici farkli (aninda degil,
    // bir Fast Tracker turunde tespit edilince)
    private function convertFilledPendingOrder(BinanceService $binance, array $pending, array $rawOrderResponse): void
    {
        $userId = (int) $pending['user_id'];
        $pair = (string) $pending['pair'];
        $requestedQuantity = (float) $pending['quantity'];

        $executedQty = (float) ($rawOrderResponse['executedQty'] ?? $requestedQuantity);
        $cumulativeQuote = (float) ($rawOrderResponse['cummulativeQuoteQty'] ?? 0);
        $entryPrice = $executedQty > 0 ? $cumulativeQuote / $executedQty : (float) $pending['limit_price'];
        $commission = BinanceService::extractFillCommission($rawOrderResponse);

        try {
            $buyOrderId = Order::create([
                'user_id' => $userId,
                'pair' => $pair,
                'side' => 'BUY',
                'type' => 'LIMIT',
                'quantity' => $executedQty > 0 ? $executedQty : $requestedQuantity,
                'price' => $entryPrice,
                'total' => $cumulativeQuote > 0 ? $cumulativeQuote : (float) $pending['budget'],
                'binance_order_id' => (int) $pending['binance_order_id'],
                'status' => 'FILLED',
                'strategy_bucket' => $pending['strategy_bucket'],
                'commission' => $commission['commission'],
                'commission_asset' => $commission['commission_asset'],
            ]);

            $this->protectPositionWithOco(
                $binance,
                $userId,
                $pair,
                $buyOrderId,
                $entryPrice,
                $executedQty > 0 ? $executedQty : $requestedQuantity,
                (float) $pending['take_profit_percent'],
                (float) $pending['stop_loss_percent'],
                (float) $pending['budget'],
                (float) $pending['budget_percent'],
                $pending['strategy_bucket'],
                $pending['score'] !== null ? (int) $pending['score'] : null,
                $pending['rsi_1h'] !== null ? (float) $pending['rsi_1h'] : null,
                $pending['rsi_15m'] !== null ? (float) $pending['rsi_15m'] : null
            );
        } catch (Throwable $e) {
            // huntForAllUsers()'daki "hayalet pozisyon" ilkesiyle AYNI: bu noktada Binance'te
            // GERCEK bir alim var, sessizce loglayip gecmek YETMEZ
            $this->logAutomationError(
                "KRİTİK: Kullanıcı #{$userId} için {$pair} limit emri DOLDU ".
                "(orderId: {$pending['binance_order_id']}, miktar: {$executedQty}, fiyat: {$entryPrice}) ".
                "ama işlem sonrası kayıt/koruma adımı başarısız oldu: " . $e->getMessage()
            );

            $this->notifyAdminAndCustomer(
                $userId,
                "🚨 ACİL: Limit Emri Doldu ama Sistem Kaydı Başarısız!\n" .
                "Coin: {$pair}\n" .
                "Binance Order ID: {$pending['binance_order_id']}\n" .
                "Miktar: {$executedQty} | Fiyat: {$this->formatPrice($entryPrice)}\n\n" .
                'Bu pozisyon Dashboard\'da GÖRÜNMEYEBİLİR ve koruma emri girilmemiş olabilir. ' .
                'Lütfen borsa hesabını manuel olarak kontrol edin.'
            );
        }
    }

    // Acik pozisyonlarin OCO grubunun Binance'teki guncel durumunu kontrol eder
    // Kar Al bacagi gerceklestiyse "closed_profit", Zarar Kes bacagi gerceklestiyse "closed_loss" olarak kapatir
    // ve gercek gerceklesme fiyati/miktariyla orders tablosuna kalici bir SATIS kaydi dusurur (PNL hesaplamasi icin)
    // Ana cron ve Fast Tracker'in AYNI acik pozisyonlari es zamanli islemesini engelleyen ince
    // kilit - bkz. RECONCILE_LOCK_NAME yorumu. Kilit mesgulse (diger cagiran TAM O ANDA mutabakat
    // yapiyor) bu tur SESSIZCE atlanir, 0 doner - bir sonraki cagrida (ya ana cron ya Fast Tracker,
    // hangisi once tetiklenirse) normal sekilde devam eder, veri kaybi olmaz
    private function reconcileActiveTrades(): int
    {
        if (!CronLock::acquire(self::RECONCILE_LOCK_NAME, self::RECONCILE_LOCK_TIMEOUT_SECONDS)) {
            return 0;
        }

        try {
            return $this->reconcileActiveTradesInternal();
        } finally {
            CronLock::release(self::RECONCILE_LOCK_NAME);
        }
    }

    // Bkz. BINANCE_CONNECTIVITY_ALERT_THRESHOLD_MINUTES yorumu - ilk basarisiz cagrida sadece
    // "kesinti baslangici" zaman damgasi yazilir, esik asilana kadar SESSIZCE bekler (her cron
    // turunda tekrar tekrar uyari gondermez)
    private function recordBinanceConnectivityFailure(): void
    {
        $firstFailureAt = Setting::get(self::BINANCE_CONNECTIVITY_FIRST_FAILURE_SETTING_KEY);

        if ($firstFailureAt === null) {
            Setting::set(self::BINANCE_CONNECTIVITY_FIRST_FAILURE_SETTING_KEY, (string) time());

            return;
        }

        if (Setting::get(self::BINANCE_CONNECTIVITY_ALERT_SENT_SETTING_KEY) === '1') {
            return; // bu kesinti icin zaten uyarildi, tekrar spam etme
        }

        $elapsedMinutes = (time() - (int) $firstFailureAt) / 60;

        if ($elapsedMinutes < self::BINANCE_CONNECTIVITY_ALERT_THRESHOLD_MINUTES) {
            return;
        }

        (new TelegramService())->notifyAdmin(sprintf(
            "🚨 KRİTİK: Binance Bağlantı Sorunu\n\nSistem yaklaşık %d dakikadır Binance API'sine bağlanamıyor (muhtemelen barındırma sunucusunun ağ/DDoS koruma kesintisi). Pozisyon mutabakatı ve koruma kontrolleri bu süre boyunca çalışamıyor olabilir.\n\nBağlantı düzelince ayrıca bilgilendirileceksiniz.",
            (int) round($elapsedMinutes)
        ));

        Setting::set(self::BINANCE_CONNECTIVITY_ALERT_SENT_SETTING_KEY, '1');
    }

    // Bir Binance cagrisi basarili oldugunda cagrilir - devam eden bir kesinti kaydi varsa temizler,
    // daha once kritik uyari gonderildiyse ("streak" esigi asildiysa) ayrica "duzeldi" mesaji atar
    private function recordBinanceConnectivitySuccess(): void
    {
        $firstFailureAt = Setting::get(self::BINANCE_CONNECTIVITY_FIRST_FAILURE_SETTING_KEY);

        if ($firstFailureAt === null) {
            return; // devam eden bir kesinti kaydi yok, yapilacak bir sey yok
        }

        if (Setting::get(self::BINANCE_CONNECTIVITY_ALERT_SENT_SETTING_KEY) === '1') {
            (new TelegramService())->notifyAdmin(
                '✅ Binance bağlantısı düzeldi, sistem normal çalışmaya devam ediyor.'
            );
        }

        Setting::set(self::BINANCE_CONNECTIVITY_FIRST_FAILURE_SETTING_KEY, '');
        Setting::set(self::BINANCE_CONNECTIVITY_ALERT_SENT_SETTING_KEY, '');
    }

    // 28 Temmuz'da eklendi: cron-job.org (TUM cron endpoint'lerini tetikleyen dis servis) sabit,
    // yukseltilemeyen 30sn zaman asimina sahip - canli olayda SOLUSDT #193 Zarar Kes'i gecmisken
    // sistemde 'open' kalmis, Telegram bildirimi gitmemisti (kok neden: SentimentService'in
    // standart-disi 10sn/15sn timeout'lari, ayrica DUZELTILDI). Bu butce, SentimentService duzeltmesi
    // yetmezse (ör. Binance API kendisi yavaslarsa) bile dongunun 30sn'yi asip TUM turu (henuz
    // baslanmamis pozisyonlar dahil) sessizce kaybetmesini onler - butce dolarsa kalan pozisyonlar bir
    // sonraki cron turuna (fast-tracker 1 dk'da bir) birakilir, sonsuza kadar unutulmazlar
    private const RECONCILE_TIME_BUDGET_SECONDS = 20.0;

    private function reconcileActiveTradesInternal(): int
    {
        $loopStartedAt = microtime(true);
        $openTrades = ActiveTrade::findAllOpen();
        $reconciledCount = 0;

        // Dinamik Kaçış Protokolü: acik pozisyonlarin AI skorunu YENIDEN kontrol etmek ucretli
        // (OpenAI) oldugu icin, kendi ayri throttle'i (POSITION_MONITOR_INTERVAL_SECONDS) ile
        // sinirlanir. Skorlar TUM acik pozisyonlar icin (sembol basina TEK cagriyla) ONCEDEN,
        // dongu disinda hesaplanir - dongu icinde TEKRAR OpenAI cagrisi YAPILMAZ
        $shouldCheckAiExit = $openTrades !== [] && $this->shouldRunPositionMonitor();
        $sentimentScores = $shouldCheckAiExit ? $this->scoreOpenPositionSymbols($openTrades) : [];

        if ($shouldCheckAiExit) {
            Setting::set(self::POSITION_MONITOR_SETTING_KEY, (string) time());
        }

        $checkedCount = 0;

        foreach ($openTrades as $trade) {
            $tradeId = (int) $trade['id'];
            $userId = (int) $trade['user_id'];
            $pair = (string) $trade['pair'];

            // Zaman butcesi: bir sonraki pozisyonu ISLEMEYE BASLAMADAN once kontrol edilir - halihazirda
            // baslanmis bir pozisyonun ortasinda asla kesilmez, sadece kalanlari bir sonraki tura birakir
            if ((microtime(true) - $loopStartedAt) > self::RECONCILE_TIME_BUDGET_SECONDS) {
                $this->logAutomationError(sprintf(
                    'Mutabakat zaman bütçesi (%.0fsn) aşıldı, %d/%d pozisyon bu turda kontrol edilemedi, bir sonraki turda devam edilecek.',
                    self::RECONCILE_TIME_BUDGET_SECONDS,
                    count($openTrades) - $checkedCount,
                    count($openTrades)
                ));
                break;
            }

            $checkedCount++;

            // bkz. Database::ensureConnected() yorumu - her pozisyon icin Binance/AI cagrilari
            // birikince baglanti kopmus olabilir, sonraki DB yazimindan once dogrulanir
            Database::ensureConnected();

            try {
                // Kar Al Tavani Kaldirilmis (bkz. applyContinuousTrailing/removeTakeProfitCeiling)
                // pozisyonlarda artik bir OCO grubu yok - oco_order_list_id KALICI olarak NULL'dur,
                // bu ARTIK "korumasiz" anlamina gelmez. Gercekten korumasiz olan tek durum: ne OCO
                // ne tekil Zarar Kes emri var (ör. removeTakeProfitCeiling/replaceStopOnlyOrder
                // basarisiz olup clearOcoReference cagirdiginda)
                $takeProfitRemoved = (bool) ((int) ($trade['take_profit_removed'] ?? 0));

                if (!$takeProfitRemoved && $trade['oco_order_list_id'] === null) {
                    // 31 Temmuz'da eklendi: bu dal eskiden SESSIZCE atlanip pozisyonu SONSUZA KADAR
                    // mutabakat disinda birakiyordu - bkz. alertIfUnprotected() yorumu (Volkan #243
                    // BANKUSDT canli olayi: OCO hic girilememis, hicbir alarm/log tekrarlanmadigi
                    // icin fark edilmeden gunlerce boyle kaldi)
                    $this->alertIfUnprotected($trade);
                    continue;
                }

                if ($takeProfitRemoved && $trade['stop_loss_order_id'] === null) {
                    // Kar Al kaldirilmis ama emir yok - gercekten korumasiz, atla
                    $this->alertIfUnprotected($trade);
                    continue;
                }

                $apiKey = ApiKey::findByUser($userId)[0] ?? null;

                if ($apiKey === null) {
                    continue;
                }

                $binance = new BinanceService($apiKey['api_key'], $apiKey['secret_key']);

                if ($takeProfitRemoved) {
                    $reconciledCount += $this->reconcileTakeProfitRemovedTrade($binance, $trade, $shouldCheckAiExit, $sentimentScores);
                    continue;
                }

                $ocoStatus = $binance->getOcoOrderStatus((int) $trade['oco_order_list_id']);
                $this->recordBinanceConnectivitySuccess();
                $listStatus = strtoupper((string) ($ocoStatus['listOrderStatus'] ?? ''));

                if ($listStatus !== 'ALL_DONE') {
                    // Trade Diagnostics: pozisyon ACIKKEN, trailing/kismi kar alma durumundan
                    // BAGIMSIZ HER turda zirve/dip guncellenir - asagidaki erken-cikis/DCA
                    // kararlarindan ONCE (o kararlar pozisyonu bu turda kapatsa bile, kapanmadan
                    // HEMEN once gorulen bu fiyat noktasi gecerli/degerlidir). Kendi bagimsiz
                    // public (imzasiz) fiyat sorgusu kullanir - Pullback Kalkani'ndaki AYNI hafif
                    // desen, mevcut $binance (imzali) istemcinin cagri butcesini TUKETMEZ
                    $diagnosticPrice = (new BinanceService('', ''))->getPrice($pair);

                    if ($diagnosticPrice > 0) {
                        ActiveTrade::updatePriceExtremes($tradeId, $diagnosticPrice);
                        $this->checkRiseAlert($trade, $diagnosticPrice);
                    }

                    // Dinamik Kaçış Protokolü: EN ONCE kontrol edilir - AI skoru kritik cokusteyse
                    // pozisyon hemen kapatilir, asagidaki İzleyen Stop/DCA kontrolleri artik anlamsiz
                    // (pozisyon zaten kapali) oldugu icin ATLANIR
                    if ($shouldCheckAiExit && $this->attemptEarlyExitOnAiCollapse($binance, $trade, $sentimentScores)) {
                        $reconciledCount++;
                        continue;
                    }

                    // Kademeli Kâr Alma: AI skoruna bagli degil, her turda kontrol edilebilir - Erken
                    // Kacis'tan HEMEN SONRA (pozisyon zaten kapandiysa anlamsiz) ama İzleyen
                    // Stop/DCA'dan ONCE calisir. Bu turda tetiklendiyse (quantity/OCO zaten
                    // guncellendi) asagidaki kontroller AYNI turda TEKRAR calismaz - bir sonraki
                    // turda taze veriyle devam eder (DCA motorundaki AYNI "bir turda bir islem" deseni)
                    if ($this->applyPartialTakeProfitIfEligible($binance, $trade)) {
                        continue;
                    }

                    // Kademeli Izleyen Stop: AI skoruna bagli degil, her turda kontrol edilebilir
                    $this->applyTrailingStopIfEligible($binance, $trade);

                    // Ani Fitil Korumasi sikilastirmasi: İzleyen Stop'tan HEMEN SONRA calisir (bkz.
                    // tightenStopLossIfEligible yorumu) - trailing AYNI turda devreye girdiyse TAZE
                    // veriyle bunu gorup SL'in uzerine yazmaktan kacinir
                    $this->tightenStopLossIfEligible($binance, $trade, $apiKey);

                    // KRITIK DUZELTME #1: OCO durumu ONCE kontrol edildi (hala EXECUTING) - simdi
                    // DCA'ya uygun mu diye bakilabilir. Eger OCO zaten ALL_DONE olsaydi buraya hic
                    // girilmezdi, boylece tamamlanmis bir OCO'yu yanlislikla iptale calisma riski yok
                    $this->attemptDcaIfEligible($binance, $trade, $apiKey);
                    continue;
                }

                $filledLeg = null;
                // SADECE mekanik aciklama icin (log metinlerinde "Kar Al bacagi mi Zarar Kes bacagi
                // mi gerceklesti" demek icin) - ARTIK status/cooldown/bildirim kararini BELIRLEMEZ,
                // bkz. asagidaki KRITIK DUZELTME yorumu
                $filledLegType = null;

                if ($trade['take_profit_order_id'] !== null) {
                    $tpStatus = $binance->getOrderStatus($pair, (int) $trade['take_profit_order_id']);

                    if (strtoupper((string) ($tpStatus['status'] ?? '')) === 'FILLED') {
                        $filledLeg = $tpStatus;
                        $filledLegType = 'take_profit';
                    }
                }

                if ($filledLeg === null && $trade['stop_loss_order_id'] !== null) {
                    $slStatus = $binance->getOrderStatus($pair, (int) $trade['stop_loss_order_id']);

                    if (strtoupper((string) ($slStatus['status'] ?? '')) === 'FILLED') {
                        $filledLeg = $slStatus;
                        $filledLegType = 'stop_loss';
                    }
                }

                if ($filledLeg === null) {
                    // 10 Temmuz'da (TIAUSDT) tespit edilen veri kaybi: getOrderStatus() bir API
                    // kesintisi yuzunden BASARISIZ olmadan da (ikisi de FILLED disinda bir durum
                    // donerse) buraya duserdi ve pozisyon SONSUZA KADAR "kapandi, ama nasil
                    // bilinmiyor" durumunda kalirdi - hicbir SATIS kaydi/PNL asla olusmazdi. Son
                    // care olarak Binance'in KENDI gercek islem gecmisinden (myTrades), bilinen
                    // take_profit/stop_loss orderId'leriyle eslesen gercek bir fill arar
                    $fallbackFill = $this->findFillFromTradeHistory(
                        $binance,
                        $pair,
                        $trade['take_profit_order_id'] !== null ? (int) $trade['take_profit_order_id'] : null,
                        $trade['stop_loss_order_id'] !== null ? (int) $trade['stop_loss_order_id'] : null
                    );

                    if ($fallbackFill !== null) {
                        $filledLeg = $fallbackFill['leg'];
                        $filledLegType = $fallbackFill['is_profit'] ? 'take_profit' : 'stop_loss';
                        $this->logAutomationError(sprintf(
                            'Mutabakat yedeği: Pozisyon #%d (%s) OCO durumu API\'den net alınamadı, ama Binance işlem geçmişinden (myTrades) gerçek kapanış doğrulandı (%s).',
                            $tradeId,
                            $pair,
                            $filledLegType === 'take_profit' ? 'Kâr Al' : 'Zarar Kes'
                        ));
                    }
                }

                if ($filledLeg === null) {
                    // OCO tamamlandi ama hangi bacagin gerceklestigi belirlenemedi (ör. ikisi de iptal/expired)
                    // VE Binance islem gecmisinde de eslesen bir fill bulunamadi (ör. musteri manuel
                    // iptal edip kendi emrini girmis olabilir - bkz. TIAUSDT RCA)
                    ActiveTrade::markClosed($tradeId, 'closed_manual');
                    $this->logAutomationError("Pozisyon #{$tradeId} ({$pair}) OCO tamamlandı ama gerçekleşen bacak belirlenemedi (myTrades yedeği de sonuçsuz), manuel incelemeye alındı.");
                    continue;
                }

                $executedQty = (float) ($filledLeg['executedQty'] ?? $trade['quantity']);
                $cumulativeQuote = (float) ($filledLeg['cummulativeQuoteQty'] ?? 0);
                $exitPrice = $executedQty > 0 ? $cumulativeQuote / $executedQty : (float) $filledLeg['price'];
                $exitTotal = $cumulativeQuote > 0 ? $cumulativeQuote : $exitPrice * $executedQty;
                $exitOrderId = isset($filledLeg['orderId']) ? (int) $filledLeg['orderId'] : null;

                $this->finalizeSpotClose($binance, $trade, $exitPrice, $executedQty, $exitTotal, $exitOrderId, 'OCO');
                $reconciledCount++;
            } catch (Throwable $e) {
                if ($e instanceof BinanceApiTimeoutException) {
                    $this->recordBinanceConnectivityFailure();
                }

                $this->logAutomationError("Pozisyon #{$tradeId} mutabakatı sırasında hata: " . $e->getMessage());
                continue;
            }
        }

        return $reconciledCount;
    }

    // Kar Al Tavani Kaldirilmis (take_profit_removed=1) pozisyonlar icin reconcileActiveTrades()'in
    // ALTERNATIF yolu: artik bir OCO grubu olmadigi icin getOcoOrderStatus() yerine TEKIL Zarar Kes
    // emrinin durumuna (getOrderStatus) bakilir. Kademeli Kar Alma/DCA bu modda GECERSIZDIR - DCA
    // sadece ZARAR bolgesinde tetiklenir (bkz. attemptDcaIfEligible), bu pozisyon zaten Sinirsiz
    // Izleme'ye ulasacak kadar KARDA oldugu icin ikisi mimari olarak asla ayni anda gecerli olamaz;
    // Kademeli Kar Alma da SADECE OCO'lu pozisyonlarda anlamlidir (Kar Al Tavani zaten kaldirilmis
    // bir pozisyonda "kismi kar al" ayrica bir OCO daha kurmaya calisirdi, gereksiz)
    private function reconcileTakeProfitRemovedTrade(BinanceService $binance, array $trade, bool $shouldCheckAiExit, array $sentimentScores): int
    {
        $pair = (string) $trade['pair'];
        $stopOrderId = (int) $trade['stop_loss_order_id'];

        $stopStatus = $binance->getOrderStatus($pair, $stopOrderId);
        $stopState = strtoupper((string) ($stopStatus['status'] ?? ''));

        if ($stopState !== 'FILLED') {
            // Trade Diagnostics: ana OCO yolundaki AYNI bagimsiz/kosulsuz zirve-dip takibi (bkz.
            // reconcileActiveTrades() yorumu) - bu SL-only (Kar Al Tavani Kaldirilmis) yolda da
            // pozisyon acik oldugu surece calismali
            $diagnosticPrice = (new BinanceService('', ''))->getPrice($pair);

            if ($diagnosticPrice > 0) {
                ActiveTrade::updatePriceExtremes((int) $trade['id'], $diagnosticPrice);
            }

            // Dinamik Kaçış Protokolü: OCO yolundaki AYNI oncelik sirasi - AI skoru kritik cokusteyse
            // Sinirsiz Izleme'yi beklemeden aninda kapatilir
            if ($shouldCheckAiExit && $this->attemptEarlyExitOnAiCollapse($binance, $trade, $sentimentScores)) {
                return 1;
            }

            // Sinirsiz Izleme HER turda calismaya devam eder - Zarar Kes'i yukseltmeye devam eder
            $this->applyTrailingStopIfEligible($binance, $trade);

            return 0;
        }

        $executedQty = (float) ($stopStatus['executedQty'] ?? $trade['quantity']);
        $cumulativeQuote = (float) ($stopStatus['cummulativeQuoteQty'] ?? 0);
        $exitPrice = $executedQty > 0 ? $cumulativeQuote / $executedQty : (float) ($stopStatus['price'] ?? 0);
        $exitTotal = $cumulativeQuote > 0 ? $cumulativeQuote : $exitPrice * $executedQty;

        $this->finalizeSpotClose($binance, $trade, $exitPrice, $executedQty, $exitTotal, $stopOrderId, 'stop_loss');

        return 1;
    }

    // Kapanan bir SPOT pozisyon icin ORTAK kapanis mantigi: Order kaydi olusturur, GERCEK PNL
    // isaretine gore status/soguma/post-mortem/bildirim uygular, komisyonu (myTrades'ten) ogrenir.
    // Hem klasik OCO bacak kapanisindan hem Kar Al Tavani Kaldirilmis (SL-only) kapanisindan
    // cagrilir - KRITIK DUZELTME (22 Temmuz, status artik GERCEK PNL'e gore) TEK yerde yasar.
    // 30 Temmuz'da public'e cevrildi: DashboardController::apiClosePosition() (musterinin manuel
    // "Şimdi Kapat" butonu) AYNI kapanis mantigini (PNL/loglama/bildirim/soguma) TEKRAR YAZMAK
    // yerine bu metodu dogrudan cagirir - koddaki TEK kapanis yolu bolunmez
    public function finalizeSpotClose(
        BinanceService $binance,
        array $trade,
        float $exitPrice,
        float $executedQty,
        float $exitTotal,
        ?int $binanceOrderId,
        string $orderType
    ): void {
        $tradeId = (int) $trade['id'];
        $userId = (int) $trade['user_id'];
        $pair = (string) $trade['pair'];
        $entryPrice = (float) $trade['entry_price'];

        // KRITIK DUZELTME (22 Temmuz): status ARTIK hangi bacagin/emrin gerceklestigine degil,
        // GERCEK PNL isaretine (cikis fiyati giris fiyatindan buyuk mu) gore belirlenir. Izleyen
        // Stop, Zarar Kes seviyesini girisin USTUNE cektiginde (kar kilitleme), SONRADAN o seviye
        // tetiklense bile pozisyon GERCEKTE karda kapanmis olur. Canli veride 46 kapali islemden
        // 8'inin (%17) eski mantikla yanlis etiketlendigi tespit edildi (bkz. CHANGELOG)
        $isProfit = $exitPrice >= $entryPrice;

        // Komisyon Takibi: getOrderStatus() komisyon dondurmez - myTrades'ten SONRADAN ogrenilir
        $commission = $binanceOrderId !== null
            ? $binance->getCommissionForOrder($pair, $binanceOrderId)
            : ['commission' => null, 'commission_asset' => null];

        $this->criticalPersist(function () use ($binanceOrderId, $userId, $pair, $orderType, $executedQty, $exitPrice, $exitTotal, $commission, $trade, $tradeId, $isProfit): void {
            // Idempotenslik korumasi: bu binance_order_id icin zaten bir Order satiri varsa
            // (ör. bir onceki tur Order::create BASARILI oldu ama hemen sonraki markClosed
            // PDOException ile patladi, trade DB'de 'open' kaldigi icin bir sonraki cron turu
            // AYNI FILLED emri tekrar bulup finalizeSpotClose'u YENIDEN cagirdi) - PNL'i cift
            // saymamak icin tekrar INSERT yapilmaz, sadece kapanis durumu tamamlanir
            if ($binanceOrderId === null || !Order::existsByBinanceOrderId($binanceOrderId)) {
                Order::create([
                    'user_id' => $userId,
                    'pair' => $pair,
                    'side' => 'SELL',
                    'type' => $orderType,
                    'quantity' => $executedQty,
                    'price' => $exitPrice,
                    'total' => $exitTotal,
                    'binance_order_id' => $binanceOrderId,
                    'commission' => $commission['commission'],
                    'commission_asset' => $commission['commission_asset'],
                    'parent_order_id' => (int) $trade['buy_order_id'],
                    'status' => 'FILLED',
                    'strategy_bucket' => $this->resolveParentStrategyBucket((int) $trade['buy_order_id']),
                ]);
            }

            ActiveTrade::markClosed($tradeId, $isProfit ? 'closed_profit' : 'closed_loss');
        }, $userId, $pair, 'Pozisyon Kapanışı');

        // Sembol bazli soguma: SADECE GERCEKTEN zararla kapandiysa devreye girer (artik $isProfit
        // GERCEK PNL'e gore belirleniyor) - Izleyen Stop'un kar kilitledigi bir pozisyon (SL bacagi
        // tetiklense bile fiyat karda) ARTIK yanlislikla soguma almiyor
        if (!$isProfit) {
            $cooldownHours = $this->resolveSymbolCooldownHours($userId, $pair, self::SYMBOL_COOLDOWN_STOP_LOSS_HOURS);
            SymbolCooldown::setCooldown($userId, $pair, $cooldownHours, 'Zarar Kes (SL) ile kapandı');
            $this->logAutomationError(sprintf(
                'Sembol soğuması: Kullanıcı #%d %s - Zarar Kes ile kapandığı için %d saat kara listeye alındı.%s',
                $userId,
                $pair,
                $cooldownHours,
                $cooldownHours < self::SYMBOL_COOLDOWN_STOP_LOSS_HOURS ? ' (Kanıtlanmış Kazanan istisnası uygulandı)' : ''
            ));

            // Trade Post-Mortem: kok nedeni tespit edip kaydeder - pozisyon zaten YUKARIDA
            // kapandi/kaydedildi, bu analiz asla kapanisi bloklamaz, en kotu ihtimalle basarisiz
            // olur ve genel bir metinle sonuclanir (TradePostMortemService fail-open'dir). Hata
            // durumunda da ACIK bir yer tutucu yaziliyor - loss_reason bir daha ASLA sessizce bos kalmiyor
            try {
                $lossReason = $this->postMortem->analyze($trade);
                ActiveTrade::setLossReason($tradeId, $lossReason);
            } catch (Throwable $e) {
                $this->logAutomationError("Trade Post-Mortem: Pozisyon #{$tradeId} ({$pair}) icin analiz basarisiz - " . $e->getMessage());

                try {
                    ActiveTrade::setLossReason($tradeId, 'Bilinmeyen Neden - Log İncelenmeli');
                } catch (Throwable $inner) {
                    // Ikinci deneme de basarisiz olursa (ör. ayni DB kesintisi devam ediyor)
                    // artik yapilacak baska bir sey yok - zaten yukarida loglandi, sessizce vazgecilir
                }
            }
        }

        $entryTotal = $entryPrice * $executedQty;
        $pnlAmount = $exitTotal - $entryTotal;
        $pnlPercent = $entryPrice > 0 ? (($exitPrice - $entryPrice) / $entryPrice) * 100 : 0.0;

        // Gercek Finans Matematigi: Binance Spot standart oranina (Alis %0.1 + Satis %0.1) dayanan
        // TAHMINI komisyon - Order::FEE_RATE_PER_LEG ile AYNI kaynak (bkz. o sabitin yorumu, gercek
        // komisyon verisi COGUNLUKLA BNB/altcoin cinsinden oldugu icin USDT'ye cevirmek yerine
        // duz tahmin tercih edildi). Musteriye HER kapanista (kar/zarar farketmez) gercek net
        // sonucu goster - marjinal "karli" bir islem komisyon sonrasi aslinda zarar olabilir
        $estimatedFee = ($entryTotal + $exitTotal) * Order::FEE_RATE_PER_LEG;
        $netPnlAmount = $pnlAmount - $estimatedFee;
        $netPnlPercent = $entryTotal > 0 ? ($netPnlAmount / $entryTotal) * 100 : 0.0;

        // Trade Diagnostics: her kapanista (kar/zarar farketmez) - kok neden analizi loss_reason
        // ile ayni ROLDE DEGIL, onu TAMAMLAR: TradePostMortemService AI'nin YORUMUNU yazar,
        // burasi ise SADECE gercek fiyat verisine (zirve/dip) dayanir, asla patlamayan saf formatlama
        $diagnosticsSummary = $this->buildTradeDiagnosticsSummary($trade, $exitPrice, $pnlPercent, $isProfit);

        // 30 Temmuz'da eklendi: eskiden bu etiket SADECE $isProfit'e bakiyordu (Kar Al/Zarar Kes),
        // musterinin "Simdi Kapat" butonuyla manuel kapattigi bir pozisyon bile botun kendisi
        // otomatik kapatmis gibi goruniyordu - yanlis izlenim verirdi. $orderType artik etikette de
        // kullaniliyor, sadece Order tablosundaki 'type' kolonunda degil
        $closeReasonLabel = match (true) {
            $orderType === 'manual_close' => 'Manuel Kapatma',
            $isProfit => 'Kâr Al',
            default => 'Zarar Kes',
        };

        $this->notifyCustomer($userId, sprintf(
            "%s [NexaTrade] Pozisyon Kapandı (%s)\nCoin: %s\nGiriş: %s → Çıkış: %s\n\n" .
            "Brüt Sonuç: %+.2f USDT\nBorsa Kesintisi (tahmini): -%.2f USDT\nNET SONUÇ: %+.2f USDT (%+.2f%%)\n\n📊 Teşhis:\n%s",
            $isProfit ? '✅' : '🔻',
            $closeReasonLabel,
            $pair,
            $this->formatPrice($entryPrice),
            $this->formatPrice($exitPrice),
            $pnlAmount,
            $estimatedFee,
            $netPnlAmount,
            $netPnlPercent,
            $diagnosticsSummary
        ));
    }

    // Dinamik Kaçış Protokolü: pozisyonun GÜNCEL AI skoru "kritik çöküş" esiginin
    // (EARLY_EXIT_AI_SCORE_THRESHOLD) ALTINDAYSA, Zarar Kes'i beklemeden OCO'yu iptal edip
    // pozisyonu ANINDA piyasa fiyatindan kapatir. $sentimentScores, reconcileActiveTrades()
    // tarafindan TUM acik pozisyonlar icin ONCEDEN (sembol basina TEK OpenAI cagrisiyla)
    // hesaplanmis skor haritasidir - burada TEKRAR bir cagri YAPILMAZ
    // @return bool true ise pozisyon bu cagrida KAPATILDI (cagiran taraf breakeven/DCA denememeli)
    private function attemptEarlyExitOnAiCollapse(BinanceService $binance, array $trade, array $sentimentScores): bool
    {
        $pair = (string) $trade['pair'];
        $score = $sentimentScores[$pair] ?? null;

        // Skor alinamadiysa (API hatasi) ASLA kapatma - fail-safe: bir hata yanlislikla
        // pozisyonu kapatmamali, sadece bir sonraki turda tekrar denenir
        if ($score === null || $score >= self::EARLY_EXIT_AI_SCORE_THRESHOLD) {
            return false;
        }

        $tradeId = (int) $trade['id'];
        $userId = (int) $trade['user_id'];
        $quantity = (float) $trade['quantity'];
        $entryPrice = (float) $trade['entry_price'];
        // Kar Al Tavani Kaldirilmis pozisyonlarda artik bir OCO grubu yok - TEKIL Zarar Kes emri
        // iptal edilir (bkz. replaceStopOnlyOrder ile AYNI ayrim)
        $takeProfitRemoved = (bool) ((int) ($trade['take_profit_removed'] ?? 0));

        if ($takeProfitRemoved) {
            $stopOrderId = $trade['stop_loss_order_id'] !== null ? (int) $trade['stop_loss_order_id'] : null;
            $cancelResult = $stopOrderId !== null
                ? $binance->cancelOrder($pair, $stopOrderId)
                : ['success' => false, 'error' => 'stop_loss_order_id NULL'];
        } else {
            $ocoListId = (int) $trade['oco_order_list_id'];
            $cancelResult = $binance->cancelOcoOrder($pair, $ocoListId);
        }

        if (!$cancelResult['success']) {
            $this->logAutomationError(sprintf(
                'Erken Kaçış denendi: Kullanıcı #%d %s - AI skoru %d (kritik eşik %d altı) ama mevcut koruma emri iptal edilemedi: %s',
                $userId,
                $pair,
                $score,
                self::EARLY_EXIT_AI_SCORE_THRESHOLD,
                $cancelResult['error']
            ));

            return false; // eski koruma hala gecerli - normal Kar Al/Zarar Kes'e birakilir
        }

        $sellResult = $binance->placeOrder($pair, 'SELL', 'MARKET', $quantity);

        if (!$sellResult['success']) {
            // OCO zaten iptal edildi ve pozisyon SIMDI korumasiz - EN KRITIK durum
            ActiveTrade::clearOcoReference($tradeId);

            $this->logAutomationError(sprintf(
                'KRİTİK: Kullanıcı #%d %s - Erken Kaçış için market satışı BAŞARISIZ, pozisyon KORUMASIZ kaldı: %s',
                $userId,
                $pair,
                $sellResult['error']
            ));

            $this->notifyAdminAndCustomer(
                $userId,
                "🚨 ACİL: Erken Kaçış Satışı Başarısız, Pozisyon Korumasız!\nCoin: {$pair}\n\nLütfen borsa hesabını manuel olarak kontrol edin."
            );

            return true; // OCO iptal edildi - cagiran taraf bu pozisyona artik breakeven/DCA denememeli
        }

        $raw = $sellResult['raw'];
        $executedQty = (float) ($raw['executedQty'] ?? $quantity);
        $cumulativeQuote = (float) ($raw['cummulativeQuoteQty'] ?? 0);
        $exitPrice = $executedQty > 0 ? $cumulativeQuote / $executedQty : $entryPrice;
        $exitTotal = $cumulativeQuote > 0 ? $cumulativeQuote : $exitPrice * $executedQty;
        // Komisyon Takibi: dogrudan MARKET satisi - $raw'daki fills[] icin EK API cagrisi gerekmez
        $commission = BinanceService::extractFillCommission($raw);

        $isProfit = $exitPrice >= $entryPrice;

        $this->criticalPersist(function () use ($userId, $pair, $executedQty, $exitPrice, $exitTotal, $sellResult, $commission, $trade, $tradeId, $isProfit): void {
            Order::create([
                'user_id' => $userId,
                'pair' => $pair,
                'side' => 'SELL',
                'type' => 'MARKET',
                'quantity' => $executedQty,
                'price' => $exitPrice,
                'total' => $exitTotal,
                'binance_order_id' => $sellResult['order_id'],
                'commission' => $commission['commission'],
                'commission_asset' => $commission['commission_asset'],
                'parent_order_id' => (int) $trade['buy_order_id'],
                'status' => 'FILLED',
                'strategy_bucket' => $this->resolveParentStrategyBucket((int) $trade['buy_order_id']),
            ]);

            ActiveTrade::markClosed($tradeId, $isProfit ? 'closed_profit' : 'closed_loss');
        }, $userId, $pair, 'Erken Kaçış Satışı');

        // Trade Post-Mortem: bu kapanis yolunda kok neden zaten BILINIYOR (AI skoru cokusu) -
        // Hiz/BTC/Zirh kural zincirini tekrar calistirmaya gerek yok, dogrudan kaydedilir
        if (!$isProfit) {
            try {
                ActiveTrade::setLossReason($tradeId, "Dinamik Erken Kaçış: AI Skoru {$score}'e düştüğü için pozisyon piyasa fiyatından kapatıldı (Zarar Kes beklenmedi).");
            } catch (Throwable $e) {
                $this->logAutomationError("Trade Post-Mortem: Pozisyon #{$tradeId} ({$pair}) icin Erken Kacis sebebi kaydedilemedi - " . $e->getMessage());
            }
        }

        // Sembol bazli soguma: Erken Kaçış HER ZAMAN devreye girer (kâr/zarar fark etmez) - bu
        // coin icin AI sinyali zaten "kritik cokus" gosterdi, skor birkac dakika icinde tekrar
        // yukselse bile ayni coine hemen geri girmek ("intikam islemi") engellenir
        $earlyExitCooldownHours = $this->resolveSymbolCooldownHours($userId, $pair, self::SYMBOL_COOLDOWN_HOURS);
        SymbolCooldown::setCooldown($userId, $pair, $earlyExitCooldownHours, "Dinamik Erken Kaçış (AI Skoru {$score})");

        $pnlAmount = $exitTotal - ($entryPrice * $executedQty);
        $pnlPercent = $entryPrice > 0 ? (($exitPrice - $entryPrice) / $entryPrice) * 100 : 0.0;

        // Kullanicinin istedigi TAM metin, ek tanilama bilgileriyle birlikte
        $this->logAutomationError(sprintf(
            "Erken Kaçış: AI Skoru %d'in altına düştüğü için pozisyon kapatıldı. Kullanıcı #%d, %s, güncel skor: %d, çıkış: %s, PNL: %+.2f USDT (%+.2f%%).",
            self::EARLY_EXIT_AI_SCORE_THRESHOLD,
            $userId,
            $pair,
            $score,
            $this->formatPrice($exitPrice),
            $pnlAmount,
            $pnlPercent
        ));

        $this->notifyCustomer($userId, sprintf(
            "⚡ [NexaTrade] Erken Kaçış Tetiklendi!\nCoin: %s\nAI Skoru %d'e düştüğü için pozisyon piyasa fiyatından kapatıldı (Zarar Kes beklenmedi).\nGiriş: %s → Çıkış: %s\nPNL: %+.2f USDT (%+.2f%%)",
            $pair,
            $score,
            $this->formatPrice($entryPrice),
            $this->formatPrice($exitPrice),
            $pnlAmount,
            $pnlPercent
        ));

        return true;
    }

    // Korumasiz Pozisyon Alarmi: reconcileActiveTradesInternal()'in "bu pozisyonun ne OCO ne tekil
    // Zarar Kes emri var" tespit noktalarindan cagirilir (bkz. o metodun yorumu). Eskiden bu durum
    // SESSIZCE atlanip pozisyon SONSUZA KADAR mutabakat disinda kalabiliyordu (Volkan #243 BANKUSDT
    // canli olayi: %64 cokene kadar fark edilmedi). Artik throttle suresi (UNPROTECTED_ALERT_REPEAT_
    // HOURS) gecmisse HER turda tekrar admin+musteriye "Kritik/Acil" ciddiyetinde bildirim gider -
    // digger rutin bildirimlerden farkli olarak notifyAdminAndCustomer kullanilir (bkz. "Bildirim
    // Yonlendirmesi": kritik olaylar hem musteriye hem admine gider)
    private function alertIfUnprotected(array $trade): void
    {
        $tradeId = (int) $trade['id'];

        if (!ActiveTrade::shouldSendUnprotectedAlert($tradeId, self::UNPROTECTED_ALERT_REPEAT_HOURS)) {
            return;
        }

        $userId = (int) $trade['user_id'];
        $pair = (string) $trade['pair'];

        $this->logAutomationError("KRİTİK: Pozisyon #{$tradeId} ({$pair}, Kullanıcı #{$userId}) hâlâ korumasız (ne OCO ne tekil Zarar Kes emri var) - tekrar uyarı gönderildi.");

        $this->notifyAdminAndCustomer(
            $userId,
            "🚨 ACİL: Pozisyon Korumasız!\nCoin: {$pair} (#{$tradeId})\n\nBu pozisyon için ne Kâr Al/Zarar Kes (OCO) ne de tek başına bir Zarar Kes emri bulunuyor. Lütfen Binance hesabınızı manuel olarak kontrol edip gerekirse elle koruyun/kapatın. Bu uyarı her " . self::UNPROTECTED_ALERT_REPEAT_HOURS . " saatte bir tekrarlanacaktır."
        );
    }

    // Yukselis Uyarisi: reconcileActiveTradesInternal()'in zaten HER turda cektigi diagnosticPrice'i
    // kullanir (ekstra Binance cagrisi YOK) - otomatik satis mantigina (Izleyen Stop/Kademeli Kar
    // Alma) hicbir etkisi yoktur, SADECE musteriye bilgi amacli Telegram bildirimi gonderir. Rutin
    // bir bildirim oldugu icin "Bildirim Yonlendirmesi" desenine gore SADECE musteriye gider, admin'e
    // dusmez (bkz. CLAUDE.md)
    private function checkRiseAlert(array $trade, float $currentPrice): void
    {
        $entryPrice = (float) $trade['entry_price'];

        if ($entryPrice <= 0) {
            return;
        }

        $changePercent = (($currentPrice - $entryPrice) / $entryPrice) * 100;
        $currentStep = (int) floor($changePercent / self::RISE_ALERT_STEP_PERCENT) * self::RISE_ALERT_STEP_PERCENT;

        if ($currentStep < self::RISE_ALERT_START_PERCENT) {
            return;
        }

        $lastAlertedPercent = (int) ($trade['rise_alert_last_percent'] ?? 0);

        if ($currentStep <= $lastAlertedPercent) {
            return;
        }

        $tradeId = (int) $trade['id'];
        $userId = (int) $trade['user_id'];
        $pair = (string) $trade['pair'];

        ActiveTrade::updateRiseAlertLastPercent($tradeId, $currentStep);

        $chatId = User::findTelegramChatId($userId);

        if ($chatId === null) {
            return;
        }

        $message = sprintf(
            "📈 %s yükselişte! (+%%%.1f)\nGiriş: $%s\nGüncel: $%s",
            $pair,
            $changePercent,
            $this->formatPrice($entryPrice),
            $this->formatPrice($currentPrice)
        );

        // Musteri talebi (31 Temmuz): "sadece yuzde degil, NEDEN yukseliyor da bileyim" - AI/OpenAI
        // MALIYETI OLMADAN (SentimentService/GPT'ye HIC dokunulmaz), TechnicalScoreEngine'in zaten
        // tarama turunde adaylar icin urettigi AYNI deterministik RSI/MACD/hacim gerekcesi burada
        // acik pozisyon icin de uretilir. Herhangi bir adimi basarisiz olursa (ör. Binance yavas
        // yanit verirse) SESSIZCE atlanir - bu SADECE bir bilgi zenginlestirmesi, bildirimin
        // KENDISINI ASLA engellemez (fail-open, TelegramService'teki AYNI ilke)
        $technicalContext = $this->buildTechnicalContext($pair);

        if ($technicalContext !== null) {
            $message .= "\n\n🔍 Teknik durum (skor {$technicalContext['score']}/100): {$technicalContext['reason']}";
        }

        // "Ne kadar daha yukselebilir" icin UYDURMA bir tahmin YAPILMAZ - botun ZATEN girişte
        // belirledigi gercek hedefe (Kar Al fiyati) olan mesafe raporlanir. Kar Al Tavani kaldirilmis
        // (Sinirsiz Izleme) pozisyonlarda sabit bir hedef yoktur, bu durum ayrica belirtilir
        $takeProfitRemoved = (int) ($trade['take_profit_removed'] ?? 0) === 1;
        $takeProfitPrice = (float) ($trade['take_profit_price'] ?? 0);

        if ($takeProfitRemoved) {
            $message .= "\n🎯 Kâr Al tavanı kaldırılmış (Sınırsız İzleme) - sabit bir hedef yok, İzleyen Stop trendi takip ediyor.";
        } elseif ($takeProfitPrice > 0 && $currentPrice > 0) {
            $distanceToTarget = (($takeProfitPrice - $currentPrice) / $currentPrice) * 100;

            if ($distanceToTarget > 0) {
                $message .= sprintf("\n🎯 Kâr Al hedefine (%s\$) kalan mesafe: %%%.1f", $this->formatPrice($takeProfitPrice), $distanceToTarget);
            }
        }

        $message .= "\n\nİsterseniz dashboard'dan \"Şimdi Kapat\" ile manuel kâr alabilirsiniz - bot otomatik stratejisine (İzleyen Stop) devam ediyor, bu sadece bilgilendirmedir.";

        $this->telegram->notifyUser($chatId, $message);
    }

    // checkRiseAlert() ve protectPositionWithOco()'nun "Yeni Pozisyon Açıldı" bildirimi ORTAK kullanir:
    // deterministik (AI/OpenAI MALIYETI OLMAYAN) teknik skor + Turkce gerekce metni -
    // MarketScanner::calculateTechnicalScore() ile AYNI motor, tarama turunde adaylar icin
    // kullanilanla BIREBIR ayni fonksiyon. protectPositionWithOco() cagirdiginda bu, giristeki ASIL
    // tarama karariyla BIREBIR ayni olmayabilir (ozellikle bekleyen limit emri dakikalarca sonra
    // dolduysa) - GIRIS ANINDAKI GUNCEL teknik durumun bir anlik goruntusudur, tarama turunun
    // arsivlenmis gerekcesi degil. Herhangi bir Binance/hesaplama adimi basarisiz olursa null doner -
    // cagiran taraf bunu sessizce atlar, bildirimin gonderilmesini ASLA engellemez
    private function buildTechnicalContext(string $pair): ?array
    {
        try {
            $scanner = new MarketScanner();
            $ticker = $scanner->getTickerData($pair);

            if ($ticker === null) {
                return null;
            }

            $rsi1h = $scanner->calculateRsi($pair);
            $technicalScore = $scanner->calculateTechnicalScore($pair, $ticker['priceChangePercent'], $rsi1h);

            if ($technicalScore === null) {
                return null;
            }

            return [
                'score' => (int) $technicalScore['score'],
                'reason' => (string) $technicalScore['reason'],
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    // Izleyen Stop: pozisyon TRAILING_STOP_STAGES'teki tek sabit esige (+%1.5) ulastiginda, Zarar
    // Kes seviyesi breakeven+%0.3'e cekilir - eski OCO iptal edilip AYNI miktar + AYNI Kar Al
    // fiyatiyla, SADECE Zarar Kes'i yukari tasiyan yeni bir OCO yerlestirilir. AI skoruna bagli
    // DEGILDIR (sadece anlik fiyat), OpenAI maliyeti yoktur - HER cron turunda kontrol edilebilir
    // Giris noktasi: fiyati bir kez ceker, sonra pozisyonun asamasina gore Asama 1'e (sabit esik)
    // veya Asama 2'ye (Sinirsiz Izleme, Asama 1 zaten kilitlenmisse) yonlendirir
    // Kademeli Kâr Alma: pozisyon PARTIAL_TAKE_PROFIT_TRIGGER_PERCENT karina ulastiginda miktarin
    // yarisini ANINDA (MARKET) satip gercek kari cebe indirir, kalan yari icin (Kar Al artik uzak
    // bir guvenlik agi olan) yeni bir OCO kurar. Bir pozisyonda SADECE BIR KEZ tetiklenir
    // (partial_tp_executed=1 sonrasi bir daha kontrol edilmez). @return bool true ise bu turda
    // TETIKLENDI - cagiran taraf ayni turda İzleyen Stop/DCA'yi ATLAMALI (taze veriyle bir sonraki
    // turda devam eder), false ise (henuz esige ulasilmadi VEYA zaten daha once tetiklenmis) hicbir
    // sey yapilmadi, cagiran taraf normal akisina devam etmeli
    private function applyPartialTakeProfitIfEligible(BinanceService $binance, array $trade): bool
    {
        if ((int) $trade['partial_tp_executed'] === 1) {
            return false;
        }

        $pair = (string) $trade['pair'];
        $entryPrice = (float) $trade['entry_price'];

        if ($entryPrice <= 0) {
            return false;
        }

        try {
            $currentPrice = $binance->getPrice($pair);
        } catch (Throwable $e) {
            return false;
        }

        if ($currentPrice <= 0) {
            return false;
        }

        $changePercent = (($currentPrice - $entryPrice) / $entryPrice) * 100;

        if ($changePercent < self::PARTIAL_TAKE_PROFIT_TRIGGER_PERCENT) {
            return false;
        }

        $tradeId = (int) $trade['id'];
        $userId = (int) $trade['user_id'];
        $totalQuantity = (float) $trade['quantity'];
        $oldOcoListId = (int) $trade['oco_order_list_id'];

        // Izleyen Zirh'in dogru asamadan devam edebilmesi icin: bu pozisyon henuz Asama 1'e
        // (+%1.5 -> breakeven) ULASMADAN dogrudan +%3'e (Kademeli Kar Alma esigi) sicradiysa,
        // trailing_stop_stage hala 0 olabilir. Bu durumda BIR SONRAKI turda
        // applyDiscreteTrailingStage() asagida kurdugumuz (cok daha iyi) Zarar Kes'i, ESKI/DUSUK
        // Asama 1 hedefine (entry+%0.3) GERI CEKMEYE calisirdi - applyDiscreteTrailingStage()
        // mevcut seviyeyle KARSILASTIRMA yapmadan uygular (applyContinuousTrailing'in aksine).
        // O yuzden burada asamayi PESINEN maksimum discrete asamaya ilerletiyoruz, boylece bir
        // sonraki tur dogrudan applyContinuousTrailing()'e (surekli izleme, HER ZAMAN mevcut
        // seviyeden asagi inmez) gecer
        $strategyBucket = $this->resolveParentStrategyBucket((int) $trade['buy_order_id']);
        $isSniperPosition = $strategyBucket === 'announcement_hunter';
        // Normal pozisyonlarda ApiKey::getTrailingSettings() (Asama 1, DB'den) + 27 Temmuz'da eklenen
        // NORMAL_TRAILING_STOP_STAGE_2/3 (Asama 2/3, sabit) birlikte kullaniliyor - applyTrailingStopIfEligible()
        // ile AYNI 3 asamali dizi, max asama HER ZAMAN 3'tur. Bu satir eskiden kaldirilmis constant'i
        // cagirip HER Kademeli Kar Alma tetiklenmesinde "Undefined constant" fatal hatasi veriyordu
        // (canli olayda tespit edildi: pozisyon #105, 2026-07-23 23:31 - reconcileActiveTrades()'in
        // try/catch'i sessizce yakalayip logluyordu ama o turda Izleyen Stop/Ani Fitil sikilastirma/DCA
        // hep atlaniyordu) - o yuzden burada da applyTrailingStopIfEligible() ile SENKRON tutulmali
        $maxTrailingStage = $isSniperPosition ? max(array_keys(self::SNIPER_TRAILING_STOP_STAGES)) : 3;

        try {
            $filters = $binance->getSymbolFilters($pair);
            $stepSize = $filters['step_size'] > 0 ? $filters['step_size'] : 0.0001;
            $tickSize = $filters['tick_size'] > 0 ? $filters['tick_size'] : 0.00000001;
            $minNotional = $filters['min_notional'];
        } catch (Throwable $e) {
            $stepSize = 0.0001;
            $tickSize = 0.00000001;
            $minNotional = 0.0;
        }

        $sellQuantity = $this->floorToStep($totalQuantity * self::PARTIAL_TAKE_PROFIT_SELL_RATIO, $stepSize);
        $remainingQuantity = $this->floorToStep($totalQuantity - $sellQuantity, $stepSize);

        // Ikiye bolunen parcalardan biri Binance'in asgari islem tutarinin (MIN_NOTIONAL) altinda
        // kalirsa kismi satis YAPILMAZ - hem satilan hem kalan parca gecerli birer emir olabilmeli.
        // Bir hata degil, sessizce atlanir - pozisyon buyudukce (DCA ile) veya fiyat yukseldikce
        // bir sonraki turda kosullar saglanabilir
        if ($sellQuantity <= 0 || $remainingQuantity <= 0) {
            return false;
        }

        if ($minNotional > 0 && ($sellQuantity * $currentPrice < $minNotional || $remainingQuantity * $currentPrice < $minNotional)) {
            return false;
        }

        // Mevcut OCO hem satilacak hem kalacak miktari kilitliyordu - ikisi de serbest
        // birakilmadan ne MARKET satis ne de yeni (kucultulmus) OCO girilebilir
        $cancelResult = $binance->cancelOcoOrder($pair, $oldOcoListId);

        if (!$cancelResult['success']) {
            $this->logAutomationError(sprintf(
                'Kademeli Kâr Alma: Kullanıcı #%d %s - mevcut OCO iptal edilemedi: %s',
                $userId,
                $pair,
                $cancelResult['error']
            ));

            return false; // eski OCO hala gecerli/korumali - bir sonraki turda tekrar denenir
        }

        $sellResult = $binance->placeOrder($pair, 'SELL', 'MARKET', $sellQuantity);

        if (!$sellResult['success']) {
            // OCO zaten iptal edildi, MARKET satisi basarisiz oldu - pozisyon SIMDI tamamen
            // korumasiz. DCA/Kar Kilitleme'deki AYNI "asla sessizce korumasiz birakma" ilkesi:
            // hemen TAM (orijinal) miktar icin DEGISMEMIS hedeflerle OCO yeniden kurulmaya calisilir
            $restoreResult = $binance->placeOCOOrder(
                $pair,
                'SELL',
                $totalQuantity,
                (float) $trade['take_profit_price'],
                (float) $trade['stop_loss_price']
            );

            if ($restoreResult['success']) {
                ActiveTrade::applyTrailingStop(
                    $tradeId,
                    (int) $trade['trailing_stop_stage'],
                    (float) $trade['stop_loss_price'],
                    $restoreResult['order_list_id'],
                    $restoreResult['take_profit_order_id'],
                    $restoreResult['stop_loss_order_id'],
                    $trade['highest_price_seen'] !== null ? (float) $trade['highest_price_seen'] : null
                );
            } else {
                ActiveTrade::clearOcoReference($tradeId);
            }

            $this->logAutomationError(sprintf(
                'KRİTİK: Kullanıcı #%d %s - Kademeli Kâr Alma satışı başarısız: %s (OCO %s)',
                $userId,
                $pair,
                $sellResult['error'],
                $restoreResult['success'] ? 'eski haliyle yeniden kuruldu' : 'YENİDEN KURULAMADI, pozisyon KORUMASIZ'
            ));

            if (!$restoreResult['success']) {
                $this->notifyAdminAndCustomer(
                    $userId,
                    "🚨 ACİL: Kademeli Kâr Alma Başarısız, Pozisyon Korumasız!\nCoin: {$pair}\n\nLütfen borsa hesabını manuel olarak kontrol edin."
                );
            }

            return false;
        }

        // Komisyon Takibi: dogrudan MARKET satisi - $sellResult['raw']'daki fills[] icin EK API cagrisi gerekmez
        $commission = BinanceService::extractFillCommission($sellResult['raw']);

        // Kismi satis kaydi - active_trades.buy_order_id'ye bagli, tam bir kapanis DEGIL (status='open' kalir)
        $this->criticalPersist(function () use ($userId, $pair, $sellQuantity, $currentPrice, $sellResult, $commission, $trade): void {
            Order::create([
                'user_id' => $userId,
                'pair' => $pair,
                'side' => 'SELL',
                'type' => 'MARKET',
                'quantity' => $sellQuantity,
                'price' => $currentPrice,
                'total' => round($sellQuantity * $currentPrice, 8),
                'binance_order_id' => $sellResult['order_id'],
                'commission' => $commission['commission'],
                'commission_asset' => $commission['commission_asset'],
                'parent_order_id' => (int) $trade['buy_order_id'],
                'status' => 'FILLED',
                'strategy_bucket' => $this->resolveParentStrategyBucket((int) $trade['buy_order_id']),
            ]);
        }, $userId, $pair, 'Kademeli Kâr Alma Satışı');

        // Kalan yari icin: Kar Al artik pratik bir tavan degil (cok uzak, giris+%100) - gercek
        // "trend bitene kadar sur" davranisi applyContinuousTrailing()'in ZATEN var olan Zarar
        // Kes yukseltme mekanizmasindan gelir, bu OCO sadece Binance'in "iki bacak zorunlu"
        // kuralini karsilamak icin bir guvenlik agi. Yeni Zarar Kes, mevcut kilitli seviyeden
        // ASAGI dusmez, ayrica kismi kardan SONRA bile pozisyonun net zarara donmesini onlemek
        // icin mevcut fiyatin hemen altina da cekilir - iki adaydan YUKSEK olani secilir
        $runnerTakeProfitPrice = $this->floorToStep($entryPrice * (1 + self::PARTIAL_TAKE_PROFIT_RUNNER_TARGET_PERCENT / 100), $tickSize);
        $currentStopLoss = (float) $trade['stop_loss_price'];
        $postPartialStopCandidate = max($currentStopLoss, $currentPrice * 0.995);
        // Guvenlik siniri: applyContinuousTrailing()'teki AYNI kural - Zarar Kes, Kar Al seviyesine
        // ASLA esit/uzerinde olamaz. Normalde imkansizdir (runner tavani cok uzaktir) ama fiyat
        // cron turlari arasinda %100'u asan bir sicrama yaparsa (nadir ama imkansiz degil) OCO'nun
        // gecersiz (SL >= TP) gitmesini engeller
        $postPartialStopPrice = $this->floorToStep(min($postPartialStopCandidate, $runnerTakeProfitPrice * 0.999), $tickSize);

        $newOcoResult = $binance->placeOCOOrder($pair, 'SELL', $remainingQuantity, $runnerTakeProfitPrice, $postPartialStopPrice);

        if (!$newOcoResult['success']) {
            $newOcoResult = $binance->placeOCOOrder($pair, 'SELL', $remainingQuantity, $runnerTakeProfitPrice, $postPartialStopPrice);
        }

        if (!$newOcoResult['success']) {
            $this->criticalPersist(
                fn () => ActiveTrade::applyPartialTakeProfit($tradeId, $remainingQuantity, null, null, null, null, null, $maxTrailingStage, $currentPrice),
                $userId,
                $pair,
                'Kademeli Kâr Alma (korumasız kalan miktar kaydı)'
            );

            $this->logAutomationError(sprintf(
                'KRİTİK: Kullanıcı #%d %s - Kademeli Kâr Alma sonrası kalan miktar için yeni OCO girilemedi, pozisyon KORUMASIZ: %s',
                $userId,
                $pair,
                $newOcoResult['error']
            ));

            $this->notifyAdminAndCustomer(
                $userId,
                "🚨 ACİL: Kademeli Kâr Alma Sonrası Koruma Girilemedi!\nCoin: {$pair}\n\nLütfen borsa hesabını manuel olarak kontrol edin."
            );

            return true; // kismi satis GERCEKLESTI - cagiran taraf yine de bu turu burada birakmali
        }

        $this->criticalPersist(
            fn () => ActiveTrade::applyPartialTakeProfit(
                $tradeId,
                $remainingQuantity,
                $runnerTakeProfitPrice,
                $postPartialStopPrice,
                $newOcoResult['order_list_id'],
                $newOcoResult['take_profit_order_id'],
                $newOcoResult['stop_loss_order_id'],
                $maxTrailingStage,
                $currentPrice
            ),
            $userId,
            $pair,
            'Kademeli Kâr Alma (yeni koruma kaydı)'
        );

        $partialPnl = ($currentPrice - $entryPrice) * $sellQuantity;

        $this->logAutomationError(sprintf(
            'Kademeli Kâr Alma: %s için pozisyonun %%%s kadarı %s fiyattan satıldı (+%%%.2f), kalan %s İzleyen Zırh ile sürdürülüyor.',
            $pair,
            $this->formatPercentTrim(self::PARTIAL_TAKE_PROFIT_SELL_RATIO * 100),
            $this->formatPrice($currentPrice),
            $changePercent,
            $this->formatPrice($remainingQuantity)
        ));

        $this->notifyCustomer($userId, sprintf(
            "💰 [NexaTrade] Kademeli Kâr Alındı!\nCoin: %s\nPozisyon %%%.2f kâra ulaştı, %%%s satılıp kâr cebe indirildi (+%.2f USDT).\nKalan pozisyon İzleyen Zırh ile sürdürülüyor.",
            $pair,
            $changePercent,
            $this->formatPercentTrim(self::PARTIAL_TAKE_PROFIT_SELL_RATIO * 100),
            $partialPnl
        ));

        return true;
    }

    private function applyTrailingStopIfEligible(BinanceService $binance, array $trade): void
    {
        $pair = (string) $trade['pair'];
        $entryPrice = (float) $trade['entry_price'];

        if ($entryPrice <= 0) {
            return;
        }

        try {
            $currentPrice = $binance->getPrice($pair);
        } catch (Throwable $e) {
            return;
        }

        if ($currentPrice <= 0) {
            return;
        }

        // Dinamik Zırh: pozisyonu ACAN ilk ALIS siparisinin strategy_bucket'ine gore (Duyuru
        // Avcısı'nin sabit "announcement_hunter" etiketi) normal mi yoksa cok daha agresif sniper
        // izleyen stop esikleri mi kullanilacagina karar verilir - reconcileActiveTrades()'in
        // GENEL akisi (OCO durumu kontrolu, DCA, mutabakat) HICBIR sekilde degismez, sadece bu
        // esikler degisir
        $strategyBucket = $this->resolveParentStrategyBucket((int) $trade['buy_order_id']);
        $isSniperPosition = $strategyBucket === 'announcement_hunter';

        if ($isSniperPosition) {
            $stages = self::SNIPER_TRAILING_STOP_STAGES;
            $trailingDistancePercent = self::SNIPER_TRAILING_STOP_DISTANCE_PERCENT;
        } else {
            // Kullaniciya ozel, Dashboard'dan duzenlenebilir tetik/kilit/izleme yuzdeleri (bkz.
            // ApiKey::getTrailingSettings() - eskiden burasi sabit TRAILING_STOP_STAGES idi)
            $trailingSettings = ApiKey::getTrailingSettings((int) $trade['user_id']);

            if (!$trailingSettings['trailing_stop_enabled']) {
                return; // kullanici Izleyen Stop'u kapatmis - sabit TP/SL OCO'ya dokunulmaz
            }

            $stages = [
                1 => [
                    'trigger_percent' => $trailingSettings['trailing_trigger_percent'],
                    'lock_percent' => $trailingSettings['trailing_lock_percent'],
                ],
                2 => self::NORMAL_TRAILING_STOP_STAGE_2,
                3 => self::NORMAL_TRAILING_STOP_STAGE_3,
            ];
            $trailingDistancePercent = $trailingSettings['trailing_distance_percent'];

            // ATR Bazlı Volatilite Çarpanı: SADECE normal (sniper olmayan) pozisyonlarda, kullanıcının
            // KENDİ mesafe ayarını ezmeden bir çarpanla esnetir - bkz. ATR_REFERENCE_PERCENT yorumu.
            // ATR alınamazsa (null - API hatası/yetersiz veri) fail-open: mesafe DEĞİŞTİRİLMEDEN kalır
            $atrPercent = (new MarketScanner())->calculateAtr($pair, self::ATR_PERIOD);

            if ($atrPercent !== null && $atrPercent > 0.0) {
                $atrMultiplier = max(
                    self::ATR_MULTIPLIER_MIN,
                    min(self::ATR_MULTIPLIER_MAX, $atrPercent / self::ATR_REFERENCE_PERCENT)
                );
                $trailingDistancePercent *= $atrMultiplier;
            }
        }

        $currentStage = (int) $trade['trailing_stop_stage'];
        $maxDiscreteStage = max(array_keys($stages));
        $takeProfitRemoved = (bool) ((int) ($trade['take_profit_removed'] ?? 0));

        // 27 Temmuz'da eklendi: eskiden Aşama 1 kilitlendikten SONRA, bir sonraki sabit kademeye
        // (ör. Aşama 2, %4) ulaşılana kadar Zarar Kes TAMAMEN DONUK kalıyordu - araya sıkışan zirveler
        // (ör. %1.8, Aşama 2'nin %4 eşiğinin altında) HİÇ korunmuyordu. Canlıda tespit edildi
        // (AEROUSDT, 27 Temmuz 22:xx): %1.53'te Aşama 1 kilitlendi (+%1.0), fiyat %1.8'e çıktı ama
        // Aşama 2'ye (%4) hiç ulaşamadan geri döndü, pozisyon SADECE +%1.0 ile kapandı - o fazladan
        // %0.8'i koruyan hiçbir mekanizma yoktu. Artık en az 1 aşama kilitliyken (VEYA TP tavanı
        // zaten kaldırılmışsa) Sınırsız İzleme de HER turda PARALEL çalışır - applyContinuousTrailing()
        // kendi içinde "sadece mevcut Zarar Kes'ten daha iyiyse uygula" kuralına sahip olduğu için asla
        // geriye gitmez, discrete kademe kilidinden daha kötü bir seviyeye ASLA düşürmez
        if (!$takeProfitRemoved && $currentStage < $maxDiscreteStage) {
            $jumped = $this->applyDiscreteTrailingStage($binance, $trade, $currentPrice, $entryPrice, $currentStage, $stages);

            if ($jumped) {
                return; // bu turda zaten guncellendi (OCO degisti) - ayni turda tekrar dokunmuyoruz
            }
        }

        if ($currentStage >= 1 || $takeProfitRemoved) {
            $this->applyContinuousTrailing($binance, $trade, $currentPrice, $trailingDistancePercent);
        }
    }

    // Asama 1 (+%1.5 -> giris+%0.3, breakeven+ucret payi): sabit esige gore BIR KEZ tetiklenen
    // kademe (dizi tek elemanli olsa da sniper pozisyonlar SNIPER_TRAILING_STOP_STAGES ile ayni
    // fonksiyonu birden fazla asamayla kullanabildigi icin genel/dizi-tabanli birakildi).
    // Donus degeri: bu turda gercekten bir kademe atlamasi olup olmadigi (applyTrailingStopIfEligible()
    // ayni turda applyContinuousTrailing()'i CAKISTIRMAMAK icin kullanir)
    private function applyDiscreteTrailingStage(BinanceService $binance, array $trade, float $currentPrice, float $entryPrice, int $currentStage, array $stages): bool
    {
        $pair = (string) $trade['pair'];
        $userId = (int) $trade['user_id'];
        $changePercent = (($currentPrice - $entryPrice) / $entryPrice) * 100;

        $targetStage = null;
        $lockPercent = null;

        foreach (array_reverse($stages, true) as $stage => $config) {
            if ($stage > $currentStage && $changePercent >= $config['trigger_percent']) {
                $targetStage = $stage;
                $lockPercent = $config['lock_percent'];
                break;
            }
        }

        if ($targetStage === null) {
            return false; // henuz yeni bir esige ulasilmadi
        }

        $newStopTargetPrice = $entryPrice * (1 + $lockPercent / 100);
        $appliedStopPrice = $this->replaceOcoWithNewStop($binance, $trade, $newStopTargetPrice, $targetStage, null);

        if ($appliedStopPrice === null) {
            return false; // basarisizlik zaten replaceOcoWithNewStop icinde loglandi/bildirildi
        }

        // Kullanicinin istedigi TAM format: "Kâr Kilitlendi: TIAUSDT için Stop-Loss %2 seviyesine çekildi"
        $this->logAutomationError(sprintf(
            'Kâr Kilitlendi: %s için Stop-Loss %s seviyesine çekildi.',
            $pair,
            $this->formatPercentTrim($lockPercent)
        ));

        $this->notifyCustomer($userId, sprintf(
            "🔒 [NexaTrade] Kâr Kilitlendi!\nCoin: %s\nPozisyon %%%.2f kâra ulaştı, Zarar Kes seviyesi %%%s kâr noktasına (%s) çekildi.",
            $pair,
            $changePercent,
            $this->formatPercentTrim($lockPercent),
            $this->formatPrice($appliedStopPrice)
        ));

        return true;
    }

    // Asama 2 (Sinirsiz Izleme): Asama 1 zaten kilitlenmisse (trailing_stop_stage>=1), fiyat
    // yukselmeye devam ettikce Zarar Kes'i goruledigi en yuksek fiyatin (highest_price_seen)
    // TRAILING_STOP_DISTANCE_PERCENT kadar ALTINDA tutmaya devam eder.
    // KRITIK DUZELTME (22 Temmuz - Kar Al Tavanini Kaldirma): canli veri analizi, Sinirsiz Izleme'ye
    // ulasan kazanan islemlerin BILE hep ayni sabit Kar Al fiyatinda (~%5) tavan yaptigini ortaya
    // cikardi - bu fonksiyon Zarar Kes'i yukseltirken Kar Al'a hic dokunmuyordu, OCO'nun TP bacagi
    // once tetikleniyordu. Artik ILK KEZ buraya girildiginde ($trade['take_profit_removed']==0)
    // mevcut OCO TAMAMEN iptal edilip YERINE SADECE tek yonlu bir Zarar Kes emri konuyor
    // (removeTakeProfitCeiling) - boylece sabit tavan devreden cikiyor. Zaten Kar Al'siz (SL-only)
    // modda olan pozisyonlarda (take_profit_removed==1) her turda replaceStopOnlyOrder ile AYNI
    // sekilde devam edilir, ama artik "Zarar Kes Kar Al'i gecemez" guvenlik siniri YOKTUR - trend
    // ne kadar surerse Zarar Kes de o kadar yukselir. Anlamli bir iyilesme
    // (TRAILING_STOP_MIN_IMPROVEMENT_PERCENT) yoksa emre DOKUNULMAZ, sadece en yuksek fiyat
    // kaydedilir - gereksiz Binance cagrisi/korumasizlik penceresi biriktirmez
    private function applyContinuousTrailing(BinanceService $binance, array $trade, float $currentPrice, float $trailingDistancePercent): void
    {
        $tradeId = (int) $trade['id'];
        $userId = (int) $trade['user_id'];
        $pair = (string) $trade['pair'];
        $currentStopLoss = (float) $trade['stop_loss_price'];
        $storedHighest = $trade['highest_price_seen'] !== null ? (float) $trade['highest_price_seen'] : null;
        $highestPriceSeen = $storedHighest !== null ? max($storedHighest, $currentPrice) : $currentPrice;
        $takeProfitRemoved = (bool) ((int) ($trade['take_profit_removed'] ?? 0));

        $candidateStopPrice = $highestPriceSeen * (1 - $trailingDistancePercent / 100);

        if (!$takeProfitRemoved) {
            // Kar Al hala devrede - eski guvenlik siniri korunur (Zarar Kes asla Kar Al'a esit/uzerinde olamaz)
            $takeProfitPrice = (float) $trade['take_profit_price'];
            $candidateStopPrice = min($candidateStopPrice, $takeProfitPrice * 0.999);
        }
        // $takeProfitRemoved ise artik boyle bir tavan yok - candidateStopPrice serbestce yukselebilir

        $minImprovement = $currentStopLoss * (self::TRAILING_STOP_MIN_IMPROVEMENT_PERCENT / 100);

        if ($candidateStopPrice < $currentStopLoss + $minImprovement) {
            // Anlamli bir iyilesme yok - emre dokunma, ama yine de yeni bir zirve gorulduyse
            // (henuz esigi asmasa da) bir sonraki turun dogru noktadan devam etmesi icin kaydet
            if ($storedHighest === null || $highestPriceSeen > $storedHighest) {
                ActiveTrade::updateHighestPriceSeen($tradeId, $highestPriceSeen);
            }

            return;
        }

        if (!$takeProfitRemoved) {
            $this->removeTakeProfitCeiling($binance, $trade, $candidateStopPrice, $highestPriceSeen);

            return;
        }

        $appliedStopPrice = $this->replaceStopOnlyOrder($binance, $trade, $candidateStopPrice, $highestPriceSeen);

        if ($appliedStopPrice === null) {
            return;
        }

        $this->logAutomationError(sprintf(
            'Kâr Kilitlendi (İzleme): %s için Stop-Loss en yüksek fiyatın (%s) %%%s altına, %s seviyesine çekildi.',
            $pair,
            $this->formatPrice($highestPriceSeen),
            $this->formatPercentTrim($trailingDistancePercent),
            $this->formatPrice($appliedStopPrice)
        ));

        $this->notifyCustomer($userId, sprintf(
            "📈 [NexaTrade] Kâr İzleniyor!\nCoin: %s\nYeni zirve: %s\nZarar Kes seviyesi %s'e çekildi (zirvenin %%%s altında).",
            $pair,
            $this->formatPrice($highestPriceSeen),
            $this->formatPrice($appliedStopPrice),
            $this->formatPercentTrim($trailingDistancePercent)
        ));
    }

    // Kar Al Tavanini Kaldirma: Sinirsiz Izleme ILK KEZ aktiflesince cagrilir - mevcut OCO'yu
    // (Kar Al + Zarar Kes birlikte) TAMAMEN iptal eder, YERINE SADECE tek yonlu bir Zarar Kes emri
    // koyar. Basarisiz olursa (iptal veya yeni emir basarisiz), pozisyon KORUMASIZ kalmasin diye
    // eski OCO'yu AYNEN geri kurmayi dener - DCA/Kademeli Kar Alma'daki AYNI "asla sessizce
    // korumasiz birakma" ilkesi
    private function removeTakeProfitCeiling(BinanceService $binance, array $trade, float $newStopPrice, float $highestPriceSeen): void
    {
        $tradeId = (int) $trade['id'];
        $userId = (int) $trade['user_id'];
        $pair = (string) $trade['pair'];
        $quantity = (float) $trade['quantity'];
        $oldOcoListId = (int) $trade['oco_order_list_id'];
        $oldTakeProfitPrice = (float) $trade['take_profit_price'];
        $oldStopLossPrice = (float) $trade['stop_loss_price'];

        $cancelResult = $binance->cancelOcoOrder($pair, $oldOcoListId);

        if (!$cancelResult['success']) {
            $this->logAutomationError(sprintf(
                'Kâr Al Tavanı Kaldırma: Kullanıcı #%d %s - mevcut OCO iptal edilemedi: %s',
                $userId,
                $pair,
                $cancelResult['error']
            ));

            return; // eski OCO hala gecerli/korumali - bir sonraki turda tekrar denenir
        }

        // replaceOcoWithNewStop() ile AYNI kural: fiyat Binance'in PRICE_FILTER tick size'ina
        // yuvarlanmadan gonderilirse emir reddedilir
        try {
            $filters = $binance->getSymbolFilters($pair);
            $tickSize = $filters['tick_size'] > 0 ? $filters['tick_size'] : 0.00000001;
        } catch (Throwable $e) {
            $tickSize = 0.00000001;
        }

        $newStopPrice = $this->floorToStep($newStopPrice, $tickSize);

        $stopResult = $binance->placeStopLossOrder($pair, 'SELL', $quantity, $newStopPrice);

        if (!$stopResult['success']) {
            // OCO zaten iptal edildi - pozisyon SIMDI korumasiz. Eski (degismemis) TP/SL ile
            // OCO'yu AYNEN geri kurmayi dene
            $restoreResult = $binance->placeOCOOrder($pair, 'SELL', $quantity, $oldTakeProfitPrice, $oldStopLossPrice);

            if ($restoreResult['success']) {
                ActiveTrade::applyTrailingStop(
                    $tradeId,
                    (int) $trade['trailing_stop_stage'],
                    $oldStopLossPrice,
                    $restoreResult['order_list_id'],
                    $restoreResult['take_profit_order_id'],
                    $restoreResult['stop_loss_order_id'],
                    null
                );
            }

            $this->logAutomationError(sprintf(
                'KRİTİK: Kullanıcı #%d %s - Kâr Al tavanı kaldırılırken tekil Zarar Kes emri girilemedi: %s (OCO %s)',
                $userId,
                $pair,
                $stopResult['error'],
                $restoreResult['success'] ? 'eski haliyle yeniden kuruldu' : 'YENİDEN KURULAMADI, pozisyon KORUMASIZ'
            ));

            if (!$restoreResult['success']) {
                $this->notifyAdminAndCustomer(
                    $userId,
                    "🚨 ACİL: Kâr Al Tavanı Kaldırma Başarısız, Pozisyon Korumasız!\nCoin: {$pair}\n\nLütfen borsa hesabını manuel olarak kontrol edin."
                );
            }

            return;
        }

        $this->criticalPersist(
            fn () => ActiveTrade::applyTakeProfitRemoval($tradeId, $newStopPrice, $stopResult['order_id'], $highestPriceSeen),
            $userId,
            $pair,
            'Kâr Al Tavanı Kaldırma'
        );

        $this->logAutomationError(sprintf(
            'Kâr Al Tavanı Kaldırıldı: %s artık sınırsız izleniyor (sabit Kar Al iptal edildi), Zarar Kes %s seviyesinde.',
            $pair,
            $this->formatPrice($newStopPrice)
        ));

        $this->notifyCustomer($userId, sprintf(
            "🚀 [NexaTrade] Kâr Al Tavanı Kaldırıldı!\nCoin: %s\nPozisyon güçlü bir trendde - sabit Kâr Al hedefi iptal edildi, artık SADECE yükselen bir Zarar Kes (%s) ile sınırsız sürülüyor.",
            $pair,
            $this->formatPrice($newStopPrice)
        ));
    }

    // Kar Al Tavanini Kaldirma sonrasi (take_profit_removed=1) SONRAKI her Sinirsiz Izleme turunda
    // cagrilir - mevcut tekil Zarar Kes emrini iptal edip YENI (daha yuksek) fiyatta yenisini koyar.
    // removeTakeProfitCeiling() ile AYNI "asla sessizce korumasiz birakma" ilkesi, ama artik OCO
    // degil TEKIL emir iptal/kurulumu. Basarili olursa GERCEK (tick'e yuvarlanmis) Zarar Kes
    // fiyatini doner, basarisiz olursa null doner
    private function replaceStopOnlyOrder(BinanceService $binance, array $trade, float $newStopPrice, float $highestPriceSeen): ?float
    {
        $tradeId = (int) $trade['id'];
        $userId = (int) $trade['user_id'];
        $pair = (string) $trade['pair'];
        $quantity = (float) $trade['quantity'];
        $oldStopOrderId = $trade['stop_loss_order_id'] !== null ? (int) $trade['stop_loss_order_id'] : null;

        if ($oldStopOrderId !== null) {
            $cancelResult = $binance->cancelOrder($pair, $oldStopOrderId);

            if (!$cancelResult['success']) {
                $this->logAutomationError(sprintf(
                    'İzleme (Kar Al Kaldırılmış): Kullanıcı #%d %s - mevcut Zarar Kes emri iptal edilemedi: %s',
                    $userId,
                    $pair,
                    $cancelResult['error']
                ));

                return null; // eski emir hala gecerli/korumali - bir sonraki turda tekrar denenir
            }
        }

        // replaceOcoWithNewStop() ile AYNI kural: fiyat Binance'in PRICE_FILTER tick size'ina
        // yuvarlanmadan gonderilirse emir reddedilir
        try {
            $filters = $binance->getSymbolFilters($pair);
            $tickSize = $filters['tick_size'] > 0 ? $filters['tick_size'] : 0.00000001;
        } catch (Throwable $e) {
            $tickSize = 0.00000001;
        }

        $newStopPrice = $this->floorToStep($newStopPrice, $tickSize);

        $stopResult = $binance->placeStopLossOrder($pair, 'SELL', $quantity, $newStopPrice);

        if (!$stopResult['success']) {
            // Eski emir zaten iptal edildi - pozisyon SIMDI korumasiz. DCA/Kar Al Tavanini
            // Kaldirma'daki AYNI ilke: sessizce birakilmaz, ACIL bildirim gonderilir
            $this->logAutomationError(sprintf(
                'KRİTİK: Kullanıcı #%d %s - İzleme (Kar Al Kaldırılmış) sırasında yeni Zarar Kes emri girilemedi, pozisyon KORUMASIZ: %s',
                $userId,
                $pair,
                $stopResult['error']
            ));

            $this->notifyAdminAndCustomer(
                $userId,
                "🚨 ACİL: İzleyen Zarar Kes Güncellenemedi, Pozisyon Korumasız!\nCoin: {$pair}\n\nLütfen borsa hesabını manuel olarak kontrol edin."
            );

            ActiveTrade::clearOcoReference($tradeId); // stop_loss_order_id de NULL'a ceker - reconcile bunu "korumasiz" olarak izler

            return null;
        }

        $this->criticalPersist(
            fn () => ActiveTrade::applyTakeProfitRemoval($tradeId, $newStopPrice, $stopResult['order_id'], $highestPriceSeen),
            $userId,
            $pair,
            'İzleyen Zarar Kes Güncelleme'
        );

        return $newStopPrice;
    }

    // bkz. WICK_SHIELD_MULTIPLIER yorumu - protectPositionWithOco()'nun genis actigi ilk OCO'yu,
    // WICK_SHIELD_MINUTES dakika sonra kullanicinin GERCEK Zarar Kes'ine sikilastirir. reconcileActiveTrades()
    // dongusunde applyTrailingStopIfEligible()'DAN HEMEN SONRA cagirilir - boylece trailing AYNI
    // turda devreye girmisse asagidaki trailing_stop_stage kontrolu bunu TAZE (findById ile yeniden
    // okunmus) veriyle gorebilir, loop'un basindaki STALE $trade degiskenine guvenilmez
    private function tightenStopLossIfEligible(BinanceService $binance, array $trade, array $apiKey): void
    {
        $tradeId = (int) $trade['id'];
        $fresh = ActiveTrade::findById($tradeId);

        if ($fresh === null || $fresh['status'] !== 'open') {
            return;
        }

        if ((int) $fresh['is_sl_tightened'] === 1) {
            return; // zaten sikilastirilmis (ya da bu satir fitil korumasindan once acilmis eski bir pozisyon)
        }

        // Izleyen Stop veya Kismi Kar Alma bu turda ya da daha once SL'e ZATEN dokunmussa, onun
        // uzerine yazma - sadece bayragi kapat, bundan sonra fitil korumasi anlamsiz kalir
        if ((int) $fresh['trailing_stop_stage'] !== 0 || (int) $fresh['partial_tp_executed'] === 1) {
            ActiveTrade::markSlTightened($tradeId);
            return;
        }

        if ($fresh['oco_order_list_id'] === null) {
            return; // OCO'suz/korumasiz durum - baska bir mekanizma zaten ilgileniyor
        }

        $openedAt = strtotime((string) $fresh['opened_at']);

        if ($openedAt === false || (time() - $openedAt) < self::WICK_SHIELD_MINUTES * 60) {
            return; // henuz vakti gelmedi
        }

        $entryPrice = (float) $fresh['entry_price'];
        $stopLossPercent = (float) ($apiKey['stop_loss_percent'] ?? 0);

        if ($entryPrice <= 0 || $stopLossPercent <= 0) {
            return;
        }

        $targetStopPrice = $entryPrice * (1 - $stopLossPercent / 100);
        $highestPriceSeen = $fresh['highest_price_seen'] !== null ? (float) $fresh['highest_price_seen'] : null;

        $result = $this->replaceOcoWithNewStop($binance, $fresh, $targetStopPrice, 0, $highestPriceSeen);

        if ($result !== null) {
            ActiveTrade::markSlTightened($tradeId);
        }
        // basarisiz olursa (zaten replaceOcoWithNewStop icinde loglanip/bildirilmis) is_sl_tightened
        // 0'da kalir - bir sonraki turda tekrar denenir, DCA motorundaki AYNI "tekrar dene" deseni
    }

    // Kâr kilitleme mekaniginin ORTAK cekirdegi: eski OCO'yu iptal eder, AYNI miktar + AYNI Kar Al
    // fiyatiyla SADECE Zarar Kes'i $newStopTargetPrice'a tasiyan yeni bir OCO yerlestirir. Hem
    // Asama 1/2 (discrete) hem Asama 3 (surekli izleme) tarafindan kullanilir - DCA motorundaki
    // AYNI "once yeni OCO/tekrar dene, basarisizsa pozisyonu ASLA sessizce korumasiz birakma"
    // deseni tekrarlanir. Basarili olursa GERCEK (tick'e yuvarlanmis) Zarar Kes fiyatini doner,
    // basarisiz olursa (zaten loglanip/bildirilmis olarak) null doner
    private function replaceOcoWithNewStop(BinanceService $binance, array $trade, float $newStopTargetPrice, int $newStage, ?float $highestPriceSeen): ?float
    {
        $tradeId = (int) $trade['id'];
        $userId = (int) $trade['user_id'];
        $pair = (string) $trade['pair'];
        $quantity = (float) $trade['quantity'];
        $takeProfitPrice = (float) $trade['take_profit_price'];
        $oldOcoListId = (int) $trade['oco_order_list_id'];

        // Kar Al seviyesi zaten yeni Zarar Kes hedefinin UZERINDE olmalidir (Kar Al her zaman
        // > giris > kilitlenen kar seviyesi olmalidir) - normalde imkansizdir ama guvenli
        // tarafta kalmak icin kontrol edilir
        if ($takeProfitPrice <= $newStopTargetPrice) {
            return null;
        }

        $cancelResult = $binance->cancelOcoOrder($pair, $oldOcoListId);

        if (!$cancelResult['success']) {
            $this->logAutomationError(sprintf(
                'Kâr Kilitleme: Kullanıcı #%d %s - mevcut OCO iptal edilemedi: %s',
                $userId,
                $pair,
                $cancelResult['error']
            ));

            return null; // eski OCO hala gecerli/korumali - bir sonraki turda tekrar denenir
        }

        try {
            $filters = $binance->getSymbolFilters($pair);
            $tickSize = $filters['tick_size'] > 0 ? $filters['tick_size'] : 0.00000001;
        } catch (Throwable $e) {
            $tickSize = 0.00000001;
        }

        $newStopTriggerPrice = $this->floorToStep($newStopTargetPrice, $tickSize);

        // stopLimitPrice verilmiyor (null) - duz STOP_LOSS (piyasa) emri, slippage riski yok
        $ocoResult = $binance->placeOCOOrder($pair, 'SELL', $quantity, $takeProfitPrice, $newStopTriggerPrice);

        if (!$ocoResult['success']) {
            // Ilk deneme basarisizsa bir kez daha dene (DCA motorundaki AYNI desen) - gecici bir
            // Binance hatasi/oran siniri olabilir
            $ocoResult = $binance->placeOCOOrder($pair, 'SELL', $quantity, $takeProfitPrice, $newStopTriggerPrice);
        }

        if (!$ocoResult['success']) {
            // Eski OCO zaten iptal edildi, yenisi girilemedi - pozisyon SIMDI korumasiz
            ActiveTrade::clearOcoReference($tradeId);

            $this->logAutomationError(sprintf(
                'KRİTİK: Kullanıcı #%d %s - Kâr Kilitleme için yeni OCO girilemedi, pozisyon KORUMASIZ kaldı: %s',
                $userId,
                $pair,
                $ocoResult['error']
            ));

            $this->notifyAdminAndCustomer(
                $userId,
                "🚨 ACİL: Kâr Kilitleme Uygulanamadı, Pozisyon Korumasız!\nCoin: {$pair}\n\nLütfen borsa hesabını manuel olarak kontrol edin."
            );

            return null;
        }

        $this->criticalPersist(
            fn () => ActiveTrade::applyTrailingStop(
                $tradeId,
                $newStage,
                $newStopTriggerPrice,
                $ocoResult['order_list_id'],
                $ocoResult['take_profit_order_id'],
                $ocoResult['stop_loss_order_id'],
                $highestPriceSeen
            ),
            $userId,
            $pair,
            'Kâr Kilitleme'
        );

        return $newStopTriggerPrice;
    }

    // "%2.0" yerine "%2" gibi gereksiz sondaki sifirlari kirpar - sadece log/bildirim metinlerinin
    // okunabilirligi icin (hesaplamalarda kullanilmaz)
    private function formatPercentTrim(float $value): string
    {
        return rtrim(rtrim(sprintf('%.2f', $value), '0'), '.');
    }

    // getOrderStatus() basarisiz/tutarsiz oldugunda (ikisi de FILLED disinda bir durum donerse) son
    // care: Binance'in KENDI gercek islem gecmisinden (myTrades), bilinen take_profit/stop_loss
    // orderId'leriyle eslesen bir fill arar. Eslesen orderId'ye gore hangi bacagin (Kar Al mi Zarar
    // Kes mi) gerceklestigini KESIN olarak belirler - tahmine dayanmaz, Binance'in kendi kaydidir.
    // 10 Temmuz'da (TIAUSDT) bu yedek olmadigi icin bir pozisyonun gercek kapanis bilgisi kalici
    // olarak kaybolmustu (bkz. RCA) - manuel duzeltme gerekmisti
    // @return array{leg: array, is_profit: bool}|null
    private function findFillFromTradeHistory(BinanceService $binance, string $pair, ?int $takeProfitOrderId, ?int $stopLossOrderId): ?array
    {
        if ($takeProfitOrderId === null && $stopLossOrderId === null) {
            return null;
        }

        try {
            $trades = $binance->getMyTrades($pair);
        } catch (Throwable $e) {
            return null;
        }

        foreach ($trades as $t) {
            $orderId = (int) ($t['orderId'] ?? 0);

            if ($takeProfitOrderId !== null && $orderId === $takeProfitOrderId) {
                return ['leg' => $this->mapTradeToLeg($t), 'is_profit' => true];
            }

            if ($stopLossOrderId !== null && $orderId === $stopLossOrderId) {
                return ['leg' => $this->mapTradeToLeg($t), 'is_profit' => false];
            }
        }

        return null;
    }

    // Binance myTrades formatini (qty/quoteQty/price), getOrderStatus() ile AYNI sekle
    // (executedQty/cummulativeQuoteQty/price) donusturur - cagiran taraf ikisini ayni kod
    // yoluyla (Order::create + PNL hesabi) isleyebilsin diye
    private function mapTradeToLeg(array $trade): array
    {
        return [
            'executedQty' => $trade['qty'] ?? 0,
            'cummulativeQuoteQty' => $trade['quoteQty'] ?? 0,
            'price' => $trade['price'] ?? 0,
            'orderId' => $trade['orderId'] ?? null,
        ];
    }

    // DCA (Maliyet Dusurme) Motoru: pozisyon zarara girdiginde (kullanicinin kendi stop_loss_percent'ine
    // gore olceklenmis bir esikte, KRITIK DUZELTME #6) VE SentimentService hala pozitifse (>=80),
    // stop-loss'a birakmak yerine ayni butceyle bir kez daha alim yapip ortalama maliyeti dusurur.
    // Pozisyon basina EN FAZLA 1 tur (dca_rounds_used ile sinirlanir, hicbir zaman asilmaz)
    private function attemptDcaIfEligible(BinanceService $binance, array $trade, array $apiKey): void
    {
        if ((int) ($apiKey['dca_enabled'] ?? 0) !== 1) {
            return;
        }

        if ((int) $trade['dca_rounds_used'] >= 1) {
            return;
        }

        $pair = (string) $trade['pair'];
        $entryPrice = (float) $trade['entry_price'];
        $stopLossPercent = (float) $apiKey['stop_loss_percent'];

        // KRITIK DUZELTME #6: sabit %5 yerine kullanicinin kendi stop_loss_percent'inin bir
        // fraksiyonu kullanilir - aksi halde stop_loss_percent <= 5 olan kullanicilarda DCA
        // hicbir zaman tetiklenmez (stop-loss her zaman once patlar)
        $dcaTriggerPercent = min(5.0, $stopLossPercent * 0.6);

        try {
            $currentPrice = $binance->getPrice($pair);
        } catch (Throwable $e) {
            return;
        }

        if ($currentPrice <= 0 || $entryPrice <= 0) {
            return;
        }

        $changePercent = (($currentPrice - $entryPrice) / $entryPrice) * 100;

        if ($changePercent > -$dcaTriggerPercent) {
            return; // henuz DCA esigine ulasilmadi
        }

        $userId = (int) $trade['user_id'];

        try {
            $usdtBalance = $this->getAssetBalance($binance, 'USDT');
            $openTrades = ActiveTrade::findOpenForUser($userId);
            $openPositionsCost = 0.0;

            foreach ($openTrades as $openTrade) {
                $openPositionsCost += (float) $openTrade['entry_price'] * (float) $openTrade['quantity'];
            }

            $totalEquity = $usdtBalance + $openPositionsCost;
        } catch (Throwable $e) {
            return;
        }

        $blockReason = $this->riskManager->checkCircuitBreaker($userId, $apiKey, $totalEquity);

        if ($blockReason !== null) {
            return; // devre kesici aktifse DCA da durur
        }

        try {
            $sentiment = new SentimentService();
            $analysis = $sentiment->analyze($pair);
        } catch (Throwable $e) {
            return;
        }

        if (!$analysis['is_buy_signal']) {
            return; // AI artik pozitif degil, pozisyon normal stop-loss'a birakilir
        }

        $budgetPercent = (float) ($apiKey['auto_trade_budget_percent'] ?? 10.0);
        // $usdtBalance yukarida (toplam ozkaynak hesaplanirken) zaten cekilmisti, tekrar cekmiyoruz
        $budget = $usdtBalance * ($budgetPercent / 100);

        if ($budget < self::MIN_ORDER_BUDGET_USDT) {
            return;
        }

        $this->executeDcaFill($binance, $trade, $apiKey, $currentPrice, $budget);
    }

    // KRITIK DUZELTME #2: ONCE yeni alim yapilir, SONRA eski OCO iptal edilir, EN SON yeni OCO
    // yerlestirilir - boylece alim basarisiz olursa orijinal pozisyon hicbir an korumasiz kalmaz.
    // KRITIK DUZELTME #3: yeni OCO yerlestirme basarisiz olursa oco alanlari NULL yapilir (eski OCO
    // zaten iptal edilmis oldugundan artik gecersizdir) ki reconcileActiveTrades() bunu yanlislikla
    // "kapandi" saymasin, sadece "korumasiz, acik" olarak izlemeye devam etsin
    private function executeDcaFill(BinanceService $binance, array $trade, array $apiKey, float $currentPrice, float $budget): void
    {
        $tradeId = (int) $trade['id'];
        $userId = (int) $trade['user_id'];
        $pair = (string) $trade['pair'];
        $oldQuantity = (float) $trade['quantity'];
        $oldEntryPrice = (float) $trade['entry_price'];
        $oldOcoListId = (int) $trade['oco_order_list_id'];
        $takeProfitPercent = (float) $apiKey['take_profit_percent'];
        $stopLossPercent = (float) $apiKey['stop_loss_percent'];
        // DCA, AYNI pozisyonun devami oldugu icin orijinal ALIS siparisinin strateji etiketini miras alir
        $strategyBucket = $this->resolveParentStrategyBucket((int) $trade['buy_order_id']);

        $quantity = round($budget / $currentPrice, 4);

        if ($quantity <= 0) {
            return;
        }

        $buyResult = $binance->placeOrder($pair, 'BUY', 'MARKET', $quantity);

        if (!$buyResult['success']) {
            // Alim basarisiz oldu - eski OCO'ya hic dokunulmadi, pozisyon tamamen korumali kaldi
            $this->logAutomationError("DCA: Kullanıcı #{$userId} için {$pair} alımı başarısız - " . $buyResult['error']);
            return;
        }

        $raw = $buyResult['raw'];
        $executedQty = (float) ($raw['executedQty'] ?? $quantity);
        $cumulativeQuote = (float) ($raw['cummulativeQuoteQty'] ?? 0);
        $fillPrice = $executedQty > 0 ? $cumulativeQuote / $executedQty : $currentPrice;
        $filledQty = $executedQty > 0 ? $executedQty : $quantity;
        // Komisyon Takibi: $raw'daki fills[] icin EK bir API cagrisi gerekmez
        $commission = BinanceService::extractFillCommission($raw);

        $dcaOrderId = $this->criticalPersist(function () use ($userId, $pair, $filledQty, $fillPrice, $cumulativeQuote, $budget, $strategyBucket, $buyResult, $commission, $tradeId): int {
            $dcaOrderId = Order::create([
                'user_id' => $userId,
                'pair' => $pair,
                'side' => 'BUY',
                'type' => 'MARKET',
                'quantity' => $filledQty,
                'price' => $fillPrice,
                'total' => $cumulativeQuote > 0 ? $cumulativeQuote : $budget,
                'strategy_bucket' => $strategyBucket,
                'binance_order_id' => $buyResult['order_id'],
                'commission' => $commission['commission'],
                'commission_asset' => $commission['commission_asset'],
                'status' => 'FILLED',
            ]);

            ActiveTrade::addFillRecord($tradeId, $dcaOrderId, $filledQty, $fillPrice, 'dca');

            return $dcaOrderId;
        }, $userId, $pair, 'DCA Alımı Kaydı');

        $newTotalQtyRaw = $oldQuantity + $filledQty;
        $newAvgEntryPrice = (($oldQuantity * $oldEntryPrice) + ($filledQty * $fillPrice)) / $newTotalQtyRaw;

        // Simdi (alim guvenceye alindiktan SONRA) eski OCO'yu iptal et
        $cancelResult = $binance->cancelOcoOrder($pair, $oldOcoListId);

        if (!$cancelResult['success']) {
            // Eski OCO hala GECERLI ve ESKI miktari korumaya devam ediyor - entry_price/quantity/oco
            // alanlarina DOKUNULMAZ (degistirmek eski korumayi bozardi). Sadece DCA ile alinan EK
            // miktar korumasiz kaldi - bu net şekilde bildirilir, DCA hakki yine de tuketilir
            ActiveTrade::markDcaRoundConsumed($tradeId);

            $this->logAutomationError(
                "DCA: Kullanıcı #{$userId} {$pair} - eski OCO iptal edilemedi: " . $cancelResult['error']
            );

            $this->notifyAdminAndCustomer(
                $userId,
                "🚨 ACİL: DCA Alımı Yapıldı Ama Eski Koruma İptal Edilemedi!\n" .
                "Coin: {$pair}\n" .
                "DCA ile alınan {$filledQty} adet korumasız kaldı (orijinal miktar hâlâ korunuyor).\n" .
                'Lütfen borsa hesabını manuel olarak kontrol edin.'
            );

            return;
        }

        // Yeni ortalama maliyete gore TP/SL'i, kullanicinin KENDI yuzdeleriyle yeniden hesapla
        $newTakeProfitPrice = round($newAvgEntryPrice * (1 + $takeProfitPercent / 100), 8);
        $newStopTriggerPrice = round($newAvgEntryPrice * (1 - $stopLossPercent / 100), 8);

        try {
            $filters = $binance->getSymbolFilters($pair);
            $stepSize = $filters['step_size'] > 0 ? $filters['step_size'] : 0.0001;
        } catch (Throwable $e) {
            $stepSize = 0.0001;
        }

        // KRITIK DUZELTME #7: iki bagimsiz yuvarlanmis fill'in toplami, borsanin gercek LOT_SIZE
        // stepSize'ina gore yeniden yuvarlanir (iki 4-ondalikli toplamin kabul edilmeyecek bir
        // miktar uretme riskine karsi)
        $newTotalQty = $this->floorToStep($newTotalQtyRaw * self::FEE_SAFETY_MARGIN, $stepSize);

        if ($newTotalQty <= 0) {
            $this->criticalPersist(
                fn () => ActiveTrade::applyDcaFill($tradeId, $newAvgEntryPrice, $newTotalQtyRaw, $newTakeProfitPrice, $newStopTriggerPrice, null, null, null),
                $userId,
                $pair,
                'DCA (sıfır miktar kaydı)'
            );
            $this->logAutomationError("DCA: Kullanıcı #{$userId} {$pair} - birleşik miktar sıfır çıktı, pozisyon korumasız kaldı.");
            $this->notifyAdminAndCustomer($userId, "🚨 ACİL: DCA Sonrası Miktar Hesaplanamadı, Pozisyon Korumasız!\nCoin: {$pair}\n\nLütfen borsa hesabını manuel olarak kontrol edin.");
            return;
        }

        // stopLimitPrice verilmiyor (null) - duz STOP_LOSS (piyasa) emri, slippage riski yok
        $ocoResult = $binance->placeOCOOrder($pair, 'SELL', $newTotalQty, $newTakeProfitPrice, $newStopTriggerPrice);

        // Ilk deneme basarisizsa, ayni parametrelerle bir kez daha dene (gecici bir Binance
        // hatasi/oran siniri olabilir) - basari orani onemli olcude artar
        if (!$ocoResult['success']) {
            $ocoResult = $binance->placeOCOOrder($pair, 'SELL', $newTotalQty, $newTakeProfitPrice, $newStopTriggerPrice);
        }

        if (!$ocoResult['success']) {
            // KRITIK DUZELTME #3: oco alanlari NULL - reconcileActiveTrades()'teki
            // "if (oco_order_list_id === null) continue" korumasi sayesinde bir sonraki tur bunu
            // yanlislikla "kapandi" saymaz, sadece "acik ama korumasiz" olarak atlar
            $this->criticalPersist(function () use ($tradeId, $newAvgEntryPrice, $newTotalQty, $newTakeProfitPrice, $newStopTriggerPrice, $userId, $pair, $dcaOrderId, $ocoResult, $strategyBucket): void {
                ActiveTrade::applyDcaFill($tradeId, $newAvgEntryPrice, $newTotalQty, $newTakeProfitPrice, $newStopTriggerPrice, null, null, null);

                Order::create([
                    'user_id' => $userId,
                    'pair' => $pair,
                    'side' => 'SELL',
                    'type' => 'OCO',
                    'quantity' => $newTotalQty,
                    'price' => $newTakeProfitPrice,
                    'total' => round($newTotalQty * $newTakeProfitPrice, 8),
                    'binance_order_id' => null,
                    'parent_order_id' => $dcaOrderId,
                    'status' => 'FAILED',
                    'error_message' => $ocoResult['error'],
                    'strategy_bucket' => $strategyBucket,
                ]);
            }, $userId, $pair, 'DCA (OCO başarısız kaydı)');

            $this->logAutomationError(
                "KRİTİK: Kullanıcı #{$userId} {$pair} - DCA sonrası yeni OCO girilemedi: " . $ocoResult['error']
            );

            $this->notifyAdminAndCustomer(
                $userId,
                "🚨 ACİL: DCA Sonrası Yeni Koruma Emri Girilemedi, Pozisyon Korumasız!\n" .
                "Coin: {$pair}\n" .
                "Hata: {$ocoResult['error']}\n\n" .
                'Lütfen borsa hesabını manuel olarak kontrol edin.'
            );

            return;
        }

        $this->criticalPersist(
            fn () => ActiveTrade::applyDcaFill(
                $tradeId,
                $newAvgEntryPrice,
                $newTotalQty,
                $newTakeProfitPrice,
                $newStopTriggerPrice,
                $ocoResult['order_list_id'],
                $ocoResult['take_profit_order_id'],
                $ocoResult['stop_loss_order_id']
            ),
            $userId,
            $pair,
            'DCA (yeni koruma kaydı)'
        );

        $this->notifyCustomer(
            $userId,
            "📉 [NexaTrade] Maliyet Düşürme (DCA) Uygulandı\n" .
            "Coin: {$pair}\n" .
            "Yeni Ortalama Giriş: {$this->formatPrice($newAvgEntryPrice)} | Toplam Miktar: {$newTotalQty}\n\n" .
            "🛡️ Yeni Koruma — Kâr Al: {$this->formatPrice($newTakeProfitPrice)} | Zarar Kes: {$this->formatPrice($newStopTriggerPrice)}"
        );
    }

    // floorToPrecision'in aksine, borsanin GERCEK LOT_SIZE adimina (stepSize) gore asagi yuvarlar
    private function floorToStep(float $value, float $stepSize): float
    {
        if ($stepSize <= 0) {
            return $this->floorToPrecision($value, 4);
        }

        return floor($value / $stepSize) * $stepSize;
    }

    // Rutin islem bildirimleri (pozisyon acildi/kapandi): SADECE ilgili musterinin kendi bagladigi
    // Telegram'a gider. Musteri henuz baglamadiysa hicbir yere gonderilmez - platform buyudukce
    // admin'in her musterinin her islemiyle bogulmamasi icin admin'e asla otomatik dusmez
    private function notifyCustomer(int $userId, string $message): void
    {
        $chatId = User::findTelegramChatId($userId);

        if ($chatId !== null) {
            $this->telegram->notifyUser($chatId, "Kullanıcı: #{$userId}\n" . $message);
        }
    }

    // Istisnai risk olaylari (devre kesici, korumasiz pozisyon): musteri baglıysa ona da gider,
    // ama platform operatorunun her zaman haberdar olmasi gerektigi icin admin'e HER ZAMAN da gider
    private function notifyAdminAndCustomer(int $userId, string $message): void
    {
        $chatId = User::findTelegramChatId($userId);

        if ($chatId !== null) {
            $this->telegram->notifyUser($chatId, $message);
        }

        $this->telegram->notifyAdmin("Kullanıcı: #{$userId}\n" . $message);
    }

    // KRITIK ISLEM SONRASI KAYIT: Binance'te GERI DONUSU OLMAYAN bir islem (BUY/SELL/OCO/Zarar Kes
    // degisimi) zaten basariyla gerceklestikten SONRA calisan DB yazma adimlarini sarmalar. 22
    // Temmuz'daki BANKUSDT/ERAUSDT olayinin (RCA: gercek kok neden PHP'nin yakalanamayan zaman asimiydi,
    // bkz. MAX_SAFE_ELAPSED_SECONDS_BEFORE_BUY) sonrasinda yapilan tam denetimde, AYNI "Binance basarili
    // ama DB yazimi patlarsa sessizce sadece dosyaya loglanip gecilir" deseni bu dosyada 7 farkli yerde
    // (Erken Kacis, Kademeli Kar Alma, OCO/mutabakat kapanisi, Kar Al Tavani Kaldirma, Izleyen Zarar
    // Kes, Kar Kilitleme, DCA) tekrar ettigi tespit edildi - hepsi disaridaki genel dongu catch'ine
    // (sadece log) guveniyordu. Artik OCO-basarisiz senaryosuyla AYNI ciddiyette notifyAdminAndCustomer
    // tetiklenir. Istisna YUTULMAZ (rethrow) - cagiran tarafin mevcut akisi/disaridaki catch degismeden
    // calismaya devam eder, bu SADECE ek bir garanti edilmis alarm katmanidir
    private function criticalPersist(callable $fn, int $userId, string $pair, string $context): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $this->logAutomationError("KRİTİK: Kullanıcı #{$userId} {$pair} - {$context} sonrası kayıt başarısız: " . $e->getMessage());

            $this->notifyAdminAndCustomer(
                $userId,
                "🚨 ACİL: {$context} Sonrası Sistem Kaydı Başarısız!\nCoin: {$pair}\n\nBinance işlemi muhtemelen gerçekleşti ama veritabanına yazılamadı. Lütfen borsa hesabını manuel olarak kontrol edin."
            );

            throw $e;
        }
    }

    // Telegram mesajlarinda fiyatlari (mikro-cap coinlerde 8 ondalikli olabilir) gereksiz sondaki
    // sifirlar olmadan, okunakli bicimde gosterir (ör. 0.00022360 -> 0.0002236)
    private function formatPrice(float $price): string
    {
        $formatted = rtrim(rtrim(sprintf('%.8f', $price), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function getAssetBalance(BinanceService $binance, string $asset): float
    {
        foreach ($binance->getBalances() as $balance) {
            if ($balance['asset'] === $asset) {
                return $balance['free'];
            }
        }

        return 0.0;
    }

    // SAT (kapanis) veya DCA siparisleri, kendi strategy_bucket'ini YENIDEN hesaplamaz - pozisyonu
    // ACAN ilk ALIS siparisinin etiketini miras alir (ayni pozisyon = ayni strateji sinifi). Bu,
    // reconcileActiveTrades/executeDcaFill gibi ilk alimdan SAATLER/GUNLER sonra, ayri bir cron
    // turunda calisan kodlarin dogru etiketi bulabilmesinin TEK yolu (bellekte artik yok, DB'den okunur)
    private function resolveParentStrategyBucket(?int $parentOrderId): ?string
    {
        if ($parentOrderId === null) {
            return null;
        }

        $parent = Order::findById($parentOrderId);

        return $parent['strategy_bucket'] ?? null;
    }

    // round()'un aksine her zaman asagi yuvarlar; elde tutulandan fazlasini satma riskini ortadan kaldirir
    private function floorToPrecision(float $value, int $decimals): float
    {
        $factor = 10 ** $decimals;

        return floor($value * $factor) / $factor;
    }

    private function logAutomationError(string $message): void
    {
        $logDir = __DIR__ . '/../../storage/logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $entry = sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $message, PHP_EOL);
        file_put_contents($logDir . '/auto_trade.log', $entry, FILE_APPEND);
    }

    // Trade Diagnostics: digerlerinden AYRI kendi log dosyasi - "Loglama" deseni (bkz. CLAUDE.md),
    // auto_trade.log'un yuzlerce satirlik gurultusune karismasin, kapanis analizleri TEK yerden
    // taranabilsin diye
    private function logTradeDiagnostics(string $message): void
    {
        $logDir = __DIR__ . '/../../storage/logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $entry = sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $message, PHP_EOL);
        file_put_contents($logDir . '/trade_diagnostics.log', $entry, FILE_APPEND);
    }

    // Trade Diagnostics: "neden Zarar Kes ile kapandi" sorusuna GERCEK fiyat verisiyle cevap -
    // highest_price_reached/lowest_price_reached (bkz. migration yorumu) pozisyon acikken HER
    // turda, trailing durumundan BAGIMSIZ toplanir. Bu SAF/stateless bir formatlama fonksiyonu -
    // DB'ye yazmaz, sadece storage/logs/trade_diagnostics.log'a yazip Telegram'a eklenecek metni
    // dondurur. $trade['highest_price_reached']/['lowest_price_reached'] NULL ise (ör. pozisyon
    // acilir acilmaz ayni turda kapandi, hic izleme turu gecmedi) giris fiyatina duser - "veri
    // toplanamadi" notu eklenir
    private function buildTradeDiagnosticsSummary(array $trade, float $exitPrice, float $exitPnlPercent, bool $isProfit): string
    {
        $pair = (string) $trade['pair'];
        $entryPrice = (float) $trade['entry_price'];
        $noDataCollected = $trade['highest_price_reached'] === null && $trade['lowest_price_reached'] === null;
        $highestPrice = $trade['highest_price_reached'] !== null ? (float) $trade['highest_price_reached'] : $entryPrice;
        $lowestPrice = $trade['lowest_price_reached'] !== null ? (float) $trade['lowest_price_reached'] : $entryPrice;

        $peakPercent = $entryPrice > 0 ? (($highestPrice - $entryPrice) / $entryPrice) * 100 : 0.0;
        $dipPercent = $entryPrice > 0 ? (($lowestPrice - $entryPrice) / $entryPrice) * 100 : 0.0;

        if ($noDataCollected) {
            $analysisSentence = 'Zirve/dip verisi toplanamadı (pozisyon çok hızlı kapandı, hiç izleme turu geçmedi).';
        } elseif ($peakPercent <= 0.0) {
            $analysisSentence = 'Coin alındıktan sonra hiç kâr göremeden direkt düşüşe geçti (Tepeden alım şüphesi).';
        } else {
            $analysisSentence = sprintf(
                'Coin girişten sonra maksimum %+.1f%% yükseldi, ancak hedefe ulaşamayıp %+.1f%% ile %s oldu.',
                $peakPercent,
                $exitPnlPercent,
                $isProfit ? 'Kâr Al' : 'Zarar Kes'
            );
        }

        // Telegram'a eklenecek kisa ozet: Giris/Cikis disaridaki (cagiran) mesajda ZATEN var,
        // burada TEKRAR edilmez - sadece zirve/dip ve analiz cumlesi
        $summary = sprintf(
            "Zirve: %s (%+.1f%%) | Dip: %s (%+.1f%%)\n%s",
            $this->formatPrice($highestPrice),
            $peakPercent,
            $this->formatPrice($lowestPrice),
            $dipPercent,
            $analysisSentence
        );

        // Log satiri ise TEK BASINA anlamli olmali (baska bir mesajin devami degil) - Giris/Cikis
        // burada AYRICA yer alir
        $this->logTradeDiagnostics(sprintf(
            'Pozisyon #%d (%s) kapandı [%s] - Giriş: %s, Çıkış: %s, %s',
            (int) $trade['id'],
            $pair,
            $isProfit ? 'Kâr Al' : 'Zarar Kes',
            $this->formatPrice($entryPrice),
            $this->formatPrice($exitPrice),
            str_replace("\n", ' | ', $summary)
        ));

        return $summary;
    }
}
