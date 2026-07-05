<?php

namespace Core;

class Router
{
    protected $routes = [];

    public function get($url, $controller)
    {
        $this->routes[] = [
            'url' => $url,
            'controller' => $controller,
            'method' => 'GET'
        ];
    }

    public function post($url, $controller)
    {
        $this->routes[] = [
            'url' => $url,
            'controller' => $controller,
            'method' => 'POST'
        ];
    }

    public function delete($url, $controller)
    {
        $this->routes[] = [
            'url' => $url,
            'controller' => $controller,
            'method' => 'DELETE'
        ];
    }

    public function patch($url, $controller)
    {
        $this->routes[] = [
            'url' => $url,
            'controller' => $controller,
            'method' => 'PATCH'
        ];
    }

    public function put($url, $controller)
    {
        $this->routes[] = [
            'url' => $url,
            'controller' => $controller,
            'method' => 'PUT'
        ];
    }

    public function route($uri, $method)
    {
        foreach ($this->routes as $route) {
            if ($route['url'] === $uri && $route['method'] === strtoupper($method)) {
                return require BASE_PATH . ($route['controller']);
            }
        }
        $this->abort();
    }
    public function abort($value = 404, $message = "404 not Found")
    {
        http_response_code($value);
        echo json_encode([
            "status" => "Error",
            "message" => $message
        ]);
        die();
    }
}
