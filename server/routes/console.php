<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Dijalankan oleh cron: * * * * * php /path/artisan schedule:run
Schedule::command('netwatch:check-status')->everyMinute()->withoutOverlapping();
Schedule::command('netwatch:ping-offline')->everyMinute()->withoutOverlapping(5);
Schedule::command('netwatch:ping-targets')->everyMinute()->withoutOverlapping(5);
Schedule::command('netwatch:evaluate-alerts')->everyMinute()->withoutOverlapping(5);
Schedule::command('netwatch:send-alerts')->everyMinute()->withoutOverlapping(5);
Schedule::command('netwatch:expire-commands')->everyMinute()->withoutOverlapping();
Schedule::command('netwatch:prune')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('netwatch:rollup-bandwidth')->hourlyAt(5)->withoutOverlapping(120);
Schedule::command('netwatch:backup')->dailyAt('01:30')->withoutOverlapping(180);
