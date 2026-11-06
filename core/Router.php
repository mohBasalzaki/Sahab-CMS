<?php
declare(strict_types=1);

namespace Sahab;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $re = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = [strtoupper($method), '#^' . $re . '$#u', $handler];
    }

    public function get(string $p, callable $h): void { $this->add('GET', $p, $h); }
    public function post(string $p, callable $h): void { $this->add('POST', $p, $h); }

    /** @return bool true when a route matched */
    public function dispatch(Request $req): bool
    {
        foreach ($this->routes as [$m, $re, $h]) {
            if ($m !== $req->method && !($m === 'GET' && $req->method === 'HEAD')) continue;
            if (preg_match($re, $req->path, $mt)) {
                $params = array_filter($mt, 'is_string', ARRAY_FILTER_USE_KEY);
                $h($req, $params);
                return true;
            }
        }
        return false;
    }
}
