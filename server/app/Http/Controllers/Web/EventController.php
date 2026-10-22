<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\EventCategory;
use App\Enums\EventSeverity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\EventFilterRequest;
use App\Models\Device;
use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class EventController extends Controller
{
    private const PAGE_SIZE = 50;

    public function index(EventFilterRequest $request): View
    {
        $range = $request->range();
        $canSeeSecurity = Gate::allows('manage-fleet');
        $category = EventCategory::tryFrom((string) $request->input('category'));
        $severity = EventSeverity::tryFrom((string) $request->input('severity'));
        $device = $request->filled('device') ? Device::query()->where('public_id', $request->input('device'))->first() : null;

        // Event keamanan (email/IP percobaan login) dan isi Windows Event Log (bisa memuat nama pengguna/jalur) hanya untuk administrator.
        /** @var Builder<Event> $scoped */
        $scoped = Event::query()
            ->whereBetween('occurred_at', [$range->from, $range->to])
            ->when(! $canSeeSecurity, fn (Builder $q) => $q->where('category', '!=', EventCategory::Security->value)->where('code', 'not like', 'winlog.%'))
            ->when($category !== null, fn (Builder $q) => $q->where('category', $category->value))
            ->when($device !== null, fn (Builder $q) => $q->where('device_id', $device->id));

        $counts = (clone $scoped)->selectRaw('severity, count(*) AS total')->groupBy('severity')->pluck('total', 'severity');

        $events = (clone $scoped)
            ->when($severity !== null, fn (Builder $q) => $q->where('severity', $severity->value))
            ->with(['device', 'user'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString();

        return view('events.index', [
            'events' => $events,
            'range' => $range,
            'counts' => $counts,
            'category' => $category,
            'severity' => $severity,
            'device' => $device,
            'categories' => array_values(array_filter(
                EventCategory::cases(),
                fn (EventCategory $c): bool => $canSeeSecurity || $c !== EventCategory::Security,
            )),
            'devices' => Device::query()->active()->orderBy('hostname')->get(['id', 'public_id', 'hostname', 'display_name']),
            'query' => array_filter($request->only(['from', 'to', 'preset', 'category', 'device'])),
        ]);
    }
}
