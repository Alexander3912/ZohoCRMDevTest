<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ZohoController;

Route::post('/zoho/create-deal', [ZohoController::class, 'createDeal']);
Route::get('/oauth2callback', [ZohoController::class, 'handleOAuthCallback']);
Route::get('/zoho/refresh-token', [ZohoController::class, 'refreshAccessToken']);
Route::get('/zoho/modules', [ZohoController::class, 'getModules']);
Route::get('/', function () {
    return view('welcome');
});
