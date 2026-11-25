<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ReceiptSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $username = 'manager'): void
    {
        $this->seed();
        $this->actingAs(User::where('username', $username)->firstOrFail());
    }

    private function postAction(string $path, array $data): TestResponse
    {
        return $this->postJson('/api/v1/'.$path, $data, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function format(array $overrides = []): array
    {
        return ['receipt_title' => 'GUEST BILL', 'footer' => "Thank you!\nSee you again.", 'receipt_paper_width' => 80, ...$overrides];
    }

    public function test_manager_can_upload_replace_and_remove_business_logo_without_affecting_other_businesses(): void
    {
        $this->signIn();
        $other = DB::table('businesses')->insertGetId(['name' => 'Other', 'code' => 'other-logo', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('restaurant_settings')->insert(['business_id' => $other, 'name' => 'Other']);
        $logo = UploadedFile::fake()->image('logo.png', 160, 80);
        $expected = 'data:image/png;base64,'.base64_encode(file_get_contents($logo->getRealPath()));

        $this->postAction('manage/receipts', $this->format(['image' => $logo]))->assertOk();
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('settings.logo_data_url', $expected);
        $this->assertDatabaseHas('restaurant_settings', ['business_id' => $other, 'logo_data_url' => null]);
        $this->postAction('manage/receipts', $this->format(['logo_data_url' => 'https://untrusted.test/logo.svg']))->assertOk();
        $this->assertDatabaseHas('restaurant_settings', ['business_id' => 1, 'logo_data_url' => $expected]);
        $replacement = UploadedFile::fake()->image('replacement.png', 80, 80);
        $replacementData = 'data:image/png;base64,'.base64_encode(file_get_contents($replacement->getRealPath()));
        $this->postAction('manage/receipts', $this->format(['image' => $replacement]))->assertOk();
        $this->assertDatabaseHas('restaurant_settings', ['business_id' => 1, 'logo_data_url' => $replacementData]);
        $this->postAction('manage/receipts', $this->format(['remove_logo' => true]))->assertOk();
        $this->assertDatabaseHas('restaurant_settings', ['business_id' => 1, 'logo_data_url' => null]);
    }

    public function test_logo_upload_validation_preserves_the_previous_logo(): void
    {
        $this->signIn();
        foreach ([UploadedFile::fake()->create('logo.png', 257, 'image/png'), UploadedFile::fake()->image('wide.png', 1025, 20), UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')] as $invalid) {
            $this->postAction('manage/receipts', $this->format(['image' => $invalid]))->assertUnprocessable()->assertJsonValidationErrors('image');
        }
        $this->assertDatabaseHas('restaurant_settings', ['business_id' => 1, 'logo_data_url' => null]);
    }

    public function test_completed_receipts_preserve_their_business_logo_after_replacement_and_removal(): void
    {
        $this->signIn();
        $logo = UploadedFile::fake()->image('logo.png', 160, 80);
        $expected = 'data:image/png;base64,'.base64_encode(file_get_contents($logo->getRealPath()));
        $this->postAction('manage/receipts', $this->format(['image' => $logo]))->assertOk();
        $order = $this->postAction('orders', ['type' => 'takeaway'])->assertOk()->json();
        $order = $this->postAction("orders/{$order['id']}/add-item", ['version' => $order['version'], 'menu_item_id' => 1, 'quantity' => 1])->assertOk()->json();
        $order = $this->postAction("orders/{$order['id']}/submit", ['version' => $order['version']])->assertOk()->json();
        $order = $this->postAction("orders/{$order['id']}/pay", ['version' => $order['version'], 'method' => 'cash', 'tendered' => 10000])->assertOk()->json();
        $this->assertSame($expected, $order['payment']['restaurant_snapshot']['logo_data_url']);
        $this->postAction('manage/receipts', $this->format(['image' => UploadedFile::fake()->image('new.png', 80, 80)]))->assertOk();
        $this->postAction('manage/receipts', $this->format(['remove_logo' => true]))->assertOk();
        $this->getJson('/api/v1/orders/'.$order['id'])->assertOk()
            ->assertJsonPath('payment.restaurant_snapshot.logo_data_url', $expected)
            ->assertJsonPath('receipts.0.restaurant_snapshot.logo_data_url', $expected);
    }

    #[TestWith([56])]
    #[TestWith([58])]
    #[TestWith([80])]
    #[TestWith([88])]
    #[TestWith([112])]
    #[TestWith([86])]
    public function test_manager_can_save_receipt_formats_and_custom_widths(int $width): void
    {
        $this->signIn();
        $format = $this->format(['receipt_paper_width' => $width]);
        $this->postAction('manage/receipts', [...$format, 'name' => 'Unexpected restaurant change'])->assertOk();
        $this->assertDatabaseHas('restaurant_settings', ['id' => 1, ...$format, 'name' => 'Little Lemon']);
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('settings.receipt_paper_width', $width)
            ->assertJsonPath('settings.receipt_title', 'GUEST BILL')->assertJsonPath('settings.footer', "Thank you!\nSee you again.");
    }

    public function test_title_and_footer_can_be_hidden_and_general_settings_preserve_receipt_format(): void
    {
        $this->signIn();
        $this->postAction('manage/receipts', $this->format(['receipt_title' => '', 'footer' => '', 'receipt_paper_width' => 58]))->assertOk();
        $this->postAction('manage/settings', ['name' => 'Updated restaurant', 'currency' => 'MMK', 'timezone' => 'Asia/Yangon', 'contact' => '123', 'other_payment_label' => 'QR'])->assertOk();
        $this->assertDatabaseHas('restaurant_settings', ['id' => 1, 'name' => 'Updated restaurant', 'receipt_title' => '', 'footer' => '', 'receipt_paper_width' => 58]);
    }

    public function test_paid_receipt_keeps_its_original_format_after_settings_change(): void
    {
        $this->signIn();
        $this->postAction('manage/receipts', $this->format(['receipt_paper_width' => 56]))->assertOk();
        $order = $this->postAction('orders', ['type' => 'takeaway'])->assertOk()->json();
        $order = $this->postAction("orders/{$order['id']}/add-item", ['version' => $order['version'], 'menu_item_id' => 1, 'quantity' => 1])->assertOk()->json();
        $order = $this->postAction("orders/{$order['id']}/submit", ['version' => $order['version']])->assertOk()->json();
        $order = $this->postAction("orders/{$order['id']}/pay", ['version' => $order['version'], 'method' => 'cash', 'tendered' => 10000])->assertOk()->json();
        $this->assertSame(56, $order['payment']['restaurant_snapshot']['receipt_paper_width']);
        $this->assertSame('GUEST BILL', $order['payment']['restaurant_snapshot']['receipt_title']);
        $this->postAction('manage/receipts', $this->format(['receipt_title' => 'NEW FORMAT', 'footer' => 'New footer', 'receipt_paper_width' => 88]))->assertOk();
        $this->getJson('/api/v1/orders/'.$order['id'])->assertOk()
            ->assertJsonPath('payment.restaurant_snapshot.receipt_paper_width', 56)
            ->assertJsonPath('payment.restaurant_snapshot.receipt_title', 'GUEST BILL')
            ->assertJsonPath('payment.restaurant_snapshot.footer', "Thank you!\nSee you again.");
    }

    public function test_receipts_created_before_format_settings_remain_readable(): void
    {
        $this->signIn();
        $order = $this->postAction('orders', ['type' => 'takeaway'])->assertOk()->json();
        $order = $this->postAction("orders/{$order['id']}/add-item", ['version' => $order['version'], 'menu_item_id' => 1, 'quantity' => 1])->assertOk()->json();
        $order = $this->postAction("orders/{$order['id']}/submit", ['version' => $order['version']])->assertOk()->json();
        $order = $this->postAction("orders/{$order['id']}/pay", ['version' => $order['version'], 'method' => 'cash', 'tendered' => 10000])->assertOk()->json();
        $snapshot = $order['payment']['restaurant_snapshot'];
        unset($snapshot['receipt_title'], $snapshot['receipt_paper_width']);
        DB::table('payments')->where('order_id', $order['id'])->update(['restaurant_snapshot' => json_encode($snapshot)]);
        $this->getJson('/api/v1/orders/'.$order['id'])->assertOk()->assertJsonPath('payment.restaurant_snapshot.name', 'Little Lemon')
            ->assertJsonMissingPath('payment.restaurant_snapshot.receipt_paper_width');
    }

    #[TestWith(['receipt_paper_width', 39])]
    #[TestWith(['receipt_paper_width', 121])]
    #[TestWith(['receipt_paper_width', 80.5])]
    #[TestWith(['receipt_paper_width', '80mm'])]
    public function test_invalid_widths_return_422(string $field, mixed $value): void
    {
        $this->signIn();
        $this->postAction('manage/receipts', $this->format([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseHas('restaurant_settings', ['id' => 1, 'receipt_paper_width' => 80]);
    }

    public function test_missing_or_overlong_fields_return_422(): void
    {
        $this->signIn();
        $this->postAction('manage/receipts', [])->assertUnprocessable()->assertJsonValidationErrors(['receipt_title', 'footer', 'receipt_paper_width']);
        $this->postAction('manage/receipts', $this->format(['receipt_title' => str_repeat('x', 101), 'footer' => str_repeat('x', 256)]))->assertUnprocessable()->assertJsonValidationErrors(['receipt_title', 'footer']);
    }

    #[TestWith(['waiter'])]
    #[TestWith(['cashier'])]
    #[TestWith(['kitchen'])]
    public function test_non_managers_cannot_change_receipt_settings(string $role): void
    {
        $this->signIn($role);
        $this->postAction('manage/receipts', $this->format())->assertForbidden();
    }

    public function test_guests_cannot_change_receipt_settings(): void
    {
        $this->postAction('manage/receipts', $this->format())->assertUnauthorized();
    }
}
