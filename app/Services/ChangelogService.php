<?php

declare(strict_types=1);

namespace App\Services;

// Proje kokundeki CHANGELOG.md dosyasini basit bir bicimde ayristirip Dashboard'daki
// "Neler Yeni?" penceresine yapisal veri olarak sunar - CHANGELOG.md TEK dogru kaynak,
// ikinci bir yerde (ör. veritabani) surum notu metni TEKRAR yazilmaz
final class ChangelogService
{
    private const CHANGELOG_PATH = __DIR__ . '/../../CHANGELOG.md';

    // Sadece admin panelini ilgilendiren (musteriye gosterilmemesi gereken) maddelerin basina
    // konur, ör: "- [ADMIN] **Baslik**: ...". getEntries() bu isaretli maddeleri musteri
    // modalindan otomatik eler - CHANGELOG.md yine de TAM gecmisi (dev/admin icin) tutar
    private const ADMIN_ONLY_MARKER = '[ADMIN]';

    // @return array<array{version: string, date: string, sections: array<string, string[]>}>
    public static function getEntries(int $limit = 5): array
    {
        if (!is_file(self::CHANGELOG_PATH)) {
            return [];
        }

        $content = file_get_contents(self::CHANGELOG_PATH);

        if ($content === false) {
            return [];
        }

        $entries = [];
        $currentEntry = null;
        $currentSection = null;

        foreach (explode("\n", $content) as $line) {
            // "## [1.1.0] - 2026-07-06" seklindeki surum basligi
            if (preg_match('/^##\s*\[([^\]]+)\]\s*-\s*(.+)$/', $line, $matches) === 1) {
                if ($currentEntry !== null) {
                    $entries[] = $currentEntry;
                }

                $currentEntry = ['version' => trim($matches[1]), 'date' => trim($matches[2]), 'sections' => []];
                $currentSection = null;
                continue;
            }

            if ($currentEntry === null) {
                continue; // henuz bir surum basligina ulasilmadi (dosyanin ust bilgisi vb.)
            }

            // "### Eklendi" seklindeki alt baslik
            if (preg_match('/^###\s*(.+)$/', $line, $matches) === 1) {
                $currentSection = trim($matches[1]);
                $currentEntry['sections'][$currentSection] = [];
                continue;
            }

            // "- Madde metni" seklindeki liste ogesi - [ADMIN] ile isaretlenmisse musteri
            // modaline hic eklenmez (CHANGELOG.md dosyasinda oldugu gibi kalir, sadece burada elenir)
            if ($currentSection !== null && preg_match('/^-\s+(.+)$/', $line, $matches) === 1) {
                $item = trim($matches[1]);

                if (str_starts_with($item, self::ADMIN_ONLY_MARKER)) {
                    continue;
                }

                $currentEntry['sections'][$currentSection][] = $item;
            }
        }

        if ($currentEntry !== null) {
            $entries[] = $currentEntry;
        }

        // [ADMIN] maddeleri elendikten sonra bos kalan bolumleri/surumleri musteri gorunumunden kaldirir
        // (ör. bir surum SADECE admin-panel degisiklikleriyse, o surum basligi bile gosterilmez)
        $filtered = [];

        foreach ($entries as $entry) {
            $entry['sections'] = array_filter($entry['sections'], static fn (array $items): bool => $items !== []);

            if ($entry['sections'] !== []) {
                $filtered[] = $entry;
            }
        }

        return array_slice($filtered, 0, $limit);
    }
}
