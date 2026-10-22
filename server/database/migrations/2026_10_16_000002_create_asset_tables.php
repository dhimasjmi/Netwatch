<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_hardware', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('manufacturer', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('serial', 100)->nullable();
            $table->string('cpu', 150)->nullable();
            $table->unsignedSmallInteger('cpu_cores')->nullable();
            $table->unsignedSmallInteger('cpu_threads')->nullable();
            $table->unsignedBigInteger('memory_mb')->nullable();
            $table->string('os', 100)->nullable();
            $table->string('os_version', 50)->nullable();
            $table->string('os_build', 50)->nullable();
            $table->jsonb('disks')->nullable();
            $table->timestampTz('updated_at');
            $table->index('serial');
        });

        Schema::create('device_software', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('name', 300);
            $table->string('version', 100)->default(''); // kosong = tidak diketahui; bukan null agar kunci unik bekerja
            $table->string('publisher', 200)->nullable();
            $table->date('installed_on')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');

            $table->unique(['device_id', 'name', 'version']);
            $table->index('name');
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->timestampTz('assets_reported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('assets_reported_at');
        });
        Schema::dropIfExists('device_software');
        Schema::dropIfExists('device_hardware');
    }
};
