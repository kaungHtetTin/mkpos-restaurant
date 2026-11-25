<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class OfficeRememberMeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['business_id' => null, 'is_platform_admin' => true, 'office_role' => 'super_admin', 'active' => true]);
    }

    private function remember(User $admin): Cookie
    {
        $this->withCredentials();
        $response = $this->postJson('/api/v1/office/login', ['email' => $admin->email, 'password' => 'password', 'remember' => true])->assertOk();
        $cookie = $response->getCookie(Auth::guard('office')->getRecallerName());
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $cookie->getExpiresTime(), 5);

        return $cookie;
    }

    private function forgetSession(): void
    {
        $this->app['session']->flush();
        Auth::forgetGuards();
    }

    public function test_remembered_office_login_survives_a_missing_session_without_logging_into_the_business(): void
    {
        $admin = $this->admin();
        $cookie = $this->remember($admin);
        $this->forgetSession();
        $this->withCookie($cookie->getName(), $cookie->getValue())->getJson('/api/v1/office/session')->assertOk()->assertJsonPath('user.id', $admin->id);
        $this->assertTrue(Auth::guard('office')->viaRemember());
        $this->assertGuest('web');
    }

    public function test_ordinary_office_login_does_not_issue_a_remember_cookie(): void
    {
        $admin = $this->admin();
        $this->postJson('/api/v1/office/login', ['email' => $admin->email, 'password' => 'password', 'remember' => false])
            ->assertOk()->assertCookieMissing(Auth::guard('office')->getRecallerName());
    }

    public function test_logout_revokes_the_remember_cookie_and_old_token(): void
    {
        $admin = $this->admin();
        $cookie = $this->remember($admin);
        $token = $admin->fresh()->remember_token;
        $this->withCookie($cookie->getName(), $cookie->getValue())->postJson('/api/v1/office/logout')->assertOk()->assertCookieExpired($cookie->getName());
        $this->assertNotSame($token, $admin->fresh()->remember_token);
        $this->forgetSession();
        $this->withCookie($cookie->getName(), $cookie->getValue())->getJson('/api/v1/office/session')->assertOk()->assertJsonPath('user', null);
    }

    #[TestWith(['active', false])]
    #[TestWith(['is_platform_admin', false])]
    #[TestWith(['business_id', 1])]
    public function test_remembered_cookie_cannot_restore_an_ineligible_account(string $field, mixed $value): void
    {
        $admin = $this->admin();
        $cookie = $this->remember($admin);
        $admin->forceFill([$field => $value])->save();
        $this->forgetSession();
        $this->withCookie($cookie->getName(), $cookie->getValue())->getJson('/api/v1/office/session')->assertOk()->assertJsonPath('user', null);
    }

    public function test_account_update_revokes_the_previous_remember_token(): void
    {
        $admin = $this->admin();
        $cookie = $this->remember($admin);
        $this->postJson('/api/v1/office/account', ['name' => 'Updated name', 'email' => $admin->email, 'current_password' => 'password'], ['Idempotency-Key' => 'remember-account-test'])->assertOk();
        $this->forgetSession();
        $this->withCookie($cookie->getName(), $cookie->getValue())->getJson('/api/v1/office/session')->assertOk()->assertJsonPath('user', null);
    }

    public function test_remember_flag_is_validated_and_wrong_password_does_not_issue_a_cookie(): void
    {
        $admin = $this->admin();
        $this->postJson('/api/v1/office/login', ['email' => $admin->email, 'password' => 'password', 'remember' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('remember');
        $this->postJson('/api/v1/office/login', ['email' => $admin->email, 'password' => 'wrong', 'remember' => true])->assertUnprocessable()->assertCookieMissing(Auth::guard('office')->getRecallerName());
    }
}
