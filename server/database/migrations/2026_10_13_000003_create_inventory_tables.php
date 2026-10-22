<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BASE = "'availability', 'connectivity', 'addressing', 'dns', 'performance', 'command', 'enrollment', 'security', 'policy'";

    public function up(): void
    {
        Schema::create('device_ports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('protocol', 3);
            $table->string('address', 45);
            $table->unsignedInteger('port');
            $table->string('process', 100)->nullable();
            $table->boolean('exposed');                 // false = hanya loopback (tidak terjangkau dari jaringan)
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');

            $table->unique(['device_id', 'protocol', 'address', 'port']);
            $table->index(['port', 'protocol']);
        });
        DB::statement("ALTER TABLE device_ports ADD CONSTRAINT device_ports_protocol_check CHECK (protocol IN ('tcp', 'udp'))");

        Schema::create('device_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('name', 256);
            $table->string('display_name', 256)->nullable();
            $table->string('state', 20);
            $table->string('start_mode', 20)->nullable();
            $table->timestampTz('updated_at');

            $table->unique(['device_id', 'name']);
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->timestampTz('inventory_reported_at')->nullable();
        });

        DB::statement('ALTER TABLE events DROP CONSTRAINT events_category_check');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_category_check CHECK (category IN ('.self::BASE.", 'system'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM events WHERE category = 'system'");
        DB::statement('ALTER TABLE events DROP CONSTRAINT events_category_check');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_category_check CHECK (category IN ('.self::BASE.'))');

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('inventory_reported_at');
        });
        Schema::dropIfExists('device_services');
        Schema::dropIfExists('device_ports');
    }
};
