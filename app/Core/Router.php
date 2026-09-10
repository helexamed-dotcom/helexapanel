<?php
declare(strict_types=1);

namespace HeleXa\Core;

use HeleXa\Middleware\MiddlewareInterface;

/**
 * Minimal router with a middleware pipeline.
 * Every route declares its own middleware stack, so authentication and
 * authorization are structural rather than something a controller can forget.
 */
final class Router
{
    /** @var array<int, array{method:string, regex:string, params:array<int,string>, handler:array, middleware:array<int,string>}> */
    private array $routes = [];

    /** @var array<int,string> middleware applied to every route */
    private array $globalMiddleware = [];

    /** @var array<int,string> */
    private array $groupMiddleware = [];
    private string $groupPrefix = '';

    public function globalMiddleware(array $middleware): void
    {
        $this->globalMiddleware = $middleware;
    }

    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $previousPrefix     = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix     = rtrim($previousPrefix . '/' . trim($prefix, '/'), '/');
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix     = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    public function get(string $path, array $handler, array $middleware = []): void    { $this->add('GET', $path, $handler, $middleware); }
    public function post(string $path, array $handler, array $middleware = []): void   { $this->add('POST', $path, $handler, $middleware); }
    public function put(string $path, array $handler, array $middleware = []): void    { $this->add('PUT', $path, $handler, $middleware); }
    public function patch(string $path, array $handler, array $middleware = []): void  { $this->add('PATCH', $path, $handler, $middleware); }
    public function delete(string $path, array $handler, array $middleware = []): void { $this->add('DELETE', $path, $handler, $middleware); }

    private function add(string $method, string $path, array $handler, array $middleware): void
    {
        $full = rtrim($this->groupPrefix . '/' . trim($path, '/'), '/');
        if ($full === '') {
            $full = '/';
        }

        $params = [];
        $regex  = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $full
        );

        $this->routes[] = [
            'method'     => $method,
            'path'       => $full,
            'regex'      => '#^' . $regex . '$#u',
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
        ];
    }

    /**
     * The full route table, used by the security review page to prove that
     * every non-public route actually carries its guards.
     *
     * @return array<int, array{method:string, path:string, middleware:array<int,string>}>
     */
    public function table(): array
    {
        $rows = [];
        foreach ($this->routes as $route) {
            $rows[] = [
                'method'     => $route['method'],
                'path'       => $route['path'],
                'middleware' => array_merge($this->globalMiddleware, $route['middleware']),
            ];
        }
        return $rows;
    }

    public function dispatch(Request $request): Response
    {
        $path   = $request->path();
        $method = $request->method();
        $allowedForPath = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowedForPath[] = $route['method'];
                continue;
            }

            array_shift($matches);
            $parameters = [];
            foreach ($route['params'] as $index => $name) {
                $parameters[$name] = $matches[$index] ?? null;
            }

            return $this->runPipeline($request, $route, $parameters);
        }

        if ($allowedForPath !== []) {
            return Response::make('', 405, ['Allow' => implode(', ', array_unique($allowedForPath))]);
        }

        throw HttpException::notFound();
    }

    private function runPipeline(Request $request, array $route, array $parameters): Response
    {
        $stack = array_merge($this->globalMiddleware, $route['middleware']);

        $runner = function (Request $req) use ($route, $parameters): Response {
            [$class, $action] = $route['handler'];
            if (!class_exists($class)) {
                throw HttpException::notFound();
            }
            $controller = new $class();
            if (!method_exists($controller, $action)) {
                throw HttpException::notFound();
            }
            return $controller->{$action}($req, $parameters);
        };

        foreach (array_reverse($stack) as $definition) {
            $next   = $runner;
            $runner = function (Request $req) use ($definition, $next): Response {
                // Middleware may carry arguments: "Class:permission" or "Class:a,b".
                [$class, $argString] = array_pad(explode(':', $definition, 2), 2, null);
                $args = $argString === null || $argString === '' ? [] : explode(',', $argString);
                /** @var MiddlewareInterface $middleware */
                $middleware = new $class(...$args);
                return $middleware->handle($req, $next);
            };
        }

        return $runner($request);
    }
}
