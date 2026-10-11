<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Base controller: view rendering with layouts + JSON/redirect helpers.
 *
 * View convention:
 *   $this->view('student/dashboard', ['title' => 'Dashboard']);
 *     -> renders app/views/student/dashboard.php into
 *        app/views/shared/layout.php as $content.
 */
abstract class Controller
{
    protected string $viewsPath = APP_ROOT . '/views';

    protected function view(string $template, array $data = [], ?string $layout = 'shared/layout'): void
    {
        $viewFile = rtrim($this->viewsPath, '/\\') . '/' . str_replace(['..', '\\'], '', $template) . '.php';
        if (!is_file($viewFile)) {
            throw new RuntimeException("View not found: {$template}");
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();

        if ($layout !== null) {
            $layoutFile = rtrim($this->viewsPath, '/\\') . '/' . $layout . '.php';
            if (!is_file($layoutFile)) {
                throw new RuntimeException("Layout not found: {$layout}");
            }
            require $layoutFile;
            return;
        }
        echo $content;
    }

    /** Emit JSON and stop. */
    protected function json(mixed $payload, int $status = 200): void
    {
        json_response($payload, $status);
    }

    protected function redirect(string $path): void
    {
        redirect($path);
    }

    /** Guard helper for pages that need any logged-in user. */
    protected function requireLogin(): void
    {
        Auth::check() || redirect('/login');
    }

    /** Current user row (null-safe shorthand for views). */
    protected function currentUser(): ?array
    {
        return Auth::user();
    }
}
