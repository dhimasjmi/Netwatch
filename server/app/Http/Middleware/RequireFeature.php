<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Feature;
use App\Services\FeatureFlags;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Menutup route bila fiturnya dimatikan administrator: route:  ->middleware('feature:browsing'). */
final class RequireFeature
{
    public function __construct(private readonly FeatureFlags $features)
    {
    }

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $enum = Feature::from($feature); // salah ketik di route = bug; biarkan gagal saat pengembangan

        if ($this->features->enabled($enum)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => "Fitur \"{$enum->label()}\" dinonaktifkan oleh administrator."], 404);
        }

        return response()->view('features.disabled', ['feature' => $enum], 404);
    }
}
