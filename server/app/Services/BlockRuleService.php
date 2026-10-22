<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BlockRuleType;
use App\Models\BlockRule;
use App\Models\Policy;
use App\Models\User;
use App\Services\Exceptions\BlockRuleException;
use App\Support\PolicyEntries;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class BlockRuleService
{
    public function __construct(private readonly BlockRuleGuard $guard, private readonly AuditLogger $audit)
    {
    }

    /**
     * Tambah beberapa target sekaligus ke satu policy. Target yang valid tetap disimpan walau sebagian lain ditolak.
     *
     * @return array{added: list<string>, errors: array<string, string>} errors: target => alasan
     */
    public function addEntries(User $actor, Policy $policy, string $text, ?string $note): array
    {
        $result = ['added' => [], 'errors' => []];

        foreach (PolicyEntries::parse($text) as $entry) {
            try {
                $rule = $this->create($actor, $policy, PolicyEntries::typeOf($entry), $entry, $note);
                $result['added'][] = $rule->value;
            } catch (BlockRuleException $e) {
                $result['errors'][$entry] = $e->getMessage();
            }
        }

        return $result;
    }

    /** @throws BlockRuleException */
    public function create(User $actor, Policy $policy, BlockRuleType $type, string $input, ?string $note = null): BlockRule
    {
        $value = $this->guard->normalize($type, $input);

        if (BlockRule::query()->where('type', $type->value)->count() >= (int) config('netwatch.max_block_rules_per_type')) {
            throw new BlockRuleException('Jumlah aturan untuk jenis ini sudah mencapai batas. Hapus aturan yang tidak dipakai.');
        }

        // Cek di muka agar kasus biasa tidak memicu galat database; PostgreSQL membatalkan seluruh transaksi
        // yang sedang berjalan bila terjadi pelanggaran unique.
        if (BlockRule::query()->where('policy_id', $policy->id)->where('type', $type->value)->where('value', $value)->exists()) {
            throw new BlockRuleException('Sudah ada di policy ini.');
        }

        try {
            // Savepoint: bila dua admin menambah target yang sama bersamaan, hanya INSERT ini yang dibatalkan.
            $rule = DB::transaction(fn (): BlockRule => BlockRule::query()->create([
                'policy_id' => $policy->id,
                'type' => $type,
                'value' => $value,
                'note' => $note !== null && $note !== '' ? $note : null,
                'is_enabled' => true,
                'created_by' => $actor->id,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new BlockRuleException('Sudah ada di policy ini.');
        }

        $this->audit->record('block_rule.created', $actor, null, ['rule_id' => $rule->id, 'policy' => $policy->name, 'type' => $type->value, 'value' => $value]);

        return $rule;
    }

    public function delete(User $actor, BlockRule $rule): void
    {
        $policy = $rule->policy;
        $rule->delete();

        $this->audit->record('block_rule.deleted', $actor, null, ['rule_id' => $rule->id, 'policy' => $policy?->name, 'type' => $rule->type->value, 'value' => $rule->value]);
    }
}
