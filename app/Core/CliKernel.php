<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\AutoFuturesTradeController;
use App\Controllers\AutoTradeController;
use App\Controllers\DailySummaryController;
use App\Controllers\ListingSniperController;
use App\Controllers\SmartMoneyController;

// VPS/crontab gecisi (28 Temmuz): cron-job.org gibi harici bir HTTP tetikleyiciye artik ihtiyac yok -
// sunucunun kendi crontab'i dogrudan "php public/index.php cli:komut" seklinde PHP CLI'i cagirabilir,
// boylece cron-job.org'un sabit 30sn HTTP zaman asimi sinirindan tamamen kurtulunur. Router.php'ye
// KASITLI OLARAK DOKUNULMADI (o hala sadece gercek HTTP istekleri icin calisiyor) - bu, index.php'nin
// EN BASINDA, Router hic devreye girmeden calisan ayri/bagimsiz bir dagitim yolu. Ilgili
// controller'lardaki isTokenValid()/isFastTrackerTokenValid() metodlari PHP_SAPI==='cli' oldugunda
// token kontrolunu atlar (bkz. o metodlarin yorumu) - guvenlidir cunku bir HTTP istegi PHP_SAPI'yi
// asla 'cli' olarak taklit edemez (sunucu tarafindan, SAPI modulunce belirlenir)
final class CliKernel
{
    /** @var array<string, array{0: class-string, 1: string}> */
    private const COMMANDS = [
        'auto-trade-run' => [AutoTradeController::class, 'run'],
        'fast-tracker'   => [AutoTradeController::class, 'runFastTracker'],
        'listing-sniper' => [ListingSniperController::class, 'run'],
        'smart-money'    => [SmartMoneyController::class, 'run'],
        'futures-trade'  => [AutoFuturesTradeController::class, 'run'],
        'daily-summary'  => [DailySummaryController::class, 'run'],
    ];

    /** @param list<string> $argv PHP'nin kendi $argv'si - argv[1] "cli:komut" formatinda beklenir */
    public function handle(array $argv): void
    {
        $arg = $argv[1] ?? '';

        if (!str_starts_with($arg, 'cli:')) {
            fwrite(STDERR, "Kullanım: php public/index.php cli:<komut>\n");
            fwrite(STDERR, 'Geçerli komutlar: ' . implode(', ', array_map(
                static fn (string $c): string => 'cli:' . $c,
                array_keys(self::COMMANDS)
            )) . "\n");
            exit(1);
        }

        $command = substr($arg, 4);
        $action = self::COMMANDS[$command] ?? null;

        if ($action === null) {
            fwrite(STDERR, "Bilinmeyen komut: {$command}\n");
            exit(1);
        }

        [$controllerClass, $methodName] = $action;
        $controller = new $controllerClass();
        $controller->$methodName();
    }
}
