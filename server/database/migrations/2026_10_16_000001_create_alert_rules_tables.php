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
        Schema::create('alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('metric', 10);
            $table->unsignedSmallInteger('threshold');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('severity', 10);
            $table->foreignId('device_group_id')->nullable()->constrained('device_groups')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_enabled', 'device_group_id', 'device_id']);
        });

        DB::statement("ALTER TABLE alert_rules ADD CONSTRAINT alert_rules_metric_check CHECK (metric IN ('cpu', 'memory', 'disk'))");
        DB::statement("ALTER TABLE alert_rules ADD CONSTRAINT alert_rules_severity_check CHECK (severity IN ('warning', 'critical'))");
        DB::statement('ALTER TABLE alert_rules ADD CONSTRAINT alert_rules_threshold_check CHECK (threshold BETWEEN 1 AND 100)');
        DB::statement('ALTER TABLE alert_rules ADD CONSTRAINT alert_rules_duration_check CHECK (duration_minutes BETWEEN 1 AND 1440)');
        DB::statement('ALTER TABLE alert_rules ADD CONSTRAINT alert_rules_scope_check CHECK (device_group_id IS NULL OR device_id IS NULL)');

        // Keadaan per aturan dan perangkat: kapan mulai melewati ambang dan apakah alert sedang menyala.
        Schema::create('alert_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('firing_since')->nullable();
            $table->decimal('last_value', 5, 1)->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->unique(['alert_rule_id', 'device_id']);
            $table->index('firing_since');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_states');
        Schema::dropIfExists('alert_rules');
    }
};
