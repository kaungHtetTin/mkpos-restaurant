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
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('active_business_id')->nullable()->unique()->constrained('businesses');
            $table->foreignId('opened_by')->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->string('currency', 3);
            $table->unsignedBigInteger('opening_cash');
            $table->unsignedBigInteger('closing_cash')->nullable();
            $table->bigInteger('closing_expected')->nullable();
            $table->bigInteger('variance')->nullable();
            $table->string('closing_note', 500)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->index(['business_id', 'opened_at']);
        });
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('shift_id')->constrained('cash_shifts');
            $table->foreignId('recorded_by')->constrained('users');
            $table->bigInteger('amount');
            $table->string('reason', 255);
            $table->timestamp('created_at');
        });
        Schema::table('payments', fn (Blueprint $table) => $table->foreignId('shift_id')->nullable()->constrained('cash_shifts'));
        Schema::table('restaurant_settings', fn (Blueprint $table) => $table->boolean('shifts_enabled')->default(false));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('shift_id'));
        Schema::table('restaurant_settings', fn (Blueprint $table) => $table->dropColumn('shifts_enabled'));
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_shifts');
    }
};
