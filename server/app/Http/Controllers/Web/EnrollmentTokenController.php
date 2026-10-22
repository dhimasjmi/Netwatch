<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\StoreEnrollmentTokenRequest;
use App\Models\DeviceGroup;
use App\Models\EnrollmentToken;
use App\Services\EnrollmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class EnrollmentTokenController extends Controller
{
    public function index(): View
    {
        return view('tokens.index', [
            'tokens' => EnrollmentToken::query()->with(['group', 'creator'])->latest()->limit(50)->get(),
            'groups' => DeviceGroup::query()->orderBy('name')->get(),
            'defaultTtlHours' => (int) config('netwatch.enrollment_token_ttl_hours'),
        ]);
    }

    public function store(StoreEnrollmentTokenRequest $request, EnrollmentService $enrollment): RedirectResponse
    {
        $data = $request->validated();

        $issued = $enrollment->createToken(
            $request->user(),
            (string) $data['label'],
            // Hasil validasi berupa string; strict_types menolaknya untuk parameter ?int.
            isset($data['device_group_id']) ? (int) $data['device_group_id'] : null,
            (int) $data['max_uses'],
            (int) $data['ttl_hours'],
        );

        // Token polos ditampilkan sekali lewat flash; sesi dienkripsi (SESSION_ENCRYPT=true).
        return redirect()->route('tokens.index')->with('new_token', $issued['plain']);
    }

    public function destroy(Request $request, EnrollmentToken $token, EnrollmentService $enrollment): RedirectResponse
    {
        $enrollment->revokeToken($token, $request->user());

        return back()->with('status', 'Token dicabut.');
    }
}
