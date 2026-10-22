<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\EventCategory;
use App\Enums\EventSeverity;
use App\Support\EventCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EventCatalogTest extends TestCase
{
    public function test_every_code_has_a_valid_definition(): void
    {
        foreach (EventCatalog::codes() as $code) {
            $definition = EventCatalog::definition($code);

            $this->assertInstanceOf(EventCategory::class, $definition['category']);
            $this->assertInstanceOf(EventSeverity::class, $definition['severity']);
        }
    }

    public function test_offline_event_is_a_warning_with_a_troubleshooting_hint(): void
    {
        $definition = EventCatalog::definition('device.offline');

        $this->assertSame(EventSeverity::Warning, $definition['severity']);
        $this->assertNotEmpty($definition['hint']);
    }

    public function test_failed_logins_are_security_events(): void
    {
        $this->assertSame(EventCategory::Security, EventCatalog::definition('auth.login_failed')['category']);
    }

    public function test_unknown_code_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EventCatalog::definition('tidak.ada');
    }

    public function test_category_values_match_the_database_check_constraint(): void
    {
        $migration = (string) file_get_contents(__DIR__.'/../../database/migrations/2026_10_12_000001_create_events_table.php');
        $policyMigration = (string) file_get_contents(__DIR__.'/../../database/migrations/2026_10_12_000004_add_policy_event_category.php');

        $inventoryMigration = (string) file_get_contents(__DIR__.'/../../database/migrations/2026_10_13_000003_create_inventory_tables.php');

        foreach (EventCategory::cases() as $case) {
            $this->assertStringContainsString("'{$case->value}'", $migration.$policyMigration.$inventoryMigration);
        }
        foreach (EventSeverity::cases() as $case) {
            $this->assertStringContainsString("'{$case->value}'", $migration);
        }
    }
}
