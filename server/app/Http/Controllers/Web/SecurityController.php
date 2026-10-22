<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\SecurityChecker;
use Illuminate\View\View;

final class SecurityController extends Controller
{
    public function __invoke(SecurityChecker $checker): View
    {
        $checks = $checker->run();

        return view('security.index', ['checks' => $checks, 'overall' => SecurityChecker::worst($checks)]);
    }
}
