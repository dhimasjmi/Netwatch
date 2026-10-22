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
        Schema::create('process_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('captured_at');
            $table->integer('process_count');
            $table->jsonb('processes');
        });

        DB::statement('CREATE INDEX process_snapshots_device_captured_index ON process_snapshots (device_id, captured_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('process_snapshots');
    }
};
