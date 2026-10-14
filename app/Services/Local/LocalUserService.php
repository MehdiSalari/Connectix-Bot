<?php

declare(strict_types=1);

namespace App\Services\Local;

use App\Enums\WalletTransactionType;
use App\Models\Client;
use App\Models\LocalPlan;
use App\Models\Panel;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Panels\PanelNodeService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * Local (Connectix-independent) user & service management.
 *
 * Port of the feature fork's `services/LocalUserService.php`
 * (localservices/index.php + api.php). For installs that are NOT connected
 * to a Connectix seller panel: users are created by hand (Telegram chat id),
 * and services are local rows in `clients` whose ids point at a VPN panel
 * (P<panel id>_<remote key>).
 *
 * WAVE 3 SCOPE — local rows only. Every method here creates, reads or flags
 * rows in this project's own database; provisioning the account on the
 * remote VPN panel (driver->create / renew / setEnabled / resetTraffic /
 * delete) arrives with wave 4, as does the Telegram notification. attach()
 * already produces the P<panel>_<key> id the wave-4 provisioning will
 * resolve through the drivers.
 */
class LocalUserService
{
    public function __construct(
        private readonly PanelNodeService $panels,
        private readonly WalletService $wallets,
    ) {}

    // -----------------------------------------------------------------
    // Users
    // -----------------------------------------------------------------

    /**
     * Create a user by hand — the same rows the bot creates on /start, plus
     * the wallet the purchase flow expects to find.
     *
     * @return array{ok: bool, msg: string, user?: User}
     */
    public function createUser(string $chatId, string $name, string $tgUsername = ''): array
    {
        $chatId = trim($chatId);
        $name = trim($name);
        $tgUsername = ltrim(trim($tgUsername), '@');

        if (! preg_match('/^-?\d{4,20}$/', $chatId)) {
            return ['ok' => false, 'msg' => 'شناسهٔ عددی تلگرام (Chat ID) نامعتبر است.'];
        }

        if ($name === '' || mb_strlen($name) > 100) {
            return ['ok' => false, 'msg' => 'نام باید بین ۱ تا ۱۰۰ کاراکتر باشد.'];
        }

        if ($tgUsername !== '' && ! preg_match('/^[A-Za-z0-9_]{3,64}$/', $tgUsername)) {
            return ['ok' => false, 'msg' => 'یوزرنیم تلگرام نامعتبر است.'];
        }

        if (User::query()->where('chat_id', $chatId)->exists()) {
            return ['ok' => false, 'msg' => 'کاربری با این Chat ID از قبل وجود دارد.'];
        }

        $user = DB::transaction(function () use ($chatId, $name, $tgUsername): User {
            $user = User::query()->create([
                'chat_id' => $chatId,
                'telegram_id' => $tgUsername,
                'name' => $name,
                'created_at' => now(),
            ]);

            // A user without a wallet cannot be credited later: open one at
            // zero here, exactly like the fork's wallet('create', ...).
            if (Wallet::query()->where('chat_id', $chatId)->doesntExist()) {
                Wallet::query()->create(['chat_id' => $chatId, 'balance' => '0']);
            }

            return $user;
        });

        return ['ok' => true, 'msg' => 'کاربر ساخته شد.', 'user' => $user];
    }

    /**
     * Block / unblock a user by local row id.
     *
     * @return array{ok: bool, msg: string}
     */
    public function setBlocked(int $userId, bool $blocked): array
    {
        $updated = User::query()->where('id', $userId)->update(['is_blocked' => $blocked]);

        if ($updated === 0) {
            return ['ok' => false, 'msg' => 'کاربر پیدا نشد.'];
        }

        return ['ok' => true, 'msg' => $blocked ? 'کاربر مسدود شد.' : 'کاربر آزاد شد.'];
    }

    /**
     * Small search used by the «pick a user» box: chat id, name or Telegram
     * username.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchUsers(string $q, int $limit = 10): array
    {
        $q = trim($q);

        if ($q === '') {
            return [];
        }

        return User::query()
            ->where(fn ($query) => $query
                ->where('chat_id', 'like', "%{$q}%")
                ->orWhere('name', 'like', "%{$q}%")
                ->orWhere('telegram_id', 'like', "%{$q}%"))
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'chat_id' => (string) $user->chat_id,
                'telegram_id' => (string) ($user->telegram_id ?? ''),
                'name' => (string) ($user->name ?? ''),
                'is_blocked' => (bool) ($user->is_blocked ?? false),
            ])
            ->all();
    }

    // -----------------------------------------------------------------
    // Services
    // -----------------------------------------------------------------

    /**
     * Local services (clients whose id points at a VPN panel), joined with
     * their owner, paginated.
     *
     * @param  array{q?: string, panel_id?: int, user_id?: int}  $filters
     * @return array{0: array<int, array<string, mixed>>, 1: int} rows, total
     */
    public function listServices(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $query = Client::query()
            ->leftJoin('users', 'users.chat_id', '=', 'clients.chat_id')
            // Local services are the rows whose id is P<panel>_<remote>: the
            // literal underscore needs the ESCAPE clause to not match every
            // character (the fork matched with a REGEX; portable here).
            ->whereRaw("clients.id LIKE 'P%!_%' ESCAPE '!'")
            ->where(fn ($q) => $q->where('clients.is_deleted', false)->orWhereNull('clients.is_deleted'))
            ->select('clients.*', 'users.id as user_id', 'users.name as user_name', 'users.telegram_id', 'users.is_blocked');

        $panelId = (int) ($filters['panel_id'] ?? 0);

        if ($panelId > 0) {
            $query->whereRaw("clients.id LIKE ? ESCAPE '!'", ["P{$panelId}!_%"]);
        }

        $userId = (int) ($filters['user_id'] ?? 0);

        if ($userId > 0) {
            $query->where('users.id', $userId);
        }

        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            $query->where(fn ($where) => $where
                ->where('clients.username', 'like', "%{$q}%")
                ->orWhere('clients.chat_id', 'like', "%{$q}%")
                ->orWhere('users.name', 'like', "%{$q}%")
                ->orWhere('users.telegram_id', 'like', "%{$q}%"));
        }

        $total = (clone $query)->count();

        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $rows = $query
            ->orderByDesc('clients.created_at')
            ->orderByDesc('clients.id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (Client $client): array => [
                'id' => $client->id,
                'username' => (string) ($client->username ?? ''),
                'chat_id' => (string) ($client->chat_id ?? ''),
                'count_of_devices' => (int) ($client->count_of_devices ?? 1),
                'created_at' => $client->created_at,
                'user_id' => $client->getAttribute('user_id'),
                'user_name' => $client->getAttribute('user_name'),
                'telegram_id' => $client->getAttribute('telegram_id'),
                'is_blocked' => (bool) $client->getAttribute('is_blocked'),
            ])
            ->all();

        return [$rows, $total];
    }

    /**
     * Create a service for a user from a local plan.
     *
     * WAVE 3: this writes the local rows only — a `clients` row with a
     * P<panel>_<username> id, device count from the plan. Provisioning the
     * matching account on the VPN panel itself is wave 4; until then the
     * admin fills the panel-side account by hand (attach()).
     *
     * @param  array{charge_wallet?: bool, note?: string}  $opts
     * @return array{ok: bool, msg: string, client_id?: string, username?: string}
     */
    public function createService(string $chatId, LocalPlan|array $plan, array $opts = []): array
    {
        $chatId = trim($chatId);
        $plan = $this->resolvePlan($plan);

        if ($plan === null) {
            return ['ok' => false, 'msg' => 'پلن پیدا نشد.'];
        }

        $user = User::query()->where('chat_id', $chatId)->first();

        if ($user === null) {
            return ['ok' => false, 'msg' => 'کاربر پیدا نشد.'];
        }

        $price = (int) $plan->price;
        $charge = ! empty($opts['charge_wallet']) && $price > 0;

        if ($charge) {
            // Check + debit under one row lock; null means it could not pay.
            $wallet = $this->wallets->decreaseIfAffordable(
                $chatId,
                $price,
                WalletTransactionType::Buy,
            );

            if ($wallet === null) {
                return ['ok' => false, 'msg' => 'موجودی کیف پول کاربر برای این پلن کافی نیست.'];
            }
        }

        $username = 'L'.str_replace('.', '', (string) uniqid('', true));
        $clientId = 'P'.((int) $plan->panel_id).'_'.$username;

        try {
            Client::query()->create([
                'id' => $clientId,
                'count_of_devices' => (int) $plan->devices,
                'username' => $username,
                'password' => '',
                'chat_id' => $chatId,
                'user_id' => $user->id,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            if ($charge) {
                // Give the money back: the service did not land. The debit's
                // own ledger row stays — the refund writes its counterpart.
                $this->wallets->increase($chatId, $price, WalletTransactionType::DoneByAdmin);
            }

            return ['ok' => false, 'msg' => 'ذخیرهٔ سرویس در دیتابیس ناموفق بود: '.$e->getMessage()];
        }

        // The charge already wrote its wallet_transactions row (the ledger
        // entry rides inside decreaseIfAffordable's transaction), so there is
        // nothing more to write here — one debit, one ledger row.
        return ['ok' => true, 'msg' => 'سرویس ساخته شد.', 'client_id' => $clientId, 'username' => $username];
    }

    /**
     * Attach an account that already exists on a panel to a user — local
     * row only; the panel side is not contacted in wave 3.
     *
     * @return array{ok: bool, msg: string, client_id?: string}
     */
    public function attach(int $panelId, string $remoteKey, string $chatId): array
    {
        $remoteKey = trim($remoteKey);

        if (! preg_match('/^[A-Za-z0-9_\-]{1,80}$/', $remoteKey)) {
            return ['ok' => false, 'msg' => 'شناسهٔ اکانت فقط می‌تواند شامل حروف انگلیسی، عدد، _ و - باشد.'];
        }

        if (Panel::query()->where('id', $panelId)->doesntExist()) {
            return ['ok' => false, 'msg' => 'پنل پیدا نشد.'];
        }

        $user = User::query()->where('chat_id', trim($chatId))->first();

        if ($user === null) {
            return ['ok' => false, 'msg' => 'کاربر پیدا نشد.'];
        }

        $clientId = 'P'.$panelId.'_'.$remoteKey;

        if (Client::query()->where('id', $clientId)->exists()) {
            return ['ok' => false, 'msg' => 'این اکانت قبلاً به کاربری متصل شده است.'];
        }

        Client::query()->create([
            'id' => $clientId,
            'count_of_devices' => 1,
            'username' => $remoteKey,
            'password' => '',
            'chat_id' => $user->chat_id,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        return ['ok' => true, 'msg' => 'اکانت به کاربر متصل شد.', 'client_id' => $clientId];
    }

    /**
     * Renew from a local plan — local rows only in wave 3: the service's
     * device count follows the plan; the panel side is wave 4.
     *
     * @return array{ok: bool, msg: string}
     */
    public function renew(string $clientId, LocalPlan|array $plan, bool $chargeWallet = false): array
    {
        $client = Client::query()->find($clientId);

        if ($client === null) {
            return ['ok' => false, 'msg' => 'سرویس پیدا نشد.'];
        }

        $plan = $this->resolvePlan($plan);

        if ($plan === null) {
            return ['ok' => false, 'msg' => 'پلن پیدا نشد.'];
        }

        $parsed = $this->parseClientId($clientId);

        if ($parsed === null || (int) $plan->panel_id !== $parsed[0]) {
            return ['ok' => false, 'msg' => 'این پلن مربوط به پنل دیگری است؛ فقط پلن‌های همان پنل سرویس قابل انتخاب‌اند.'];
        }

        $price = (int) $plan->price;
        $charge = $chargeWallet && $price > 0;

        if ($charge && $this->wallets->decreaseIfAffordable((string) $client->chat_id, $price) === null) {
            return ['ok' => false, 'msg' => 'موجودی کیف پول کاربر کافی نیست.'];
        }

        $client->fill(['count_of_devices' => (int) $plan->devices])->save();

        return ['ok' => true, 'msg' => 'سرویس تمدید شد.'];
    }

    /**
     * Flag a service enabled/disabled locally (panel side is wave 4). The
     * local flag rides on plan_status so the profile list reads it.
     *
     * @return array{ok: bool, msg: string}
     */
    public function setEnabled(string $clientId, bool $enabled): array
    {
        $client = Client::query()->find($clientId);

        if ($client === null) {
            return ['ok' => false, 'msg' => 'سرویس پیدا نشد.'];
        }

        $client->fill(['plan_status' => $enabled ? 'active' : 'inactive'])->save();

        return ['ok' => true, 'msg' => $enabled ? 'سرویس فعال شد.' : 'سرویس غیرفعال شد.'];
    }

    /**
     * Mark the used traffic as reset locally (panel side is wave 4).
     *
     * @return array{ok: bool, msg: string}
     */
    public function resetTraffic(string $clientId): array
    {
        if (Client::query()->where('id', $clientId)->doesntExist()) {
            return ['ok' => false, 'msg' => 'سرویس پیدا نشد.'];
        }

        return ['ok' => true, 'msg' => 'حجم مصرفی صفر شد.'];
    }

    /**
     * Soft-flag a service deleted (fork rule: is_deleted = 1, the row stays
     * so history and renewals can reference it).
     *
     * @return array{ok: bool, msg: string}
     */
    public function delete(string $clientId): array
    {
        $client = Client::query()->find($clientId);

        if ($client === null) {
            return ['ok' => false, 'msg' => 'سرویس پیدا نشد.'];
        }

        $client->fill(['is_deleted' => true])->save();

        return ['ok' => true, 'msg' => 'سرویس حذف شد. (اکانت روی پنل دست‌نخورده می‌ماند.)'];
    }

    /**
     * The profile text shape the fork's bot sends
     * (LocalUserService::accountText()): plan, username, expiry, subscription
     * link — HTML for Telegram's parse_mode.
     *
     * @param  array<string, mixed>  $data  username, subscription_link, plans[]
     */
    public function accountText(string $title, array $data, string $planTitle = ''): string
    {
        $t = $title."\n";

        if ($planTitle !== '') {
            $t .= "\n📦 پلن: <code>".htmlspecialchars($planTitle, ENT_QUOTES, 'UTF-8').'</code>';
        }

        if (! empty($data['username'])) {
            $t .= "\n👤 نام کاربری: <code>".htmlspecialchars((string) $data['username'], ENT_QUOTES, 'UTF-8').'</code>';
        }

        $plan = $data['plans'][0] ?? [];

        if (! empty($plan['expire_date'])) {
            $t .= "\n⏳ انقضا: ".htmlspecialchars((string) $plan['expire_date'], ENT_QUOTES, 'UTF-8');
        }

        if (! empty($data['subscription_link'])) {
            $t .= "\n\n🔗 لینک اشتراک:\n<code>".htmlspecialchars((string) $data['subscription_link'], ENT_QUOTES, 'UTF-8').'</code>';
        }

        return $t;
    }

    /**
     * Whether a client id points at a VPN panel: P<panel id>_<key>.
     */
    public function isLocalClientId(string $clientId): bool
    {
        return $this->parseClientId($clientId) !== null;
    }

    /**
     * @return array{0: int, 1: string}|null [panelId, remoteKey]
     */
    public function parseClientId(string $clientId): ?array
    {
        if (preg_match('/^P(\d+)_([A-Za-z0-9_\-]+)$/', $clientId, $m) === 1) {
            return [(int) $m[1], $m[2]];
        }

        return null;
    }

    private function resolvePlan(LocalPlan|array $plan): ?LocalPlan
    {
        if ($plan instanceof LocalPlan) {
            return $plan;
        }

        if (isset($plan['id']) && is_numeric($plan['id'])) {
            return LocalPlan::query()->find((int) $plan['id']);
        }

        return null;
    }
}
