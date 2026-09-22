<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// The browsable API reference. `api/openapi.yaml` is the single description
// of the API (NFR-12): Orval generates the front end's client from it, and
// these two routes render and serve it. It is not copied into `public/` —
// one contract, one file.
//
// Neither route is authenticated: they expose the description of the
// interface, not the data behind it, and the repository carries the same file
// in the open.
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
