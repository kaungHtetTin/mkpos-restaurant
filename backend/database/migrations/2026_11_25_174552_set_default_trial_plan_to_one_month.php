<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('plans')->where('is_trial', true)->update(['name' => '1-month trial', 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('plans')->where('is_trial', true)->where('name', '1-month trial')->update(['name' => '14-day trial', 'updated_at' => now()]);
    }
};
