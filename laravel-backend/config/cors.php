<?php

return [
    // Ports the hardcoded CORS policy from backend/main.py, including the
    // Vercel preview/prod wildcard regex. Same-origin VPS serving makes this
    // a no-op in production; it keeps local :3000 static dev working.
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // L-5: same-origin serving makes CORS a no-op in production; localhost
    // entries exist only for the static-file dev servers. Never add wildcard
    // or placeholder origins here.
    'allowed_origins' => [
        'http://localhost:3000',
        'http://localhost:5173',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:5173',
    ],

    // F-07: the legacy `^https://.*\.vercel\.app$` pattern matched ANY attacker
    // subdomain on vercel.app with credentials enabled. Same-origin VPS serving
    // makes CORS a no-op in production; add ONLY your exact preview origin here
    // if you ever split the deployment.
    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Bearer tokens ride in headers, not cookies — no credentialed CORS needed.
    'supports_credentials' => false,
];
