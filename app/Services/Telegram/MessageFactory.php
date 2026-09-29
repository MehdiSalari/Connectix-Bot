<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Services\Panel\PanelSettingsService;

/**
 * The text of every bot message. Port of the legacy `message()` helper.
 *
 * The four texts a reseller can edit in the seller panel
 * (welcome/support/FAQ/test created) are read through
 * {@see PanelSettingsService}, so a local override wins, then the panel, and
 * only then the literal default below. Everything else was hardcoded in legacy
 * and stays hardcoded here.
 *
 * Strings are written exactly as legacy produced them, whitespace included:
 * a few carry a trailing space or a doubled space that users see.
 */
class MessageFactory
{
    public function __construct(
        private readonly PanelSettingsService $settings,
    ) {}

    /**
     * @param  array<string, string>  $variables
     */
    public function make(string $key, array $variables = []): string
    {
        return match ($key) {
            'welcome_message' => $this->settings->message('welcome_text'),
            'support' => $this->settings->message('contact_support'),
            'faq' => $this->settings->message('questions_and_answers'),
            'test_created' => $this->settings->message(
                'free_test_account_created',
                'اکانت تست شما با موفقیت ایجاد شد.'
            ),

            'accounts' => "📦 اکانت های متصل یه حساب تلگرام شما:\n\n* در صورت عدم مشاهده اکانت خود، آن را اضافه کنید.",

            'get_test' => "🎁 لطفا نوع اکانت تست را انتخاب کنید:\n\n"
                ."<b>📱 {$this->groupLabel('default')}:</b>\n{$this->groupDescription('default')}\n\n"
                ."<b>💰 {$this->groupLabel('Economic')}:</b>\n{$this->groupDescription('Economic')}",

            'count' => "{$this->groupEmoji($variables['groupName'] ?? null)} نوع سرویس "
                ."{$variables['groupName']} انتخاب شد.\n\n"
                .'🔢 این اکانت را برای چند کاربر (دستگاه) قابل استفاده باشد؟',

            'buy' => "با تشکر از اعتماد و حسن انتخاب شما در خرید سرویس فیلترشکن {$this->settings->appName()} .\n"
                ."لطفا نوع خرید خود را انتخاب کنید:\n\n"
                ."<b>🔄️ تمدید اکانت فعلی:</b>\nاین دکمه برای خرید اشتراک برای اکانت قبلی استفاده میشود.\n\n"
                ."<b>🛍️ خرید اکانت جدید:</b>\nاین دکمه برای خرید اکانت جدید استفاده میشود.",

            'renew' => '📦 لطفا اکانت مدنظر خود را جهت تمدید اشتراک انتخاب کنید:',

            'card' => "💸  لطفاً مبلغ لازمه را به شماره کارت زیر واریز و سپس سند پرداخت را به صورت تصویری در ادامه ارسال کنید:\n\n"
                ."💴 مبلغ: {$variables['amount']}\n"
                ."💳 شماره کارت: {$this->settings->cardNumber()}\n"
                ."👤 به نام: {$this->settings->cardName()}\n",

            'add_account' => "🔗 شما در حال متصل کردن اکانت قبلی به حساب تلگرام خود هستید.\n\n"
                .'👤 لطفا نام کاربری اکانت را وارد نمایید:',

            'apps' => '⚙ لطفا سیستم عامل مدنظر خود را انتخاب کنید:',
            'guide' => '📖 لطفا نحوه آموزش را انتحاب کنید.',

            'wallet' => "🤑 موجودی کیف پول شما: \n💵 {$variables['walletBalance']} تومان\n\n"
                ."👤 نام: {$variables['userName']}\n"
                .'🔢 آیدی عددی: '.$variables['userId'],

            'wallet_increase' => '💰 لطفا مبلغ مدنظر جهت افزایش موجودی کیف پول خود به (تومان) را وارد نمایید.'
                ."\n حداقل مبلغ واریزی ".number_format($this->minimumDeposit()).' تومان میباشد.',

            default => 'پیام پیشفرض',
        };
    }

    /**
     * The service type menu, listing only the groups the panel actually sells.
     *
     * @param  array<int, string>  $groupNames  Group keys, e.g. `['default']`.
     */
    public function groupMenu(array $groupNames): string
    {
        $text = 'لطفاً ابتدا نوع سرویس مدنظر را انتخاب کنید: 👇';

        foreach ($groupNames as $name) {
            $text .= "\n\n<b>{$this->groupEmoji($name)} ".$this->groupLabel($name).':</b>';
            $text .= "\n".$this->groupDescription($name);
        }

        return $text;
    }

    /**
     * The display name of a plan group, e.g. `default` => ویژه.
     */
    public function groupLabel(string $group): string
    {
        return (string) config("connectix_bot.plan_groups.{$group}", $group);
    }

    /**
     * The blurb shown under a group in the service type menu.
     */
    public function groupDescription(string $group): string
    {
        $descriptions = (array) config('connectix_bot.plan_group_descriptions', []);

        return (string) ($descriptions[$group] ?? '');
    }

    /**
     * The leading emoji of a group.
     *
     * Legacy matched on the *label* rather than the group key, so a reseller
     * who renamed a group kept the generic phone emoji.
     */
    public function groupEmoji(?string $group): string
    {
        $emojis = (array) config('connectix_bot.plan_group_emojis', []);

        if ($group !== null && isset($emojis[$group])) {
            return (string) $emojis[$group];
        }

        return (string) ($emojis['default'] ?? '📱');
    }

    /**
     * The smallest wallet top-up the bot accepts, in Toman.
     */
    public function minimumDeposit(): int
    {
        return (int) config('connectix_bot.wallet.minimum_deposit', 10000);
    }
}
