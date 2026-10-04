<?php

use App\Http\Controllers\PledgeController;
use Illuminate\Support\Facades\Route;

// Serves the compiled vanilla SPA shell. The build deploys it to public/index.html;
// nginx/artisan serve static files first, so this route is the portable fallback.
$serveShell = function () {
    $shell = public_path('index.html');

    if (! file_exists($shell)) {
        return response(
            "<!doctype html><html><head><meta charset='utf-8'><title>ROI Platform</title></head>".
            "<body style='font-family:sans-serif;background:#0f172a;color:#e2e8f0;padding:3rem'>".
            '<h1>Reaching Out Initiative API</h1><p>The frontend build was not found at <code>public/index.html</code>.</p>'.
            '<p>Build it with <code>cd frontend &amp;&amp; npm run build</code>, or use the API directly under <code>/api</code>.</p>'.
            '</body></html>',
            200
        )->header('Content-Type', 'text/html');
    }

    return response(file_get_contents($shell), 200)
        ->header('Content-Type', 'text/html; charset=utf-8');
};

Route::get('/', $serveShell);

// Open gate station: served as a standalone SPA page at the real path /checking
// (the frontend detects this path and renders the QR check-in screen, no auth).
Route::get('/checking', $serveShell);
Route::get('/checking/', $serveShell);

// Secure monthly-pledge payment page (spec §7). Registered BEFORE the SPA
// fallback; the token's dotted charset also guarantees the fallback's [^.]*
// rule can never match it. Own throttle bucket: page loads must not starve
// POST /pay or status polling.
Route::get('/pledges/pay/{token}', [PledgeController::class, 'payPage'])
    ->where('token', '[A-Za-z0-9_\-.]+')
    ->middleware('throttle:pledges-pay-page')
    ->name('pledges.pay');

// SPA client-side routing fallback: serve the compiled shell for any extension-less
// non-API path so deep links (e.g. /about, /admin/login) and hard refreshes work.
// Paths that look like files (contain a dot) or live under /api return 404 instead.
Route::get('/{any}', $serveShell)->where('any', '^(?!api(?:/|$))[^.]*$');
