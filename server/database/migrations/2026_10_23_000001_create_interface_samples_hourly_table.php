<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ringkasan bandwidth per jam. Sampel mentah (tiap 30 detik) hanya disimpan beberapa hari, sehingga tabel
        // jangka panjang tetap kecil (1 baris per interface per jam, bukan 120).
        Schema::create('interface_samples_hourly', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_interface_id')->constrained('device_interfaces')->cascadeOnDelete();
            $table->timestampTz('hour');
            $table->unsignedBigInteger('rx_bytes');
            $table->unsignedBigInteger('tx_bytes');
            $table->unsignedBigInteger('interval_seconds');
            $table->unsignedInteger('samples');

            $table->unique(['device_interface_id', 'hour']);
            $table->index('hour');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interface_samples_hourly');
    }
};
