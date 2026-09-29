<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Download\DownloadLinkService;
use App\Services\Guide\GuideService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The guide menus, link files and custom items ported from
 * `getGuideLink()`, `getCustomGuideItems()` and `guideButton()`, plus the
 * download link scraper ported from `getDownloadLinks()`.
 *
 * The traversal and SSRF cases are the reason these paths are guarded now:
 * legacy built a filename straight from callback data and fetched a URL with
 * `file_get_contents()`.
 */
class GuideAndDownloadTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'connectix-guide-test';

        $this->makeDirectory($this->base.DIRECTORY_SEPARATOR.'guide');
        $this->makeDirectory($this->base.DIRECTORY_SEPARATOR.'guide'.DIRECTORY_SEPARATOR.'custom');

        config([
            'connectix_bot.downloads.source_url' => 'https://connectix.test/#download',
            'connectix_bot.downloads.cache_ttl' => 60,
            'connectix_bot.downloads.timeout' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->base);

        parent::tearDown();
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->base.DIRECTORY_SEPARATOR.$relative;

        $this->makeDirectory(dirname($path));

        file_put_contents($path, $contents);
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path.DIRECTORY_SEPARATOR.$entry;

            is_dir($full) ? $this->deleteTree($full) : @unlink($full);
        }

        @rmdir($path);
    }

    private function guide(): GuideService
    {
        return new GuideService(
            $this->base.DIRECTORY_SEPARATOR.'guide',
            $this->base.DIRECTORY_SEPARATOR.'guide'.DIRECTORY_SEPARATOR.'custom',
        );
    }

    private function downloads(): DownloadLinkService
    {
        return new DownloadLinkService('https://connectix.test/#download', 60, 5);
    }

    // -----------------------------------------------------------------
    // Guide links
    // -----------------------------------------------------------------

    public function test_it_reads_a_guide_link_file(): void
    {
        $this->write('guide/use.txt', "  https://example.com/video  \n");

        $this->assertSame('https://example.com/video', $this->guide()->linkFor('use'));
    }

    public function test_a_missing_guide_link_is_null(): void
    {
        $this->assertNull($this->guide()->linkFor('missing'));
    }

    public function test_a_link_file_holding_something_that_is_not_a_url_is_ignored(): void
    {
        $this->write('guide/ios.txt', 'not a url at all');

        $this->assertNull($this->guide()->linkFor('ios'));
    }

    public function test_a_non_http_link_is_rejected(): void
    {
        $this->write('guide/mac.txt', 'javascript:alert(1)');

        $this->assertNull($this->guide()->linkFor('mac'));
    }

    // -----------------------------------------------------------------
    // Guide traversal
    // -----------------------------------------------------------------

    public function test_it_refuses_to_traverse_out_of_the_guide_directory(): void
    {
        $this->write('secret.txt', 'https://example.com/secret');

        // Legacy would have read assets/videos/guide/../../secret.txt.
        $this->assertNull($this->guide()->linkFor('../../secret'));
        $this->assertNull($this->guide()->videoFor('../../secret'));
    }

    public function test_it_refuses_an_absolute_path(): void
    {
        $this->assertNull($this->guide()->linkFor('C:Windowswin'));
        $this->assertNull($this->guide()->linkFor('/etc/passwd'));
    }

    public function test_it_refuses_a_null_byte_in_the_action(): void
    {
        $this->assertNull($this->guide()->linkFor("use\0.txt"));
    }

    public function test_unsafe_actions_are_rejected(): void
    {
        $this->assertTrue($this->guide()->isSafeAction('use'));
        $this->assertTrue($this->guide()->isSafeAction('custom_1'));

        $this->assertFalse($this->guide()->isSafeAction(''));
        $this->assertFalse($this->guide()->isSafeAction('../use'));
        $this->assertFalse($this->guide()->isSafeAction('a/b'));
        $this->assertFalse($this->guide()->isSafeAction('..'));
    }

    // -----------------------------------------------------------------
    // Guide videos and custom items
    // -----------------------------------------------------------------

    public function test_it_finds_a_guide_video(): void
    {
        $this->write('guide/use.mp4', 'binary');

        $this->assertNotNull($this->guide()->videoFor('use'));
    }

    public function test_a_missing_guide_video_is_null(): void
    {
        $this->assertNull($this->guide()->videoFor('use'));
    }

    public function test_custom_items_are_sorted_and_classified(): void
    {
        $this->write('guide/custom/Setup.mp4', 'binary');
        $this->write('guide/custom/intro.txt', "https://example.com/intro\n");
        $this->write('guide/custom/notes.txt', 'this is not a url');

        $items = $this->guide()->customItems();

        $this->assertCount(2, $items);

        // The sort is strnatcasecmp, so it is case insensitive: "intro" sorts
        // before "Setup" even though a capital S would win an ASCII sort.
        // The custom menu indexes into this order, so it must not change.
        $this->assertSame('intro', $items[0]['title']);
        $this->assertSame('link', $items[0]['type']);
        $this->assertSame('https://example.com/intro', $items[0]['url']);
        $this->assertSame('Setup', $items[1]['title']);
        $this->assertSame('video', $items[1]['type']);
    }

    public function test_custom_items_are_empty_when_the_seller_made_none(): void
    {
        $this->assertSame([], $this->guide()->customItems());
    }

    public function test_it_parses_a_custom_action(): void
    {
        $this->assertSame(3, $this->guide()->parseCustomAction('custom_3'));
        $this->assertNull($this->guide()->parseCustomAction('custom_'));
        $this->assertNull($this->guide()->parseCustomAction('use'));
    }

    public function test_a_custom_video_is_resolved_by_index(): void
    {
        $this->write('guide/custom/first.mp4', 'binary');

        $item = $this->guide()->customVideo(0);

        $this->assertNotNull($item);
        $this->assertSame('video', $item['type']);
    }

    public function test_a_custom_link_is_not_returned_as_a_video(): void
    {
        $this->write('guide/custom/first.txt', 'https://example.com/x');

        $this->assertNull($this->guide()->customVideo(0));
    }

    public function test_an_out_of_range_custom_index_is_null(): void
    {
        $this->assertNull($this->guide()->customVideo(99));
    }

    public function test_a_guide_button_prefers_a_direct_link(): void
    {
        $this->write('guide/ios.txt', 'https://example.com/ios');

        $button = $this->guide()->button('📱 | آیفون (iOS)', 'ios');

        $this->assertSame('https://example.com/ios', $button['url']);
        $this->assertArrayNotHasKey('callback_data', $button);
    }

    public function test_a_guide_button_falls_back_to_a_callback(): void
    {
        $button = $this->guide()->button('📱 | آیفون (iOS)', 'ios');

        $this->assertSame('guide_ios', $button['callback_data']);
        $this->assertArrayNotHasKey('url', $button);
    }

    // -----------------------------------------------------------------
    // Download links
    // -----------------------------------------------------------------

    private function landingPage(): string
    {
        return <<<'HTML'
        <!DOCTYPE html>
        <html><body>
        <section id="download">
          <div class="service-box">
            <h5>Android Application</h5>
            <a href="https://example.com/android.apk">Download APK</a>
            <a href="https://example.com/android2.apk">Mirror</a>
          </div>
          <div class="service-box">
            <h5>iOS Application</h5>
            <a href="https://example.com/ios.ipa">Download IPA</a>
          </div>
        </section>
        </body></html>
        HTML;
    }

    public function test_it_scrapes_the_download_links(): void
    {
        Http::fake(['connectix.test/*' => Http::response($this->landingPage(), 200)]);

        $links = $this->downloads()->links();

        $this->assertCount(3, $links);
        $this->assertSame('Android', $links[0]['platform']);
        $this->assertSame('Download APK', $links[0]['label']);
        $this->assertSame('https://example.com/android.apk', $links[0]['url']);
        $this->assertSame('iOS', $links[2]['platform']);
    }

    public function test_it_filters_the_links_by_platform(): void
    {
        Http::fake(['connectix.test/*' => Http::response($this->landingPage(), 200)]);

        $android = $this->downloads()->links('android');

        $this->assertCount(2, $android);

        foreach ($android as $link) {
            $this->assertSame('Android', $link['platform']);
        }
    }

    public function test_an_unknown_platform_key_is_not_filtered(): void
    {
        Http::fake(['connectix.test/*' => Http::response($this->landingPage(), 200)]);

        // Legacy's `default` arm ignored the argument entirely, so a
        // capitalised or misspelt key silently returned every link. Callers
        // depend on the unfiltered list, so the behaviour is kept.
        $this->assertCount(3, $this->downloads()->links('Android'));
        $this->assertCount(3, $this->downloads()->links('nonsense'));
    }

    public function test_it_caches_the_scraped_links(): void
    {
        Http::fake(['connectix.test/*' => Http::response($this->landingPage(), 200)]);

        $this->assertCount(3, $this->downloads()->links());
        $this->assertCount(3, $this->downloads()->links());

        Http::assertSentCount(1);
    }

    public function test_a_failing_fetch_yields_no_links_instead_of_warnings(): void
    {
        Http::fake(['connectix.test/*' => Http::response('boom', 500)]);

        $this->assertSame([], $this->downloads()->links());
    }

    public function test_a_connection_failure_yields_no_links(): void
    {
        Http::fake(['connectix.test/*' => Http::failedConnection()]);

        $this->assertSame([], $this->downloads()->links());
    }

    public function test_an_empty_page_yields_no_links(): void
    {
        Http::fake(['connectix.test/*' => Http::response('', 200)]);

        $this->assertSame([], $this->downloads()->links());
    }

    public function test_a_page_without_the_download_section_yields_no_links(): void
    {
        Http::fake(['connectix.test/*' => Http::response('<html><body><p>hi</p></body></html>', 200)]);

        $this->assertSame([], $this->downloads()->links());
    }

    public function test_it_refuses_to_scrape_a_loopback_url(): void
    {
        // The SSRF guard: the source is configuration, but this is the one
        // place the bot fetches a host it does not otherwise need.
        $service = new DownloadLinkService('http://127.0.0.1/admin', 60, 5);

        Http::fake(['*' => Http::response($this->landingPage(), 200)]);

        $this->assertSame([], $service->links());
        Http::assertNothingSent();
    }

    public function test_it_refuses_to_scrape_a_private_address(): void
    {
        $service = new DownloadLinkService('http://192.168.1.1/#download', 60, 5);

        Http::fake(['*' => Http::response($this->landingPage(), 200)]);

        $this->assertSame([], $service->links());
        Http::assertNothingSent();
    }

    public function test_it_refuses_to_scrape_a_localhost_url(): void
    {
        $service = new DownloadLinkService('http://localhost:8080/#download', 60, 5);

        Http::fake(['*' => Http::response($this->landingPage(), 200)]);

        $this->assertSame([], $service->links());
        Http::assertNothingSent();
    }

    public function test_it_refuses_a_non_http_source_url(): void
    {
        $service = new DownloadLinkService('file:///etc/passwd', 60, 5);

        Http::fake(['*' => Http::response($this->landingPage(), 200)]);

        $this->assertSame([], $service->links());
        Http::assertNothingSent();
    }

    public function test_the_json_shape_matches_legacy(): void
    {
        Http::fake(['connectix.test/*' => Http::response($this->landingPage(), 200)]);

        $decoded = json_decode($this->downloads()->toJson('ios'), true);

        $this->assertIsArray($decoded);
        $this->assertSame('https://example.com/ios.ipa', $decoded[0]['url']);
    }
}
