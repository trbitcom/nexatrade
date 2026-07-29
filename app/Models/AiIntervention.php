<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

// "Görünmez Kalkan" raporu: botun MTF/Emir Defteri gibi sert filtrelerle engellediği tuzakların
// müşteri-yüzlü özeti. Bu tablo, mevcut storage/logs/auto_trade.log'un YERİNE değil, YANINA
// yazılır - log dosyası teknik tanılama içindir, bu tablo dashboard'daki "AI Kalkanı" widget'ının
// okuduğu, kullanıcının anlayacağı dille yazılmış bir özet akıştır
final class AiIntervention
{
    // Aynı sembol + aynı müdahale türü için $throttleHours içinde zaten bir kayıt varsa, YENİ
    // satır EKLENMEZ (spam önleme) - tekrar tetiklenmeler zaten log dosyasına düşmeye devam eder,
    // bu tablo SADECE müşteri-yüzlü "seni neden koruduk" özetinin kalabalıklaşmaması içindir
    // @return bool true ise bu cagri GERCEKTEN yeni bir satir ekledi (throttle penceresinde
    // DEGILDI) - cagiran taraf bunu, ayni olay icin Telegram gibi ek/gurultulu bir bildirimi
    // AYNI throttle penceresiyle sinirlamak icin kullanabilir (bkz. PULLBACK_KACIS_SUPABI cagri
    // yeri, 25 Temmuz'da eklendi - onceden Telegram bu throttle'dan bagimsiz HER turda gidiyordu)
    public static function record(?int $userId, string $symbol, string $interventionType, string $description, int $throttleHours = 4): bool
    {
        $pdo = Database::getInstance();

        $checkStmt = $pdo->prepare(
            'SELECT 1 FROM ai_interventions
             WHERE symbol = :symbol AND intervention_type = :type
               AND created_at >= (NOW() - INTERVAL :hours HOUR)
             LIMIT 1'
        );
        $checkStmt->execute([':symbol' => $symbol, ':type' => $interventionType, ':hours' => $throttleHours]);

        if ($checkStmt->fetchColumn() !== false) {
            return false;
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO ai_interventions (user_id, symbol, intervention_type, description)
             VALUES (:user_id, :symbol, :type, :description)'
        );
        $insertStmt->execute([
            ':user_id' => $userId,
            ':symbol' => $symbol,
            ':type' => $interventionType,
            ':description' => $description,
        ]);

        return true;
    }

    // Dashboard "AI Kalkanı" widget'ı icin: en son N mudahaleyi (turu ne olursa olsun) doner
    public static function findRecent(int $limit = 5): array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT * FROM ai_interventions ORDER BY created_at DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
