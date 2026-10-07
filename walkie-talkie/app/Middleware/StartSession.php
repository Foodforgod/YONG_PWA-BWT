<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Session;

final class StartSession
{
    public function handle(Request $request): void
    {
        Session::start();
    }
}
