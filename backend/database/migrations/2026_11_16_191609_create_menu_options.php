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
            $table->boolean('uses_variants')->default(false);
            $table->unsignedInteger('options_version')->default(1);
        });
        Schema::create('menu_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('menu_item_id')->constrained();
            $table->string('kind');
            $table->string('name', 60);
            $table->unsignedInteger('price');
            $table->boolean('active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->index(['business_id', 'menu_item_id']);
        });
        Schema::table('order_items', fn (Blueprint $table) => $table->json('options_snapshot')->nullable());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('options_snapshot'));
        Schema::dropIfExists('menu_options');
        Schema::table('menu_items', fn (Blueprint $table) => $table->dropColumn(['uses_variants', 'options_version']));
    }
};
