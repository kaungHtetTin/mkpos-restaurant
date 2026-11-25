<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlatformBranding
{
    public static function name(): string
    {
        if (! Schema::hasTable('office_settings')) {
            return 'Restaurant POS';
        }

        $name = DB::table('office_settings')->where('id', 1)->value('name');

        return is_string($name) && trim($name) !== '' ? $name : 'Restaurant POS';
    }
}
