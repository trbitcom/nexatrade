<?php

declare(strict_types=1);

namespace App\Core;

// Proje alt dizinde barindirilsa bile (ör. /nexatrade) dogru redirect/link URL'i uretir
final class Url
{
    public static function to(string $path): string
    {
        return Router::basePath() . '/' . ltrim($path, '/');
    }
}
