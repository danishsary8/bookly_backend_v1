<?php

namespace App\Routes;


class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler)
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler)
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function put(string $path, callable $handler)
    {
        $this->routes['PUT'][$path] = $handler;
    }

    public function delete(string $path, callable $handler)
    {
        $this->routes['DELETE'][$path] = $handler;
    }

    public function dispatch(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = str_replace('/public', '', $uri);
        $uri = rtrim($uri, '/') ?: '/';

        if (isset($this->routes[$method][$uri])) {
            call_user_func($this->routes[$method][$uri]);
            return;
        }
        
        // Check for dynamic routes like /books/{id}
        foreach ($this->routes[$method] ?? [] as $route => $handler) {
            // Convert /books/{id} -> regex /books/(\d+)
            $pattern = rtrim($route, '/') ?: '/';
            $pattern = preg_replace('#\{[\w]+\}#', '([\w-]+)', $pattern);
            $pattern = "#^" . $pattern . "$#";

            if (preg_match($pattern, $uri, $matches)) {
                array_shift($matches); // remove full match
                call_user_func_array($handler, $matches);
                return;
            }
        }
       
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Route not found'
        ]);
    }
}
