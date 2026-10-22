<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Domain latar belakang sistem (update, telemetri, cek koneksi) yang tidak bermakna sebagai riwayat browsing. */
    private const DEFAULT_IGNORED = [
        'windowsupdate.com' => 'Windows Update',
        'update.microsoft.com' => 'Windows Update',
        'delivery.mp.microsoft.com' => 'Windows Update (pengiriman)',
        'events.data.microsoft.com' => 'Telemetri Windows',
        'vortex.data.microsoft.com' => 'Telemetri Windows',
        'msftconnecttest.com' => 'Cek koneksi Windows',
        'msftncsi.com' => 'Cek koneksi Windows',
        'smartscreen.microsoft.com' => 'Windows SmartScreen',
        'ocsp.digicert.com' => 'Pemeriksaan sertifikat',
        'ocsp.sectigo.com' => 'Pemeriksaan sertifikat',
        'ocsp.pki.goog' => 'Pemeriksaan sertifikat',
        'ctldl.windowsupdate.com' => 'Daftar sertifikat Windows',
        'gvt1.com' => 'Pembaruan Chrome',
        'gvt2.com' => 'Pembaruan Chrome',
        'edge.microsoft.com' => 'Pembaruan Edge',
    ];

    public function up(): void
    {
        // Ringkasan harian: satu baris per perangkat, hari, jenis, dan situs. IP hanya disimpan bila koneksi tidak punya nama domain.
        Schema::create('browsing_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('kind', 12);
            $table->string('host', 253)->default('');
            $table->string('ip', 45)->default('');
            $table->unsignedBigInteger('visits');
            $table->timestampTz('first_at');
            $table->timestampTz('last_at');

            $table->unique(['device_id', 'day', 'kind', 'host', 'ip']);
            $table->index('day');
            $table->index('host');
        });
        DB::statement("ALTER TABLE browsing_daily ADD CONSTRAINT browsing_daily_kind_check CHECK (kind IN ('url', 'connection'))");

        Schema::create('browsing_ignored_hosts', function (Blueprint $table): void {
            $table->id();
            $table->string('domain', 253)->unique();
            $table->string('note', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');
        });

        $now = now();
        DB::table('browsing_ignored_hosts')->insert(array_map(
            static fn (string $domain, string $note): array => ['domain' => $domain, 'note' => $note, 'created_at' => $now],
            array_keys(self::DEFAULT_IGNORED),
            array_values(self::DEFAULT_IGNORED),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('browsing_ignored_hosts');
        Schema::dropIfExists('browsing_daily');
    }
};
