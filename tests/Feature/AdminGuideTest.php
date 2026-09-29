<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The guide manager: standard platform videos and links, custom entries, and
 * the upload rules legacy enforced (mp4, at most 10 MB, otherwise a link).
 */
class AdminGuideTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpPath = 'storage/app/_test_guides_'.uniqid();

        config([
            'connectix_bot.app_name' => 'Acme VPN',
            'connectix_bot.panel.enabled' => false,
            'connectix_bot.guides.path' => $this->tmpPath.'/guide',
            'connectix_bot.guides.custom_path' => $this->tmpPath.'/guide/custom',
        ]);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path($this->tmpPath));

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

    private function guideDir(): string
    {
        return base_path($this->tmpPath.'/guide');
    }

    private function customDir(): string
    {
        return base_path($this->tmpPath.'/guide/custom');
    }

    public function test_the_guide_page_lists_platforms_and_custom_items(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.guides.index'))
            ->assertOk()
            ->assertSee('android')
            ->assertSee('windows')
            ->assertSee('راهنمای اختصاصی');
    }

    public function test_uploading_a_video_writes_mp4_and_drops_a_previous_link(): void
    {
        File::ensureDirectoryExists($this->guideDir());
        File::put($this->guideDir().'/android.txt', 'https://example.com');

        $video = UploadedFile::fake()->create('android.mp4', 1024, 'video/mp4');

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.guides.store'), ['guide_android_video' => $video])
            ->assertRedirect();

        $this->assertFileExists($this->guideDir().'/android.mp4');
        $this->assertFileDoesNotExist($this->guideDir().'/android.txt');
    }

    public function test_an_oversized_video_is_refused(): void
    {
        File::ensureDirectoryExists($this->guideDir());

        $video = UploadedFile::fake()->create('android.mp4', 11 * 1024, 'video/mp4');

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.guides.store'), ['guide_android_video' => $video])
            ->assertRedirect()
            ->assertSessionHas('guide_errors');

        $this->assertFileDoesNotExist($this->guideDir().'/android.mp4');
    }

    public function test_a_link_replaces_the_video_for_a_platform(): void
    {
        File::ensureDirectoryExists($this->guideDir());
        File::put($this->guideDir().'/use.mp4', 'fake-video-bytes');

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.guides.store'), ['guide_use_link' => 'https://example.com/use'])
            ->assertRedirect();

        $this->assertFileDoesNotExist($this->guideDir().'/use.mp4');
        $this->assertFileExists($this->guideDir().'/use.txt');
        $this->assertStringEqualsFile($this->guideDir().'/use.txt', 'https://example.com/use');
    }

    public function test_an_invalid_link_is_refused(): void
    {
        File::ensureDirectoryExists($this->guideDir());

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.guides.store'), ['guide_use_link' => 'not a url'])
            ->assertRedirect()
            ->assertSessionHas('guide_errors');

        $this->assertFileDoesNotExist($this->guideDir().'/use.txt');
    }

    public function test_a_custom_entry_with_a_link_is_saved_under_a_sanitised_title(): void
    {
        File::ensureDirectoryExists($this->customDir());

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.guides.store'), [
                'custom_title' => 'راهنمای اتصال V2Box',
                'custom_link' => 'https://example.com/v2box',
            ])
            ->assertRedirect();

        $this->assertFileExists($this->customDir().'/راهنمای اتصال V2Box.txt');
    }

    public function test_a_custom_entry_with_a_video_is_saved(): void
    {
        File::ensureDirectoryExists($this->customDir());

        $video = UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4');

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.guides.store'), [
                'custom_title' => 'My Guide',
                'custom_video' => $video,
            ])
            ->assertRedirect();

        $this->assertFileExists($this->customDir().'/My Guide.mp4');
    }

    public function test_a_standard_guide_can_be_deleted(): void
    {
        File::ensureDirectoryExists($this->guideDir());
        File::put($this->guideDir().'/linux.txt', 'https://example.com');

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.guides.destroy'), ['type' => 'platform', 'name' => 'linux'])
            ->assertRedirect();

        $this->assertFileDoesNotExist($this->guideDir().'/linux.txt');
    }

    public function test_a_custom_guide_can_be_deleted(): void
    {
        File::ensureDirectoryExists($this->customDir());
        File::put($this->customDir().'/My Guide.mp4', 'bytes');

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.guides.destroy'), ['type' => 'custom', 'name' => 'My Guide'])
            ->assertRedirect();

        $this->assertFileDoesNotExist($this->customDir().'/My Guide.mp4');
    }
}
