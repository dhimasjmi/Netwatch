<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\TicketCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\PublicTicketRequest;
use App\Models\Device;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Form "Buat Tiket" yang dibuka pengguna dari shortcut di PC-nya (tanpa login). */
final class PublicTicketController extends Controller
{
    public function create(Request $request): View
    {
        return view('tickets.public-create', [
            'categories' => TicketCategory::cases(),
            'pc' => mb_substr((string) $request->query('pc', ''), 0, 64),
        ]);
    }

    public function store(PublicTicketRequest $request, TicketService $tickets): RedirectResponse
    {
        // Bot mengisi kolom jebakan: berpura-pura berhasil tanpa menyimpan apa pun.
        if ($request->isBot()) {
            return redirect()->route('tickets.public.thanks')->with('ticket_no', 'T-0000');
        }

        $ticket = $tickets->create($request->ticketData(), $this->deviceFor((string) $request->input('pc')), 'web', null, $request->ip());

        return redirect()->route('tickets.public.thanks')->with('ticket_no', $ticket->number());
    }

    public function thanks(Request $request): View|RedirectResponse
    {
        $number = $request->session()->get('ticket_no');

        return $number === null ? redirect()->route('tickets.public.create') : view('tickets.public-thanks', ['number' => $number]);
    }

    /** Nama PC dari shortcut tidak dipercaya; dipakai hanya bila cocok dengan tepat satu perangkat aktif, demi kemudahan teknisi. */
    private function deviceFor(string $hostname): ?Device
    {
        $hostname = trim($hostname);
        if ($hostname === '') {
            return null;
        }

        $matches = Device::query()->active()->whereRaw('lower(hostname) = ?', [mb_strtolower($hostname)])->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
