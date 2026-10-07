<?php
declare(strict_types=1);

namespace App\Core;

final class Kernel
{
    public static function run(): void
    {
        $routes = require APP_ROOT . '/config/routes.php';
        (new Router($routes))->dispatch(Request::capture());
    }
}
