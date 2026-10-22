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
        Schema::create('device_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->decimal('cpu_percent', 5, 2);
            $table->integer('memory_used_mb');
            $table->integer('memory_total_mb');
            $table->integer('disk_used_gb');
            $table->integer('disk_total_gb');
            $table->bigInteger('uptime_seconds');
            $table->string('logged_in_user', 255)->nullable();
            $table->timestampTz('recorded_at')->index();
        });

        DB::statement('CREATE INDEX device_heartbeats_device_recorded_index ON device_heartbeats (device_id, recorded_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('device_heartbeats');
    }
};
