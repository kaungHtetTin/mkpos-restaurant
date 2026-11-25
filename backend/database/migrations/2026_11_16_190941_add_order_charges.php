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
            $table->unsignedInteger('tax_rate_bps')->default(0);
            $table->unsignedInteger('service_rate_bps')->default(0);
            $table->string('tax_label', 40)->default('Tax');
            $table->string('service_charge_label', 40)->default('Service charge');
            $table->boolean('tax_on_service')->default(false);
            $table->boolean('service_dine_in_only')->default(true);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('tax_rate_bps')->default(0);
            $table->unsignedInteger('service_rate_bps')->default(0);
            $table->string('tax_label', 40)->default('Tax');
            $table->string('service_charge_label', 40)->default('Service charge');
            $table->boolean('tax_on_service')->default(false);
            $table->string('discount_type')->default('none');
            $table->unsignedBigInteger('discount_value')->default(0);
            $table->string('discount_reason', 255)->nullable();
            $table->foreignId('discount_approved_by')->nullable()->constrained('users');
        });
        Schema::table('payments', fn (Blueprint $table) => $table->json('totals_snapshot')->nullable());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('totals_snapshot'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_approved_by');
            $table->dropColumn(['tax_rate_bps', 'service_rate_bps', 'tax_label', 'service_charge_label', 'tax_on_service', 'discount_type', 'discount_value', 'discount_reason']);
        });
        Schema::table('restaurant_settings', fn (Blueprint $table) => $table->dropColumn(['tax_rate_bps', 'service_rate_bps', 'tax_label', 'service_charge_label', 'tax_on_service', 'service_dine_in_only']));
    }
};
