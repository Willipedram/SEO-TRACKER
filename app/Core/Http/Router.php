<?php

declare(strict_types=1);

namespace App\Core\Http;

use InvalidArgumentException;

final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function add(string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');
        if (isset($this->routes[$method][$path])) {
            throw new InvalidArgumentException(sprintf('Duplicate route: %s %s', $method, $path));
        }
        $this->routes[$method][$path] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $path = $request->path === '/' ? '/' : rtrim($request->path, '/');
        $handler = $this->routes[$request->method][$path] ?? null;
        if ($handler === null) {
            $allowed = array_keys(array_filter($this->routes, static fn (array $routes): bool => isset($routes[$path])));
            return $allowed === []
                ? Response::json(['error' => 'Not found.'], 404)
                : Response::json(['error' => 'Method not allowed.'], 405, ['Allow' => implode(', ', $allowed)]);
        }
        $response = $handler($request);
        if (!$response instanceof Response) {
            throw new InvalidArgumentException('Route handlers must return a Response.');
        }
        return $response;
    }

    public function all(): array
    {
        $result = [];
        foreach ($this->routes as $method => $routes) {
            foreach (array_keys($routes) as $path) {
                $result[] = [$method, $path];
            }
        }
        sort($result);
        return $result;
    }
}
