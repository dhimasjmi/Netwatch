<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\CommandType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class StoreDeviceCommandRequest extends FormRequest
{
    private const POWER_DELAY_SECONDS = 60;

    public function authorize(): bool
    {
        $type = $this->commandType();

        if ($type?->requiresPowerControl()) {
            return Gate::allows('power-control');
        }

        return Gate::allows('run-commands');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['type' => ['required', Rule::enum(CommandType::class)]];

        return match ($this->commandType()) {
            CommandType::TerminateProcess => $rules + [
                'pid' => ['required', 'integer', 'min:1', 'max:4194304'],
                'process_name' => ['required', 'string', 'max:255'],
            ],
            CommandType::SendMessage => $rules + [
                'message' => ['required', 'string', 'max:500'],
            ],
            default => $rules,
        };
    }

    public function commandType(): ?CommandType
    {
        return CommandType::tryFrom((string) $this->input('type'));
    }

    /**
     * Payload yang dikirim ke agent; hanya field yang relevan untuk tipe perintah.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return match ($this->commandType()) {
            CommandType::TerminateProcess => [
                'pid' => (int) $this->validated('pid'),
                'process_name' => (string) $this->validated('process_name'),
            ],
            CommandType::SendMessage => ['message' => (string) $this->validated('message')],
            CommandType::RestartComputer, CommandType::ShutdownComputer => ['delay_seconds' => self::POWER_DELAY_SECONDS],
            default => [],
        };
    }
}
