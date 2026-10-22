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
        Schema::create('enrollment_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 100);
            $table->char('token_hash', 64)->unique();
            $table->foreignId('device_group_id')->nullable()->constrained('device_groups')->nullOnDelete();
            $table->smallInteger('max_uses')->default(1);
            $table->smallInteger('used_count')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE enrollment_tokens ADD CONSTRAINT enrollment_tokens_usage_check CHECK (used_count >= 0 AND max_uses >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_tokens');
    }
};
