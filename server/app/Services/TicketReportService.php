<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\DateRange;
use Illuminate\Support\Facades\DB;

/** Ringkasan kepuasan dan kecepatan penanganan tiket pada satu rentang tanggal. */
final class TicketReportService
{
    /**
     * @return array{
     *     rated: int, average: float|null, distribution: array<int, int>, resolved: int, avg_resolution_hours: float|null,
     *     created: int, open_now: int, by_category: list<object>, low_comments: list<object>
     * }
     */
    public function summary(DateRange $range): array
    {
        $rated = DB::table('ticket_feedback')->whereNotNull('submitted_at')->whereBetween('submitted_at', [$range->from, $range->to]);

        $distribution = array_fill_keys([1, 2, 3, 4, 5], 0);
        foreach ((clone $rated)->selectRaw('rating, count(*) AS n')->groupBy('rating')->pluck('n', 'rating') as $rating => $count) {
            $distribution[(int) $rating] = (int) $count;
        }

        $resolved = DB::table('tickets')->whereNotNull('resolved_at')->whereBetween('resolved_at', [$range->from, $range->to]);
        $avgSeconds = (clone $resolved)->selectRaw('avg(extract(epoch from (resolved_at - created_at))) AS s')->value('s');
        $average = (clone $rated)->avg('rating');

        return [
            'rated' => array_sum($distribution),
            'average' => $average !== null ? round((float) $average, 2) : null,
            'distribution' => $distribution,
            'resolved' => (clone $resolved)->count(),
            'avg_resolution_hours' => $avgSeconds !== null ? round((float) $avgSeconds / 3600, 1) : null,
            'created' => DB::table('tickets')->whereBetween('created_at', [$range->from, $range->to])->count(),
            'open_now' => DB::table('tickets')->whereIn('status', ['open', 'in_progress'])->count(),
            'by_category' => DB::table('tickets')
                ->leftJoin('ticket_feedback', fn ($j) => $j->on('ticket_feedback.ticket_id', '=', 'tickets.id')->whereNotNull('ticket_feedback.submitted_at'))
                ->whereBetween('tickets.created_at', [$range->from, $range->to])
                ->selectRaw('tickets.category, count(DISTINCT tickets.id) AS tickets, avg(ticket_feedback.rating) AS avg_rating, count(ticket_feedback.id) AS rated')
                ->groupBy('tickets.category')->orderByDesc('tickets')->get()->all(),
            // Penilaian rendah beserta komentarnya: bahan perbaikan layanan yang paling berguna.
            'low_comments' => DB::table('ticket_feedback')
                ->join('tickets', 'tickets.id', '=', 'ticket_feedback.ticket_id')
                ->whereNotNull('ticket_feedback.submitted_at')->whereBetween('ticket_feedback.submitted_at', [$range->from, $range->to])
                ->where('ticket_feedback.rating', '<=', 3)->whereNotNull('ticket_feedback.comment')
                ->orderByDesc('ticket_feedback.submitted_at')->limit(10)
                ->get(['tickets.id', 'tickets.subject', 'ticket_feedback.rating', 'ticket_feedback.comment', 'ticket_feedback.submitted_at'])->all(),
        ];
    }
}
