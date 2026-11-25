<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class OfficeMigrationTest extends TestCase
{
    public function test_office_upgrade_preserves_old_receipts_and_backfills_each_currency_once(): void
    {
        config(['database.connections.office_legacy_test' => [...config('database.connections.sqlite'), 'database' => ':memory:']]);
        $migrationPath = database_path('migrations/2026_11_16_205523_extend_platform_office.php');
        $earlier = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => basename($path) < basename($migrationPath)));
        try {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'office_legacy_test', '--path' => $earlier, '--realpath' => true, '--force' => true]));
            $legacy = DB::connection('office_legacy_test');
            $admin = $legacy->table('users')->insertGetId(['name' => 'Historical admin', 'username' => 'office', 'email' => 'historical@example.test', 'password' => Hash::make('Secret123!'), 'roles' => '[]', 'business_id' => null, 'is_platform_admin' => true, 'active' => true]);
            foreach (['MMK' => 30000, 'USD' => 9] as $currency => $amount) {
                $legacy->table('subscription_payments')->insert(['business_id' => 1, 'subscription_id' => 1, 'recorded_by' => $admin, 'request_key' => 'legacy-'.$currency, 'amount' => $amount, 'currency' => $currency, 'reference' => 'ORIGINAL-'.$currency, 'plan_name' => 'Historical plan', 'period_start' => '2026-11-01 00:00:00', 'period_end' => '2026-12-01 00:00:00', 'created_at' => '2026-11-16 20:00:00']);
            }
            $before = $legacy->table('subscription_payments')->orderBy('id')->get()->toJson();
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'office_legacy_test', '--path' => [$migrationPath], '--realpath' => true, '--force' => true]));
            $this->assertSame($before, $legacy->table('subscription_payments')->orderBy('id')->get()->toJson());
            $this->assertSame(2, $legacy->table('office_ledger')->count());
            $this->assertSame(2, $legacy->table('office_bank_books')->where('is_default', true)->count());
            $this->assertSame(30000, (int) $legacy->table('office_ledger')->where('currency', 'MMK')->sum('amount'));
            $this->assertSame(9, (int) $legacy->table('office_ledger')->where('currency', 'USD')->sum('amount'));
            $this->assertSame(['2026-11-17'], $legacy->table('office_ledger')->distinct()->pluck('entry_date')->all());
            $this->assertSame('super_admin', $legacy->table('users')->where('id', $admin)->value('office_role'));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'office_legacy_test', '--path' => [$migrationPath], '--realpath' => true, '--force' => true]));
            $this->assertSame(2, $legacy->table('office_ledger')->count());
            $migration = require $migrationPath;
            $this->expectException(RuntimeException::class);
            $migration->down();
        } finally {
            DB::purge('office_legacy_test');
        }
    }
}
