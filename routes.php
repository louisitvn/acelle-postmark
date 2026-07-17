<?php

use Illuminate\Support\Facades\Route;

Route::get('plugins/acelle/postmark/icon.svg', function () {
    $path = storage_path('app/plugins/acelle/postmark/icon.svg');
    abort_unless(file_exists($path), 404);
    return response()->file($path, [
        'Content-Type'  => 'image/svg+xml',
        'Cache-Control' => 'public, max-age=31536000',
    ]);
})->name('plugin.acelle.postmark.icon');
