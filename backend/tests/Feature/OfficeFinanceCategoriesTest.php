<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OfficeFinanceCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $book;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create(['business_id' => null, 'roles' => [], 'active' => true]);
        $this->admin->forceFill(['is_platform_admin' => true, 'office_role' => 'admin'])->save();
        $this->actingAs($this->admin, 'office');
        Auth::shouldUse('web');
        $this->book = DB::table('office_bank_books')->insertGetId(['name' => 'Test bank', 'currency' => 'MMK', 'active' => true, 'is_default' => true]);
    }

    private function category(array $data, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/office/finance/categories', $data, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function entry(array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/office/finance', ['action' => 'entry', 'kind' => 'income', 'book_id' => $this->book, 'amount' => 1000, 'currency' => 'MMK', 'description' => 'Categorized income', 'entry_date' => now('Asia/Yangon')->toDateString(), ...$extra], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_categories_support_normalized_names_versions_retry_and_unused_deletion(): void
    {
        $payload = ['action' => 'save', 'name' => '  Hosting   fees  ', 'active' => true];
        $key = (string) Str::uuid();
        $id = $this->category($payload, $key)->assertOk()->json('id');
        $this->category($payload, $key)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('office_finance_categories', 1);
        $this->assertDatabaseHas('office_finance_categories', ['id' => $id, 'name' => 'Hosting fees', 'name_key' => 'hosting fees', 'version' => 1]);
        $this->category(['action' => 'save', 'name' => 'HOSTING FEES', 'active' => true])->assertUnprocessable();
        $this->getJson('/api/v1/office/finance/categories?q=Hosting&status=active')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.entries_count', 0);
        $this->category(['action' => 'save', 'id' => $id, 'version' => 1, 'name' => 'Cloud hosting', 'active' => false])->assertOk();
        $this->category(['action' => 'delete', 'id' => $id, 'version' => 1])->assertStatus(409);
        $this->getJson('/api/v1/office/finance/categories?status=archived')->assertOk()->assertJsonPath('total', 1);
        $this->category(['action' => 'delete', 'id' => $id, 'version' => 2])->assertOk();
        $this->assertDatabaseMissing('office_finance_categories', ['id' => $id]);
        $this->assertDatabaseHas('platform_events', ['action' => 'financial_category_deleted']);
    }

    public function test_used_categories_preserve_entry_names_and_remain_filterable_after_rename_or_archive(): void
    {
        $id = $this->category(['action' => 'save', 'name' => 'Consulting', 'active' => true])->assertOk()->json('id');
        $this->entry(['category_id' => $id])->assertOk();
        $this->entry()->assertOk();
        $this->category(['action' => 'save', 'id' => $id, 'version' => 1, 'name' => 'Services', 'active' => true])->assertOk();
        $this->entry(['category_id' => $id])->assertOk();
        $this->assertDatabaseHas('office_ledger', ['category_id' => $id, 'category' => 'Consulting']);
        $this->assertDatabaseHas('office_ledger', ['category_id' => $id, 'category' => 'Services']);
        $this->getJson('/api/v1/office/finance?category_id='.$id)->assertOk()->assertJsonPath('entries.total', 2)->assertJsonPath('period.income', 2000);
        $this->getJson('/api/v1/office/finance/categories')->assertOk()->assertJsonPath('data.0.entries_count', 2);
        $this->category(['action' => 'delete', 'id' => $id, 'version' => 2])->assertUnprocessable();
        $this->category(['action' => 'save', 'id' => $id, 'version' => 2, 'name' => 'Services', 'active' => false])->assertOk();
        $this->entry(['category_id' => $id])->assertUnprocessable();
        $this->getJson('/api/v1/office/finance?category_id='.$id)->assertOk()->assertJsonPath('entries.total', 2);
        $this->category(['action' => 'save', 'id' => $id, 'version' => 3, 'name' => 'Services', 'active' => true])->assertOk();
        $this->entry(['category_id' => $id])->assertOk();
    }

    public function test_invalid_names_unknown_categories_and_arbitrary_entry_category_text_are_rejected(): void
    {
        foreach (['', '   ', str_repeat('A', 101)] as $name) {
            $this->category(['action' => 'save', 'name' => $name, 'active' => true])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $this->entry(['category_id' => 999999])->assertUnprocessable();
        $this->entry(['category' => 'Unmanaged category'])->assertUnprocessable();
        $this->category(['action' => 'save', 'id' => 999999, 'version' => 1, 'name' => 'Missing', 'active' => true])->assertNotFound();
        $this->category(['action' => 'delete', 'id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertDatabaseCount('office_ledger', 0);
    }

    public function test_business_accounts_cannot_manage_office_finance_categories(): void
    {
        Auth::guard('office')->logout();
        $this->actingAs(User::where('username', 'manager')->firstOrFail(), 'web');
        $this->getJson('/api/v1/office/finance/categories')->assertUnauthorized();
        $this->category(['action' => 'save', 'name' => 'Forbidden', 'active' => true])->assertUnauthorized();
        $this->assertDatabaseCount('office_finance_categories', 0);
    }

    public function test_migration_groups_legacy_categories_and_preserves_original_entry_text_through_rollback(): void
    {
        $migration = require database_path('migrations/2026_11_19_121317_create_office_finance_categories.php');
        $migration->down();
        $names = [' Hosting  fees ', 'hosting fees', '   '];
        foreach ($names as $name) {
            DB::table('office_ledger')->insert(['book_id' => $this->book, 'created_by' => $this->admin->id, 'kind' => 'income', 'amount' => 10, 'currency' => 'MMK', 'description' => 'Legacy income', 'category' => $name, 'entry_date' => '2026-11-19', 'created_at' => now()]);
        }
        $migration->up();
        $this->assertDatabaseCount('office_finance_categories', 1);
        $this->assertSame($names, DB::table('office_ledger')->orderBy('id')->pluck('category')->all());
        $this->assertSame(2, DB::table('office_ledger')->whereNotNull('category_id')->count());
        $this->assertSame(1, DB::table('office_ledger')->whereNotNull('category_id')->distinct()->count('category_id'));
        $migration->down();
        $this->assertSame($names, DB::table('office_ledger')->orderBy('id')->pluck('category')->all());
        $migration->up();
        $this->assertDatabaseCount('office_finance_categories', 1);
    }
}
