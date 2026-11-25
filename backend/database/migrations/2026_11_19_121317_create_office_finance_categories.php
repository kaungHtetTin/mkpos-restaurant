<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_finance_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('name_key', 300)->unique();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::table('office_ledger', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->constrained('office_finance_categories')->restrictOnDelete();
            $table->index('category_id');
        });
        foreach (DB::table('office_ledger')->where('category', '<>', '')->distinct()->orderBy('category')->pluck('category') as $original) {
            $name = preg_replace('/\\s+/u', ' ', trim($original));
            if ($name === '') {
                continue;
            }
            $key = Str::lower($name);
            DB::table('office_finance_categories')->insertOrIgnore(['name' => $name, 'name_key' => $key, 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $id = DB::table('office_finance_categories')->where('name_key', $key)->value('id');
            DB::table('office_ledger')->where('category', $original)->update(['category_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('office_ledger', function (Blueprint $table) {
            $table->dropIndex(['category_id']);
            $table->dropConstrainedForeignId('category_id');
        });
        Schema::dropIfExists('office_finance_categories');
    }
};
