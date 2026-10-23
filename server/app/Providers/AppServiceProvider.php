<?php

declare(strict_types=1);

namespace App\Providers;

use App\Alerts\MailChannel;
use App\Alerts\TelegramChannel;
use App\Models\User;
use App\Services\AlertDispatcher;
use App\Enums\Feature;
use App\Services\FeatureFlags;
use App\Services\Probe\SystemTargetProber;
use App\Services\Probe\TargetProber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TargetProber::class, SystemTargetProber::class);
        $this->app->singleton(FeatureFlags::class);

        $this->app->bind(AlertDispatcher::class, fn ($app): AlertDispatcher => new AlertDispatcher([
            $app->make(TelegramChannel::class),
            $app->make(MailChannel::class),
        ]));
    }

    public function boot(): void
    {
        if ($this->app->isProduction()) {
            DB::prohibitDestructiveCommands();

            if (config('netwatch.force_https')) {
                URL::forceScheme('https');
            }
        }

        Password::defaults(fn (): Password => Password::min(12)->mixedCase()->numbers());

        // Dipakai di view: $tanggal->toDisplay(). Data tersimpan UTC, tampil di zona waktu kantor.
        Carbon::macro('toDisplay', function (): string {
            /** @var Carbon $this */
            return $this->copy()->timezone((string) config('netwatch.display_timezone'))->format('d M Y H:i:s');
        });

        // @feature('browsing') ... @endfeature di view.
        Blade::if('feature', fn (string $feature): bool => $this->app->make(FeatureFlags::class)->enabled(Feature::from($feature)));

        $this->registerGates();
        $this->registerRateLimiters();
    }

    private function registerGates(): void
    {
        Gate::define('manage-users', fn (User $user): bool => $user->role->canManageFleet());
        Gate::define('manage-fleet', fn (User $user): bool => $user->role->canManageFleet());
        Gate::define('handle-tickets', fn (User $user): bool => $user->role->canHandleTickets());
        Gate::define('run-commands', fn (User $user): bool => $user->role->canRunCommands());
        // Restart/shutdown massal berisiko, jadi dibatasi seperti hak administrator.
        Gate::define('power-control', fn (User $user): bool => $user->role->canManageFleet());
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        // Form tiket dan penilaian dibuka tanpa login: dibatasi per alamat IP agar tidak dibanjiri.
        RateLimiter::for('ping-now', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->user()?->id));
        RateLimiter::for('ticket-create', fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->ip()));
        RateLimiter::for('ticket-feedback', fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->ip()));

        RateLimiter::for('agent-enroll', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) $request->ip()));

        RateLimiter::for('agent-api', function (Request $request): Limit {
            $token = $request->bearerToken();

            return Limit::perMinute(240)->by($token !== null ? hash('sha256', $token) : (string) $request->ip());
        });
    }
}
