<?php

namespace App\Http\Controllers;

use App\Models\BusinessData;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MenuImageController extends Controller
{
    public function show(string $filename): BinaryFileResponse
    {
        $paths = ['menu-images/'.BusinessData::id().'/'.$filename, 'menu-images/'.$filename];
        $path = BusinessData::table('menu_items')->whereIn('image_path', $paths)->value('image_path');
        abort_unless($path, 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
