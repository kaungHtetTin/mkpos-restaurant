<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_finance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('name_key', 300);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['business_id', 'name_key']);
        });
        Schema::create('business_finance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained('business_finance_categories')->restrictOnDelete();
            $table->string('category_name', 100);
            $table->string('kind', 10);
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->date('entry_date');
            $table->string('description', 255);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['business_id', 'entry_date', 'id']);
            $table->index(['business_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_finance_entries');
        Schema::dropIfExists('business_finance_categories');
    }
};
