<?php

declare(strict_types=1);

namespace App\Services\Panel;

use App\Exceptions\ConnectixApiException;
use App\Services\Connectix\ConnectixService;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the reseller's own branding and bot copy.
 *
 * The legacy project called GET /v1/seller/telegram-bot a single time during
 * installation and wrote the response to setup/bot_config.json; every later
 * read went through that file, so each reseller's install rendered its own
 * app name, support handle, channel, card details and message texts.
 *
 * This service reproduces that contract with a cache instead of a JSON file so
 * a fresh install no longer needs an installer step to become branded:
 *
 *  - local .env values always win when they are set (explicit override);
 *  - otherwise the seller panel value is used;
 *  - and if the panel is unreachable, the previously cached value is kept and
 *    the update continues with degraded branding rather than failing.
 *
 * Reads are memoised per request, and the payload is cached in the configured
 * store for `connectix_bot.panel.cache_ttl` seconds.
 */
class PanelSettingsService
{
    /**
     * Message keys the legacy installer read out of `telegramMessages`.
     *
     * @var list<string>
     */
    private const MESSAGE_KEYS = [
        'welcome_text',
        'contact_support',
        'questions_and_answers',
        'free_test_account_created',
    ];

    /**
     * Where each resolved field is configured locally, when it is at all.
     *
     * An absent entry means the field is panel-only and has no local override.
     *
     * @var array<string, string>
     */
    private const LOCAL_KEYS = [
        'app_name' => 'connectix_bot.app_name',
        'support_telegram' => 'connectix_bot.support_telegram',
        'channel_telegram' => 'connectix_bot.channel_telegram',
        'telegram_channel_id' => 'connectix_bot.telegram_channel_id',
        'card_number' => 'connectix_bot.card.number',
        'card_name' => 'connectix_bot.card.name',
    ];

    /**
     * Memoised merged payload for the current request.
     *
     * @var array<string, mixed>|null
     */
    private ?array $resolved = null;

    public function __construct(
        private readonly ConnectixService $connectix,
        private readonly Cache $cache,
    ) {}

    /**
     * The branded application name, e.g. the reseller's own product name.
     *
     * Falls back to the product name of this project so an unconfigured
     * install still says something sensible.
     */
    public function appName(): string
    {
        $name = $this->value('app_name');

        return $name !== '' ? $name : 'Connectix Bot';
    }

    /**
     * The administrator chat ids, from local config or the panel.
     *
     * @return list<string>
     */
    public function adminIds(): array
    {
        $local = $this->localAdminIds();

        if ($local !== []) {
            return $local;
        }

        $ids = [];

        foreach (['admin_id', 'admin_id_2', 'admin_id_3'] as $key) {
            $id = $this->string($this->botValue($key));

            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function supportTelegram(): string
    {
        return $this->stripAt($this->value('support_telegram'));
    }

    public function channelTelegram(): string
    {
        return $this->stripAt($this->value('channel_telegram'));
    }

    public function channelId(): ?string
    {
        $id = $this->value('telegram_channel_id');

        return $id === '' ? null : $id;
    }

    public function cardNumber(): string
    {
        return $this->value('card_number');
    }

    public function cardName(): string
    {
        return $this->value('card_name');
    }

    /**
     * The configured WebApp base URL, or null to derive it from the request.
     */
    public function webAppUrl(): ?string
    {
        $url = $this->string(config('connectix_bot.webapp_url'));

        return $url === '' ? null : $url;
    }

    /**
     * One bot message. Order of precedence: local override, seller panel,
     * and finally the built-in default supplied by the caller.
     */
    public function message(string $key, ?string $default = null): string
    {
        $override = $this->string(config("connectix_bot.messages.{$key}"));

        if ($override !== '') {
            return $override;
        }

        $fromPanel = $this->string($this->panelMessages()[$key] ?? null);

        return $fromPanel !== '' ? $fromPanel : ($default ?? '');
    }

    /**
     * All four message texts, resolved through {@see message()}.
     *
     * @param  array<string, string>  $defaults
     * @return array<string, string>
     */
    public function messages(array $defaults = []): array
    {
        $messages = [];

        foreach (self::MESSAGE_KEYS as $key) {
            $messages[$key] = $this->message($key, $defaults[$key] ?? null);
        }

        return $messages;
    }

    /**
     * The raw merged payload, mainly for the admin panel and diagnostics.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->payload();
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Merge the cached panel payload with local configuration, once per
     * request.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        return $this->resolved = $this->merge($this->panelPayload());
    }

    /**
     * The panel payload, from cache when possible.
     *
     * @return array{bot: array<string, mixed>, telegramMessages: array<string, string>}
     */
    private function panelPayload(): array
    {
        $empty = ['bot' => [], 'telegramMessages' => []];

        if (! config('connectix_bot.panel.enabled', true)) {
            return $empty;
        }

        $cacheKey = 'connectix_bot.panel.telegram_bot';
        $ttl = (int) config('connectix_bot.panel.cache_ttl', 900);

        $cached = $this->cache->get($cacheKey);

        if (is_array($cached) && ($cached['bot'] ?? null) !== null) {
            return $cached + $empty;
        }

        try {
            $fresh = $this->connectix->getTelegramBotConfig();

            $payload = [
                'bot' => $fresh['bot'],
                'telegramMessages' => $fresh['telegram_messages'],
            ];

            if ($ttl > 0) {
                $this->cache->put($cacheKey, $payload, $ttl);
            }

            return $payload;
        } catch (ConnectixApiException $e) {
            // Branding is never a reason to drop an incoming update: fall back
            // to the last known payload and carry on with degraded copy.
            Log::warning('Seller panel branding is unavailable.', [
                'error' => $e->getMessage(),
            ]);

            return $cached !== null ? $cached + $empty : $empty;
        }
    }

    /**
     * Panel values win only where the local configuration is silent.
     *
     * @param  array{bot: array<string, mixed>, telegramMessages: array<string, string>}  $panel
     * @return array<string, mixed>
     */
    private function merge(array $panel): array
    {
        $bot = $panel['bot'];

        return [
            'app_name' => $this->pick($bot['app_name'] ?? null),
            'admin_id' => $bot['admin_id'] ?? null,
            'admin_id_2' => $bot['admin_id_2'] ?? null,
            'admin_id_3' => $bot['admin_id_3'] ?? null,
            'support_telegram' => $this->stripAt($this->pick($bot['support_telegram'] ?? null)),
            'channel_telegram' => $this->stripAt($this->pick($bot['channel_telegram'] ?? null)),
            'telegram_channel_id' => $this->pick($bot['channel_id'] ?? null),
            'card_number' => $this->pick($bot['card_number'] ?? null),
            'card_name' => $this->pick($bot['card_name'] ?? null),
            'telegramMessages' => $panel['telegramMessages'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function panelMessages(): array
    {
        $messages = $this->payload()['telegramMessages'] ?? [];

        return is_array($messages) ? $messages : [];
    }

    /**
     * Local .env value when set, otherwise the seller panel value.
     */
    private function value(string $key): string
    {
        $local = $this->string(config(self::LOCAL_KEYS[$key] ?? ''));

        return $local !== '' ? $local : $this->string($this->payload()[$key] ?? null);
    }

    private function botValue(string $key): mixed
    {
        return $this->payload()[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    private function localAdminIds(): array
    {
        $ids = [];

        foreach ((array) config('connectix_bot.admin_ids', []) as $id) {
            $id = trim($this->string($id));

            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * A panel value is ignored when the seller left it blank, so a blank field
     * never overrides a local setting.
     */
    private function pick(mixed $value): string
    {
        $string = $this->string($value);

        return $string === 'null' ? '' : $string;
    }

    private function string(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return '';
        }

        return trim($value);
    }

    private function stripAt(string $value): string
    {
        return ltrim($value, '@');
    }
}
