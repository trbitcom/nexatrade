<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use PDOException;

// TUM otonom cron modulleri (auto_trade, futures_trade, listing_sniper, smart_money) icin ORTAK,
// basit ama ATOMIK bir kilit mekanizmasi - 21 Temmuz'da eklendi: AI Avci'nin tarama sikligi
// 60sn'ye dusurulunce, bir taramanin (Pullback beklemeleri yuzunden) 60sn'den uzun surmesi halinde
// bir sonraki cron isteginin AYNI anda calisip cakismasi (ayni coine cift islem acilmasi dahil)
// riski dogdu. Her modul kendi BAGIMSIZ lock_name'iyle kilitlenir, biri digerini bloklamaz
final class CronLock
{
    // Kilidi almayi dener. ONCE (housekeeping) timeoutSeconds'i asmis "takili" bir kilit varsa
    // temizler - SQL'in kendi NOW()'iyle (TIMESTAMPDIFF), PHP time()/strtotime() KARSILASTIRMASI
    // YAPILMAZ (ApiKey::hasSentCooldownNotifToday/PendingSignal ile AYNI saat dilimi onlemi).
    // Gercek atomiklik PRIMARY KEY(lock_name) UNIQUE kisitlamasindan gelir: iki es zamanli istek
    // AYNI ANDA bu INSERT'i denerse, InnoDB'nin satir kilidi sayesinde SADECE biri basarili olur -
    // digeri duplicate-key hatasi alir, bu da "kilit MESGUL" anlamina gelir (false doner)
    public static function acquire(string $lockName, int $timeoutSeconds): bool
    {
        $pdo = Database::getInstance();

        $cleanupStmt = $pdo->prepare(
            'DELETE FROM cron_locks WHERE lock_name = :lock_name AND TIMESTAMPDIFF(SECOND, locked_at, NOW()) > :timeout'
        );
        $cleanupStmt->bindValue(':lock_name', $lockName);
        $cleanupStmt->bindValue(':timeout', $timeoutSeconds, PDO::PARAM_INT);
        $cleanupStmt->execute();

        try {
            $insertStmt = $pdo->prepare('INSERT INTO cron_locks (lock_name, locked_at) VALUES (:lock_name, NOW())');
            $insertStmt->bindValue(':lock_name', $lockName);
            $insertStmt->execute();

            return true;
        } catch (PDOException $e) {
            return false; // kilit zaten baskasinda (mesgul) - PRIMARY KEY ihlali
        }
    }

    // Kilidi serbest birakir - cagiran taraf try/finally ile HER ihtimalde (basari/istisna) bu
    // metodun cagrilmasini garanti etmelidir, aksi halde kilit bir sonraki timeout'a kadar takili kalir
    public static function release(string $lockName): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('DELETE FROM cron_locks WHERE lock_name = :lock_name');
        $stmt->bindValue(':lock_name', $lockName);
        $stmt->execute();
    }
}
