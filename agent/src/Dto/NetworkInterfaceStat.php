<?php

declare(strict_types=1);

namespace NetWatch\Agent\Dto;

/** Counter byte kumulatif satu interface; selisih antar-heartbeat dihitung di server. */
final class NetworkInterfaceStat
{
    public function __construct(
        public readonly string $name,
        public readonly int $rxBytes,
        public readonly int $txBytes,
        public readonly ?int $speedBps = null,
    ) {
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'name' => mb_substr($this->name, 0, 100),
            'rx_bytes' => max(0, $this->rxBytes),
            'tx_bytes' => max(0, $this->txBytes),
            'speed_bps' => $this->speedBps !== null && $this->speedBps > 0 ? $this->speedBps : null,
        ];
    }
}
