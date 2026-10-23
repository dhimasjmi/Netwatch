<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FeatureFlags;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Panel admin untuk menghidupkan/mematikan fitur. */
final class FeatureController extends Controller
{
    public function __construct(private readonly FeatureFlags $features)
    {
    }

    public function index(): View
    {
        return view('features.index', ['features' => Feature::cases(), 'flags' => $this->features, 'records' => $this->features->records(), 'users' => User::query()->pluck('name', 'id')]);
    }

    public function update(Request $request, string $feature): RedirectResponse
    {
        $enum = Feature::tryFrom($feature);
        abort_if($enum === null, 404);

        $data = $request->validate(['action' => ['required', Rule::in(['enable', 'disable', 'reset'])]]);

        match ($data['action']) {
            'enable' => $this->features->set($enum, true, $request->user()),
            'disable' => $this->features->set($enum, false, $request->user()),
            'reset' => $this->features->reset($enum, $request->user()),
        };

        $state = $this->features->enabled($enum) ? 'aktif' : 'nonaktif';

        return redirect()->route('features.index')->with('status', "{$enum->label()} sekarang {$state}.");
    }
}
