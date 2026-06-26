<?php

use Illuminate\Support\Facades\Route;

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
});
