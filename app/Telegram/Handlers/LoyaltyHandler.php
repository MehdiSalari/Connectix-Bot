<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\LoyaltyCode;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\User;
use App\Services\Shop\LoyaltyBotService;
use App\Services\Telegram\AdminGuard;
use App\Services\Telegram\TelegramService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Support\Carbon;

class LoyaltyHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly AdminGuard $adminGuard,
    ) {
    }

    public function supports(TelegramUpdate $update, User $user): bool
    {
        $data = $update->callbackData();
        if ($data !== null) {
            return str_starts_with($data, 'loyalty_');
        }
        $text = $update->text();
        if ($text !== null) {
            return str_starts_with($text, 'loyalty_') || $text === 'loyalty';
        }
        return false;
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $data = $update->callbackData();
        $chatId = (string) $user->chat_id;
        if ($data !== null) {
            if ($data === 'loyalty_menu') {
                $this->showMenu($update, $user);
                return;
            }
            if (str_starts_with($data, 'loyalty_redeem:')) {
                $parts = explode(':', $data);
                $codeId = (int) ($parts[1] ?? 0);
                $res = LoyaltyBotService::redeemCode($chatId, $codeId);
                $msg = $res['ok'] ? '✅ با موفقیت انجام شد.' : ('❌ ' . ($res['error'] ?? 'خطا'));
                $this->render($update, $user, $msg, [[['text' => '🔙 بازگشت', 'callback_data' => 'loyalty_menu']]]);
                return;
            }
            if ($data === 'loyalty_history') {
                $this->showHistory($update, $user, 1);
                return;
            }
            if (str_starts_with($data, 'loyalty_hist:')) {
                $p = (int) explode(':', $data)[1];
                $this->showHistory($update, $user, $p);
                return;
            }
            return;
        }
        $text = (string) $update->text();
        if ($text === 'loyalty_menu' || $text === 'loyalty') {
            $this->showMenu($update, $user);
            return;
        }
    }

    private function now(): string
    {
        return Carbon::now('Asia/Tehran')->format('Y-m-d H:i:s');
    }

    private function showMenu(TelegramUpdate $update, User $user): void
    {
        $chatId = (string) $user->chat_id;
        $bal = LoyaltyBotService::getBalance($chatId);
        $rows = [];
        $codes = LoyaltyCode::query()
            ->whereNull('assigned_to')
            ->orWhere('assigned_to', $chatId)
            ->limit(20)
            ->get();
        foreach ($codes as $code) {
            $red = LoyaltyRedemption::query()->where('code', $code->code)->whereNotNull('used_at')->first();
            if ($red) {
                continue;
            }
            $reward = LoyaltyReward::query()->find($code->reward_id);
            $rows[] = [['text' => ($reward ? $reward->title : 'کد') . ' | ' . ($reward ? $reward->cost : ''), 'callback_data' => 'loyalty_redeem:' . $code->id]];
        }
        $rows[] = [['text' => '📜 تاریخچه', 'callback_data' => 'loyalty_history']];
        $rows[] = [['text' => '↪️ بازگشت', 'callback_data' => 'main_menu']];
        $text = "🎖 امتیازات شما: <b>{$bal}</b>";
        $this->render($update, $user, $text, $rows);
    }

    private function showHistory(TelegramUpdate $update, User $user, int $page): void
    {
        $chatId = (string) $user->chat_id;
        $per = 10;
        $offset = ($page - 1) * $per;
        $points = LoyaltyPoint::query()->where('chat_id', $chatId)->orderBy('id', 'desc')->limit($per)->offset($offset)->get();
        $reds = LoyaltyRedemption::query()->where('chat_id', $chatId)->orderBy('id', 'desc')->limit($per)->offset($offset)->get();
        $items = collect($points)->map(function ($p) {
            return ['type' => 'point', 'amount' => $p->amount, 'note' => $p->note ?? $p->type, 'created_at' => $p->created_at];
        })->merge(collect($reds)->map(function ($r) {
            return ['type' => 'red', 'amount' => -($r->points ?? 0), 'note' => $r->reward_title ?? 'Redeem', 'created_at' => $r->created_at];
        }))->sortByDesc('created_at')->take($per);
        $lines = '';
        foreach ($items as $it) {
            $amt = $it['amount'];
            $lines .= ($amt > 0 ? '+' . $amt : $amt) . ' | ' . ($it['note'] ?? '') . "\n";
        }
        $rows = [];
        if ($page > 1) {
            $rows[] = [['text' => '⏪ قبلی', 'callback_data' => 'loyalty_hist:' . ($page - 1)]];
        }
        $rows[] = [['text' => '🔙 بازگشت', 'callback_data' => 'loyalty_menu']];
        $this->render($update, $user, "<pre>{$lines}</pre>", $rows);
    }

    private function render(TelegramUpdate $update, User $user, string $text, array $rows): void
    {
        $chatId = (string) $user->chat_id;
        $keyboard = json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
        $mid = $update->callbackMessageId();
        if ($mid !== null) {
            $this->telegram->editMessageText($chatId, $mid, $text, ['parse_mode' => 'HTML', 'reply_markup' => $keyboard]);
            return;
        }
        $this->telegram->sendMessage($chatId, $text, ['parse_mode' => 'HTML', 'reply_markup' => $keyboard]);
    }
}
