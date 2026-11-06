<?php
declare(strict_types=1);

namespace Sahab;

final class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $post;
    public array $files;
    private ?array $json = null;

    public function __construct(string $base)
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uri = rawurldecode($uri);
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $uri = '/' . trim($uri, '/');
        if (str_ends_with($uri, '/index.php')) $uri = substr($uri, 0, -10) ?: '/';
        $this->path = $uri === '' ? '/' : $uri;
        $this->query = $_GET;
        $this->post = $_POST;
        $this->files = $_FILES;
    }

    public function isPost(): bool { return $this->method === 'POST'; }

    public function get(string $k, mixed $default = null): mixed { return $this->query[$k] ?? $default; }
    public function input(string $k, mixed $default = null): mixed { return $this->post[$k] ?? $this->json()[$k] ?? $default; }
    public function str(string $k, string $default = ''): string
    {
        $v = $this->post[$k] ?? $this->query[$k] ?? $default;
        return is_scalar($v) ? trim((string)$v) : $default;
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string)($_SERVER[$key] ?? '');
    }

    public function json(): array
    {
        if ($this->json === null) {
            $this->json = [];
            if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
                $d = json_decode((string)file_get_contents('php://input'), true);
                if (is_array($d)) $this->json = $d;
            }
        }
        return $this->json;
    }

    public function ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'); }

    public function wantsJson(): bool
    {
        return str_contains($this->header('Accept'), 'application/json')
            || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
            || $this->header('X-Requested-With') === 'XMLHttpRequest';
    }
}
