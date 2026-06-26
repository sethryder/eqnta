<?php

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// The planner is a static client-side app — all data loads via stateless GET /api/*,
// and the page uses no session or CSRF. Stripping the stateful web middleware drops the
// Set-Cookie headers that make the HTML uncacheable, then cache.headers marks it public.
// (Cloudflare also needs a Cache Rule to actually cache HTML — see deploy notes.)
Route::get('/', function () {
    $jsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'WebApplication',
        'name' => 'Norrath Transit Authority',
        'applicationCategory' => 'UtilitiesApplication',
        'description' => 'Zone trip planner for EverQuest emulator servers. Routes overland runs, boats, Plane of Knowledge books, spires, and class teleports into a single itinerary.',
        'url' => url('/'),
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    return view('planner', ['jsonLd' => $jsonLd]);
})
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->middleware('cache.headers:public;max_age=0;s_maxage=86400;must_revalidate;etag');
