<?php

declare(strict_types=1);

namespace NetWatch\Agent\Command;

/** Hasil eksekusi satu perintah, dikirim kembali ke server. */
final class CommandOutcome
{
    /** @param  array<string, mixed>  $result */
    private function __construct(
        public readonly bool $succeeded,
        public readonly array $result,
        public readonly ?string $error,
    ) {
    }

    /** @param  array<string, mixed>  $result */
    public static function success(array $result = []): self
    {
        return new self(true, $result, null);
    }

    public static function failure(string $error): self
    {
        return new self(false, [], mb_substr($error, 0, 2000));
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'status' => $this->succeeded ? 'succeeded' : 'failed',
            'result' => $this->result === [] ? null : $this->result,
            'error' => $this->error,
        ];
    }
}
