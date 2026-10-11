<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Minimal front-controller router.
 *
 *   $r->get('/students/{id}', [StudentController::class, 'show']);
 *   $r->post('/login',        [AuthController::class,   'login']);
 *   $r->get('/health',        fn(array $p) => json_response(['ok' => true]));
 *
 * Placeholders: {name} matches one path segment; values arrive to handlers
 * as an associative array keyed by placeholder name.
 */
final class Router
{
    /** @var array<string, array<string, array|callable>> method => pattern => handler */
    private array $routes = [];

    public function get(string $pattern, array|callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, array|callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, array|callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, array|callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    /**
     * Strip a subfolder base (e.g. /Capstone/public_html) from a request path.
     *
     * Apache percent-encodes REQUEST_URI but percent-DECODES SCRIPT_NAME, so a
     * base containing a space (%20) or any other encoded character never matches
     * byte-for-byte. Both sides are therefore normalised to their decoded form
     * before comparing; the returned remainder is the decoded app path, which is
     * what the route patterns expect.
     *
     * @return string Application path, always starting with '/'
     */
    private static function stripBase(string $path, string $scriptName): string
    {
        $pos = strpos($scriptName, '/public_html');
        if ($pos === false) {
            return $path;
        }

        $base = rtrim(substr($scriptName, 0, $pos + strlen('/public_html')), '/');
        if ($base === '' || $base === '/') {
            return $path;
        }

        $decodedBase  = rawurldecode($base);
        $decodedPath  = rawurldecode($path);

        if (!str_starts_with($decodedPath, $decodedBase)) {
            return $path;
        }

        $rest = substr($decodedPath, strlen($decodedBase));
        $rest = '/' . trim($rest, '/');

        return $rest === '/' ? '/' : rtrim($rest, '/');
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        // Strip subfolder base (e.g. /Capstone/public_html) when the project is
        // accessed via http://localhost/Capstone/public_html/ instead of DocumentRoot
        $path = self::stripBase($path, $_SERVER['SCRIPT_NAME'] ?? '');

        $method = strtoupper($method);

        foreach ($this->routes[$method] ?? [] as $pattern => $handler) {
            if (preg_match($pattern, $path, $m)) {
                // keep only named placeholders
                $params = array_filter(
                    $m,
                    static fn($key) => !is_int($key),
                    ARRAY_FILTER_USE_KEY
                );
                $params = array_map('urldecode', $params);
                $this->invoke($handler, $params);
                return;
            }
        }

        http_response_code(404);
        exit('<!doctype html><html lang="en"><meta charset="utf-8"><title>404 Not Found</title>'
            . '<body style="font-family:sans-serif;text-align:center;padding-top:12vh">'
            . '<h1>404 - Page not found</h1><p><a href="/">Back to portal</a></p></body></html>');
    }

    /* ------------------------------ internals ------------------------------ */

    private function add(string $method, string $pattern, array|callable $handler): void
    {
        $regex = '#^' . preg_replace(
            '#\{([A-Za-z_][A-Za-z0-9_]*)\}#',
            '(?P<$1>[^/]+)',
            rtrim($pattern, '/')
        ) . '$#';
        if ($pattern === '/') {
            $regex = '#^/$#';
        }
        $this->routes[$method][$regex] = $handler;
    }

    private function invoke(array|callable $handler, array $params): void
    {
        if (is_callable($handler) && !is_array($handler)) {
            $handler($params);
            return;
        }
        [$class, $action] = $handler;
        if (!class_exists($class)) {
            throw new RuntimeException("Controller not found: {$class}");
        }
        $controller = new $class();
        $controller->{$action}($params);
    }
}
