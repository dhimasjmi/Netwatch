<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/** Rentang waktu laporan dari input pengguna (preset atau tanggal), dibatasi dan tidak melewati sekarang. */
final class DateRange
{
    public const DEFAULT_PRESET = '7d';
    private const PRESET_HOURS = ['24h' => 24, '7d' => 168, '30d' => 720];

    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        private readonly string $timezone,
    ) {
    }

    public static function fromInput(?string $from, ?string $to, ?string $preset, string $timezone, int $maxDays = 366): self
    {
        $now = CarbonImmutable::now();

        if ($preset !== null && isset(self::PRESET_HOURS[$preset])) {
            return self::bounded($now->subHours(self::PRESET_HOURS[$preset]), $now, $timezone, $maxDays);
        }

        if ($from !== null && $from !== '' && $to !== null && $to !== '') {
            try {
                $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone)->startOfDay();
                $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone)->endOfDay();

                if ($start->lte($end)) {
                    return self::bounded($start, $end->min($now), $timezone, $maxDays);
                }
            } catch (Throwable) {
                // Input tidak valid: jatuh ke rentang bawaan di bawah.
            }
        }

        return self::bounded($now->subHours(self::PRESET_HOURS[self::DEFAULT_PRESET]), $now, $timezone, $maxDays);
    }

    public function seconds(): int
    {
        return max(0, $this->to->getTimestamp() - $this->from->getTimestamp());
    }

    public function inputFrom(): string
    {
        return $this->from->setTimezone($this->timezone)->format('Y-m-d');
    }

    public function inputTo(): string
    {
        return $this->to->setTimezone($this->timezone)->format('Y-m-d');
    }

    public function label(): string
    {
        return $this->from->setTimezone($this->timezone)->format('d M Y H:i').' – '.$this->to->setTimezone($this->timezone)->format('d M Y H:i');
    }

    private static function bounded(CarbonImmutable $from, CarbonImmutable $to, string $timezone, int $maxDays): self
    {
        if ($to->lt($from)) {
            $to = $from;
        }

        if ($from->diffInDays($to, true) > $maxDays) {
            $from = $to->subDays($maxDays);
        }

        return new self($from, $to, $timezone);
    }
}
