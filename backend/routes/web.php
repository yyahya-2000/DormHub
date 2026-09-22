<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| The browsable API reference
|--------------------------------------------------------------------------
|
| Two routes, and the reason they exist is that `backend/api/openapi.yaml` is
| the single description of the API (§3.3.6, NFR-12) and until now nothing
| rendered it: the file is read by Orval to generate the front end's client,
| and by a human being with a text editor, and by nobody else.
|
| `GET /api/docs` renders the `api-docs` view, which loads a copy of Swagger UI
| vendored under `public/swagger-ui/`. The view carries the long form of why
| the page is a view rather than a file in the document root, and why it
| fetches nothing from outside the university's own servers.
|
| `GET /api/docs/openapi.yaml` streams the description itself. It is **not**
| copied into `public/`: a contract kept in two places is a contract that will
| eventually disagree with itself, and the copy under `public/` would be the
| one nobody regenerates. The file lives outside the document root, so reaching
| it over HTTP takes a route.
|
| Neither route is behind authentication. What they expose is the description
| of the interface and not the data behind it — the same file the repository
| carries in the open — and a reference that demands a token before it will
| show you how to obtain one is a closed door with the instructions inside.
*/
Route::prefix('api/docs')->group(function (): void {
    Route::view('/', 'api-docs')->name('api.docs');

    Route::get('/openapi.yaml', fn () => response()->file(
        base_path('api/openapi.yaml'),
        [
            'Content-Type' => 'application/yaml; charset=UTF-8',
            // Named, so that a reader who opens the address directly and saves
            // the result keeps the extension the file has on the server.
            'Content-Disposition' => 'inline; filename="openapi.yaml"',
        ],
    ))->name('api.docs.openapi');
});
