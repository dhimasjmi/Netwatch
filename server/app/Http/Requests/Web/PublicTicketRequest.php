<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\TicketCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Form tiket publik (tanpa login). Kolom "website" adalah jebakan untuk bot: manusia tidak melihatnya. */
final class PublicTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'requester_name' => ['required', 'string', 'max:100'],
            'requester_email' => ['nullable', 'email:rfc', 'max:150'],
            'subject' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'category' => ['required', Rule::enum(TicketCategory::class)],
            'pc' => ['nullable', 'string', 'max:64'],
            'website' => ['nullable', 'string', 'max:200'], // jebakan: diperiksa lewat isBot(), bukan ditolak, agar bot tidak tahu ketahuan
        ];
    }

    public function isBot(): bool
    {
        return filled($this->input('website'));
    }

    /** @return array{requester_name: string, requester_email: ?string, subject: string, description: string, category: string} */
    public function ticketData(): array
    {
        return [
            'requester_name' => trim((string) $this->validated('requester_name')),
            'requester_email' => $this->validated('requester_email') !== null ? strtolower(trim((string) $this->validated('requester_email'))) : null,
            'subject' => trim((string) $this->validated('subject')),
            'description' => trim((string) $this->validated('description')),
            'category' => (string) $this->validated('category'),
        ];
    }
}
