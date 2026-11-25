<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->boolean('track_stock')->default(false);
            $table->unsignedInteger('stock_on_hand')->default(0);
            $table->unsignedInteger('stock_low_at')->default(5);
            $table->unsignedInteger('stock_version')->default(1);
        });
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('menu_item_id')->constrained();
            $table->foreignId('order_item_id')->nullable()->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->string('kind', 30);
            $table->integer('quantity');
            $table->unsignedInteger('balance');
            $table->string('reason', 255);
            $table->timestamp('created_at');
            $table->index(['business_id', 'menu_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn(['track_stock', 'stock_on_hand', 'stock_low_at', 'stock_version']);
        });
    }
};
