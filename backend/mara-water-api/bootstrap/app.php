<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Round 2 Phase 11: real role-based access control, checked
        // server-side on every request that carries it.
        $middleware->alias([
            'tier' => \App\Http\Middleware\EnsureAccessTier::class,
            // Ops brief §9: payroll / profit require password re-auth unlock.
            'hr.unlocked' => \App\Http\Middleware\EnsureHrUnlocked::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This is an API-only backend: always answer /api/* with JSON, so an
        // unauthenticated request returns 401 JSON instead of trying to
        // redirect to a non-existent "login" route (which would 500).
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $throwable) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
