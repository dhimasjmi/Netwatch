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
        Schema::create('browsing_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('visited_at');
            $table->string('kind', 12);            // url = riwayat browser, connection = koneksi keluar yang terlihat
            $table->string('host', 253)->nullable();
            $table->string('ip', 45)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('url', 1000)->nullable(); // tanpa query/fragmen; hanya terisi bila NETWATCH_BROWSING_DETAIL=path
            $table->string('process', 100)->nullable();
            $table->string('browser', 12)->nullable();
            $table->char('dedupe_hash', 40);

            $table->unique(['device_id', 'dedupe_hash']); // kiriman ulang batch yang sama tidak menggandakan data
            $table->index(['device_id', 'visited_at']);
            $table->index('visited_at');
            $table->index('host');
        });

        DB::statement("ALTER TABLE browsing_visits ADD CONSTRAINT browsing_visits_kind_check CHECK (kind IN ('url', 'connection'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('browsing_visits');
    }
};
