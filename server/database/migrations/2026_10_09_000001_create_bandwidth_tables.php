<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_interfaces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedBigInteger('speed_bps')->nullable();
            // Counter kumulatif terakhir, dipakai menghitung selisih pada heartbeat berikutnya.
            $table->unsignedBigInteger('last_rx_bytes');
            $table->unsignedBigInteger('last_tx_bytes');
            $table->timestampTz('last_sample_at');
            $table->timestampsTz();

            $table->unique(['device_id', 'name']);
        });

        Schema::create('interface_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_interface_id')->constrained('device_interfaces')->cascadeOnDelete();
            $table->timestampTz('sampled_at');
            // Selisih byte pada interval ini (bukan counter kumulatif).
            $table->unsignedBigInteger('rx_bytes');
            $table->unsignedBigInteger('tx_bytes');
            $table->unsignedInteger('interval_seconds');

            $table->index(['device_interface_id', 'sampled_at']);
            $table->index('sampled_at'); // untuk pembersihan retensi
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interface_samples');
        Schema::dropIfExists('device_interfaces');
    }
};
