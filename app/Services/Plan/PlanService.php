<?php

declare(strict_types=1);

namespace App\Services\Plan;

use App\Exceptions\ConnectixApiException;
use App\Services\Connectix\ConnectixService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Seller plan catalogue and the Connectix plan title grammar.
 *
 * Port of getSellerPlans(), getSellerPlanGroupName(), planMatchesGroup(),
 * getAvailableDeviceCountsFromPlans(), parsePlanTitle(), approximateDays(),
 * parseType() and parseTypeWithEmoji().
 *
 * The catalogue is cached because it is read on nearly every callback and the
 * panel changes rarely. A failure to reach the panel is logged and the stale
 * cache is used, so a short outage does not break the bot.
 */
class PlanService
{
    /**
     * Group names the bot supports, in the order legacy declared them.
     */
    public const SUPPORTED_GROUPS = [
        'default',
        'Sublink',
        'Economic',
        'Static IP',
        'Iran Access',
        'Business Class',
        'BCSublink',
    ];

    /**
     * The plan title grammar.
     *
     * Examples:
     *   (1x) 30M
     *   (2x) 50GB-3M
     *   (1x) Unlimited-1Y
     *   (1x)Free-1W
     *   (1x) 30M + 3D
     *   (1x) 30GB-1M + 7D Sublink
     *   (2x) 100GB-1M + 3D BCSublink
     */
    private const TITLE_PATTERN = '/^\((\d+)x\)\s*(Free-)?(?:([\d.]+)GB-)?(?:Unlimited-)?(\d+)([WMYD])?(?:\s*\+\s*(\d+)D)?\s*(.*)$/';

    public function __construct(
        private readonly ConnectixService $connectix,
    ) {}

    // -----------------------------------------------------------------
    // Catalogue
    // -----------------------------------------------------------------

    /**
     * Raw payload of `/v1/seller/seller-plans`.
     *
     * @return array<string, mixed>|null Null when the panel cannot be reached.
     */
    public function payload(): ?array
    {
        $cacheKey = 'connectix_bot.seller_plans';

        try {
            return Cache::remember(
                $cacheKey,
                now()->addMinutes(5),
                fn (): array => $this->connectix->getSellerPlansPayload(),
            );
        } catch (ConnectixApiException $e) {
            Log::error('Failed to fetch the seller plan catalogue.', [
                'error' => $e->getMessage(),
            ]);

            $stale = Cache::get($cacheKey);

            return is_array($stale) ? $stale : null;
        }
    }

    /**
     * Every plan, flattened out of their groups.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allPlans(): array
    {
        $payload = $this->payload();

        if ($payload === null || ! isset($payload['seller_plan_group'])) {
            return [];
        }

        $plans = [];

        foreach ($payload['seller_plan_group'] as $group) {
            foreach (($group['seller_plans'] ?? []) as $plan) {
                $plans[] = $plan;
            }
        }

        return $plans;
    }

    /**
     * Plans of a group, filtered to the ones the bot is allowed to sell.
     *
     * Port of the named branches of `getSellerPlans()`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function plansForGroup(string $groupName): array
    {
        return array_values(array_filter(
            $this->allPlans(),
            fn (array $plan): bool => $this->planMatchesGroup($plan, $groupName),
        ));
    }

    /**
     * Free trial plans offered through the bot.
     *
     * Port of `getSellerPlans('free')`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function freeTrialPlans(): array
    {
        return array_values(array_filter(
            $this->allPlans(),
            fn (array $plan): bool => ($plan['is_displayed_in_robot'] ?? false) == true
                && ($plan['type'] ?? '') === 'Free',
        ));
    }

    /**
     * Every premium plan the bot may sell, across all groups.
     *
     * Port of `getSellerPlans('all-bot')`, used to resolve a plan id back to
     * its record when a payment is settled.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sellablePlans(): array
    {
        return array_values(array_filter(
            $this->allPlans(),
            fn (array $plan): bool => ($plan['is_displayed_in_robot'] ?? false) == true
                && ($plan['type'] ?? '') === 'Premium',
        ));
    }

    /**
     * Groups the bot offers, keeping only those that contain at least one
     * sellable plan.
     *
     * Port of `getSellerPlans('group')`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableGroups(): array
    {
        $payload = $this->payload();

        if ($payload === null) {
            return [];
        }

        $groups = $payload['groups'] ?? null;

        if (! is_array($groups)) {
            return [];
        }

        $plans = $this->allPlans();

        $result = [];

        foreach ($groups as $group) {
            $name = $group['name'] ?? null;

            if ($name === null || ! in_array($name, self::SUPPORTED_GROUPS, true)) {
                continue;
            }

            $hasValidPlan = false;

            foreach ($plans as $plan) {
                if ($this->planMatchesGroup($plan, $name)) {
                    $hasValidPlan = true;
                    break;
                }
            }

            if ($hasValidPlan) {
                $result[] = $group;
            }
        }

        return $result;
    }

    /**
     * Find a plan by its id anywhere in the catalogue.
     *
     * The legacy default branch of `getSellerPlans()` did exactly this.
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $planId): ?array
    {
        foreach ($this->allPlans() as $plan) {
            if ((string) ($plan['id'] ?? '') === $planId) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Find a plan by its exact title anywhere in the catalogue.
     *
     * Port of the `plan` branch of `renew()`, which matched
     * `$plan['title'] === $planTitle` over every plan the bot could see. The
     * title is what an account's own plan name carries, which is why the
     * renewal flow looks plans up this way instead of by id.
     *
     * @return array<string, mixed>|null
     */
    public function findByTitle(string $title): ?array
    {
        foreach ($this->allPlans() as $plan) {
            if (($plan['title'] ?? null) === $title) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Find a sellable plan by id, used when settling a payment.
     *
     * @return array<string, mixed>|null
     */
    public function findSellableById(string $planId): ?array
    {
        foreach ($this->sellablePlans() as $plan) {
            if ((string) ($plan['id'] ?? '') === $planId) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Device counts that actually have plans, ascending.
     *
     * Port of `getAvailableDeviceCountsFromPlans()`.
     *
     * @param  array<int, array<string, mixed>>  $plans
     * @return array<int, int>
     */
    public function availableDeviceCounts(array $plans): array
    {
        $counts = [];

        foreach ($plans as $plan) {
            $devices = isset($plan['count_of_devices']) ? (int) $plan['count_of_devices'] : 0;

            if ($devices > 0) {
                $counts[$devices] = $devices;
            }
        }

        if ($counts === []) {
            return [];
        }

        ksort($counts, SORT_NUMERIC);

        return array_values($counts);
    }

    // -----------------------------------------------------------------
    // Group classification
    // -----------------------------------------------------------------

    /**
     * Display name of the group a plan belongs to.
     *
     * Port of `getSellerPlanGroupName()`. The panel's English translation is
     * preferred; when absent the title is sniffed, with `BCSublink` checked
     * before `Sublink` because the former contains the latter.
     */
    public function groupNameFor(array $plan): string
    {
        $groupName = trim((string) ($plan['group_name_translations']['en'] ?? $plan['group_name'] ?? ''));

        if ($groupName !== '') {
            return $groupName;
        }

        $title = (string) ($plan['title'] ?? '');

        return match (true) {
            stripos($title, 'BCSublink') !== false => 'BCSublink',
            stripos($title, 'Business Class') !== false => 'Business Class',
            stripos($title, 'Economic') !== false => 'Economic',
            stripos($title, 'Static IP') !== false => 'Static IP',
            stripos($title, 'Iran Access') !== false => 'Iran Access',
            stripos($title, 'Sublink') !== false => 'Sublink',
            default => 'default',
        };
    }

    /**
     * Whether a plan may be sold inside a group.
     *
     * Port of `planMatchesGroup()`. A plan must be flagged for the robot, be
     * of type Premium and belong to the group.
     */
    public function planMatchesGroup(array $plan, string $groupName): bool
    {
        if (($plan['is_displayed_in_robot'] ?? false) !== true) {
            return false;
        }

        if (($plan['type'] ?? '') !== 'Premium') {
            return false;
        }

        return $this->groupNameFor($plan) === $groupName;
    }

    // -----------------------------------------------------------------
    // Group labels
    // -----------------------------------------------------------------

    /**
     * Persian label of a group. Port of `parseType()`.
     */
    public function parseType(string $type): string
    {
        $groups = config('connectix_bot.plan_groups', []);

        return match ($type) {
            'default' => $groups['default'] ?? 'ویژه',
            'Sublink' => $groups['Sublink'] ?? 'ساب‌لینک',
            'Iran Access' => $groups['Iran Access'] ?? 'ایران اکسس',
            'Economic' => $groups['Economic'] ?? 'اقتصادی',
            'Static IP' => $groups['Static IP'] ?? 'آی‌پی ثابت',
            'Business Class' => $groups['Business Class'] ?? 'بیزینس کلاس',
            'BCSublink' => $groups['BCSublink'] ?? 'بیزینس ساب‌لینک',
            default => $type,
        };
    }

    /**
     * Group label prefixed with its emoji. Port of `parseTypeWithEmoji()`.
     */
    public function parseTypeWithEmoji(string $type): string
    {
        $name = $this->parseType($type);

        return match ($type) {
            'default' => "📱 | $name",
            'Sublink' => "🔗 | $name",
            'Economic' => "💰 | $name",
            'Static IP' => "📍 | $name",
            'Iran Access' => "🏠 | $name",
            'Business Class' => "💼 | $name",
            'BCSublink' => "💼 | $name",
            default => $name,
        };
    }

    // -----------------------------------------------------------------
    // Title grammar
    // -----------------------------------------------------------------

    /**
     * Parse a plan title into its components.
     *
     * Port of `parsePlanTitle()`. With $short the compact form used in account
     * lists is produced, otherwise the full descriptive form.
     *
     * @return array<string, mixed>
     */
    public function parsePlanTitle(string $title, bool $short = false): array
    {
        $title = trim($title);

        if (! preg_match(self::TITLE_PATTERN, $title, $matches)) {
            return [
                'raw' => $title,
                'text' => 'پلن نامشخص',
                'is_free' => false,
                'devices' => 1,
                'traffic_gb' => null,
                'period_text' => null,
                'extras' => [],
            ];
        }

        $devices = (int) $matches[1];
        $isFree = ! empty($matches[2]);
        $traffic = $matches[3] ?? null;
        $isUnlimited = str_contains($title, 'Unlimited');
        $periodNum = $matches[4];
        $periodUnit = $matches[5] ?? 'M';
        $giftDays = $matches[6] ?? null;
        $extraText = trim($matches[7] ?? '');

        $periodText = match ($periodUnit) {
            'D' => "$periodNum روز",
            'W' => "$periodNum هفته",
            'M' => "$periodNum ماه",
            'Y' => "$periodNum سال",
            default => "$periodNum ماه",
        };

        $extras = $this->parseExtras($giftDays, $extraText);

        if ($short) {
            return [
                'raw' => $title,
                'text' => $this->shortText($devices, $isFree, $isUnlimited, $traffic, $periodText, $extras, $giftDays),
                'is_free' => $isFree,
                'devices' => $devices,
                'is_unlimited' => $isUnlimited,
                'period_text' => $periodText,
                'short' => true,
            ];
        }

        $finalText = $isFree ? 'تست رایگان' : "$devices دستگاه";

        if ($isUnlimited) {
            $finalText .= ' • نامحدود';
        } elseif ($traffic) {
            $finalText .= " • {$traffic} گیگ";
        }

        $finalText .= " • $periodText";

        if ($extras !== []) {
            $finalText .= ' • '.implode(' • ', $extras);
        }

        // Legacy appends the "special" label when nothing else distinguishes
        // the plan and it is neither free nor unlimited.
        if ($extras === [] && ! $isFree && ! $isUnlimited) {
            $finalText .= ' • '.$this->parseType('default');
        }

        return [
            'raw' => $title,
            'text' => $finalText,
            'is_free' => $isFree,
            'devices' => $devices,
            // A truthy check for the same reason as the gift suffix: legacy
            // yields null (not 0.0) for a "0GB" quota.
            'traffic_gb' => $isUnlimited ? '∞' : ((bool) $traffic ? (float) $traffic : null),
            'period_text' => $periodText,
            'period_days' => $this->approximateDays((int) $periodNum, $periodUnit),
            'gift_days' => (bool) $giftDays ? (int) $giftDays : 0,
            'extras' => $extras,
            'has_sublink' => in_array('ساب‌لینک', $extras, true) || in_array('بیزینس ساب‌لینک', $extras, true),
            'has_static_ip' => in_array('آی‌پی ثابت', $extras, true),
            'is_unlimited' => $isUnlimited,
            'short' => false,
        ];
    }

    /**
     * Approximate a period in days. Port of `approximateDays()`.
     */
    public function approximateDays(int $number, string $unit): int
    {
        return match ($unit) {
            'D' => $number,
            'W' => $number * 7,
            'M' => $number * 30,
            'Y' => $number * 365,
            default => 30,
        };
    }

    /**
     * Human readable traffic, used in the plan list.
     */
    public function trafficLabel(string $title): string
    {
        $traffic = $this->parsePlanTitle($title)['traffic_gb'];

        return (string) $traffic === '∞' ? 'نامحدود' : "{$traffic} گیگ";
    }

    /**
     * Extract the feature flags from the trailing part of a title.
     *
     * @return array<int, string>
     */
    private function parseExtras(?string $giftDays, string $extraText): array
    {
        $extras = [];

        // A truthy check, not a null check: legacy drops a "+0D" suffix because
        // the string "0" is falsy in PHP, and a plan titled that way renders
        // without a gift badge.
        if ((bool) $giftDays) {
            $extras[] = "+$giftDays روز هدیه";
        }

        // BCSublink is checked first because it also contains "Sublink".
        if (str_contains($extraText, 'BCSublink')) {
            $extras[] = 'بیزینس ساب‌لینک';
        } elseif (str_contains($extraText, 'Sublink')) {
            $extras[] = 'ساب‌لینک';
        }

        if (str_contains($extraText, 'Economic')) {
            $extras[] = 'اقتصادی';
        }

        if (str_contains($extraText, 'Static IP')) {
            $extras[] = 'آی‌پی ثابت';
        }

        if (str_contains($extraText, 'Iran Access')) {
            $extras[] = 'ایران اکسس';
        }

        if (str_contains($extraText, 'Business Class')) {
            $extras[] = 'بیزینس کلاس';
        }

        return $extras;
    }

    /**
     * Compact label used in the account list keyboard.
     *
     * @param  array<int, string>  $extras
     */
    private function shortText(
        int $devices,
        bool $isFree,
        bool $isUnlimited,
        ?string $traffic,
        string $periodText,
        array $extras,
        ?string $giftDays,
    ): string {
        if ($isFree) {
            return "تست رایگان • $periodText";
        }

        if ($isUnlimited) {
            return "$devices دستگاه • نامحدود • $periodText";
        }

        $text = $traffic !== null && $traffic !== ''
            ? "$devices دستگاه • {$traffic}GB"
            : "$devices دستگاه • $periodText";

        // The group label is appended for the first matching feature only,
        // exactly as the legacy chain of elseifs did.
        if (in_array('بیزینس ساب‌لینک', $extras, true)) {
            $text .= ' • '.$this->parseType('BCSublink');
        } elseif (in_array('ساب‌لینک', $extras, true)) {
            $text .= ' • '.$this->parseType('Sublink');
        } elseif (in_array('آی‌پی ثابت', $extras, true)) {
            $text .= ' • '.$this->parseType('Static IP');
        } elseif (in_array('بیزینس کلاس', $extras, true)) {
            $text .= ' • '.$this->parseType('Business Class');
        } elseif (in_array('اقتصادی', $extras, true)) {
            $text .= ' • '.$this->parseType('Economic');
        } elseif (in_array('ایران اکسس', $extras, true)) {
            $text .= ' • '.$this->parseType('Iran Access');
        } elseif ($extras === [] || (count($extras) === 1 && $extras[0] === "+$giftDays روز هدیه")) {
            $text .= ' • '.$this->parseType('default');
        }

        return $text;
    }
}
