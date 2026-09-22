{{--
    Browsable reference of the REST contract, at `GET /api/docs`.

    A view and not a file under `public/`: nginx would find the directory and
    redirect to it, rebuilding the Location without the published port.

    Swagger UI is vendored under `public/swagger-ui/` — NFR-08 leaves no room
    for a CDN. See PROVENANCE.txt there.
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
        // Same origin as the API, so «Try it out» needs no CORS.
        url: '{{ route('api.docs.openapi', absolute: false) }}',
        dom_id: '#swagger-ui',

        // Base preset only: the standalone one adds a field for an
        // arbitrary description URL, which this page has no use for.
        presets: [SwaggerUIBundle.presets.apis],
        layout: 'BaseLayout',

        // Otherwise Swagger UI posts the whole description to
        // validator.swagger.io to draw a badge.
        validatorUrl: null,

        // Sixty-two operations: expanded in full the page is unreadable.
        docExpansion: 'list',
        deepLinking: true,
        filter: true,
        tryItOutEnabled: true,
        displayRequestDuration: true,
        defaultModelsExpandDepth: 1,
        defaultModelRendering: 'example',

        // Keeps the token across reloads, in local storage. Fine for a
        // development surface, not for a production one.
        persistAuthorization: true,
      });
    });
  </script>
</body>
</html>
