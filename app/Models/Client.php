<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\JalaliCalendar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Connectix panel client linked to a Telegram account.
 *
 * Legacy table: `clients`. The primary key is the external UUID assigned by the
 * Connectix seller API, therefore `$incrementing` is disabled and the key is a
 * string.
 */
class Client extends Model
{
    protected $table = 'clients';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'count_of_devices' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The status shown next to an account in the profile list.
     *
     * The plan flags the panel reports win, because that is the rule the
     * legacy bot's own account list used: an active plan is فعال, a queued one
     * is در صف, and no service at all is غیرفعال - even when the expiry date
     * has not caught up. Until a row's plans have been read the paid window is
     * the fallback, and a row with neither answer reads نامشخص rather than a
     * guess.
     *
     * @return array{label: string, tone: string} tone is a `.badge` modifier
     */
    public function statusBadge(): array
    {
        $byPlan = match ($this->plan_status) {
            'active' => ['label' => 'فعال', 'tone' => 'ok'],
            'queued' => ['label' => 'در صف', 'tone' => 'wait'],
            'inactive' => ['label' => 'غیرفعال', 'tone' => 'no'],
            default => null,
        };

        if ($byPlan !== null) {
            return $byPlan;
        }

        $withinWindow = $this->withinExpiryWindow();

        if ($withinWindow === null) {
            return ['label' => 'نامشخص', 'tone' => ''];
        }

        return ['label' => $withinWindow ? 'فعال' : 'غیرفعال', 'tone' => $withinWindow ? 'ok' : 'no'];
    }

    /**
     * Whether the paid window the panel reports has not ended yet.
     *
     * Returns null when the expiry is unknown (an account synced before the
     * column existed, or a panel record without one), and converts the panel's
     * Jalali timestamp before comparing it with now.
     */
    public function withinExpiryWindow(): ?bool
    {
        if (blank($this->expire_date)) {
            return null;
        }

        $expiresAt = JalaliCalendar::parseTimestamp($this->expire_date);

        if ($expiresAt === null) {
            return null;
        }

        return Carbon::parse($expiresAt)->isFuture();
    }

    /**
     * Reduce the panel's plan list to the three states the legacy account list
     * showed: the first active plan, otherwise the first queued one, otherwise
     * no service at all.
     *
     * @param  array<mixed>  $plans
     */
    public static function planStatusFromPlans(array $plans): string
    {
        $queued = false;

        foreach ($plans as $plan) {
            if (! is_array($plan)) {
                continue;
            }

            if ((int) ($plan['is_active'] ?? 0) === 1) {
                return 'active';
            }

            if (! empty($plan['is_in_queue'])) {
                $queued = true;
            }
        }

        return $queued ? 'queued' : 'inactive';
    }

    /**
     * The owning Telegram user. Legacy uses `ON DELETE SET NULL`, so this may
     * legitimately be absent for clients imported from the seller panel.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
