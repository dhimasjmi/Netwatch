<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BlockRuleType;
use App\Models\BlockRule;
use App\Models\Device;
use App\Support\DevicePolicy;
use Illuminate\Database\Eloquent\Builder;

final class PolicyBuilder
{
    /** Gabungkan semua aturan dari policy aktif yang berlaku untuk perangkat: semua perangkat, grupnya, dan perangkat itu sendiri. */
    public function forDevice(Device $device): DevicePolicy
    {
        $rules = BlockRule::query()
            ->where('is_enabled', true)
            ->whereHas('policy', function (Builder $policy) use ($device): void {
                $policy->where('is_enabled', true)->where(function (Builder $scope) use ($device): void {
                    $scope->where(fn (Builder $all) => $all->whereNull('device_group_id')->whereNull('device_id'))
                        ->orWhere('device_id', $device->id)
                        ->when($device->device_group_id !== null, fn (Builder $q) => $q->orWhere('device_group_id', $device->device_group_id));
                });
            })
            ->get(['type', 'value']);

        return DevicePolicy::fromValues(
            $rules->where('type', BlockRuleType::Domain)->pluck('value'),
            $rules->where('type', BlockRuleType::Ip)->pluck('value'),
        );
    }
}
