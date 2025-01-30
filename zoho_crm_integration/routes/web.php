<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ZohoController;

Route::get('/zoho/modules', [ZohoController::class, 'getModules'])->middleware('zoho.auth');
Route::post('/zoho/create-deal', [ZohoController::class, 'createDeal'])->middleware('zoho.auth');

Route::get('/oauth2callback', [ZohoController::class, 'handleOAuthCallback']);
Route::get('/zoho/refresh-token', [ZohoController::class, 'refreshAccessToken']);

Route::get('/', function () {
    return view('welcome');
});