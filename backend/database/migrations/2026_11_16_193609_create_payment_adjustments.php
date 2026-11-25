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
        Schema::create('payment_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('payment_id')->constrained();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('shift_id')->nullable()->constrained('cash_shifts');
            $table->foreignId('recorded_by')->constrained('users');
            $table->string('reference_number')->unique();
            $table->string('kind');
            $table->unsignedBigInteger('amount');
            $table->string('from_method');
            $table->string('from_label');
            $table->string('to_method');
            $table->string('to_label');
            $table->bigInteger('cash_effect')->default(0);
            $table->string('reason', 500);
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->index(['business_id', 'created_at']);
            $table->index(['business_id', 'payment_id']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->string('effective_method')->nullable();
            $table->string('effective_method_label')->nullable();
            $table->unsignedInteger('adjustment_version')->default(1);
            $table->json('items_snapshot')->nullable();
        });
        DB::table('payments')->update(['effective_method' => DB::raw('method'), 'effective_method_label' => DB::raw('method_label')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_adjustments');
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn(['effective_method', 'effective_method_label', 'adjustment_version', 'items_snapshot']));
    }
};
