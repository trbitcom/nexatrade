<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

// Ardisik Cift Onay (Double Scan Approval): bir sembol agir teknik filtrelerin (RSI/Hacim/MTF/
// Tahta) hepsinden gectiginde ANINDA alinmaz - once burada "gorüldu" olarak isaretlenir (pass_count=1).
// 21 Temmuz'da ZAMANA DAYALI (first_seen_at + sabit saniye penceresi) mimariden TUR TABANLI
// (pass_count) mimariye gecirildi - canli loglarda tekrar tekrar dogrulandi: cron'lar arasi gercek
// bosluk (API gecikmesi/CronLock cakismasi yuzunden) 60-185sn arasinda TUTARSIZ cikiyordu, sabit
// bir saniye esigi (60->120->...) her seferinde yetersiz kalip ayni semptomu yamaliyordu. Artik
// "ardisik" tanimi SANIYEYE degil, cron'un KENDI dogal ritmine gore art arda İKİ BASARILI TARAMA
// turuna dayanir - sunucu/API ne kadar yavaslarsa yavassin kendini otomatik ayarlar.
// Kullanicidan BAGIMSIZ, GLOBAL bir tablodur - AiIntervention'daki user_id=null deseniyle AYNI
// mantik: bu karar noktasi huntForAllUsers()'in (kullanici bazli) donguSunden ONCE, tum kullanicilar
// icin ORTAK calisir
final class PendingSignal
{
    public static function findBySymbol(string $symbol): ?array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT * FROM pending_signals WHERE symbol = :symbol LIMIT 1');
        $stmt->execute([':symbol' => $symbol]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    // pass_count VARSAYILAN (1) ile eklenir - sutunun kendi DB DEFAULT'u kullanilir, PHP tarafinda
    // ayrica belirtilmez
    public static function create(string $symbol, int $score): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'INSERT INTO pending_signals (symbol, first_seen_score, first_seen_at, last_validated_at)
             VALUES (:symbol, :score, NOW(), NOW())'
        );
        $stmt->execute([':symbol' => $symbol, ':score' => $score]);
    }

    // Ayni sembol bir SONRAKI turda da tum sert filtrelerden gecince cagrilir - pass_count'u 1
    // artirir, last_validated_at'i taze tutar (bkz. tablo yorumu - artik onay tetikleyicisi degil
    // ama "bu satir en son ne zaman dokunuldu" bilgisini gozlemlenebilir tutmak icin guncellenir)
    public static function incrementPassCount(string $symbol): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'UPDATE pending_signals SET pass_count = pass_count + 1, last_validated_at = NOW() WHERE symbol = :symbol'
        );
        $stmt->execute([':symbol' => $symbol]);
    }

    public static function delete(string $symbol): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('DELETE FROM pending_signals WHERE symbol = :symbol');
        $stmt->execute([':symbol' => $symbol]);
    }

    // Guvenlik Agi (GC) - ARTIK bir onay tetikleyicisi DEGIL: bir sembol bu sureden once ilk
    // gorulup de (ne tekrar filtreleri gecip onaylanarak, ne de bir filtrede reddedilip aninda
    // silinerek) bir daha hic aday olarak degerlendirilmediyse (ör. GPT skoru esigin altina
    // dustu, cron uzun sure calismadi) tabloyu SISMEMESI icin buradan temizlenir. Her cron
    // turunun basinda (throttle'dan BAGIMSIZ, HER run() cagrisinda) calistirilir - tablo kucuk
    // kaldigi icin (semboller UNIQUE) BotLog::pruneIfDue()'nun aksine saatlik throttle GEREKMEZ
    public static function pruneStale(int $maxAgeSeconds): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('DELETE FROM pending_signals WHERE first_seen_at < (NOW() - INTERVAL :max_age SECOND)');
        $stmt->bindValue(':max_age', $maxAgeSeconds, PDO::PARAM_INT);
        $stmt->execute();
    }
}
