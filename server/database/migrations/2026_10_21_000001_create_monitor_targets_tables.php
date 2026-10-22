<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Perangkat jaringan yang dipantau dari server (switch, AP, printer, server) tanpa agent.
        Schema::create('monitor_targets', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('address', 45);
            $table->string('kind', 20)->default('other');
            $table->string('method', 5)->default('icmp');
            $table->unsignedSmallInteger('port')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->string('status', 10)->default('unknown');
            $table->float('latency_ms')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('status_since')->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_enabled', 'status']);
        });

        DB::statement("ALTER TABLE monitor_targets ADD CONSTRAINT monitor_targets_status_check CHECK (status IN ('unknown', 'online', 'warning', 'offline'))");
        DB::statement("ALTER TABLE monitor_targets ADD CONSTRAINT monitor_targets_method_check CHECK (method IN ('icmp', 'tcp'))");
        DB::statement("ALTER TABLE monitor_targets ADD CONSTRAINT monitor_targets_port_check CHECK ((method = 'tcp' AND port BETWEEN 1 AND 65535) OR (method = 'icmp' AND port IS NULL))");

        Schema::create('monitor_target_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_target_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('checked_at');
            $table->boolean('ok');
            $table->float('latency_ms')->nullable();

            $table->index(['monitor_target_id', 'checked_at']);
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_target_checks');
        Schema::dropIfExists('monitor_targets');
    }
};
