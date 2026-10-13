<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivity;
use App\Models\Ticket;
use App\Services\Panel\PanelSettingsService;
use App\Services\Support\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The support ticket list, its thread view and the admin's answer.
 *
 * Port of the fork's tickets/index.php. Its behaviour is kept: the list
 * filters by status (and searches the subject and the chat id), the reply
 * form posts back to the same page and an admin reply turns the ticket
 * ANSWERED; the status select and the close button move it OPEN /
 * ANSWERED / CLOSED, and every change stamps the row's `updated_at` — the
 * column the list orders by.
 *
 * Role split follows the fork: an Editor opens the list and reads threads,
 * only an Admin replies, re-statuses or closes. A reply writes an
 * admin_activity row — the fork's «who did what» trail.
 */
class AdminTicketController extends Controller
{
    public function __construct(
        private readonly TicketService $tickets,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $status = strtoupper((string) $request->query('status', 'ALL'));

        if (! in_array($status, TicketService::STATUSES, true)) {
            $status = 'ALL';
        }

        $query = Ticket::query()
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($status !== 'ALL') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            // One group so the search stays under the status filter — an
            // orWhere outside it would leak the other status's rows.
            $query->where(function ($q) use ($search): void {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('chat_id', 'like', "%{$search}%");
            });
        }

        $tickets = $query->paginate(30)->withQueryString();

        return view('admin.tickets.index', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'tickets' => $tickets,
            'openCount' => $this->tickets->openCount(),
            'search' => $search,
            'status' => $status,
            'statuses' => TicketService::STATUSES,
        ]);
    }

    /**
     * The thread of one ticket: messages oldest first plus the reply form.
     */
    public function show(Request $request, Ticket $ticket): View
    {
        $this->tickets->thread($ticket);

        return view('admin.tickets.show', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'ticket' => $ticket,
        ]);
    }

    /**
     * Append the admin's answer to the thread and mark the ticket ANSWERED.
     */
    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:1'],
        ]);

        $this->tickets->reply(
            $ticket,
            'ADMIN',
            (string) ($request->user('admin')?->email ?? 'ادمین پشتیبانی'),
            trim($data['message']),
        );

        $this->record($request, 'ticket_reply', (string) $ticket->id);

        return back()->with('success', 'پاسخ شما ارسال شد و پیام به کاربر تحویل گردید.');
    }

    /**
     * Move a ticket between OPEN / ANSWERED / CLOSED from its status select.
     */
    public function status(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(TicketService::STATUSES)],
        ]);

        $this->tickets->setStatus($ticket, $data['status']);

        $this->record($request, 'ticket_status', (string) $ticket->id, 'status='.$data['status']);

        return back()->with('success', "وضعیت تیکت به {$data['status']} تغییر یافت.");
    }

    /**
     * Close a ticket — the fork's «بستن تیکت» button.
     */
    public function close(Request $request, Ticket $ticket): RedirectResponse
    {
        $closed = $this->tickets->closeTicket($ticket);

        if ($closed) {
            $this->record($request, 'ticket_close', (string) $ticket->id);

            return back()->with('success', 'تیکت با موفقیت بسته شد.');
        }

        return back()->with('error', 'این تیکت از قبل بسته شده است.');
    }

    /**
     * One row of the activity trail, best effort: the fork logged and moved
     * on, and a logging failure must never fail the admin's real action.
     */
    private function record(Request $request, string $action, string $subjectId, string $details = ''): void
    {
        try {
            AdminActivity::query()->create([
                'admin_email' => $request->user('admin')?->email,
                'admin_id' => $request->user('admin')?->id,
                'action' => $action,
                'subject_type' => 'ticket',
                'subject_id' => $subjectId,
                'details' => $details !== '' ? $details : null,
                'ip' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // never block the action over a logging failure
        }
    }
}
