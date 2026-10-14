<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\BotEmoji;
use App\Models\EmojiPackImported;
use App\Services\Bot\EmojiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Admin Emojis page: registry tabs, per-emoji edit, import-pack form,
 * favourites star. Mutations require admin.role:admin; index is admin,editor.
 */
class AdminEmojisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.connectix.base_url' => 'https://api.connectix.vip',
            'connectix_bot.app_name' => 'Acme VPN',
            'connectix_bot.panel.enabled' => false,
            'connectix_bot.broadcast.delay_us' => 0,
        ]);

        Http::preventStrayRequests();
    }

    private function actingAsAdmin()
    {
        return $this->postingAs(AdminRole::Admin);
    }

    private function actingAsEditor()
    {
        return $this->postingAs(AdminRole::Editor);
    }

    private function postingAs(AdminRole $role)
    {
        return $this->actingAs($this->makeAdmin($role), 'admin');
    }

    private function makeAdmin(AdminRole $role): Admin
    {
        return Admin::query()->create([
            'email' => $role->value.'-'.uniqid().'@acme.test',
            'password' => 'secret-pass',
            'token' => 'panel-token-a',
            'chat_id' => '10',
            'role' => $role,
        ]);
    }

    /** @test */
    public function test_index_renders_sections(): void
    {
        $this->actingAsAdmin();
        $response = $this->get(route('admin.emojis.index'));
        $response->assertOk();
        $response->assertViewHas('sections');
        $response->assertViewHas('grouped');
        $response->assertViewHas('packs');
        $response->assertViewHas('packEmojis');
    }

    /** @test */
    public function test_editor_can_see_index_but_not_mutate(): void
    {
        $this->actingAsEditor();
        $this->get(route('admin.emojis.index'))->assertOk();

        $this->post(route('admin.emojis.update'), [
            'emoji_key' => 'x', 'name' => 'X', 'section' => 'custom',
            'emoji_char' => '😀',
        ])->assertForbidden();
        $this->post(route('admin.emojis.import-pack'), [
            'set_name' => 'S', 'custom_emoji_id' => '123', 'emoji_char' => '😀',
        ])->assertForbidden();
        $this->post(route('admin.emojis.favorite'), ['custom_emoji_id' => '123'])->assertForbidden();
        $this->post(route('admin.emojis.delete-pack'), ['set_name' => 'S'])->assertForbidden();
        $this->post(route('admin.emojis.destroy'), ['emoji_key' => 'x'])->assertForbidden();
    }

    /** @test */
    public function test_upsert_creates_and_updates_emoji(): void
    {
        $this->actingAsAdmin();
        $this->post(route('admin.emojis.update'), [
            'emoji_key' => 'msg_ok',
            'name' => 'پیام موفقیت',
            'section' => 'messages',
            'emoji_char' => '✅',
            'custom_emoji_id' => 'premium_123',
            'description' => 'آیکون تایید',
        ])->assertRedirect();

        $emoji = BotEmoji::query()->where('emoji_key', 'msg_ok')->first();
        $this->assertNotNull($emoji);
        $this->assertEquals('پیام موفقیت', $emoji->name);
        $this->assertEquals('messages', $emoji->section);
        $this->assertEquals('✅', $emoji->emoji_char);
        $this->assertEquals('premium_123', $emoji->custom_emoji_id);
        $this->assertEquals('آیکون تایید', $emoji->description);

        // Update preserves key, changes label
        $this->post(route('admin.emojis.update'), [
            'emoji_key' => 'msg_ok',
            'name' => 'موفقیت',
            'section' => 'messages',
            'emoji_char' => '✅',
        ])->assertRedirect();

        $emoji->refresh();
        $this->assertEquals('موفقیت', $emoji->name);
    }

    /** @test */
    public function test_delete_removes_emoji(): void
    {
        $this->actingAsAdmin();
        BotEmoji::query()->create([
            'emoji_key' => 'del_me',
            'name' => 'Del',
            'section' => 'custom',
            'emoji_char' => '🗑️',
        ]);

        $this->post(route('admin.emojis.destroy'), ['emoji_key' => 'del_me'])
            ->assertRedirect();
        $this->assertNull(BotEmoji::query()->where('emoji_key', 'del_me')->first());
    }

    /** @test */
    public function test_import_pack_creates_and_refreshes_on_same_custom_emoji_id(): void
    {
        $this->actingAsAdmin();

        // First import
        $this->post(route('admin.emojis.import-pack'), [
            'set_name' => 'Pack A',
            'custom_emoji_id' => 'id_111',
            'emoji_char' => '🌟',
        ])->assertRedirect();

        $this->assertEquals(1, EmojiPackImported::query()->where('custom_emoji_id', 'id_111')->count());
        $row = EmojiPackImported::query()->where('custom_emoji_id', 'id_111')->first();
        $this->assertEquals('Pack A', $row->set_name);
        $this->assertEquals('🌟', $row->emoji_char);

        // Re-import same custom_emoji_id refreshes the row
        $this->post(route('admin.emojis.import-pack'), [
            'set_name' => 'Pack B',
            'custom_emoji_id' => 'id_111',
            'emoji_char' => '✨',
        ])->assertRedirect();

        $this->assertEquals(1, EmojiPackImported::query()->where('custom_emoji_id', 'id_111')->count());
        $row->refresh();
        $this->assertEquals('Pack B', $row->set_name);
        $this->assertEquals('✨', $row->emoji_char);
    }

    /** @test */
    public function test_toggle_favorite_on_imported_emoji(): void
    {
        $this->actingAsAdmin();
        EmojiPackImported::query()->create([
            'set_name' => 'Test Pack',
            'custom_emoji_id' => 'fav_999',
            'emoji_char' => '⭐',
            'is_favorite' => false,
        ]);

        // First toggle -> true
        $this->post(route('admin.emojis.favorite'), ['custom_emoji_id' => 'fav_999'])
            ->assertRedirect();
        $this->assertTrue((bool) EmojiPackImported::query()->where('custom_emoji_id', 'fav_999')->first()->is_favorite);

        // Second toggle -> false
        $this->post(route('admin.emojis.favorite'), ['custom_emoji_id' => 'fav_999'])
            ->assertRedirect();
        $this->assertFalse((bool) EmojiPackImported::query()->where('custom_emoji_id', 'fav_999')->first()->is_favorite);

        // Unknown id returns null from service -> 404 style redirect with error
        $this->post(route('admin.emojis.favorite'), ['custom_emoji_id' => 'unknown'])
            ->assertRedirect();
    }

    /** @test */
    public function test_sections_list_groups_by_section(): void
    {
        $this->actingAsAdmin();
        $svc = app(EmojiService::class);

        BotEmoji::query()->create([
            'emoji_key' => 'k1', 'name' => 'One', 'section' => 'buttons', 'emoji_char' => '1️⃣',
        ]);
        BotEmoji::query()->create([
            'emoji_key' => 'k2', 'name' => 'Two', 'section' => 'messages', 'emoji_char' => '2️⃣',
        ]);
        BotEmoji::query()->create([
            'emoji_key' => 'k3', 'name' => 'Three', 'section' => '', 'emoji_char' => '3️⃣',
        ]);

        $sections = $svc->sections();
        $this->assertArrayHasKey('buttons', $sections);
        $this->assertArrayHasKey('messages', $sections);
        $this->assertArrayHasKey('custom', $sections); // empty string becomes 'custom'
        $this->assertCount(1, $sections['buttons']);
        $this->assertCount(1, $sections['messages']);
        $this->assertCount(1, $sections['custom']);
    }

    /** @test */
    public function test_pack_list_and_delete_pack(): void
    {
        $this->actingAsAdmin();
        $svc = app(EmojiService::class);

        EmojiPackImported::query()->create([
            'set_name' => 'Pack X', 'custom_emoji_id' => 'px1', 'emoji_char' => '🅰',
        ]);
        EmojiPackImported::query()->create([
            'set_name' => 'Pack X', 'custom_emoji_id' => 'px2', 'emoji_char' => '🅱',
        ]);
        EmojiPackImported::query()->create([
            'set_name' => 'Pack Y', 'custom_emoji_id' => 'py1', 'emoji_char' => '🅲',
        ]);

        $list = $svc->packList();
        $this->assertCount(2, $list);
        $names = array_column($list, 'set_name');
        $this->assertContains('Pack X', $names);
        $this->assertContains('Pack Y', $names);

        // Delete pack Y — Pack X keeps both of its rows
        $this->post(route('admin.emojis.delete-pack'), ['set_name' => 'Pack Y'])
            ->assertRedirect();
        $this->assertEquals(2, EmojiPackImported::query()->where('set_name', 'Pack X')->count());
        $this->assertEquals(0, EmojiPackImported::query()->where('set_name', 'Pack Y')->count());
    }
}
