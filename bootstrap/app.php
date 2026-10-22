<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;

// Console-only: the eq:* data-pipeline commands. The site itself is static (see README).
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
    )
    // Looks like a no-op, but this call is what binds the exception handler; Artisan won't boot without it.
    ->withExceptions(function (Exceptions $exceptions): void {})
    ->create();
