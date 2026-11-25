<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('username')->nullable()->unique(); $t->json('roles')->nullable(); $t->boolean('active')->default(true);
        });
        Schema::create('restaurant_settings', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('currency')->default('MMK');
            $t->string('timezone')->default('Asia/Yangon'); $t->string('contact')->default('');
            $t->string('footer')->default('Thank you. Come back hungry!'); $t->string('other_payment_label')->default('QR / Card');
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->id(); $t->string('name')->unique(); $t->integer('sort')->default(0); $t->boolean('active')->default(true);
        });
        Schema::create('menu_items', function (Blueprint $t) {
            $t->id(); $t->foreignId('category_id')->constrained(); $t->string('name');
            $t->string('description')->default(''); $t->string('emoji')->default('🍽️');
            $t->unsignedInteger('price'); $t->boolean('available')->default(true); $t->boolean('active')->default(true);
        });
        Schema::create('dining_tables', function (Blueprint $t) {
            $t->id(); $t->string('name')->unique(); $t->unsignedInteger('seats')->default(4); $t->boolean('active')->default(true);
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->string('number')->unique(); $t->string('type');
            $t->foreignId('active_table_id')->nullable()->unique()->constrained('dining_tables');
            $t->string('table_name')->nullable(); $t->string('customer_name')->nullable(); $t->string('phone')->nullable();
            $t->string('status')->default('open')->index(); $t->boolean('paid')->default(false); $t->boolean('bill_requested')->default(false);
            $t->unsignedInteger('version')->default(1); $t->foreignId('created_by')->constrained('users');
            $t->timestamp('collected_at')->nullable(); $t->timestamp('closed_at')->nullable(); $t->timestamps();
        });
        Schema::create('kitchen_tickets', function (Blueprint $t) {
            $t->id(); $t->foreignId('order_id')->constrained(); $t->foreignId('submitted_by')->constrained('users'); $t->timestamp('created_at');
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id(); $t->foreignId('order_id')->constrained(); $t->foreignId('menu_item_id')->constrained();
            $t->foreignId('ticket_id')->nullable()->constrained('kitchen_tickets');
            $t->string('name'); $t->unsignedInteger('price'); $t->unsignedInteger('quantity'); $t->string('notes', 500)->default('');
            $t->string('status')->default('draft'); $t->string('cancel_reason', 500)->nullable();
            $t->timestamp('cancel_ack_at')->nullable(); $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id(); $t->foreignId('order_id')->unique()->constrained(); $t->string('receipt_number')->unique();
            $t->unsignedBigInteger('amount'); $t->string('method'); $t->string('method_label');
            $t->unsignedBigInteger('tendered'); $t->unsignedBigInteger('change'); $t->foreignId('recorded_by')->constrained('users');
            $t->json('restaurant_snapshot'); $t->timestamp('created_at');
        });
        Schema::create('operational_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('order_id')->nullable()->constrained(); $t->foreignId('user_id')->constrained();
            $t->string('action'); $t->json('details'); $t->timestamp('created_at');
        });
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained(); $t->string('key', 100); $t->string('fingerprint', 64);
            $t->longText('response'); $t->timestamp('created_at'); $t->unique(['user_id', 'key']);
        });
    }
    public function down(): void
    {
        foreach (['idempotency_keys', 'operational_events', 'payments', 'order_items', 'kitchen_tickets', 'orders', 'dining_tables', 'menu_items', 'categories', 'restaurant_settings'] as $table) Schema::dropIfExists($table);
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['username', 'roles', 'active']));
    }
};
