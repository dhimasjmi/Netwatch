<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\TicketCategory;
use App\Http\Controllers\Controller;
use App\Services\TicketReportService;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class TicketReportController extends Controller
{
    public function __invoke(Request $request, TicketReportService $reports): View
    {
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'preset' => ['nullable', 'in:24h,7d,30d']]);

        $range = DateRange::fromInput($request->input('from'), $request->input('to'), $request->input('preset') ?? '30d', (string) config('netwatch.display_timezone'), maxDays: 366);

        return view('tickets.report', ['range' => $range, 'report' => $reports->summary($range), 'categories' => collect(TicketCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])]);
    }
}
