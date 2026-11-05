<?php

declare(strict_types=1);

namespace Orbit;

final class Router
{
    /** @var array<int, array<string, mixed>> */
    private array $routes = [];

    /** @var array<string, callable> */
    private array $middleware = [];

    public function __construct(private readonly App $app)
    {
    }

    public function middleware(string $name, callable $handler): void
    {
        $this->middleware[$name] = $handler;
    }

    public function add(string $method, string $pattern, string $class, string $action, array $middleware = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'class' => $class,
            'action' => $action,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): void
    {
        if ($request->method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            $regex = preg_replace('#\{([A-Za-z_][A-Za-z0-9_]*)\}#', '(?P<$1>[^/]+)', $route['pattern']);
            if (!preg_match('#^' . $regex . '$#', $request->path, $matches)) {
                continue;
            }
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            $request->params = $params;
            foreach ($route['middleware'] as $name) {
                if (!isset($this->middleware[$name])) {
                    throw new \RuntimeException('Unknown middleware: ' . $name);
                }
                ($this->middleware[$name])($request);
            }
            $controller = new $route['class']($this->app);
            $controller->{$route['action']}($request);
            return;
        }

        throw new HttpException(404, 'The requested endpoint does not exist.');
    }
}
