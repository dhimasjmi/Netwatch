<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hanya fitur yang diatur dari panel admin yang punya baris; tanpa baris, berlaku nilai bawaan dari .env/konfigurasi.
        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->string('key', 40)->primary();
            $table->boolean('enabled');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
