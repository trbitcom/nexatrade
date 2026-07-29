<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

// Her cron calistirmasinin yapisal ozetini tutar: taranan coinler, AI skorlari, secilen coin, acilan pozisyon sayisi
final class BotLog
{
    // Bu sureden eski kayitlar create() her cagrildiginda (saatte en fazla bir kez kontrol edilerek,
    // her INSERT'te degil) otomatik silinir - tablo sunucuda (cPanel/MySQL) sinirsiz buyumesin diye.
    // 60 gune cikarildi: 60 gunluk performans/PNL/AI karar analizi testi icin gecmis taramalarin
    // (hangi coin ne skor aldi, neden secildi/elendi) TAM olarak saklanmasi gerekiyor - eskiden 15
    // gunde silindigi icin bu sure dolmadan analiz yapilmazsa veri kaybediliyordu
    private const PRUNE_RETENTION_DAYS = 60;
    private const PRUNE_CHECK_SETTING_KEY = 'bot_logs_last_pruned_at';
    private const PRUNE_CHECK_INTERVAL_SECONDS = 3600;

    /**
     * @param string[] $scannedSymbols
     * @param array<array{symbol: string, score: int, reason: string, is_buy_signal: bool}> $aiScores
     * @param array<string, array{priceChangePercent?: float, quoteVolume?: float, high_90d?: float, low_90d?: float, position_percent_90d?: float}> $inputData
     *        GPT'ye gonderilen ham piyasa verisi (sembol => veri) - ileride "GPT bu karari verirken
     *        piyasa nasildi?" backtest/analizi icin. Bos birakilirsa NULL olarak saklanir
     */
    public static function create(
        array $scannedSymbols,
        array $aiScores,
        ?string $selectedSymbol,
        ?int $selectedScore,
        int $positionsOpened,
        ?string $notes = null,
        string $tradeType = 'spot',
        array $inputData = []
    ): int {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'INSERT INTO bot_logs (trade_type, scanned_symbols, ai_scores, input_data, selected_symbol, selected_score, positions_opened, notes)
             VALUES (:trade_type, :scanned_symbols, :ai_scores, :input_data, :selected_symbol, :selected_score, :positions_opened, :notes)'
        );

        $stmt->execute([
            ':trade_type' => $tradeType,
            ':scanned_symbols' => json_encode($scannedSymbols, JSON_UNESCAPED_UNICODE),
            ':ai_scores' => json_encode($aiScores, JSON_UNESCAPED_UNICODE),
            ':input_data' => $inputData === [] ? null : json_encode($inputData, JSON_UNESCAPED_UNICODE),
            ':selected_symbol' => $selectedSymbol,
            ':selected_score' => $selectedScore,
            ':positions_opened' => $positionsOpened,
            ':notes' => $notes,
        ]);

        $insertedId = (int) $pdo->lastInsertId();

        self::pruneIfDue();

        return $insertedId;
    }

    // Son PRUNE_RETENTION_DAYS gunden eski loglari siler. Her INSERT'te degil, en fazla saatte bir
    // (Setting uzerinden takip edilerek) calisir - boylece sik cron tetiklemelerinde (ör. futures'ta
    // 1 dk) gereksiz DELETE sorgusu ile sunucu/MySQL yorulmaz
    private static function pruneIfDue(): void
    {
        $lastPrunedAt = Setting::get(self::PRUNE_CHECK_SETTING_KEY);

        if ($lastPrunedAt !== null && (time() - (int) $lastPrunedAt) < self::PRUNE_CHECK_INTERVAL_SECONDS) {
            return;
        }

        $pdo = Database::getInstance();
        $pdo->exec('DELETE FROM bot_logs WHERE run_at < (NOW() - INTERVAL ' . self::PRUNE_RETENTION_DAYS . ' DAY)');

        Setting::set(self::PRUNE_CHECK_SETTING_KEY, (string) time());
    }

    public static function findRecent(int $limit = 20): array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT * FROM bot_logs ORDER BY run_at DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // Son cron calistirmasinin tam kaydini doner — apiScanStatus icin
    public static function findLatest(): ?array
    {
        $pdo = Database::getInstance();
        $row = $pdo->query('SELECT * FROM bot_logs ORDER BY run_at DESC LIMIT 1')->fetch();
        return $row !== false ? $row : null;
    }
}
