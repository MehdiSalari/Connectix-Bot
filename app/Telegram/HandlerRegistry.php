<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Models\User;
use App\Telegram\Contracts\UpdateHandler;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * The ordered list of update handlers.
 *
 * The list is explicit rather than discovered so the precedence that legacy
 * relied on stays visible and reviewable: media and state driven flows run
 * before commands, and the catch-all handler always comes last.
 *
 * Handlers are resolved through the container on every request, which keeps
 * them swappable in tests.
 */
class HandlerRegistry
{
    /**
     * Ordered handler class names.
     *
     * Filled in as the corresponding features are ported; the routes simply
     * fall through until then, which is what legacy did with its own guards.
     *
     * @var array<int, class-string<UpdateHandler>>
     */
    private const HANDLERS = [
        \App\Telegram\Handlers\StartHandler::class,
        \App\Telegram\Handlers\MainMenuHandler::class,
        \App\Telegram\Handlers\AccountHandler::class,
        \App\Telegram\Handlers\FreeTestHandler::class,
        \App\Telegram\Handlers\PurchaseHandler::class,
        \App\Telegram\Handlers\RenewHandler::class,
        \App\Telegram\Handlers\PaymentHandler::class,
        \App\Telegram\Handlers\CouponHandler::class,
        \App\Telegram\Handlers\WalletHandler::class,
        \App\Telegram\Handlers\AddAccountHandler::class,
        \App\Telegram\Handlers\ShareContactHandler::class,
        \App\Telegram\Handlers\GuideHandler::class,
        \App\Telegram\Handlers\SupportHandler::class,
        \App\Telegram\Handlers\AdminHandler::class,
    ];

    /**
     * @param  array<int, class-string<UpdateHandler>>  $handlers  Defaults to
     *                                                              {@see self::HANDLERS}.
     *                                                             Injecting the
     *                                                             list keeps the
     *                                                             dispatch path
     *                                                             testable.
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $handlers = self::HANDLERS,
    ) {
    }

    /**
     * Handlers resolved from the container, in precedence order.
     *
     * A handler that cannot be built is skipped with a log line instead of
     * breaking the whole update: a broken optional feature must not stop the
     * bot from answering a /start.
     *
     * @return array<int, UpdateHandler>
     */
    public function resolve(): array
    {
        $handlers = [];

        foreach ($this->handlers as $class) {
            if (! class_exists($class)) {
                continue;
            }

            try {
                $handlers[] = $this->container->make($class);
            } catch (\Throwable $e) {
                Log::error('A Telegram update handler could not be resolved.', [
                    'handler' => $class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $handlers;
    }

    /**
     * The first handler that claims the update, or null when none does.
     */
    public function firstSupporting(TelegramUpdate $update, User $user): ?UpdateHandler
    {
        foreach ($this->resolve() as $handler) {
            if ($handler->supports($update, $user)) {
                return $handler;
            }
        }

        return null;
    }
}
