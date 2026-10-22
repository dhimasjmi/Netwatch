<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateAgent;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'agent' => AuthenticateAgent::class,
            'active' => EnsureActiveUser::class,
        ]);
        // Bila di belakang Nginx/Caddy, isi NETWATCH_TRUSTED_PROXIES (IP proxy, pisahkan koma) agar IP klien dan skema HTTPS terbaca benar.
        $trustedProxies = array_filter(array_map('trim', explode(',', (string) env('NETWATCH_TRUSTED_PROXIES', ''))));
        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies);
        }

        $middleware->web(append: [SecurityHeaders::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })
    ->create();
