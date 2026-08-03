<?php

declare(strict_types=1);

namespace BYD\Controllers;

use BYD\Security\Security;

/**
 * Simple Router - maps URI + method to controller actions
 *
 * مكان هذا الملف: app/Controllers/Router.php
 */
final class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[] = compact('method', 'path', 'handler');
    }

    public function dispatch(string $method, string $uri): void
    {
        // Strip query string
        $path = $this->normalizePath((string) parse_url($uri, PHP_URL_PATH));

        // HEAD يجب أن يُعامل كأنه GET (نفس الهاندلر، بدون جسم الرد) —
        // خدمات خارجية زي Green API بتعمل HEAD أول قبل تحميل أي ملف
        // (مثلاً قبل ما تبعت صورة سيارة عبر sendFileByUrl).
        $lookupMethod = $method === 'HEAD' ? 'GET' : $method;

        foreach ($this->routes as $route) {
            if ($route['method'] === $lookupMethod) {
                $pattern = preg_replace('/\{[a-zA-Z0-9_]+\}/', '([^/]+)', $route['path']);
                $pattern = '#^' . $pattern . '$#';
                if (preg_match($pattern, $path, $matches)) {
                    array_shift($matches);

                    if ($method === 'HEAD') {
                        // نفّذ نفس الهاندلر، بس تجاهل الجسم (echo/readfile).
                        // الهيدرز اللي بيضبطها الهاندلر عبر header() (متل
                        // Content-Type اللي بيحطها روت الصور) بتنبعت عادي.
                        ob_start();
                        call_user_func_array($route['handler'], $matches);
                        ob_end_clean();
                        return;
                    }

                    call_user_func_array($route['handler'], $matches);
                    return;
                }
            }
        }

        Security::jsonError("Route not found: {$method} {$path}", 404);
    }

    private function normalizePath(string $path): string
    {
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $baseDir = str_replace('\\', '/', dirname($scriptName));

        if ($baseDir !== '/' && $baseDir !== '.' && str_starts_with($path, $baseDir)) {
            $path = substr($path, strlen($baseDir)) ?: '/';
        }

        if ($path === '') {
            return '/';
        }

        return $path[0] === '/' ? $path : '/' . $path;
    }
}