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
        Schema::create('device_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10);
            $table->timestampTz('started_at');
            // NULL berarti interval masih berjalan (status saat ini).
            $table->timestampTz('ended_at')->nullable();
            $table->string('reason', 30);

            $table->index(['device_id', 'started_at']);
        });

        DB::statement("ALTER TABLE device_status_history ADD CONSTRAINT device_status_history_status_check CHECK (status IN ('online', 'offline'))");
        DB::statement('ALTER TABLE device_status_history ADD CONSTRAINT device_status_history_range_check CHECK (ended_at IS NULL OR ended_at >= started_at)');
        // Hanya boleh ada satu interval terbuka per perangkat; menjaga data tetap konsisten saat dua proses berbalapan.
        DB::statement('CREATE UNIQUE INDEX device_status_history_open_unique ON device_status_history (device_id) WHERE ended_at IS NULL');

        // Perangkat yang sudah ada sebelum fitur ini dianggap online sejak terakhir terlihat;
        // perintah netwatch:check-status akan menutupnya bila sebenarnya sudah offline.
        DB::statement(<<<'SQL'
            INSERT INTO device_status_history (device_id, status, started_at, ended_at, reason)
            SELECT id, 'online', COALESCE(last_seen_at, enrolled_at), NULL, 'backfill'
            FROM devices
            WHERE revoked_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('device_status_history');
    }
};
