<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\DatabaseHealthService;
use Illuminate\View\View;

/** Halaman admin "Database & Backup" (hanya baca). */
final class DatabaseHealthController extends Controller
{
    public function __invoke(DatabaseHealthService $health): View
    {
        return view('database.index', ['report' => $health->report()]);
    }
}
