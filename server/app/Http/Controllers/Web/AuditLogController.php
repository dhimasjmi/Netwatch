<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AuditLogController extends Controller
{
    private const PAGE_SIZE = 50;

    public function index(Request $request): View
    {
        $action = trim($request->string('action')->toString());

        return view('audit.index', [
            'logs' => AuditLog::query()
                ->with(['user', 'device'])
                ->when($action !== '', fn ($q) => $q->where('action', $action))
                ->latest('created_at')
                ->paginate(self::PAGE_SIZE)
                ->withQueryString(),
            'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
            'selectedAction' => $action,
        ]);
    }
}
