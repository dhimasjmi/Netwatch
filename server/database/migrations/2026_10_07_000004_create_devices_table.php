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
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('device_group_id')->nullable()->constrained('device_groups')->nullOnDelete();
            $table->foreignId('enrollment_token_id')->nullable()->constrained('enrollment_tokens')->nullOnDelete();
            $table->string('hostname', 255)->index();
            $table->string('display_name', 255)->nullable();
            $table->string('os_family', 20);
            $table->string('os_version', 100)->nullable();
            $table->string('agent_version', 20);
            $table->ipAddress('ip_address')->nullable();   // tipe native `inet` di PostgreSQL
            $table->macAddress('mac_address')->nullable(); // tipe native `macaddr` di PostgreSQL
            $table->char('token_hash', 64)->unique();
            $table->jsonb('latest_metrics')->nullable();
            $table->timestampTz('last_seen_at')->nullable()->index();
            $table->timestampTz('enrolled_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE devices ADD CONSTRAINT devices_os_family_check CHECK (os_family IN ('windows', 'linux'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
