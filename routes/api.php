<?php

use App\Http\Controllers\RoutingController;
use Illuminate\Support\Facades\Route;

Route::get('/zones', [RoutingController::class, 'zones']);
Route::get('/servers', [RoutingController::class, 'servers']);
Route::get('/route', [RoutingController::class, 'route']);
Route::get('/map/{zone}', [RoutingController::class, 'map']);
Route::get('/map/{zone}/exits', [RoutingController::class, 'mapExits']);
Route::get('/map/{zone}/paths', [RoutingController::class, 'mapPaths']);
