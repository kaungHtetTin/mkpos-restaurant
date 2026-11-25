<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformBrandingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed();
        config(['realtime.enabled' => true, 'realtime.secret' => str_repeat('s', 64), 'realtime.publish_url' => 'http://realtime.test/publish']);
        Http::fake(['realtime.test/*' => Http::response('', 202)]);
    }

    public function test_public_branding_is_minimal_and_server_html_escapes_the_saved_name(): void
    {
        $name = 'Cafe $1 "Brand" <script>alert(1)</script>';
        DB::table('office_settings')->where('id', 1)->update(['name' => $name, 'support_email' => 'private@example.test']);
        $address = config('mail.from.address');
        $this->getJson('/api/v1/platform/branding')->assertOk()->assertExactJson(['name' => $name])->assertHeader('Cache-Control', 'no-store, private');
        $html = $this->get('/register')->assertOk()->getContent();
        $this->assertStringContainsString('<title>'.e($name).' · Restaurant POS</title>', $html);
        $this->assertStringContainsString('<meta name="application-name" content="'.e($name).'"/>', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertSame($name, config('app.name'));
        $this->assertSame($name, config('mail.from.name'));
        $this->assertSame($address, config('mail.from.address'));
    }

    public function test_public_socket_credentials_only_subscribe_to_public_branding_and_their_session(): void
    {
        $response = $this->getJson('/api/v1/realtime/platform')->assertOk();
        [$payload, $signature] = explode('.', $response->json('token'));
        $this->assertSame(hash_hmac('sha256', $payload, config('realtime.secret')), $signature);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertCount(2, $claims['channels']);
        $this->assertSame('platform', $claims['channels'][0]);
        $this->assertStringStartsWith('session:', $claims['channels'][1]);
    }

    public function test_office_name_save_updates_all_branding_and_publishes_a_public_invalidation(): void
    {
        $business = DB::table('businesses')->where('id', 1)->value('name');
        $admin = User::factory()->create(['business_id' => null, 'roles' => [], 'active' => true]);
        $admin->forceFill(['is_platform_admin' => true, 'office_role' => 'super_admin'])->save();
        $admin = $admin->fresh();
        $this->withSession(['office_version' => $admin->fresh()->office_version])->actingAs($admin, 'office');
        Auth::shouldUse('web');
        $this->postJson('/api/v1/office/manage/settings', ['version' => 1, 'name' => 'Shared Platform', 'timezone' => 'Asia/Yangon', 'theme_color' => '#c7a92e'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        Http::assertSent(fn ($request) => $request['channels'] === ['office', 'platform'] && $request['topic'] === 'platform');
        $this->getJson('/api/v1/platform/branding')->assertExactJson(['name' => 'Shared Platform']);
        $this->get('/office')->assertSee('<title>Shared Platform · Restaurant POS</title>', false);
        $this->assertSame($business, DB::table('businesses')->where('id', 1)->value('name'));
    }
}
