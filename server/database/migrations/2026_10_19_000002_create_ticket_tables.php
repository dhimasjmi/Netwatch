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
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('requester_name', 100);
            $table->string('requester_email', 150)->nullable();
            $table->string('subject', 150);
            $table->text('description');
            $table->string('category', 20)->default('other');
            $table->string('priority', 10)->default('normal');
            $table->string('status', 20)->default('open');
            $table->string('source', 10)->default('web');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->ipAddress('ip_address')->nullable();
            $table->timestampsTz();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['status', 'created_at']);
            $table->index('assigned_to');
            $table->index('device_id');
        });
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('open', 'in_progress', 'resolved'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_priority_check CHECK (priority IN ('low', 'normal', 'high'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_category_check CHECK (category IN ('network', 'hardware', 'software', 'account', 'other'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_source_check CHECK (source IN ('web', 'admin'))");

        Schema::create('ticket_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestampTz('created_at');

            $table->index(['ticket_id', 'created_at']);
        });

        // Satu permintaan penilaian per tiket. Kode disimpan sebagai hash; kode asli hanya dikirim ke pemohon.
        Schema::create('ticket_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->string('sent_via', 10)->nullable();
            $table->unsignedSmallInteger('rating')->nullable();
            $table->text('comment')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('created_at');

            $table->index('submitted_at');
        });
        DB::statement('ALTER TABLE ticket_feedback ADD CONSTRAINT ticket_feedback_rating_check CHECK (rating IS NULL OR rating BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_feedback');
        Schema::dropIfExists('ticket_comments');
        Schema::dropIfExists('tickets');
    }
};
