<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->boolean('guest_direct_to_kitchen')->default(false);
        });
        Schema::table('guest_order_requests', function (Blueprint $table) {
            $table->boolean('auto_accepted')->default(false);
        });
        foreach (['orders' => 'created_by', 'kitchen_tickets' => 'submitted_by', 'operational_events' => 'user_id'] as $name => $column) {
            Schema::table($name, function (Blueprint $table) use ($column) {
                $table->unsignedBigInteger($column)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['orders' => 'created_by', 'kitchen_tickets' => 'submitted_by', 'operational_events' => 'user_id'] as $name => $column) {
            if (DB::table($name)->whereNull($column)->exists()) {
                throw new RuntimeException('Direct customer order history cannot be rolled back safely. Restore the pre-migration backup.');
            }
        }
        foreach (['orders' => 'created_by', 'kitchen_tickets' => 'submitted_by', 'operational_events' => 'user_id'] as $name => $column) {
            Schema::table($name, function (Blueprint $table) use ($column) {
                $table->unsignedBigInteger($column)->nullable(false)->change();
            });
        }
        Schema::table('guest_order_requests', fn (Blueprint $table) => $table->dropColumn('auto_accepted'));
        Schema::table('restaurant_settings', fn (Blueprint $table) => $table->dropColumn('guest_direct_to_kitchen'));
    }
};
