<?php
declare(strict_types=1);

/**
 * EduPortal - Front Controller
 * Datamex College of Saint Adeline
 *
 * All HTTP requests land here via .htaccess. Application code lives outside
 * public_html and is loaded through app/core/bootstrap.php.
 */

define('APP_ROOT', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app');

require APP_ROOT . '/Core/bootstrap.php';

use App\Core\Auth;
use App\Core\Router;

/* Idle-session enforcement + activity stamp for logged-in users. */
Auth::boot();

/* Route table (Phase 4 adds auth/enrollment/grading/payment routes). */
$router = new Router();
$routeLoader = require APP_ROOT . '/routes/web.php';
$routeLoader($router);

$router->dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['REQUEST_URI'] ?? '/'
);
