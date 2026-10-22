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
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            // Event tetap berguna walau perangkat/pengguna sudah dihapus, jadi relasi dilepas, bukan ikut terhapus.
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 30);
            $table->string('severity', 10);
            $table->string('code', 60);
            $table->string('message', 500);
            $table->jsonb('details')->nullable();
            $table->timestampTz('occurred_at');

            $table->index('occurred_at');
            $table->index(['device_id', 'occurred_at']);
            $table->index(['category', 'occurred_at']);
            $table->index(['severity', 'occurred_at']);
        });

        DB::statement("ALTER TABLE events ADD CONSTRAINT events_severity_check CHECK (severity IN ('info', 'warning', 'critical'))");
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_category_check CHECK (category IN ('availability', 'connectivity', 'addressing', 'dns', 'performance', 'command', 'enrollment', 'security'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
