<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\AdminTicketRequest;
use App\Models\Device;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketNotifier;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class TicketController extends Controller
{
    public function __construct(private readonly TicketService $tickets)
    {
    }

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'active');
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $like = '%'.addcslashes(mb_strtolower($search), '\\%_').'%';

        $list = Ticket::query()->with(['device', 'assignee'])
            ->when($status === 'active', fn ($q) => $q->whereIn('status', ['open', 'in_progress']))
            ->when(TicketStatus::tryFrom($status) !== null, fn ($q) => $q->where('status', $status))
            ->when($request->query('mine') === '1', fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('lower(subject) LIKE ?', [$like])->orWhereRaw('lower(requester_name) LIKE ?', [$like])))
            ->orderByRaw("CASE status WHEN 'resolved' THEN 1 ELSE 0 END")->orderByDesc('created_at')->paginate(25)->withQueryString();

        return view('tickets.index', [
            'tickets' => $list,
            'status' => $status,
            'search' => $search,
            'mine' => $request->query('mine') === '1',
            'categories' => TicketCategory::cases(),
            'priorities' => TicketPriority::cases(),
            'devices' => Device::query()->active()->orderBy('hostname')->get(['id', 'public_id', 'hostname', 'display_name']),
        ]);
    }

    public function store(AdminTicketRequest $request): RedirectResponse
    {
        $device = $request->filled('device') ? Device::query()->where('public_id', $request->input('device'))->first() : null;
        $ticket = $this->tickets->create($request->ticketData(), $device, 'admin', $request->user(), $request->ip());

        return redirect()->route('tickets.show', $ticket)->with('status', "Tiket {$ticket->number()} dibuat.");
    }

    public function show(Ticket $ticket, TicketNotifier $notifier): View
    {
        $ticket->load(['device', 'assignee', 'comments.author', 'feedback']);

        return view('tickets.show', [
            'ticket' => $ticket,
            'staff' => User::query()->where('is_active', true)->whereIn('role', ['admin', 'operator'])->orderBy('name')->get(['id', 'name']),
            'statuses' => [TicketStatus::Open, TicketStatus::InProgress],
            'priorities' => TicketPriority::cases(),
            'emailAvailable' => $notifier->emailAvailable($ticket),
        ]);
    }

    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'in_progress'])],
            'priority' => ['sometimes', Rule::enum(TicketPriority::class)],
            'assigned_to' => ['sometimes', 'nullable', Rule::exists('users', 'id')->whereIn('role', ['admin', 'operator'])->where('is_active', true)],
        ]);

        $this->tickets->update($request->user(), $ticket, $data);

        return back()->with('status', 'Tiket diperbarui.');
    }

    public function comment(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000'], 'internal' => ['nullable', 'boolean']]);
        $this->tickets->comment($request->user(), $ticket, trim($data['body']), (bool) ($data['internal'] ?? false));

        return back()->with('status', 'Komentar ditambahkan.');
    }

    public function resolve(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000'], 'send_email' => ['nullable', 'boolean'], 'send_popup' => ['nullable', 'boolean']]);

        $result = $this->tickets->resolve($request->user(), $ticket, isset($data['note']) ? trim($data['note']) : null, (bool) ($data['send_email'] ?? false), (bool) ($data['send_popup'] ?? false));

        return $this->withFeedback(back(), $result, "Tiket {$ticket->number()} diselesaikan.");
    }

    public function feedbackCode(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->status === TicketStatus::Resolved, 404);
        $data = $request->validate(['send_email' => ['nullable', 'boolean'], 'send_popup' => ['nullable', 'boolean']]);

        $result = $this->tickets->requestFeedback($request->user(), $ticket, null, (bool) ($data['send_email'] ?? false), (bool) ($data['send_popup'] ?? false));

        return $this->withFeedback(back(), $result, 'Kode penilaian baru dibuat; kode lama tidak berlaku lagi.');
    }

    /** @param  array{code: string|null, url: string|null, notes: list<string>}  $result */
    private function withFeedback(RedirectResponse $redirect, array $result, string $message): RedirectResponse
    {
        // Kode asli hanya ditampilkan sekali (flash); di database hanya hash-nya.
        return $redirect->with('status', $message)->with('feedback_code', $result['code'])->with('feedback_url', $result['url'])->with('feedback_notes', $result['notes']);
    }
}
