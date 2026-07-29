<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

// Sembol bazli soguma (kara liste): bir kullanicinin ZARARLA (Zarar Kes) veya Dinamik Erken
// Kacis ile kapattigi bir coine, AI skoru ne kadar hizli toparlanirsa toparlansin belirli bir
// sure tekrar giris yapmasini engeller ("intikam islemi" / revenge trading onlemi). Devre
// kesiciden FARKLI olarak SADECE o (kullanici, sembol) ciftini etkiler - botun geri kalanini
// veya diger kullanicilari asla durdurmaz
final class SymbolCooldown
{
    // Ayni (user_id, pair) icin tekrar tetiklenirse soguma BASTAN baslar - "kara liste" suresi
    // her yeni zararli/erken-kacis kapanisinda yenilenir (ON DUPLICATE KEY UPDATE)
    public static function setCooldown(int $userId, string $pair, int $hours, string $reason): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'INSERT INTO symbol_cooldowns (user_id, pair, cooldown_until, reason)
             VALUES (:user_id, :pair, DATE_ADD(NOW(), INTERVAL :hours HOUR), :reason)
             ON DUPLICATE KEY UPDATE
                cooldown_until = DATE_ADD(NOW(), INTERVAL :hours2 HOUR),
                reason = :reason2'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':pair' => $pair,
            ':hours' => $hours,
            ':reason' => $reason,
            ':hours2' => $hours,
            ':reason2' => $reason,
        ]);
    }

    // Kullanicinin bu sembol icin HALA aktif bir sogumasi varsa bicimlendirilmis bitis tarihini
    // doner, yoksa (hic olmadi veya suresi doldu) null doner. "Aktif mi" karsilastirmasi BILEREK
    // MySQL'in KENDI saatiyle (NOW()) yapilir - PHP tarafinda strtotime()/time() ile DEGIL (bu
    // sunucuda PHP/MySQL saat dilimi farkli - bkz. ApiKey::getCircuitBreakerStatus() ayni prensip)
    public static function getCooldownUntil(int $userId, string $pair): ?string
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(cooldown_until, '%d.%m.%Y %H:%i')
             FROM symbol_cooldowns
             WHERE user_id = :user_id AND pair = :pair AND cooldown_until > NOW()
             LIMIT 1"
        );
        $stmt->execute([':user_id' => $userId, ':pair' => $pair]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    // Dashboard "Sembol Soğuması" listesi icin: kullanicinin HALA kilitli olan TUM sembollerini
    // (en yakinda bitecek once) doner - getCooldownUntil()'daki AYNI MySQL-saati prensibi (NOW())
    public static function findActiveForUser(int $userId): array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "SELECT pair, reason, DATE_FORMAT(cooldown_until, '%d.%m.%Y %H:%i') AS cooldown_until_formatted,
                    TIMESTAMPDIFF(MINUTE, NOW(), cooldown_until) AS minutes_remaining
             FROM symbol_cooldowns
             WHERE user_id = :user_id AND cooldown_until > NOW()
             ORDER BY cooldown_until ASC"
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll();
    }

    // Musterinin dashboard'dan MANUEL olarak bir sogumayi atlamasi icin - kullanicinin BILINCLI
    // tercihi, botun kendi karari degil. Sadece o (user_id, pair) ciftini siler, gecmis
    // active_trades/orders kayitlarina HIC dokunmaz - sadece "ardisik zarar sayimini yoksay"
    // deseniyle (bkz. ApiKey::resetLossStreak) AYNI ilke: veriyi degil, sadece ileri-donuk
    // kisitlamayi kaldirir
    public static function clear(int $userId, string $pair): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('DELETE FROM symbol_cooldowns WHERE user_id = :user_id AND pair = :pair');
        $stmt->execute([':user_id' => $userId, ':pair' => $pair]);
    }
}
