<?php

use Carbon\CarbonImmutable;
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
        Schema::table('plans', function (Blueprint $t) {
            $t->text('description')->nullable();
            $t->json('features')->nullable();
            $t->boolean('is_public')->default(true);
            $t->unsignedInteger('sort')->default(0);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('office_role', 20)->nullable();
            $t->unsignedInteger('office_version')->default(1);
        });
        DB::table('users')->where('is_platform_admin', true)->update(['office_role' => 'super_admin']);
        Schema::table('businesses', function (Blueprint $t) {
            $t->string('contact', 255)->default('');
            $t->string('address', 500)->default('');
            $t->string('city', 100)->default('');
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
        });
        Schema::create('office_settings', function (Blueprint $t) {
            $t->id();
            $t->string('name')->default('Little Lemon');
            $t->string('timezone')->default('Asia/Yangon');
            $t->string('theme_color', 7)->default('#c7a92e');
            $t->string('support_email')->default('');
            $t->string('support_phone')->default('');
            $t->string('support_url', 500)->default('');
            $t->string('logo_path')->nullable();
            $t->unsignedInteger('version')->default(1);
        });
        DB::table('office_settings')->insert(['id' => 1]);
        Schema::create('office_commands', function (Blueprint $t) {
            $t->id();
            $t->foreignId('admin_id')->constrained('users');
            $t->string('key', 100);
            $t->string('fingerprint', 64);
            $t->longText('response');
            $t->timestamp('created_at');
            $t->unique(['admin_id', 'key']);
        });
        Schema::create('office_bank_books', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('currency', 3);
            $t->boolean('active')->default(true);
            $t->boolean('is_default')->default(false);
            $t->unsignedInteger('version')->default(1);
        });
        Schema::create('office_founders', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->unsignedInteger('share_bps');
            $t->boolean('active')->default(true);
        });
        Schema::create('office_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('book_id')->constrained('office_bank_books');
            $t->foreignId('to_book_id')->nullable()->constrained('office_bank_books');
            $t->foreignId('business_id')->nullable()->constrained();
            $t->foreignId('subscription_payment_id')->nullable()->unique()->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->string('kind', 30);
            $t->bigInteger('amount');
            $t->string('currency', 3);
            $t->string('description', 255);
            $t->string('category', 100)->default('');
            $t->string('reference', 150)->default('');
            $t->date('entry_date');
            $t->json('allocation_snapshot')->nullable();
            $t->timestamp('created_at');
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users');
            $t->string('void_reason', 255)->nullable();
            $t->index(['currency', 'entry_date']);
        });
        foreach (DB::table('subscription_payments')->select('currency')->distinct()->pluck('currency') as $currency) {
            $book = DB::table('office_bank_books')->insertGetId(['name' => 'Legacy subscription receipts', 'currency' => $currency, 'is_default' => true]);
            DB::table('subscription_payments')->where('currency', $currency)->orderBy('id')->each(function ($payment) use ($book) {
                DB::table('office_ledger')->insert(['book_id' => $book, 'business_id' => $payment->business_id, 'subscription_payment_id' => $payment->id, 'created_by' => $payment->recorded_by, 'kind' => 'subscription', 'amount' => $payment->amount, 'currency' => $payment->currency, 'description' => $payment->plan_name, 'reference' => $payment->reference, 'entry_date' => CarbonImmutable::parse($payment->created_at, 'UTC')->setTimezone('Asia/Yangon')->toDateString(), 'created_at' => $payment->created_at]);
            });
        }
        Schema::create('office_payment_methods', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('currency', 3);
            $t->text('instructions');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
        });
        Schema::create('subscription_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('submitted_by')->constrained('users');
            $t->foreignId('plan_id')->constrained();
            $t->foreignId('method_id')->constrained('office_payment_methods');
            $t->json('plan_snapshot');
            $t->json('method_snapshot');
            $t->unsignedBigInteger('amount');
            $t->string('currency', 3);
            $t->string('reference', 150);
            $t->string('receipt_path')->nullable();
            $t->string('status', 20)->default('pending');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->foreignId('payment_id')->nullable()->unique()->constrained('subscription_payments');
            $t->string('review_reason', 500)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('created_at');
            $t->timestamp('reviewed_at')->nullable();
            $t->index(['business_id', 'status']);
        });
        Schema::create('platform_support_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('submitted_by')->constrained('users');
            $t->string('title', 150);
            $t->text('description');
            $t->string('priority', 20)->default('normal');
            $t->string('status', 20)->default('open');
            $t->text('resolution')->nullable();
            $t->text('internal_note')->nullable();
            $t->string('screenshot_path')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('office_content', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 20);
            $t->string('title', 150);
            $t->text('body');
            $t->string('url', 500)->nullable();
            $t->string('audience', 20)->default('all');
            $t->boolean('published')->default(false);
            $t->unsignedInteger('sort')->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Office finance and approval history cannot be discarded safely. Restore the pre-migration backup instead.');
    }
};
