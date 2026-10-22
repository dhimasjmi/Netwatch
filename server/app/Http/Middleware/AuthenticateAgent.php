<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use App\Services\TokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/** Mengautentikasi agent lewat bearer token per-perangkat (hanya hash-nya yang tersimpan). */
final class AuthenticateAgent
{
    private const ATTRIBUTE = 'agent_device';
    private const TOKEN_LENGTH = 64;

    public function __construct(private readonly TokenService $tokens)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || strlen($token) !== self::TOKEN_LENGTH) {
            return $this->unauthorized();
        }

        $device = Device::query()
            ->active()
            ->where('token_hash', $this->tokens->hash($token))
            ->first();

        if ($device === null) {
            return $this->unauthorized();
        }

        $request->attributes->set(self::ATTRIBUTE, $device);

        return $next($request);
    }

    /** Perangkat yang sudah diautentikasi untuk request ini. */
    public static function deviceFrom(Request $request): Device
    {
        $device = $request->attributes->get(self::ATTRIBUTE);

        if (! $device instanceof Device) {
            throw new LogicException('Route ini harus dilindungi middleware "agent".');
        }

        return $device;
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
