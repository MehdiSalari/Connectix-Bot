<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AdminActivity;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Support\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The support ticket panel: the list, its filters, the reply, the status
 * transitions and the role split (an editor reads, an admin answers) — the
 * behaviours the fork's tickets/index.php + TicketService exposed.
 */
class AdminTicketsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.connectix.base_url' => 'https://api.connectix.vip',
            'connectix_bot.app_name' => 'Acme VPN',
            'connectix_bot.panel.enabled' => false,
        ]);

        Http::preventStrayRequests();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function makeAdmin(AdminRole $role = AdminRole::Admin): Admin
    {
        return Admin::query()->create([
            'email' => $role->value.'-'.uniqid().'@acme.test',
            'password' => 'secret-pass',
            'token' => 'panel-token-a',
            'chat_id' => '10',
            'role' => $role,
        ]);
    }

    private function postingAs(AdminRole $role = AdminRole::Admin)
    {
        return $this->actingAs($this->makeAdmin($role), 'admin');
    }

    private function makeTicket(array $overrides = []): Ticket
    {
        return Ticket::query()->create(array_merge([
            'ticket_number' => 'TK-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'chat_id' => '553',
            'subject' => 'اتصال برقرار نمی‌شود',
            'department' => 'پشتیبانی عمومی',
            'status' => 'OPEN',
            'priority' => 'MEDIUM',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    // -----------------------------------------------------------------
    // The list
    // -----------------------------------------------------------------

    public function test_the_ticket_list_renders_and_counts_open(): void
    {
        $ticket = $this->makeTicket();
        $this->makeTicket(['status' => 'CLOSED', 'ticket_number' => 'TK-000099']);

        $this->postingAs()
            ->get(route('admin.tickets.index'))
            ->assertOk()
            ->assertSee($ticket->ticket_number)
            ->assertSee('اتصال برقرار نمی‌شود')
            // The open badge the sidebar layout counts is passed to the view.
            ->assertViewHas('openCount', 1);
    }

    public function test_the_ticket_list_filters_by_status_and_search(): void
    {
        $open = $this->makeTicket(['subject' => 'مشکل اتصال']);
        $closed = $this->makeTicket(['status' => 'CLOSED', 'subject' => 'تغییر پلن', 'ticket_number' => 'TK-000098']);

        $this->postingAs()
            ->get(route('admin.tickets.index', ['status' => 'OPEN']))
            ->assertOk()
            ->assertSee($open->ticket_number)
            ->assertDontSee($closed->ticket_number);

        $this->postingAs()
            ->get(route('admin.tickets.index', ['search' => 'تغییر پلن']))
            ->assertOk()
            ->assertSee($closed->ticket_number)
            ->assertDontSee($open->ticket_number);

        // The chat id is searchable too — the admin often only has it.
        $this->postingAs()
            ->get(route('admin.tickets.index', ['search' => '553']))
            ->assertOk()
            ->assertSee($open->ticket_number)
            ->assertSee($closed->ticket_number);
    }

    // -----------------------------------------------------------------
    // Creation through the service
    // -----------------------------------------------------------------

    public function test_the_service_creates_a_numbered_ticket_with_the_first_message(): void
    {
        $service = new TicketService;

        $ticket = $service->create('553', 'سرور قطع است', 'پشتیبانی عمومی', 'HIGH', null, 'کانکت نمی‌شوم', 'Ali');

        $this->assertSame('OPEN', $ticket->status);
        $this->assertMatchesRegularExpression('/^TK-\d{6}$/', $ticket->ticket_number);
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertSame('USER', $ticket->messages()->first()->sender_type);
        $this->assertSame('کانکت نمی‌شوم', $ticket->messages()->first()->message);

        // A second ticket gets the next number: zero-padded and unique.
        $second = $service->create('554', 'موضوع دیگر');
        $this->assertNotSame($ticket->ticket_number, $second->ticket_number);
        $this->assertMatchesRegularExpression('/^TK-\d{6}$/', $second->ticket_number);
    }

    // -----------------------------------------------------------------
    // Replies and status
    // -----------------------------------------------------------------

    public function test_an_admin_reply_persists_a_message_and_bumps_updated_at(): void
    {
        $ticket = $this->makeTicket(['updated_at' => now()->subHour()]);
        $before = $ticket->updated_at->getTimestamp();

        $this->postingAs()
            ->from(route('admin.tickets.show', $ticket))
            ->post(route('admin.tickets.reply', $ticket), ['message' => 'در حال بررسی هستیم'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'sender_type' => 'ADMIN',
            'message' => 'در حال بررسی هستیم',
        ]);

        $ticket->refresh();
        $this->assertSame('ANSWERED', $ticket->status, 'an admin reply marks the ticket ANSWERED');
        $this->assertGreaterThan($before, $ticket->updated_at->getTimestamp(), 'the reply bumps updated_at');

        // The fork's trail: every reply is recorded.
        $this->assertDatabaseHas('admin_activity', [
            'action' => 'ticket_reply',
            'subject_type' => 'ticket',
            'subject_id' => (string) $ticket->id,
        ]);
    }

    public function test_the_status_transitions_open_answered_closed(): void
    {
        $ticket = $this->makeTicket();

        $this->postingAs()
            ->post(route('admin.tickets.status', $ticket), ['status' => 'ANSWERED'])
            ->assertRedirect();

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'ANSWERED']);

        $this->postingAs()
            ->post(route('admin.tickets.status', $ticket), ['status' => 'CLOSED'])
            ->assertRedirect();

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'CLOSED']);

        // An invalid status never reaches the row.
        $this->postingAs()
            ->post(route('admin.tickets.status', $ticket), ['status' => 'BOGUS'])
            ->assertSessionHasErrors('status');

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'CLOSED']);
    }

    public function test_close_stamps_closed_and_twice_is_harmless(): void
    {
        $ticket = $this->makeTicket();

        $this->postingAs()
            ->post(route('admin.tickets.close', $ticket))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'CLOSED']);

        $this->postingAs()
            ->post(route('admin.tickets.close', $ticket))
            ->assertRedirect();

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'CLOSED']);
        $this->assertSame(1, AdminActivity::query()->where('action', 'ticket_close')->count());
    }

    public function test_a_user_reply_to_a_closed_ticket_is_refused(): void
    {
        $ticket = $this->makeTicket(['status' => 'CLOSED']);
        $service = new TicketService;

        $this->expectException(\RuntimeException::class);

        $service->reply($ticket, 'USER', 'Ali', 'دوباره قطع شد');
    }

    // -----------------------------------------------------------------
    // Role gating
    // -----------------------------------------------------------------

    public function test_an_editor_can_read_but_not_answer(): void
    {
        $ticket = $this->makeTicket();

        $this->postingAs(AdminRole::Editor)
            ->get(route('admin.tickets.index'))
            ->assertOk()
            ->assertSee($ticket->ticket_number);

        $this->postingAs(AdminRole::Editor)
            ->get(route('admin.tickets.show', $ticket))
            ->assertOk();

        $this->postingAs(AdminRole::Editor)
            ->post(route('admin.tickets.reply', $ticket), ['message' => 'hi'])
            ->assertForbidden();

        $this->postingAs(AdminRole::Editor)
            ->post(route('admin.tickets.status', $ticket), ['status' => 'CLOSED'])
            ->assertForbidden();

        $this->postingAs(AdminRole::Editor)
            ->post(route('admin.tickets.close', $ticket))
            ->assertForbidden();

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'OPEN']);
        $this->assertSame(0, $ticket->messages()->count());
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $ticket = $this->makeTicket();

        $this->get(route('admin.tickets.index'))->assertRedirect(route('admin.login'));
        // The model binding resolves before the guard answers, so the probe
        // needs a real row to reach the redirect.
        $this->post(route('admin.tickets.reply', $ticket), ['message' => 'x'])
            ->assertRedirect(route('admin.login'));
    }

    // -----------------------------------------------------------------
    // openCount
    // -----------------------------------------------------------------

    public function test_open_count_reflects_the_open_rows(): void
    {
        $service = new TicketService;

        $this->makeTicket(['status' => 'OPEN']);
        $this->makeTicket(['status' => 'ANSWERED']);
        $this->makeTicket(['status' => 'CLOSED']);

        $this->assertSame(1, $service->openCount());

        // A new instance (a new request) counts again.
        $this->makeTicket(['status' => 'OPEN']);
        $this->assertSame(2, (new TicketService)->openCount());
    }
}
