<?php

declare(strict_types=1);

use App\Http\Controllers\Web\AlertRuleController;
use App\Http\Controllers\Web\AssetController;
use App\Http\Controllers\Web\FeedbackController;
use App\Http\Controllers\Web\PublicTicketController;
use App\Http\Controllers\Web\TicketController;
use App\Http\Controllers\Web\TicketReportController;
use App\Http\Controllers\Web\BrowsingIgnoredHostController;
use App\Http\Controllers\Web\DeviceAssetsController;
use App\Http\Controllers\Web\AuditLogController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\BrowsingController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DeviceBandwidthController;
use App\Http\Controllers\Web\DeviceCommandController;
use App\Http\Controllers\Web\DeviceController;
use App\Http\Controllers\Web\DeviceInventoryController;
use App\Http\Controllers\Web\DeviceMaintenanceController;
use App\Http\Controllers\Web\DeviceMetricsController;
use App\Http\Controllers\Web\DeviceGroupController;
use App\Http\Controllers\Web\DeviceUptimeController;
use App\Http\Controllers\Web\EnrollmentTokenController;
use App\Http\Controllers\Web\EventController;
use App\Http\Controllers\Web\BrowsingMonitorController;
use App\Http\Controllers\Web\DeviceBrowsingController;
use App\Http\Controllers\Web\FeatureController;
use App\Http\Controllers\Web\FleetBandwidthController;
use App\Http\Controllers\Web\NetworkMonitorController;
use App\Http\Controllers\Web\PolicyController;
use App\Http\Controllers\Web\SecurityController;
use App\Http\Controllers\Web\UptimeReportController;
use App\Http\Controllers\Web\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// Halaman untuk pengguna akhir (tanpa login): buat tiket dan beri penilaian. Dibatasi laju per IP.
Route::middleware('feature:tickets')->group(function (): void {
    Route::get('/tickets/new', [PublicTicketController::class, 'create'])->name('tickets.public.create');
    Route::post('/tickets/new', [PublicTicketController::class, 'store'])->middleware('throttle:ticket-create')->name('tickets.public.store');
    Route::get('/tickets/thanks', [PublicTicketController::class, 'thanks'])->name('tickets.public.thanks');
    Route::post('/feedback', [FeedbackController::class, 'lookup'])->middleware('throttle:ticket-feedback')->name('feedback.lookup');
    Route::get('/f/{code}', [FeedbackController::class, 'show'])->middleware('throttle:ticket-feedback')->name('feedback.show');
    Route::post('/f/{code}', [FeedbackController::class, 'store'])->middleware('throttle:ticket-feedback')->name('feedback.store');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboard/widgets', [DashboardController::class, 'update'])->name('dashboard.widgets');

    Route::get('/devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::get('/devices/live', [DeviceController::class, 'liveIndex'])->name('devices.live');
    Route::get('/devices/{device}', [DeviceController::class, 'show'])->name('devices.show');
    Route::get('/devices/{device}/live', [DeviceController::class, 'live'])->name('devices.show.live');
    Route::get('/devices/{device}/metrics', DeviceMetricsController::class)->name('devices.metrics');
    Route::get('/devices/{device}/uptime', DeviceUptimeController::class)->name('devices.uptime');

    Route::middleware('feature:bandwidth')->group(function (): void {
        Route::get('/devices/{device}/bandwidth', [DeviceBandwidthController::class, 'show'])->name('devices.bandwidth');
        Route::get('/devices/{device}/bandwidth/data', [DeviceBandwidthController::class, 'data'])->name('devices.bandwidth.data');
        Route::get('/devices/{device}/bandwidth/export', [DeviceBandwidthController::class, 'csv'])->name('devices.bandwidth.csv');
        Route::get('/monitoring/bandwidth', [FleetBandwidthController::class, 'index'])->name('monitor.bandwidth');
        Route::get('/monitoring/bandwidth/data', [FleetBandwidthController::class, 'data'])->name('monitor.bandwidth.data');
    });

    Route::get('/events', [EventController::class, 'index'])->name('events.index');
    Route::get('/network', [NetworkMonitorController::class, 'index'])->middleware('feature:network')->name('network.index');

    Route::get('/reports/uptime', [UptimeReportController::class, 'index'])->name('reports.uptime');
    Route::get('/reports/uptime/export', [UptimeReportController::class, 'csv'])->name('reports.uptime.csv');
    Route::post('/devices/{device}/commands', [DeviceCommandController::class, 'store'])
        ->middleware('can:run-commands')
        ->name('devices.commands.store');

    Route::middleware(['can:handle-tickets', 'feature:tickets'])->group(function (): void {
        Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
        Route::get('/tickets/report', TicketReportController::class)->name('tickets.report');
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->whereNumber('ticket')->name('tickets.show');
        Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])->whereNumber('ticket')->name('tickets.update');
        Route::post('/tickets/{ticket}/comments', [TicketController::class, 'comment'])->whereNumber('ticket')->name('tickets.comment');
        Route::post('/tickets/{ticket}/resolve', [TicketController::class, 'resolve'])->whereNumber('ticket')->name('tickets.resolve');
        Route::post('/tickets/{ticket}/feedback-code', [TicketController::class, 'feedbackCode'])->whereNumber('ticket')->name('tickets.feedback-code');
    });

    Route::middleware('can:manage-fleet')->group(function (): void {
        Route::patch('/devices/{device}', [DeviceController::class, 'update'])->name('devices.update');
        Route::delete('/devices/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy');
        Route::post('/devices/{device}/maintenance', [DeviceMaintenanceController::class, 'store'])->name('devices.maintenance.store');
        Route::delete('/devices/{device}/maintenance', [DeviceMaintenanceController::class, 'destroy'])->name('devices.maintenance.destroy');

        Route::middleware('feature:inventory')->group(function (): void {
            Route::get('/devices/{device}/inventory', DeviceInventoryController::class)->name('devices.inventory');
            Route::get('/devices/{device}/assets', DeviceAssetsController::class)->name('devices.assets');
            Route::get('/assets', [AssetController::class, 'index'])->name('assets.index');
            Route::get('/assets/software', [AssetController::class, 'software'])->name('assets.software');
        });

        Route::middleware('feature:browsing')->group(function (): void {
            Route::get('/browsing', [BrowsingController::class, 'index'])->name('browsing.index');
            Route::post('/browsing/ignored', [BrowsingIgnoredHostController::class, 'store'])->name('browsing.ignored.store');
            Route::delete('/browsing/ignored/{ignored}', [BrowsingIgnoredHostController::class, 'destroy'])->name('browsing.ignored.destroy');
            Route::get('/browsing/csv', [BrowsingController::class, 'csv'])->name('browsing.csv');
            Route::get('/devices/{device}/browsing', [DeviceBrowsingController::class, 'index'])->name('devices.browsing');
            Route::get('/devices/{device}/browsing/data', [DeviceBrowsingController::class, 'data'])->name('devices.browsing.data');
            Route::get('/monitoring/browsing', [BrowsingMonitorController::class, 'index'])->name('monitor.browsing');
            Route::get('/monitoring/browsing/data', [BrowsingMonitorController::class, 'data'])->name('monitor.browsing.data');
        });

        Route::get('/features', [FeatureController::class, 'index'])->name('features.index');
        Route::patch('/features/{feature}', [FeatureController::class, 'update'])->name('features.update');

        Route::get('/groups', [DeviceGroupController::class, 'index'])->name('groups.index');
        Route::post('/groups', [DeviceGroupController::class, 'store'])->name('groups.store');
        Route::delete('/groups/{group}', [DeviceGroupController::class, 'destroy'])->name('groups.destroy');

        Route::get('/enrollment-tokens', [EnrollmentTokenController::class, 'index'])->name('tokens.index');
        Route::post('/enrollment-tokens', [EnrollmentTokenController::class, 'store'])->name('tokens.store');
        Route::delete('/enrollment-tokens/{token}', [EnrollmentTokenController::class, 'destroy'])->name('tokens.destroy');

        Route::get('/policies', [PolicyController::class, 'index'])->name('policies.index');
        Route::post('/policies', [PolicyController::class, 'store'])->name('policies.store');
        Route::patch('/policies/{policy}', [PolicyController::class, 'update'])->name('policies.update');
        Route::post('/policies/{policy}/toggle', [PolicyController::class, 'toggle'])->name('policies.toggle');
        Route::delete('/policies/{policy}', [PolicyController::class, 'destroy'])->name('policies.destroy');
        Route::post('/policies/{policy}/entries', [PolicyController::class, 'addEntries'])->name('policies.entries.store');
        Route::delete('/policies/{policy}/entries/{rule}', [PolicyController::class, 'removeEntry'])->name('policies.entries.destroy');

        Route::middleware('feature:network')->group(function (): void {
            Route::post('/network', [NetworkMonitorController::class, 'store'])->name('network.store');
            Route::patch('/network/{target}', [NetworkMonitorController::class, 'update'])->whereNumber('target')->name('network.update');
            Route::post('/network/{target}/toggle', [NetworkMonitorController::class, 'toggle'])->whereNumber('target')->name('network.toggle');
            Route::post('/network/{target}/ping', [NetworkMonitorController::class, 'ping'])->whereNumber('target')->middleware('throttle:ping-now')->name('network.ping');
            Route::delete('/network/{target}', [NetworkMonitorController::class, 'destroy'])->whereNumber('target')->name('network.destroy');
        });

        Route::middleware('feature:threshold_alerts')->group(function (): void {
            Route::get('/alerts', [AlertRuleController::class, 'index'])->name('alerts.index');
            Route::post('/alerts', [AlertRuleController::class, 'store'])->name('alerts.store');
            Route::patch('/alerts/{rule}', [AlertRuleController::class, 'update'])->name('alerts.update');
            Route::post('/alerts/{rule}/toggle', [AlertRuleController::class, 'toggle'])->name('alerts.toggle');
            Route::delete('/alerts/{rule}', [AlertRuleController::class, 'destroy'])->name('alerts.destroy');
        });

        Route::get('/security', SecurityController::class)->name('security.index');
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
    });

    Route::middleware('can:manage-users')->prefix('users')->name('users.')->group(function (): void {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('/create', [UserController::class, 'create'])->name('create');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit');
        Route::patch('/{user}', [UserController::class, 'update'])->name('update');
        Route::patch('/{user}/active', [UserController::class, 'toggleActive'])->name('active');
        Route::put('/{user}/password', [UserController::class, 'resetPassword'])->name('password');
    });
});
