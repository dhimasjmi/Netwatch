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
        Schema::create('block_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 10);
            $table->string('value', 255);
            $table->string('note', 255)->nullable();
            // Keduanya NULL = berlaku untuk semua perangkat; salah satu terisi = grup atau perangkat tertentu.
            $table->foreignId('device_group_id')->nullable()->constrained('device_groups')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['is_enabled', 'device_group_id', 'device_id']);
        });

        DB::statement("ALTER TABLE block_rules ADD CONSTRAINT block_rules_type_check CHECK (type IN ('domain', 'ip'))");
        DB::statement('ALTER TABLE block_rules ADD CONSTRAINT block_rules_scope_check CHECK (device_group_id IS NULL OR device_id IS NULL)');
        // Aturan identik pada cakupan yang sama tidak boleh ganda.
        DB::statement('CREATE UNIQUE INDEX block_rules_unique ON block_rules (type, value, COALESCE(device_group_id, 0), COALESCE(device_id, 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('block_rules');
    }
};
