<?php

declare(strict_types=1);

namespace Khatauat\Core;

final class Router
{
    private array $routes = [];

    public function get(string $pattern, callable|array $handler): void { $this->add('GET', $pattern, $handler); }
    public function post(string $pattern, callable|array $handler): void { $this->add('POST', $pattern, $handler); }

    private function add(string $method, string $pattern, callable|array $handler): void
    {
        $this->routes[] = [$method, $pattern, $handler];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = trim(parse_url($uri, PHP_URL_PATH) ?: '/', '/');
        foreach ($this->routes as [$verb, $pattern, $handler]) {
            if ($verb !== $method) continue;
            $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', trim($pattern, '/'));
            $regex = '#^' . ($regex === '' ? '' : $regex) . '$#u';
            if (preg_match($regex, $path, $matches)) {
                $params = [];
                foreach ($matches as $k => $v) if (is_string($k)) $params[$k] = urldecode($v);
                if (is_array($handler) && is_string($handler[0])) {
                    $controller = new $handler[0]();
                    $controller->{$handler[1]}(...array_values($params));
                } else {
                    $handler(...array_values($params));
                }
                return;
            }
        }
        http_response_code(404);
        View::render('errors/404', ['title' => 'الصفحة غير موجودة']);
    }
}
