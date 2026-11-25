<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'settlement_snapshot')) {
            Schema::table('orders', fn (Blueprint $table) => $table->json('settlement_snapshot')->nullable());
        }
        if (! Schema::hasColumn('orders', 'equal_shares_left')) {
            Schema::table('orders', fn (Blueprint $table) => $table->unsignedInteger('equal_shares_left')->nullable());
        }
        if (! Schema::hasColumn('orders', 'cancelled_balance')) {
            Schema::table('orders', fn (Blueprint $table) => $table->unsignedBigInteger('cancelled_balance')->default(0));
        }
        if (! Schema::hasTable('payment_receipts')) {
            Schema::create('payment_receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained();
                $table->foreignId('order_id')->constrained();
                $table->foreignId('recorded_by')->constrained('users');
                $table->string('receipt_number')->unique();
                $table->string('mode')->default('full');
                $table->string('payer_label', 100)->nullable();
                $table->unsignedBigInteger('amount');
                $table->unsignedBigInteger('order_total');
                $table->unsignedBigInteger('prior_paid')->default(0);
                $table->unsignedBigInteger('balance_after')->default(0);
                $table->unsignedBigInteger('credited_amount')->default(0);
                $table->text('table_label')->nullable();
                $table->json('restaurant_snapshot');
                $table->json('totals_snapshot')->nullable();
                $table->json('items_snapshot');
                $table->timestamp('created_at');
            });
        }
        foreach (['order_id', 'receipt_number'] as $column) {
            $index = 'payments_'.$column.'_index';
            $unique = 'payments_'.$column.'_unique';
            if (! Schema::hasIndex('payments', $index)) {
                Schema::table('payments', fn (Blueprint $table) => $table->index($column, $index));
            }
            if (Schema::hasIndex('payments', $unique, 'unique')) {
                Schema::table('payments', fn (Blueprint $table) => $table->dropUnique($unique));
            }
        }
        if (! Schema::hasColumn('payments', 'receipt_id')) {
            Schema::table('payments', fn (Blueprint $table) => $table->foreignId('receipt_id')->nullable()->constrained('payment_receipts'));
        }
        if (! Schema::hasTable('payment_allocations')) {
            Schema::create('payment_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained();
                $table->foreignId('order_id')->constrained();
                $table->foreignId('receipt_id')->constrained('payment_receipts');
                $table->foreignId('item_id')->constrained('order_items');
                $table->unsignedInteger('unit_index');
                $table->unsignedBigInteger('amount');
                $table->unique(['receipt_id', 'item_id', 'unit_index']);
            });
        }
        DB::table('payments')->whereNull('receipt_id')->eachById(function (stdClass $payment): void {
            DB::transaction(function () use ($payment): void {
                $order = DB::table('orders')->find($payment->order_id);
                $items = $payment->items_snapshot;
                if (! $items) {
                    $items = json_encode(DB::table('order_items')->where('order_id', $order->id)->where('status', '!=', 'cancelled')->orderBy('id')->get()->map(fn ($item) => [
                        'id' => $item->id, 'name' => $item->name, 'price' => $item->price, 'quantity' => $item->quantity, 'options_snapshot' => json_decode($item->options_snapshot ?? '[]', true),
                    ])->all());
                }
                $receipt = DB::table('payment_receipts')->where('business_id', $payment->business_id)->where('order_id', $payment->order_id)->where('receipt_number', $payment->receipt_number)->value('id');
                $receipt ??= DB::table('payment_receipts')->insertGetId(['business_id' => $payment->business_id, 'order_id' => $payment->order_id, 'recorded_by' => $payment->recorded_by,
                    'receipt_number' => $payment->receipt_number, 'amount' => $payment->amount, 'order_total' => $payment->amount, 'mode' => 'full', 'table_label' => $order->table_name,
                    'restaurant_snapshot' => $payment->restaurant_snapshot, 'totals_snapshot' => $payment->totals_snapshot, 'items_snapshot' => $items, 'created_at' => $payment->created_at]);
                DB::table('payments')->where('id', $payment->id)->update(['receipt_id' => $receipt, 'created_at' => $payment->created_at]);
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Split receipts cannot be merged safely on rollback. Restore a pre-migration backup instead.');
    }
};
