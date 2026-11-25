<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SplitPaymentMigrationTest extends TestCase
{
    private string $originalConnection;

    private Migration $migration;

    private int $paymentId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        $database = getenv('SPLIT_MIGRATION_MYSQL_DATABASE');
        if ($database !== false) {
            if (! str_starts_with($database, 'mkpos_split_test_')) {
                throw new RuntimeException('Migration tests require a dedicated mkpos_split_test_ database.');
            }
            $connection = [...config('database.connections.mysql'), 'url' => null, 'host' => '127.0.0.1', 'port' => 3306, 'database' => $database, 'username' => 'root', 'password' => '', 'modes' => ['STRICT_TRANS_TABLES', 'ERROR_FOR_DIVISION_BY_ZERO', 'NO_ENGINE_SUBSTITUTION']];
        } else {
            $connection = [...config('database.connections.sqlite'), 'database' => ':memory:'];
        }
        config(['database.connections.split_migration_test' => $connection]);
        DB::setDefaultConnection('split_migration_test');
        Schema::clearResolvedInstance('db.schema');
        $path = database_path('migrations/2026_11_16_195048_support_split_payments.php');
        $earlier = array_values(array_filter(glob(database_path('migrations/*.php')), fn (string $file): bool => basename($file) < basename($path)));
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'split_migration_test', '--path' => $earlier, '--realpath' => true, '--force' => true]));
        $this->migration = require $path;
        $user = DB::table('users')->insertGetId(['name' => 'Historical cashier', 'email' => 'cashier@example.test', 'password' => 'unused', 'roles' => '[]', 'business_id' => 1]);
        $order = DB::table('orders')->insertGetId(['business_id' => 1, 'number' => 'OLD-001', 'type' => 'dine_in', 'table_name' => '03', 'created_by' => $user, 'paid' => true, 'status' => 'closed']);
        $this->paymentId = DB::table('payments')->insertGetId([
            'business_id' => 1, 'order_id' => $order, 'recorded_by' => $user, 'receipt_number' => 'OLD-RECEIPT-001',
            'amount' => 6500, 'method' => 'cash', 'method_label' => 'Cash', 'tendered' => 10000, 'change' => 3500,
            'restaurant_snapshot' => '{"name":"Historical restaurant"}', 'totals_snapshot' => '{"total":6500}',
            'items_snapshot' => '[{"name":"Historical dish","quantity":1,"price":6500}]', 'created_at' => '2026-11-16 12:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if (config('database.connections.split_migration_test') !== null) {
                Schema::connection('split_migration_test')->dropAllTables();
            }
        } finally {
            DB::setDefaultConnection($this->originalConnection);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('split_migration_test');
            parent::tearDown();
        }
    }

    public function test_upgrade_preserves_payments_and_foreign_keys_and_allows_split_tenders(): void
    {
        $before = DB::table('payments')->find($this->paymentId);
        $this->migration->up();
        $after = DB::table('payments')->find($this->paymentId);
        foreach ((array) $before as $column => $value) {
            $this->assertEquals($value, $after->{$column});
        }
        $receipt = DB::table('payment_receipts')->find($after->receipt_id);
        $this->assertSame(6500, (int) $receipt->amount);
        $this->assertSame('03', $receipt->table_label);
        $this->assertEquals(json_decode($before->items_snapshot), json_decode($receipt->items_snapshot));
        $this->assertTrue(Schema::hasIndex('payments', 'payments_order_id_index'));
        $this->assertFalse(Schema::hasIndex('payments', 'payments_order_id_unique'));
        $this->assertNotEmpty(array_filter(Schema::getForeignKeys('payments'), fn (array $key): bool => $key['columns'] === ['order_id'] && $key['foreign_table'] === 'orders'));
        $secondTender = (array) $after;
        unset($secondTender['id']);
        DB::table('payments')->insert($secondTender);
        $this->assertSame(2, DB::table('payments')->where('order_id', $before->order_id)->count());
    }

    public function test_retry_resumes_after_receipts_table_was_created(): void
    {
        $this->interruptAfterCreating('payment_receipts');
        $this->assertTrue(Schema::hasColumn('orders', 'settlement_snapshot'));
        $this->assertTrue(Schema::hasIndex('payments', 'payments_order_id_unique'));
        $this->migration->up();
        $this->assertNotNull(DB::table('payments')->where('id', $this->paymentId)->value('receipt_id'));
        $this->assertSame(1, DB::table('payment_receipts')->count());
    }

    public function test_retry_reuses_a_receipt_from_interrupted_backfill(): void
    {
        $this->interruptAfterCreating('payment_allocations');
        $payment = DB::table('payments')->find($this->paymentId);
        $receipt = DB::table('payment_receipts')->insertGetId([
            'business_id' => 1, 'order_id' => $payment->order_id, 'recorded_by' => $payment->recorded_by,
            'receipt_number' => $payment->receipt_number, 'amount' => $payment->amount, 'order_total' => $payment->amount,
            'restaurant_snapshot' => $payment->restaurant_snapshot, 'items_snapshot' => $payment->items_snapshot, 'created_at' => $payment->created_at,
        ]);
        $this->migration->up();
        $this->migration->up();
        $this->assertSame($receipt, (int) DB::table('payments')->where('id', $this->paymentId)->value('receipt_id'));
        $this->assertSame(1, DB::table('payment_receipts')->count());
    }

    public function test_backfill_does_not_skip_payments_across_batches(): void
    {
        $this->interruptAfterCreating('payment_allocations');
        $payment = (array) DB::table('payments')->find($this->paymentId);
        unset($payment['id']);
        $payments = [];
        for ($index = 0; $index < 1000; $index++) {
            $payments[] = [...$payment, 'receipt_number' => 'BATCH-'.$index];
        }
        foreach (array_chunk($payments, 50) as $batch) {
            DB::table('payments')->insert($batch);
        }
        $this->migration->up();
        $this->assertSame(0, DB::table('payments')->whereNull('receipt_id')->count());
        $this->assertSame(1001, DB::table('payment_receipts')->count());
    }

    private function interruptAfterCreating(string $table): void
    {
        $interrupt = true;
        DB::listen(function (QueryExecuted $query) use ($table, &$interrupt): void {
            if ($interrupt && $query->connectionName === 'split_migration_test' && str_starts_with(strtolower($query->sql), 'create table') && str_contains($query->sql, $table)) {
                $interrupt = false;
                throw new RuntimeException('Simulated interruption after DDL.');
            }
        });
        try {
            $this->migration->up();
            $this->fail('The migration should have been interrupted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated interruption after DDL.', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable($table));
    }
}
