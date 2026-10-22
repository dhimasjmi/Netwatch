<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\BrowsingIgnoredHost;
use App\Services\BrowsingIgnoreList;
use App\Support\HostMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Mengelola domain yang tidak disimpan sebagai riwayat browsing. */
final class BrowsingIgnoredHostController extends Controller
{
    public function store(Request $request, BrowsingIgnoreList $list): RedirectResponse
    {
        $data = $request->validate(['domain' => ['required', 'string', 'max:253'], 'note' => ['nullable', 'string', 'max:100']]);
        $domain = HostMatcher::normalizeDomain($data['domain']);

        if ($domain === null) {
            throw ValidationException::withMessages(['domain' => 'Masukkan nama domain yang valid, misalnya windowsupdate.com.']);
        }

        $list->add($request->user(), $domain, $data['note'] ?? null);

        return redirect()->route('browsing.index')->with('status', "Domain {$domain} (dan subdomainnya) tidak akan disimpan lagi.");
    }

    public function destroy(Request $request, BrowsingIgnoredHost $ignored, BrowsingIgnoreList $list): RedirectResponse
    {
        $list->remove($request->user(), $ignored);

        return redirect()->route('browsing.index')->with('status', "Domain {$ignored->domain} dihapus dari daftar diabaikan.");
    }
}
