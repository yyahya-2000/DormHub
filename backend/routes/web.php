<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// The API reference. Not authenticated: it is the description of the
// interface, not the data behind it.
Route::prefix('api/docs')->group(function (): void {
    Route::view('/', 'api-docs')->name('api.docs');

    Route::get('/openapi.yaml', fn () => response()->file(
        base_path('api/openapi.yaml'),
        [
            'Content-Type' => 'application/yaml; charset=UTF-8',
            // So a reader who saves it keeps the extension.
            'Content-Disposition' => 'inline; filename="openapi.yaml"',
        ],
    ))->name('api.docs.openapi');
});
