<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DeviceGroup;
use App\Models\EnrollmentToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EnrollmentTokenWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_token_with_and_without_group(): void
    {
        $admin = User::query()->create(['name' => 'A', 'email' => 'a@example.test', 'password' => 'Password-12345', 'role' => UserRole::Admin, 'is_active' => true]);
        $group = DeviceGroup::query()->create(['name' => 'Lab 1']);

        $this->actingAs($admin)->post(route('tokens.store'), ['label' => 'Lab 1', 'device_group_id' => (string) $group->id, 'max_uses' => '5', 'ttl_hours' => '24'])
            ->assertRedirect(route('tokens.index'))->assertSessionHas('new_token');
        $this->post(route('tokens.store'), ['label' => 'Tanpa grup', 'device_group_id' => '', 'max_uses' => '1', 'ttl_hours' => '24'])
            ->assertRedirect(route('tokens.index'))->assertSessionHas('new_token');

        $this->assertSame($group->id, EnrollmentToken::query()->where('label', 'Lab 1')->value('device_group_id'));
        $this->assertNull(EnrollmentToken::query()->where('label', 'Tanpa grup')->value('device_group_id'));
        $this->get(route('tokens.index'))->assertOk()->assertSee('Lab 1');
    }
}
