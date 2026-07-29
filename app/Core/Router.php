<?php

declare(strict_types=1);

namespace App\Core;

// Framework bagimsiz, hafif Router: URI + HTTP metoduna gore Controller metoduna yonlendirir
final class Router
{
    /** @var array<string, array<string, array{0: class-string, 1: string}>> */
    private array $routes = [
        'GET' => [],
        'POST' => [],
    ];

    public function get(string $uri, array $action): void
    {
        $this->addRoute('GET', $uri, $action);
    }

    public function post(string $uri, array $action): void
    {
        $this->addRoute('POST', $uri, $action);
    }

    private function addRoute(string $method, string $uri, array $action): void
    {
        $this->routes[$method][$this->normalize($uri)] = $action;
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $this->resolveUri();

        $action = $this->routes[$method][$uri] ?? null;

        if ($action === null) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Route bulunamadi']);
            return;
        }

        [$controllerClass, $methodName] = $action;
        $controller = new $controllerClass();
        $controller->$methodName();
    }

    // Gercek istek URI'sini, public/ klasorunun bulundugu alt dizini (varsa) cikararak cozer
    private function resolveUri(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $basePath = self::basePath();

        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        return $this->normalize($path);
    }

    // Proje alt dizinde (ör. /nexatrade) barindirilsa bile dogru kok yolu hesaplar
    // Url::to() tarafindan da kullanilir, boylece redirect/link'ler ortama gore otomatik uyarlanir
    public static function basePath(): string
    {
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/public/index.php');

        return rtrim(dirname($scriptDir), '/');
    }

    private function normalize(string $uri): string
    {
        $trimmed = trim($uri, '/');

        return $trimmed === '' ? '/' : '/' . $trimmed;
    }
}
