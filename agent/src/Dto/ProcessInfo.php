<?php

declare(strict_types=1);

namespace NetWatch\Agent\Dto;

final class ProcessInfo
{
    public function __construct(
        public readonly int $pid,
        public readonly string $name,
        public readonly ?float $cpuSeconds,
        public readonly ?float $memoryMb,
        public readonly ?string $user,
    ) {
    }

    /** @return array<string, int|float|string|null> */
    public function toArray(): array
    {
        return [
            'pid' => $this->pid,
            'name' => mb_substr($this->name, 0, 255),
            'cpu_seconds' => $this->cpuSeconds,
            'memory_mb' => $this->memoryMb,
            'user' => $this->user === null ? null : mb_substr($this->user, 0, 255),
        ];
    }
}
