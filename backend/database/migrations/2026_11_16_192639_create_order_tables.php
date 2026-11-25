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
        Schema::create('order_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('table_id')->unique()->constrained('dining_tables');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('merged_into_id')->nullable()->constrained('orders');
            $table->json('billing_group_ids')->nullable();
            $table->string('billing_label')->nullable();
            $table->json('table_names_snapshot')->nullable();
            $table->text('table_name')->nullable()->change();
        });
        Schema::table('order_items', fn (Blueprint $table) => $table->foreignId('origin_order_id')->nullable()->constrained('orders'));
        Schema::table('kitchen_tickets', fn (Blueprint $table) => $table->text('table_name')->nullable());
        DB::table('orders')->orderBy('id')->each(function ($order): void {
            DB::table('orders')->where('id', $order->id)->update(['billing_label' => $order->table_name ?? $order->number, 'table_names_snapshot' => json_encode($order->table_name ? [$order->table_name] : [])]);
            if ($order->active_table_id && $order->status === 'open') {
                DB::table('order_tables')->insert(['business_id' => $order->business_id, 'order_id' => $order->id, 'table_id' => $order->active_table_id]);
            }
            DB::table('order_items')->where('order_id', $order->id)->update(['origin_order_id' => $order->id]);
            DB::table('kitchen_tickets')->where('order_id', $order->id)->update(['table_name' => $order->table_name]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_tables');
        Schema::table('order_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('origin_order_id'));
        Schema::table('kitchen_tickets', fn (Blueprint $table) => $table->dropColumn('table_name'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn(['billing_group_ids', 'billing_label', 'table_names_snapshot']);
        });
    }
};
