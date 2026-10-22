<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Admin/operator membuatkan tiket atas nama pengguna (mis. dari telepon). */
final class AdminTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Dibatasi di route lewat can:handle-tickets.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'requester_name' => ['required', 'string', 'max:100'],
            'requester_email' => ['nullable', 'email:rfc', 'max:150'],
            'subject' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'min:5', 'max:2000'],
            'category' => ['required', Rule::enum(TicketCategory::class)],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'device' => ['nullable', 'string', 'max:64', 'exists:devices,public_id'],
        ];
    }

    /** @return array<string, string|null> */
    public function ticketData(): array
    {
        return [
            'requester_name' => trim((string) $this->validated('requester_name')),
            'requester_email' => $this->validated('requester_email') !== null ? strtolower(trim((string) $this->validated('requester_email'))) : null,
            'subject' => trim((string) $this->validated('subject')),
            'description' => trim((string) $this->validated('description')),
            'category' => (string) $this->validated('category'),
            'priority' => (string) $this->validated('priority'),
        ];
    }
}
