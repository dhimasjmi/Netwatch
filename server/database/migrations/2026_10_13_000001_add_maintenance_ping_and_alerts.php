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
        Schema::table('devices', function (Blueprint $table): void {
            $table->timestampTz('maintenance_until')->nullable();
            $table->string('maintenance_note', 255)->nullable();
            // Hasil ping terakhir dari server (hanya dicek untuk perangkat yang offline).
            $table->boolean('ping_ok')->nullable();
            $table->float('ping_ms')->nullable();
            $table->timestampTz('ping_checked_at')->nullable();
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->timestampTz('notified_at')->nullable();       // sudah diproses pengirim notifikasi (terkirim atau sengaja dilewati)
            $table->boolean('notification_sent')->default(false); // benar-benar terkirim ke minimal satu saluran
        });

        // Event lama dianggap sudah diproses supaya notifikasi tidak membanjir saat fitur pertama kali aktif.
        DB::statement('UPDATE events SET notified_at = NOW()');
        DB::statement('CREATE INDEX events_pending_notification_idx ON events (id) WHERE notified_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS events_pending_notification_idx');

        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn(['notified_at', 'notification_sent']);
        });
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['maintenance_until', 'maintenance_note', 'ping_ok', 'ping_ms', 'ping_checked_at']);
        });
    }
};
