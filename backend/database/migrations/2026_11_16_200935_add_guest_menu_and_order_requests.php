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
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->boolean('guest_menu_enabled')->default(false);
            $table->boolean('guest_ordering_enabled')->default(false);
        });
        Schema::create('guest_menu_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('table_id')->nullable()->constrained('dining_tables');
            $table->string('token', 64)->unique();
            $table->timestamp('created_at');
        });
        Schema::create('guest_order_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('table_id')->nullable()->constrained('dining_tables');
            $table->foreignId('order_id')->nullable()->constrained();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->string('public_token', 64)->unique();
            $table->string('request_key', 100);
            $table->string('fingerprint', 64);
            $table->string('status', 20)->default('pending');
            $table->string('table_label')->nullable();
            $table->string('guest_name', 100)->nullable();
            $table->json('items');
            $table->json('totals_snapshot');
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->unique(['business_id', 'request_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_order_requests');
        Schema::dropIfExists('guest_menu_links');
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->dropColumn(['guest_menu_enabled', 'guest_ordering_enabled']);
        });
    }
};
