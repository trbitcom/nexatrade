<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

// Binance'te su ana kadar gorulmus USDT paritelerinin kaydini tutar; "yeni listelenen" tespiti
// bu tabloyla canli borsa listesi arasindaki farktan (diff) hesaplanir
final class KnownSymbol
{
    public static function count(): int
    {
        $pdo = Database::getInstance();

        return (int) $pdo->query('SELECT COUNT(*) FROM known_symbols')->fetchColumn();
    }

    public static function findAllSymbols(): array
    {
        $pdo = Database::getInstance();

        return $pdo->query('SELECT symbol FROM known_symbols')->fetchAll(PDO::FETCH_COLUMN);
    }

    // $isBootstrap=true: ilk kurulumda mevcut tum pariteleri taban olarak kaydeder, bunlar asla
    // "yeni listelenen" olarak gosterilmez (aksi halde kurulumdan sonraki gunlerde tum piyasa "yeni" gorunurdu)
    /** @param string[] $symbols */
    public static function insertMany(array $symbols, bool $isBootstrap = false): void
    {
        if ($symbols === []) {
            return;
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('INSERT IGNORE INTO known_symbols (symbol, is_bootstrap) VALUES (:symbol, :is_bootstrap)');

        foreach ($symbols as $symbol) {
            $stmt->execute([':symbol' => $symbol, ':is_bootstrap' => $isBootstrap ? 1 : 0]);
        }
    }

    // Duyuru Avcisi'nin bir sembolu ATOMIK olarak "ele gecirmesini" saglar: INSERT IGNORE ile
    // ayni anda calisan iki cron tetiklemesinden sadece biri satiri gercekten ekleyebilir
    // (digeri sessizce yok sayilir). rowCount()===1 -> bu cagri gercekten kazandi, alima gecilebilir.
    // rowCount()===0 -> sembol zaten (bu veya baska bir surec tarafindan) claim edilmis, dokunma -
    // boylece cakisan iki cron calistirmasinin ayni yeni listelemeyi cift almasi engellenir
    public static function claimIfNew(string $symbol): bool
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('INSERT IGNORE INTO known_symbols (symbol, is_bootstrap) VALUES (:symbol, 0)');
        $stmt->execute([':symbol' => $symbol]);

        return $stmt->rowCount() === 1;
    }

    // Son N gun icinde ilk kez gorulen (gercekten yeni listelenen, taban kaydi olmayan) sembolleri getirir
    public static function findRecentlyListed(int $days = 3, int $limit = 8): array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'SELECT symbol, first_seen_at FROM known_symbols
             WHERE is_bootstrap = 0
               AND first_seen_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             ORDER BY first_seen_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // Binance exchangeInfo'da ILK KEZ PRE_TRADING/BREAK durumunda gorulen bir sembolu ATOMIK olarak
    // sniper izlemesine alir. TEK bir INSERT IGNORE ile hem taban satiri olusturur hem de
    // sniper_status='pending' yazar - eger sembol tabloda zaten VARSA (claimIfNew ile daha once
    // gercekten listelenmis bir coin ya da bootstrap taban kaydi) rowCount()=0 doner ve HICBIR SEY
    // degistirilmez. Bu kritik: var olan bir coin'in BREAK'e girmesi genelde bir BAKIM penceresidir,
    // yeni bir listeleme DEGIL - sniper sadece Binance'in tablo disi tamamen YENI bir sembolu
    // gundeme getirdigi durumlarda devreye girmeli. Cakisan iki cron tetiklemesi ayni yeni sembolu
    // ayni anda gormeye calisirsa, INSERT IGNORE'un atomikligi sayesinde sadece biri kazanir
    public static function claimPending(string $symbol, string $status, ?string $expectedStartTime = null): bool
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO known_symbols (symbol, is_bootstrap, status, expected_start_time, sniper_status)
             VALUES (:symbol, 0, :status, :expected_start_time, 'pending')"
        );
        $stmt->execute([
            ':symbol' => $symbol,
            ':status' => $status,
            ':expected_start_time' => $expectedStartTime,
        ]);

        return $stmt->rowCount() === 1;
    }

    // Su an aktif olarak izlenen (sniper_status='pending') Duyuru Avcisi hedeflerini getirir - tight-poll
    // dongusunun her turda "hangi sembollere yakin takip yapmaliyim" sorusuna cevap verir. En once
    // gorulenden (first_seen_at ASC) baslar - PRE_TRADING'e en once gecenler genelde listelenmeye
    // en yakin olanlardir
    public static function findPendingSniperTargets(int $limit = 20): array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "SELECT symbol, status, expected_start_time, first_seen_at FROM known_symbols
             WHERE sniper_status = 'pending'
             ORDER BY first_seen_at ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // 16 Temmuz'da tespit edilen KRITIK bug: eskiden bu adimdan ONCE hicbir atomik "claim" yoktu -
    // markSniperExecuted() SADECE gercek alim YAPILDIKTAN SONRA cagriliyordu, kendisi de sadece bir
    // duz UPDATE'ti (WHERE sniper_status='pending' sarti/rowCount kontrolu YOKTU). Sniper cron'u
    // ~1 dakikada bir tetiklenip KENDI ICINDE bir tight-poll donguSu (TIGHT_POLL_MAX_SECONDS)
    // calistirdigi icin, bir onceki calistirma hala donguSundeyken bir sonraki baslayabiliyordu -
    // ikisi de AYNI sembolu findPendingSniperTargets()'ten 'pending' olarak okuyup ikisi de TRADING
    // gorup ikisi de GERCEK Binance alim emri gonderebiliyordu (cift alim). Artik gercek alim
    // denemesinden HEMEN ONCE claimSniperExecution() ile TEK bir surecin "kazanmasi" saglaniyor -
    // digerleri rowCount()===0 gorup HICBIR ALIM DENEMEDEN atlar (bkz. ListingSniperService)
    // @return bool true ise BU cagri kazandi (sembol hala 'pending' idi, artik 'executing') -
    // alim denemesi guvenle yapilabilir. false ise baska bir surec zaten claim etmis, DOKUNMA
    public static function claimSniperExecution(string $symbol): bool
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "UPDATE known_symbols SET sniper_status = 'executing' WHERE symbol = :symbol AND sniper_status = 'pending'"
        );
        $stmt->execute([':symbol' => $symbol]);

        return $stmt->rowCount() === 1;
    }

    // claimSniperExecution() ile 'executing'e claim edilmis bir hedefin ALIM DENEMESI (basarili ya
    // da bilerek atlanmis - ör. likidite yetersiz/flas cokus) TAMAMLANDIKTAN SONRA cagrilir - status
    // TRADING'e guncellenir ki bu sembol artik ne pending sniper taramasinda ne de
    // detectNewListings()'in known_symbols diff'inde tekrar "yeni listelenen" olarak islenmesin
    public static function markSniperExecuted(string $symbol): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "UPDATE known_symbols SET sniper_status = 'executed', status = 'TRADING' WHERE symbol = :symbol"
        );
        $stmt->execute([':symbol' => $symbol]);
    }

    // Izleme suresi (PENDING_STALE_HOURS) doldugu halde sembol hic TRADING'e gecmediyse (ör.
    // Binance listelemeyi iptal etti/erteledi) hedefi 'failed' olarak isaretler - sonsuza kadar
    // her cron turunde tekrar tekrar tight-poll'a girip gereksiz API cagrisi biriktirmesini engeller
    public static function markSniperFailed(string $symbol): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "UPDATE known_symbols SET sniper_status = 'failed' WHERE symbol = :symbol"
        );
        $stmt->execute([':symbol' => $symbol]);
    }
}
