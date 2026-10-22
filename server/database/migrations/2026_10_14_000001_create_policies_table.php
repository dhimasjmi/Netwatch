<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aturan blokir kini dikelompokkan ke dalam "policy" bernama (mis. "Media sosial"). Cakupan (semua/grup/perangkat)
 * dan status aktif dipindahkan dari aturan ke policy-nya. Aturan lama otomatis dimasukkan ke policy menurut cakupannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('device_group_id')->nullable()->constrained('device_groups')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['is_enabled', 'device_group_id', 'device_id']);
        });
        DB::statement('ALTER TABLE policies ADD CONSTRAINT policies_scope_check CHECK (device_group_id IS NULL OR device_id IS NULL)');

        Schema::table('block_rules', function (Blueprint $table): void {
            $table->foreignId('policy_id')->nullable()->constrained('policies')->cascadeOnDelete();
        });

        $this->migrateExistingRules();

        DB::statement('DROP INDEX IF EXISTS block_rules_unique');
        DB::statement('ALTER TABLE block_rules DROP CONSTRAINT IF EXISTS block_rules_scope_check');
        Schema::table('block_rules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('device_group_id');
            $table->dropConstrainedForeignId('device_id');
        });
        DB::statement('ALTER TABLE block_rules ALTER COLUMN policy_id SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX block_rules_unique ON block_rules (policy_id, type, value)');
    }

    public function down(): void
    {
        // Pemisahan kembali ke cakupan per aturan tidak dibuat; rollback menghapus policy dan memulihkan kolom lama kosong.
        DB::statement('DROP INDEX IF EXISTS block_rules_unique');
        Schema::table('block_rules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('policy_id');
            $table->foreignId('device_group_id')->nullable()->constrained('device_groups')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->cascadeOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX block_rules_unique ON block_rules (type, value, COALESCE(device_group_id, 0), COALESCE(device_id, 0))');
        Schema::dropIfExists('policies');
    }

    private function migrateExistingRules(): void
    {
        $pairs = DB::table('block_rules')->select('device_group_id', 'device_id')->distinct()->get();

        foreach ($pairs as $pair) {
            $name = match (true) {
                $pair->device_id !== null => 'Perangkat: '.(DB::table('devices')->where('id', $pair->device_id)->value('hostname') ?? '?').' (#'.$pair->device_id.')',
                $pair->device_group_id !== null => 'Grup: '.(DB::table('device_groups')->where('id', $pair->device_group_id)->value('name') ?? '?'),
                default => 'Umum (semua perangkat)',
            };

            $policyId = DB::table('policies')->insertGetId([
                'name' => mb_substr($name, 0, 100),
                'description' => 'Dibuat otomatis dari aturan blokir lama.',
                'is_enabled' => true,
                'device_group_id' => $pair->device_group_id,
                'device_id' => $pair->device_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('block_rules')
                ->when($pair->device_group_id === null, fn ($q) => $q->whereNull('device_group_id'), fn ($q) => $q->where('device_group_id', $pair->device_group_id))
                ->when($pair->device_id === null, fn ($q) => $q->whereNull('device_id'), fn ($q) => $q->where('device_id', $pair->device_id))
                ->update(['policy_id' => $policyId]);
        }
    }
};
