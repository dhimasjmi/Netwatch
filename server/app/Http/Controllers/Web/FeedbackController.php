<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\FeedbackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Halaman penilaian untuk pemohon (tanpa login), diakses lewat kode sekali pakai. */
final class FeedbackController extends Controller
{
    public function lookup(Request $request, FeedbackService $feedback): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:30']]);
        $found = $feedback->find($data['code']);

        return $found !== null && $found->isUsable()
            ? redirect()->route('feedback.show', ['code' => strtoupper((string) preg_replace('/[\s-]+/', '', $data['code']))])
            : redirect()->route('tickets.public.create')->withErrors(['code' => 'Kode penilaian tidak valid, sudah dipakai, atau sudah kedaluwarsa.']);
    }

    public function show(string $code, FeedbackService $feedback): View
    {
        $found = $feedback->find($code);

        return view('tickets.feedback', ['feedback' => $found !== null && $found->isUsable() ? $found : null, 'code' => $code, 'done' => false]);
    }

    public function store(Request $request, string $code, FeedbackService $feedback): View
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $found = $feedback->find($code);

        if ($found === null || ! $feedback->submit($found, (int) $data['rating'], $data['comment'] ?? null)) {
            return view('tickets.feedback', ['feedback' => null, 'code' => $code, 'done' => false]);
        }

        return view('tickets.feedback', ['feedback' => $found, 'code' => $code, 'done' => true]);
    }
}
