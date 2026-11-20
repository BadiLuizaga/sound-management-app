<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
        ]);

        // Fase 2 — Etapa 2: el webhook de PayPal no tiene sesión ni token CSRF;
        // su autenticidad se verifica con la firma de PayPal. Única excepción.
        $middleware->validateCsrfTokens(except: [
            'webhooks/paypal',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
