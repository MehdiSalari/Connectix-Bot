<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Services\Broadcast\BroadcastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The broadcast fan-out: job persistence, the resume counter, failure
 * handling and the Server-Sent Events progress stream that performs the
 * sends, ported from broadcast_start.php / broadcast_progress.php.
 */
class AdminBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.app_name' => 'Acme VPN',
            'connectix_bot.panel.enabled' => false,
            'connectix_bot.broadcast.delay_us' => 0,
        ]);

        Http::preventStrayRequests();

        File::ensureDirectoryExists(storage_path('app/broadcast'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/broadcast'));

        parent::tearDown();
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'email' => 'admin@acme.test',
            'password' => 'secret-pass',
            'token' => 'panel-token-a',
            'chat_id' => '10',
            'role' => AdminRole::Admin,
        ]);
    }

    private function makeUser(string $chatId): User
    {
        return User::query()->create([
            'chat_id' => $chatId,
            'telegram_id' => 'u_'.$chatId,
            'name' => 'User '.$chatId,
            'created_at' => now(),
        ]);
    }

    private function broadcast(): BroadcastService
    {
        return app(BroadcastService::class);
    }

    public function test_it_sends_to_every_user_and_cleans_up(): void
    {
        $this->makeUser('553');
        $this->makeUser('554');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $service = $this->broadcast();
        $service->persist('سلام به همه', null, '10', false);

        $this->assertSame(2, count($service->targets()));
        $this->assertNotNull($service->pending());
        $this->assertSame(0, $service->progress());

        $progress = [];

        $service->run(
            function (string $chatId, bool $ok, string $error, int $done, int $total) use (&$progress): void {
                $progress[] = [$chatId, $ok, $done, $total];
            },
            function (int $total): void {}
        );

        $this->assertCount(2, $progress);
        $this->assertSame([['553', true, 1, 2], ['554', true, 2, 2]], $progress);

        Http::assertSentCount(2);

        // The job and the counter are gone after a completed run.
        $this->assertNull($service->pending());
        $this->assertSame(0, $service->progress());
    }

    public function test_test_mode_sends_only_to_the_admin(): void
    {
        $this->makeUser('553');
        $this->makeUser('554');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $service = $this->broadcast();
        $service->persist('پیام', null, '10', true);

        $sent = [];

        $service->run(
            function (string $chatId, bool $ok) use (&$sent): void {
                $sent[] = $chatId;
            },
            function (int $total): void {}
        );

        $this->assertSame(['10'], $sent);
        Http::assertSentCount(1);
    }

    public function test_the_test_button_delivers_immediately_without_a_job(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $this->postingStart(['message' => 'پیش‌نمایش پیام', 'test' => '1'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('test', true)
            ->assertJsonPath('message', 'تست با موفقیت ارسال شد!');

        // The test send is immediate, so nothing is left waiting for a stream.
        $this->assertNull($this->broadcast()->pending());

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request) => $request['chat_id'] === '10'
            && $request['text'] === 'پیش‌نمایش پیام');
    }

    public function test_a_failed_test_send_reports_the_telegram_error(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: chat not found',
            ], 400),
        ]);

        $this->postingStart(['message' => 'پیش‌نمایش', 'test' => '1'])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'خطا در ارسال تست')
            ->assertJsonPath('description', 'Bad Request: chat not found');

        $this->assertNull($this->broadcast()->pending());
    }

    public function test_a_test_send_deletes_its_media(): void
    {
        // A real `ftyp` box so finfo reports video/mp4, like a real upload.
        $video = UploadedFile::fake()->createWithContent(
            'preview.mp4',
            "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08free"
        );

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.broadcast.start'), [
                'message' => 'پیش‌نمایش ویدیو',
                'media' => $video,
                'test' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertCount(0, glob(storage_path('app/broadcast/*')) ?: []);
    }

    public function test_a_flood_controlled_recipient_is_retried(): void
    {
        config([
            'connectix_bot.broadcast.flood_retry_after_cap' => 0,
            'connectix_bot.broadcast.flood_retries' => 1,
        ]);

        $this->makeUser('553');

        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => false,
                    'error_code' => 429,
                    'description' => 'Too Many Requests: retry after 3',
                    'parameters' => ['retry_after' => 3],
                ], 429)
                ->push(['ok' => true, 'result' => []], 200),
        ]);

        $service = $this->broadcast();
        $service->persist('پیام', null, '10', false);

        $results = [];

        $service->run(
            function (string $chatId, bool $ok, string $error) use (&$results): void {
                $results[] = [$chatId, $ok, $error];
            },
            function (int $total): void {}
        );

        // The 429 did not cost the recipient: it was retried and delivered.
        $this->assertSame([['553', true, '']], $results);
        Http::assertSentCount(2);
    }

    public function test_a_flood_control_beyond_the_retry_budget_is_reported(): void
    {
        config([
            'connectix_bot.broadcast.flood_retry_after_cap' => 0,
            'connectix_bot.broadcast.flood_retries' => 1,
        ]);

        $this->makeUser('553');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 429,
                'description' => 'Too Many Requests: retry after 30',
                'parameters' => ['retry_after' => 30],
            ], 429),
        ]);

        $service = $this->broadcast();
        $service->persist('پیام', null, '10', false);

        $results = [];

        $service->run(
            function (string $chatId, bool $ok, string $error) use (&$results): void {
                $results[] = [$chatId, $ok, $error];
            },
            function (int $total): void {}
        );

        $this->assertSame([['553', false, 'Too Many Requests: retry after 30']], $results);
        Http::assertSentCount(2);
    }

    public function test_a_failed_recipient_does_not_stop_the_loop(): void
    {
        $this->makeUser('553');
        $this->makeUser('554');

        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => []], 200)
                ->push(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 200),
        ]);

        $service = $this->broadcast();
        $service->persist('پیام', null, '10', false);

        $results = [];

        $service->run(
            function (string $chatId, bool $ok, string $error) use (&$results): void {
                $results[] = [$chatId, $ok, $error];
            },
            function (int $total): void {}
        );

        $this->assertSame([
            ['553', true, ''],
            ['554', false, 'Forbidden: bot was blocked by the user'],
        ], $results);

        $this->assertNull($service->pending());
    }

    public function test_a_resumed_run_skips_already_sent_recipients(): void
    {
        $this->makeUser('553');
        $this->makeUser('554');
        $this->makeUser('555');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $service = $this->broadcast();
        $service->persist('پیام', null, '10', false);

        File::put(storage_path('app/broadcast/broadcast_done.stamp'), '2');

        $sent = [];

        $service->run(
            function (string $chatId, bool $ok) use (&$sent): void {
                $sent[] = $chatId;
            },
            function (int $total): void {}
        );

        // The first two recipients were already done; only the third is sent.
        $this->assertSame(['555'], $sent);
        Http::assertSentCount(1);
    }

    public function test_the_start_endpoint_persists_the_job_and_returns_the_progress_url(): void
    {
        $this->postingStart(['message' => 'آگهی فروش'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('progress_url', route('admin.broadcast.progress'));

        $this->assertNotNull($this->broadcast()->pending());
    }

    public function test_the_start_endpoint_requires_a_message(): void
    {
        $this->postingStart(['message' => ''])
            ->assertSessionHasErrors('message');

        $this->assertNull($this->broadcast()->pending());
    }

    public function test_media_upload_is_parked_next_to_the_job(): void
    {
        $this->makeUser('553');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        // A real `ftyp` box so finfo reports video/mp4, exactly like a real
        // upload would, and the fan-out picks sendVideo over sendDocument.
        $video = UploadedFile::fake()->createWithContent(
            'promo.mp4',
            "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08free"
        );

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.broadcast.start'), [
                'message' => 'ویدیو تبلیغاتی',
                'media' => $video,
            ])
            ->assertOk();

        $job = $this->broadcast()->pending();

        $this->assertNotNull($job);
        $this->assertNotNull($job['media']);
        $this->assertFileExists($job['media']);

        $this->broadcast()->run(static function (): void {}, static function (): void {});

        $urls = Http::recorded()
            ->map(static fn (array $pair): string => $pair[0]->url())
            ->all();

        $this->assertContains('https://api.telegram.org/bottest-token/sendVideo', $urls);
    }

    public function test_the_progress_stream_reports_each_recipient_and_finishes(): void
    {
        $this->makeUser('553');
        $this->makeUser('554');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.broadcast.progress', ['with_secret' => '1']))
            ->assertOk();

        $this->assertStringContainsString(
            'text/event-stream',
            (string) $response->headers->get('content-type'),
        );
    }

    private function postingStart(array $payload)
    {
        return $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.broadcast.start'), $payload);
    }
}
