<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DigitalPortfolioController;
use App\Http\Controllers\DigitalSolutionController;
use App\Http\Controllers\DigitalSolutionFaqController;
use App\Http\Controllers\DigitalSolutionIndustryController;
use App\Http\Controllers\DigitalSolutionTechController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PledgeController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\SiteContentController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

// --- Health / readiness (public, exempt from rate limiting) ---
Route::get('/health', [HealthController::class, 'health']);
Route::get('/ready', [HealthController::class, 'ready']);

// --- Password authentication for the fixed single-admin slot ---
Route::prefix('auth')->group(function () {
    // Strict per-route throttle keeps password guessing outside the global bucket.
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/logout', [AuthController::class, 'logout']);
});

// --- Public Content & Forms ---
Route::prefix('public')->group(function () {
    Route::get('/blog', [PublicController::class, 'blog']);
    Route::get('/blog/{slug}', [PublicController::class, 'blogBySlug'])->where('slug', '.*');
    Route::get('/events', [PublicController::class, 'events']);
    Route::get('/media/latest', [PublicController::class, 'latestMedia']);
    // F-03: force-refresh wipes the media cache — admin-only.
    Route::get('/media/refresh', [PublicController::class, 'refreshMedia'])->middleware('roi.admin');
    Route::get('/media', [PublicController::class, 'media']);
    Route::get('/metrics', [PublicController::class, 'metrics']);
    Route::get('/site', [SiteContentController::class, 'show']);
    Route::post('/volunteer', [PublicController::class, 'volunteer'])->middleware('throttle:public-form');
    Route::post('/contact', [PublicController::class, 'contact'])->middleware('throttle:public-form');
    Route::get('/leaders', [PublicController::class, 'leaders']);
    Route::get('/events/{eventId}/tickets', [TicketController::class, 'publicEventTickets'])->whereNumber('eventId');
    Route::get('/solutions', [DigitalSolutionController::class, 'index']);
    Route::get('/solutions/{slug}', [DigitalSolutionController::class, 'show']);
    Route::post('/solutions/inquire', [DigitalSolutionController::class, 'inquire'])->middleware('throttle:solution-inquiry');
    Route::get('/solution-faqs', [DigitalSolutionFaqController::class, 'index']);
    Route::get('/industries', [DigitalSolutionIndustryController::class, 'index']);
    Route::get('/tech', [DigitalSolutionTechController::class, 'index']);
    Route::get('/portfolio', [DigitalPortfolioController::class, 'index']);
    Route::get('/portfolio/{slug}', [DigitalPortfolioController::class, 'show']);
});

// --- Event ticketing (public checkout + confirmation) ---
Route::prefix('tickets')->group(function () {
    Route::post('/checkout', [TicketController::class, 'checkout'])->middleware('throttle:ticket-checkout');
    Route::post('/recover', [TicketController::class, 'recover'])->middleware('throttle:ticket-recover');
    Route::post('/lookup-order', [TicketController::class, 'lookupOrder'])->middleware('throttle:ticket-lookup-order');
    Route::get('/portal/{token}', [TicketController::class, 'portal'])->where('token', '.*');
    // M-1/H-3: email-gated endpoints get their own modest buckets so a leaked
    // reference cannot be brute-forced against buyer emails without limit.
    Route::middleware('throttle:ticket-orders')->group(function () {
        Route::get('/orders/{reference}/verify', [TicketController::class, 'verifyOrder']);
        Route::post('/orders/{reference}/stk-retry', [TicketController::class, 'retryStk'])->middleware('throttle:stk-retry');
        Route::get('/orders/{reference}', [TicketController::class, 'showOrder']);
    });
    Route::get('/lookup/{code}', [TicketController::class, 'lookupTicket'])->middleware('throttle:ticket-lookup');
});

// --- Public gate station (open QR check-in, no admin JWT) ---
// Undo is admin-only on purpose: the public route was removed so a leaked
// QR code can no longer un-check anyone in from the door.
Route::prefix('gate')->group(function () {
    Route::get('/tickets/{code}', [TicketController::class, 'gateInspect']);
    Route::post('/tickets/{code}/check-in', [TicketController::class, 'gateCheckIn']);
});

// --- Payment Gateways (Paystack, M-Pesa Daraja) ---
Route::prefix('payments')->group(function () {
    Route::get('/paybills', [PaymentController::class, 'paybills']);
    // M-1: each hit inserts a ledger row and can invoke live gateway APIs /
    // dispatch real M-Pesa STK prompts — never ride the global 120/min alone.
    Route::post('/checkout', [PaymentController::class, 'checkout'])->middleware('throttle:payments-checkout');
    Route::get('/verify/{reference}', [PaymentController::class, 'verify'])->middleware('throttle:payments-verify');
    // Hosted Paystack manage link (view card / cancel pledge) for monthly
    // references — M-2: its own named limiter, not the shared verify bucket.
    Route::get('/subscription/{reference}/manage', [PaymentController::class, 'subscriptionManage'])->middleware('throttle:payments-manage');
    Route::post('/webhook/mpesa', [PaymentController::class, 'mpesaWebhook']);
    Route::post('/webhook/paystack', [PaymentController::class, 'paystackWebhook']);
});

// --- Monthly pledge collection (M-Pesa-first, spec §1–§10) ---
Route::prefix('pledges')->group(function () {
    // Creation dispatches a real STK prompt — tight per-IP bucket, never the
    // global limiter alone (spec §18).
    Route::post('/', [PledgeController::class, 'store'])->middleware('throttle:pledges-create');

    // Secure pay-link endpoints: keyed by the HMAC token so one leaked or
    // brute-forced link cannot hammer the gateway, plus a per-IP backstop.
    // Status polling gets its own bucket so it can't starve the POST (§10).
    Route::get('/pay/{token}/status', [PledgeController::class, 'payStatus'])
        ->middleware('throttle:pledges-pay-status')
        ->where('token', '[A-Za-z0-9_\-.]+');
    Route::post('/pay/{token}', [PledgeController::class, 'pay'])
        ->middleware('throttle:pledges-pay')
        ->where('token', '[A-Za-z0-9_\-.]+');
    // §17: the same secure token can cancel the whole pledge (idempotent).
    Route::post('/pay/{token}/cancel', [PledgeController::class, 'cancel'])
        ->middleware('throttle:pledges-pay')
        ->where('token', '[A-Za-z0-9_\-.]+');
});

// --- YouTube Data API v3 Synchronization Engine ---
Route::prefix('youtube')->group(function () {
    Route::post('/cron-sync', [MediaController::class, 'cronSync']);
    Route::get('/channel-videos', [MediaController::class, 'channelVideos']);
});

// --- Singular Secure Admin Console (JWT-guarded) ---
Route::prefix('admin')->middleware('roi.admin')->group(function () {
    Route::get('/stats', [AdminController::class, 'stats']);
    Route::get('/audit-logs', [AdminController::class, 'auditLogs']);

    Route::get('/site', [SiteContentController::class, 'adminShow']);
    Route::put('/site', [SiteContentController::class, 'update']);
    Route::post('/uploads', [UploadController::class, 'store']);

    Route::post('/blog', [AdminController::class, 'createBlog']);
    Route::put('/blog/{postId}', [AdminController::class, 'updateBlog'])->whereNumber('postId');
    Route::delete('/blog/{postId}', [AdminController::class, 'deleteBlog'])->whereNumber('postId');

    Route::post('/events', [AdminController::class, 'createEvent']);
    Route::put('/events/{eventId}', [AdminController::class, 'updateEvent'])->whereNumber('eventId');
    Route::delete('/events/{eventId}', [AdminController::class, 'deleteEvent'])->whereNumber('eventId');

    Route::get('/volunteers/export', [AdminController::class, 'exportVolunteersCsv']);
    Route::get('/volunteers', [AdminController::class, 'volunteers']);

    Route::get('/inquiries', [AdminController::class, 'inquiries']);
    Route::put('/inquiries/{inquiryId}/read', [AdminController::class, 'markInquiryRead'])->whereNumber('inquiryId');

    Route::post('/media/sync', [AdminController::class, 'mediaSync']);

    Route::post('/leaders', [AdminController::class, 'createLeader']);
    Route::put('/leaders/{leaderId}', [AdminController::class, 'updateLeader'])->whereNumber('leaderId');
    Route::delete('/leaders/{leaderId}', [AdminController::class, 'deleteLeader'])->whereNumber('leaderId');

    Route::get('/ticket-types', [TicketController::class, 'adminTicketTypes']);
    Route::post('/ticket-types', [TicketController::class, 'createTicketType']);
    Route::put('/ticket-types/{typeId}', [TicketController::class, 'updateTicketType'])->whereNumber('typeId');
    Route::delete('/ticket-types/{typeId}', [TicketController::class, 'deleteTicketType'])->whereNumber('typeId');
    Route::get('/ticket-stats', [TicketController::class, 'adminStats']);
    Route::get('/ticket-orders/export', [TicketController::class, 'exportOrdersCsv']);
    Route::get('/ticket-orders', [TicketController::class, 'adminOrders']);
    Route::get('/tickets/{code}', [TicketController::class, 'inspectTicket']);
    Route::post('/tickets/{code}/check-in', [TicketController::class, 'checkIn']);
    Route::post('/tickets/{code}/undo-check-in', [TicketController::class, 'undoCheckIn']);

    Route::get('/solutions', [DigitalSolutionController::class, 'adminIndex']);
    Route::post('/solutions', [DigitalSolutionController::class, 'adminStore']);
    Route::put('/solutions/reorder', [DigitalSolutionController::class, 'reorder']);
    Route::put('/solutions/{id}', [DigitalSolutionController::class, 'adminUpdate'])->whereNumber('id');
    Route::delete('/solutions/{id}', [DigitalSolutionController::class, 'adminDestroy'])->whereNumber('id');
    Route::get('/solution-inquiries', [DigitalSolutionController::class, 'adminInquiries']);
    Route::get('/solution-inquiries/stats', [DigitalSolutionController::class, 'inquiryStats']);
    Route::put('/solution-inquiries/{id}', [DigitalSolutionController::class, 'updateInquiry'])->whereNumber('id');
    Route::put('/solution-inquiries/{id}/read', [DigitalSolutionController::class, 'markInquiryRead'])->whereNumber('id');

    Route::get('/solution-faqs', [DigitalSolutionFaqController::class, 'adminIndex']);
    Route::post('/solution-faqs', [DigitalSolutionFaqController::class, 'adminStore']);
    Route::put('/solution-faqs/reorder', [DigitalSolutionFaqController::class, 'reorder']);
    Route::put('/solution-faqs/{id}', [DigitalSolutionFaqController::class, 'adminUpdate'])->whereNumber('id');
    Route::delete('/solution-faqs/{id}', [DigitalSolutionFaqController::class, 'adminDestroy'])->whereNumber('id');

    Route::get('/industries', [DigitalSolutionIndustryController::class, 'adminIndex']);
    Route::post('/industries', [DigitalSolutionIndustryController::class, 'adminStore']);
    Route::put('/industries/reorder', [DigitalSolutionIndustryController::class, 'reorder']);
    Route::put('/industries/{id}', [DigitalSolutionIndustryController::class, 'adminUpdate'])->whereNumber('id');
    Route::delete('/industries/{id}', [DigitalSolutionIndustryController::class, 'adminDestroy'])->whereNumber('id');

    Route::get('/tech', [DigitalSolutionTechController::class, 'adminIndex']);
    Route::post('/tech', [DigitalSolutionTechController::class, 'adminStore']);
    Route::put('/tech/reorder', [DigitalSolutionTechController::class, 'reorder']);
    Route::put('/tech/{id}', [DigitalSolutionTechController::class, 'adminUpdate'])->whereNumber('id');
    Route::delete('/tech/{id}', [DigitalSolutionTechController::class, 'adminDestroy'])->whereNumber('id');

    Route::get('/portfolio', [DigitalPortfolioController::class, 'adminIndex']);
    Route::post('/portfolio', [DigitalPortfolioController::class, 'store']);
    Route::put('/portfolio/{id}', [DigitalPortfolioController::class, 'update'])->whereNumber('id');
    Route::delete('/portfolio/{id}', [DigitalPortfolioController::class, 'destroy'])->whereNumber('id');
});
