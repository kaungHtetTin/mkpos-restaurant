<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = ['restaurant_settings', 'categories', 'menu_items', 'dining_tables', 'orders', 'kitchen_tickets', 'order_items', 'payments', 'operational_events', 'idempotency_keys'];

    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code', 60)->unique();
            $t->foreignId('owner_id')->nullable()->unique()->constrained('users');
            $t->string('status')->default('active');
            $t->timestamps();
        });
        DB::table('businesses')->insert(['id' => 1, 'name' => DB::table('restaurant_settings')->value('name') ?? 'Little Lemon', 'code' => 'little-lemon', 'owner_id' => DB::table('users')->where('roles', 'like', '%manager%')->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('business_id')->nullable()->default(1)->constrained();
            $t->boolean('is_platform_admin')->default(false);
            $t->dropUnique(['username']);
            $t->unique(['business_id', 'username']);
        });
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('business_id')->default(1)->constrained();
            });
        }
        Schema::table('restaurant_settings', fn (Blueprint $t) => $t->unique('business_id'));
        foreach (['categories', 'dining_tables'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropUnique(['name']);
                $t->unique(['business_id', 'name']);
            });
        }
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->unsignedBigInteger('price');
            $t->string('currency', 3)->default('MMK');
            $t->string('billing_period')->default('month');
            $t->boolean('active')->default(true);
            $t->boolean('is_trial')->default(false);
            $t->timestamps();
        });
        $plan = DB::table('plans')->insertGetId(['name' => '1-month trial', 'price' => 0, 'currency' => 'MMK', 'billing_period' => 'month', 'is_trial' => true, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->unique()->constrained();
            $t->foreignId('plan_id')->constrained();
            $t->string('status')->default('trial');
            $t->string('plan_name');
            $t->unsignedBigInteger('price');
            $t->string('currency', 3);
            $t->string('billing_period');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->timestamps();
        });
        DB::table('subscriptions')->insert(['business_id' => 1, 'plan_id' => $plan, 'status' => 'trial', 'plan_name' => '1-month trial', 'price' => 0, 'currency' => 'MMK', 'billing_period' => 'month', 'starts_at' => now(), 'ends_at' => now()->addMonthNoOverflow(), 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('subscription_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('subscription_id')->constrained();
            $t->foreignId('recorded_by')->constrained('users');
            $t->string('request_key', 100)->unique();
            $t->unsignedBigInteger('amount');
            $t->string('currency', 3);
            $t->string('reference', 150);
            $t->string('plan_name');
            $t->timestamp('period_start');
            $t->timestamp('period_end');
            $t->timestamp('created_at');
        });
        Schema::create('platform_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->nullable()->constrained();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('action');
            $t->json('details');
            $t->timestamp('created_at');
        });
        Schema::create('subscription_notices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_id')->constrained();
            $t->timestamp('period_end');
            $t->timestamp('sent_at');
            $t->unique(['subscription_id', 'period_end']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Business separation cannot be reversed safely. Restore the pre-migration backup instead.');
    }
};
