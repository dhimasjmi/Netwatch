<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PgDumpCommand;
use PHPUnit\Framework\TestCase;

final class PgDumpCommandTest extends TestCase
{
    public function test_dump_arguments_exclude_only_valid_table_names_and_never_contain_the_password(): void
    {
        $db = ['host' => '127.0.0.1', 'port' => '5432', 'username' => 'netwatch', 'database' => 'netwatch', 'password' => 'rahasia-sekali'];

        $args = PgDumpCommand::dump('/usr/bin/pg_dump', $db, '/tmp/x.partial', ['device_heartbeats', 'a;drop table x', 'sessions']);

        $this->assertSame('/usr/bin/pg_dump', $args[0]);
        $this->assertContains('--format=custom', $args);
        $this->assertContains('--host=127.0.0.1', $args);
        $this->assertContains('--dbname=netwatch', $args);
        $this->assertContains('--exclude-table-data=public.device_heartbeats', $args);
        $this->assertContains('--exclude-table-data=public.sessions', $args);
        $this->assertCount(2, array_filter($args, fn (string $a): bool => str_starts_with($a, '--exclude-table-data=')));
        $this->assertContains('--file=/tmp/x.partial', $args);
        $this->assertStringNotContainsString('rahasia-sekali', implode(' ', $args));
        $this->assertSame(['PGPASSWORD' => 'rahasia-sekali'], PgDumpCommand::environment($db));
    }

    public function test_empty_connection_values_are_omitted(): void
    {
        $args = PgDumpCommand::dump('pg_dump', ['host' => '', 'port' => '', 'username' => '', 'database' => 'nw', 'password' => ''], 'o', []);

        $this->assertSame([], PgDumpCommand::environment(['password' => '']));
        $this->assertEmpty(array_filter($args, fn (string $a): bool => str_starts_with($a, '--host=') || str_starts_with($a, '--username=')));
        $this->assertContains('--dbname=nw', $args);
        $this->assertSame(['pg_restore', '--list', 'f.dump'], PgDumpCommand::listContents('pg_restore', 'f.dump'));
    }
}
