<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            // Hash kebijakan yang terakhir dilaporkan agent sebagai sudah diterapkan, dan galat penerapan terakhir.
            $table->string('policy_hash', 64)->nullable();
            $table->string('policy_error', 500)->nullable();
            $table->timestampTz('policy_reported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['policy_hash', 'policy_error', 'policy_reported_at']);
        });
    }
};
