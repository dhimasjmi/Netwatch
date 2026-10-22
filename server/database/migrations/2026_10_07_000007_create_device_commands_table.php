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
        Schema::create('device_commands', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40);
            $table->jsonb('payload')->nullable();
            $table->string('status', 20)->default('pending');
            $table->jsonb('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE device_commands ADD CONSTRAINT device_commands_status_check CHECK (status IN ('pending', 'dispatched', 'succeeded', 'failed', 'expired'))");
        DB::statement('CREATE INDEX device_commands_device_created_index ON device_commands (device_id, created_at DESC)');
        // Antrean yang dibaca setiap heartbeat: indeks parsial menjaganya tetap kecil.
        DB::statement("CREATE INDEX device_commands_pending_index ON device_commands (device_id, created_at) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('device_commands');
    }
};
