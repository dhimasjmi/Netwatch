<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CommandType;
use App\Enums\UserRole;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketFeedback;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Support\FeedbackCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TicketFlowTest extends TestCase
{
    use RefreshDatabase;

    // ---------- form publik ----------

    public function test_public_form_creates_ticket_linked_to_matching_device_and_raises_event(): void
    {
        $device = $this->device('PC-TIKET-01');

        $this->get('/tickets/new?pc=PC-TIKET-01')->assertOk()->assertSee('Buat tiket bantuan IT');
        $this->post('/tickets/new', $this->form(['pc' => 'pc-tiket-01']))->assertRedirect(route('tickets.public.thanks'));

        $ticket = Ticket::query()->sole();
        $this->assertSame($device->id, $ticket->device_id);
        $this->assertSame('web', $ticket->source);
        $this->assertSame('open', $ticket->status->value);
        $this->assertSame('budi@example.test', $ticket->requester_email);
        $this->get(route('tickets.public.thanks'))->assertOk()->assertSee($ticket->number());
        $this->assertSame(1, Event::query()->where('code', 'ticket.created')->count());
    }

    public function test_unknown_or_ambiguous_pc_name_is_not_linked(): void
    {
        $this->device('PC-A');
        $this->post('/tickets/new', $this->form(['pc' => 'tidak-ada']))->assertRedirect();

        $this->assertNull(Ticket::query()->sole()->device_id);
    }

    public function test_honeypot_and_validation(): void
    {
        $this->post('/tickets/new', $this->form(['website' => 'http://spam.test']))->assertRedirect(route('tickets.public.thanks'));
        $this->assertSame(0, Ticket::query()->count());

        $this->from('/tickets/new')->post('/tickets/new', $this->form(['description' => 'pendek', 'category' => 'bukan', 'requester_email' => 'salah']))
            ->assertSessionHasErrors(['description', 'category', 'requester_email']);
        $this->assertSame(0, Ticket::query()->count());
        $this->get(route('tickets.public.thanks'))->assertRedirect(route('tickets.public.create')); // tanpa tiket baru, tidak ada halaman terima kasih
    }

    public function test_description_is_escaped_in_admin_view(): void
    {
        $this->post('/tickets/new', $this->form(['subject' => '<script>alert(1)</script>']));

        $this->actingAs($this->user(UserRole::Operator))->get(route('tickets.show', Ticket::query()->sole()))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_new_ticket_is_sent_to_telegram_once(): void
    {
        config(['netwatch.alerts.enabled' => true, 'netwatch.alerts.telegram_token' => '1:X', 'netwatch.alerts.telegram_chat_id' => '9', 'netwatch.alerts.mail_to' => []]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->post('/tickets/new', $this->form());

        $this->artisan('netwatch:send-alerts')->assertSuccessful();
        $this->artisan('netwatch:send-alerts')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['text'], '🎫') && str_contains($r['text'], 'Budi'));
    }

    // ---------- admin ----------

    public function test_only_admin_and_operator_handle_tickets(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->user(UserRole::Viewer))->get(route('tickets.index'))->assertForbidden();
        $this->get(route('tickets.show', $ticket))->assertForbidden();
        $this->post(route('tickets.resolve', $ticket))->assertForbidden();
        $this->get(route('tickets.report'))->assertForbidden();

        $this->actingAs($this->user(UserRole::Operator))->get(route('tickets.index'))->assertOk()->assertSee($ticket->number());
        $this->actingAs($this->user(UserRole::Admin))->get(route('tickets.show', $ticket))->assertOk()->assertSee('Mouse rusak');
    }

    public function test_resolved_ticket_detail_renders_pending_feedback(): void
    {
        $ticket = $this->ticket(['status' => 'resolved', 'resolved_at' => now()]);

        $this->actingAs($this->user(UserRole::Admin))
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Penilaian')
            ->assertSee('Belum dinilai')
            ->assertSee('Buat kode penilaian baru');
    }

    public function test_list_filters_by_status_search_and_mine(): void
    {
        $operator = $this->user(UserRole::Operator);
        $this->ticket(['subject' => 'Printer macet']);
        $this->ticket(['subject' => 'Wifi lambat', 'status' => 'resolved', 'resolved_at' => now()]);
        $this->ticket(['subject' => 'Email error', 'assigned_to' => $operator->id]);

        $this->actingAs($operator);
        $this->get(route('tickets.index'))->assertSee('Printer macet')->assertDontSee('Wifi lambat');
        $this->get(route('tickets.index', ['status' => 'resolved']))->assertSee('Wifi lambat')->assertDontSee('Printer macet');
        $this->get(route('tickets.index', ['status' => 'all', 'q' => 'wifi']))->assertSee('Wifi lambat')->assertDontSee('Printer macet');
        $this->get(route('tickets.index', ['mine' => '1']))->assertSee('Email error')->assertDontSee('Printer macet');
        $this->get(route('tickets.index', ['status' => 'all', 'q' => '%']))->assertDontSee('Printer macet'); // "%" dicari harfiah
    }

    public function test_admin_creates_ticket_for_user_with_audit(): void
    {
        $device = $this->device('PC-ADM-01');

        $this->actingAs($this->user(UserRole::Operator))->post(route('tickets.store'), [
            'requester_name' => 'Sari', 'subject' => 'Telepon: tidak bisa login', 'description' => 'Lupa kata sandi Windows',
            'category' => 'account', 'priority' => 'high', 'device' => $device->public_id,
        ])->assertRedirect();

        $ticket = Ticket::query()->sole();
        $this->assertSame('admin', $ticket->source);
        $this->assertSame($device->id, $ticket->device_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ticket.created']);
    }

    public function test_update_assign_and_comment_with_validation_and_audit(): void
    {
        $operator = $this->user(UserRole::Operator);
        $viewer = $this->user(UserRole::Viewer);
        $ticket = $this->ticket();
        $this->actingAs($operator);

        $this->patch(route('tickets.update', $ticket), ['status' => 'in_progress', 'priority' => 'high', 'assigned_to' => $operator->id])->assertSessionHasNoErrors();
        $ticket->refresh();
        $this->assertSame('in_progress', $ticket->status->value);
        $this->assertSame($operator->id, $ticket->assigned_to);

        $this->patch(route('tickets.update', $ticket), ['assigned_to' => $viewer->id])->assertSessionHasErrors('assigned_to'); // hanya admin/operator
        $this->patch(route('tickets.update', $ticket), ['status' => 'resolved'])->assertSessionHasErrors('status'); // selesai hanya lewat tombol selesaikan

        $this->post(route('tickets.comment', $ticket), ['body' => 'Sudah dicek kabelnya', 'internal' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ticket_comments', ['ticket_id' => $ticket->id, 'is_internal' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ticket.updated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ticket.commented']);
    }

    // ---------- selesai + penilaian ----------

    public function test_resolve_issues_one_time_code_stored_only_as_hash(): void
    {
        $ticket = $this->ticket();
        $response = $this->actingAs($this->user(UserRole::Operator))->post(route('tickets.resolve', $ticket), ['note' => 'Mouse diganti']);
        $response->assertRedirect()->assertSessionHas('feedback_code');

        $code = (string) session('feedback_code');
        $ticket->refresh();
        $this->assertSame('resolved', $ticket->status->value);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertDatabaseHas('ticket_comments', ['ticket_id' => $ticket->id, 'body' => 'Mouse diganti', 'is_internal' => false]);
        $this->assertSame(FeedbackCode::hash($code), TicketFeedback::query()->sole()->code_hash);
        $this->assertDatabaseMissing('ticket_feedback', ['code_hash' => $code]);
        $this->assertSame(1, Event::query()->where('code', 'ticket.resolved')->count());
    }

    public function test_feedback_can_be_submitted_exactly_once(): void
    {
        $code = $this->resolvedWithCode();

        $this->get("/f/{$code}")->assertOk()->assertSee('Bagaimana penanganan tiket Anda?');
        $this->post("/f/{$code}", ['rating' => 4, 'comment' => 'Cepat'])->assertOk()->assertSee('Terima kasih');

        $feedback = TicketFeedback::query()->sole();
        $this->assertSame(4, $feedback->rating);
        $this->assertSame('Cepat', $feedback->comment);
        $this->assertSame(1, Event::query()->where('code', 'ticket.feedback')->count());

        $this->post("/f/{$code}", ['rating' => 1])->assertOk()->assertSee('Tautan tidak berlaku');
        $this->get("/f/{$code}")->assertSee('Tautan tidak berlaku');
        $this->assertSame(4, $feedback->refresh()->rating); // tidak tertimpa
    }

    public function test_feedback_rejects_bad_rating_expired_and_unknown_codes(): void
    {
        $code = $this->resolvedWithCode();

        $this->post("/f/{$code}", ['rating' => 9])->assertSessionHasErrors('rating');
        $this->post("/f/{$code}", ['rating' => 0])->assertSessionHasErrors('rating');
        $this->assertNull(TicketFeedback::query()->sole()->rating);

        $this->get('/f/ABCDEFGHJK')->assertOk()->assertSee('Tautan tidak berlaku');
        $this->get('/f/'.str_repeat('x', 40))->assertOk()->assertSee('Tautan tidak berlaku');

        $this->travel(15)->days();
        $this->post("/f/{$code}", ['rating' => 5])->assertOk()->assertSee('Tautan tidak berlaku');
        $this->assertNull(TicketFeedback::query()->sole()->rating);
    }

    public function test_code_lookup_form_normalizes_input(): void
    {
        $code = $this->resolvedWithCode();
        $spaced = strtolower(substr($code, 0, 5).' - '.substr($code, 5));

        $this->post('/feedback', ['code' => $spaced])->assertRedirect("/f/{$code}");
        $this->post('/feedback', ['code' => 'SALAH'])->assertRedirect(route('tickets.public.create'))->assertSessionHasErrors('code');
    }

    public function test_new_code_invalidates_old_one_and_is_refused_after_rating(): void
    {
        $operator = $this->user(UserRole::Operator);
        $old = $this->resolvedWithCode();
        $ticket = Ticket::query()->sole();

        $this->actingAs($operator)->post(route('tickets.feedback-code', $ticket))->assertSessionHas('feedback_code');
        $new = (string) session('feedback_code');
        $this->assertNotSame($old, $new);
        $this->get("/f/{$old}")->assertSee('Tautan tidak berlaku');
        $this->get("/f/{$new}")->assertSee('Bagaimana penanganan');

        $this->post("/f/{$new}", ['rating' => 5])->assertSee('Terima kasih');
        $this->actingAs($operator)->post(route('tickets.feedback-code', $ticket))->assertSessionHas('feedback_notes');
        $this->assertSame(['Tiket ini sudah dinilai oleh pemohon.'], session('feedback_notes'));
        $this->assertSame(5, TicketFeedback::query()->sole()->rating);
    }

    public function test_feedback_code_not_allowed_for_unresolved_ticket(): void
    {
        $this->actingAs($this->user(UserRole::Operator))->post(route('tickets.feedback-code', $this->ticket()))->assertNotFound();
    }

    public function test_popup_command_carries_code_and_reports_missing_device(): void
    {
        $device = $this->device('PC-POP-01');
        $withDevice = $this->ticket(['device_id' => $device->id]);
        $without = $this->ticket(['subject' => 'Tanpa perangkat']);
        $this->actingAs($this->user(UserRole::Operator));

        $this->post(route('tickets.resolve', $withDevice), ['send_popup' => '1'])->assertSessionHas('feedback_code');
        $command = DeviceCommand::query()->where('device_id', $device->id)->where('type', CommandType::SendMessage->value)->sole();
        $this->assertStringContainsString((string) session('feedback_code'), $command->payload['message']);
        $this->assertSame('popup', TicketFeedback::query()->where('ticket_id', $withDevice->id)->sole()->sent_via);

        $this->post(route('tickets.resolve', $without), ['send_popup' => '1']);
        $this->assertStringContainsString('tidak terkait perangkat', implode(' ', (array) session('feedback_notes')));
    }

    public function test_email_is_sent_when_mailer_configured_and_skipped_otherwise(): void
    {
        $operator = $this->user(UserRole::Operator);
        $unconfigured = $this->ticket(['requester_email' => 'budi@example.test']);
        $this->actingAs($operator)->post(route('tickets.resolve', $unconfigured), ['send_email' => '1']);
        $this->assertStringContainsString('MAIL_*', implode(' ', (array) session('feedback_notes')));

        config(['mail.default' => 'uji', 'mail.mailers.uji' => ['transport' => 'array']]);
        app('mail.manager')->forgetMailers();
        $ticket = $this->ticket(['requester_email' => 'budi@example.test', 'subject' => 'Layar berkedip']);
        $this->post(route('tickets.resolve', $ticket), ['send_email' => '1']);

        $messages = app('mail.manager')->mailer('uji')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $body = $messages[0]->getOriginalMessage()->getBody()->bodyToString();
        $this->assertStringContainsString((string) session('feedback_code'), $body);
        $this->assertStringContainsString('budi@example.test', $messages[0]->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertSame('email', TicketFeedback::query()->where('ticket_id', $ticket->id)->sole()->sent_via);
    }

    public function test_report_summarizes_ratings_and_resolution_time(): void
    {
        $this->travelTo(now()->startOfMinute());
        foreach ([[5, null], [2, 'Lama sekali'], [4, null]] as $i => [$rating, $comment]) {
            $ticket = $this->ticket(['subject' => "Tiket {$i}", 'category' => 'network', 'status' => 'resolved', 'created_at' => now()->subHours(4), 'resolved_at' => now()->subHours(2)]);
            TicketFeedback::query()->create(['ticket_id' => $ticket->id, 'code_hash' => hash('sha256', "k{$i}"), 'expires_at' => now()->addDays(5),
                'rating' => $rating, 'comment' => $comment, 'submitted_at' => now()->subHour(), 'created_at' => now()->subHours(2)]);
        }

        $this->actingAs($this->user(UserRole::Operator))->get(route('tickets.report', ['preset' => '7d']))->assertOk()
            ->assertSee('3.67 / 5')->assertSee('2 jam')->assertSee('Lama sekali')->assertSee('Jaringan / internet');
    }

    // ---------- helper ----------

    /** @param  array<string, mixed>  $override */
    private function form(array $override = []): array
    {
        return $override + ['requester_name' => 'Budi', 'requester_email' => 'Budi@Example.test', 'category' => 'hardware',
            'subject' => 'Mouse rusak', 'description' => 'Mouse tidak bisa digerakkan sejak pagi', 'pc' => ''];
    }

    /** @param  array<string, mixed>  $override */
    private function ticket(array $override = []): Ticket
    {
        return Ticket::unguarded(fn () => Ticket::query()->create($override + ['requester_name' => 'Budi', 'subject' => 'Mouse rusak', 'description' => 'Mouse tidak bisa digerakkan',
            'category' => 'hardware', 'priority' => 'normal', 'status' => 'open', 'source' => 'web']));
    }

    private function resolvedWithCode(): string
    {
        $ticket = $this->ticket();
        $this->actingAs($this->user(UserRole::Operator))->post(route('tickets.resolve', $ticket));

        return (string) session('feedback_code');
    }

    private function device(string $hostname): Device
    {
        $token = app(EnrollmentService::class)->createToken($this->user(UserRole::Admin), 'tes', null, 5, 24)['plain'];
        $response = $this->postJson('/api/agent/v1/enroll', [
            'enrollment_token' => $token, 'hostname' => $hostname, 'os_family' => 'windows', 'os_version' => 'Windows 11', 'agent_version' => '1.6.0',
        ])->assertCreated();

        return Device::query()->where('public_id', $response->json('device_id'))->firstOrFail();
    }

    private function user(UserRole $role): User
    {
        return User::query()->firstOrCreate(
            ['email' => "{$role->value}@example.test"],
            ['name' => $role->label(), 'password' => 'Password-12345', 'role' => $role, 'is_active' => true],
        );
    }
}
