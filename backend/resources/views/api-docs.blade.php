{{--
    The browsable reference of the REST contract (§3.3.6, NFR-12), served by
    the application's own web server at `GET /api/docs`.

    A Blade view rather than a file in `public/`, for a reason that is entirely
    about the web server. `docker/nginx/default.conf` resolves a request
    against the document root first, and a directory that matches the address
    makes nginx answer 301 to the same path with a slash — rebuilding the
    Location header from the host name alone and dropping the published port in
    the process, so `/api/docs` on port 8080 would redirect a browser to port
    80. Keeping the page out of the document root leaves no directory for
    nginx to find, the request falls through to the framework as every other
    route does, and `docker/nginx/default.conf` — with it the deployment
    described in §3.2.3 — stays as it was.

    Three properties of the page are deliberate, and each one is a refusal.

    **Nothing is loaded from a network the university does not own.** NFR-08
    requires every component on the mandatory path to be free software running
    on the university's servers, depending on no external service. The usual
    way to put Swagger UI on a page — a script tag pointing at unpkg or
    jsdelivr — would make that statement false on the first page load, and
    would leave the reference blank on a machine with no route to the internet,
    which is the situation a defence is most likely to happen in. The
    distribution is vendored under `public/swagger-ui/`; see PROVENANCE.txt
    there for the version, its origin and the checksum it was verified against.

    **`validatorUrl` is null.** Left at its default, Swagger UI renders a
    validity badge in the footer and fetches it from validator.swagger.io,
    sending the whole description to a third party to do so. That is the
    dependency the paragraph above refuses, and it is easy to miss, because it
    arrives as a sixty-pixel image rather than as a script tag.

    **The description is read over HTTP from the one file the front end's
    client is also generated from.** `backend/api/openapi.yaml` is not copied
    into `public/`: a contract kept in two places is a contract that will
    disagree with itself, and the copy would be the one nobody regenerates.
    The route beside this view streams the original.
--}}
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>HSE DormHub — API v1 reference</title>

  <link rel="stylesheet" href="/swagger-ui/swagger-ui.css">
  <link rel="icon" type="image/png" sizes="32x32" href="/swagger-ui/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/swagger-ui/favicon-16x16.png">

  <style>
    html { box-sizing: border-box; }
    *, *:before, *:after { box-sizing: inherit; }
    body { margin: 0; background: #fafafa; }
  </style>
</head>
<body>
  <div id="swagger-ui"></div>

  <script src="/swagger-ui/swagger-ui-bundle.js"></script>
  <script>
    window.addEventListener('load', function () {
      window.ui = SwaggerUIBundle({
        /*
         * Same origin as the API. The page is served by the container that
         * serves `/api/v1`, so «Try it out» is a plain same-origin request and
         * there is no CORS layer to configure — the second reason the
         * reference lives inside the application rather than in a container of
         * its own.
         */
        url: '{{ route('api.docs.openapi', absolute: false) }}',
        dom_id: '#swagger-ui',

        /*
         * The base preset only. `SwaggerUIStandalonePreset` adds a top bar
         * with a field for an arbitrary description URL; on a page whose point
         * is that it describes this application and fetches nothing from
         * anywhere else, that field is an invitation to a misunderstanding.
         */
        presets: [SwaggerUIBundle.presets.apis],
        layout: 'BaseLayout',

        /* The one request Swagger UI makes on its own, and how it is switched
         * off. See the note at the top of this file. */
        validatorUrl: null,

        /*
         * The contract carries sixty-two operations under fourteen tags.
         * Expanded in full the page is unreadable; collapsed to the tags alone
         * it hides what the reader came for. `list` shows every operation as a
         * closed row beneath its tag, which is the shape a reference is read
         * in.
         */
        docExpansion: 'list',
        deepLinking: true,
        filter: true,
        tryItOutEnabled: true,
        displayRequestDuration: true,
        defaultModelsExpandDepth: 1,
        defaultModelRendering: 'example',

        /*
         * The token survives a reload. Authentication is a bearer token from
         * `POST /auth/login` (FR-08), and re-pasting it after every navigation
         * turns a demonstration into a typing exercise. It is kept in the
         * browser's local storage, which is acceptable for a development and
         * demonstration surface and would not be for a production one.
         */
        persistAuthorization: true,
      });
    });
  </script>
</body>
</html>
