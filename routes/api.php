<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\TelegramAssistantController;

use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\MasterController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/telegram/webhook', [TelegramAssistantController::class, 'webhook']);
Route::post('/telegram/assistant_webhook', [TelegramAssistantController::class, 'webhook']);


Route::prefix('v1')->middleware('api.access')->group(function () {
    Route::apiResource('orders', OrderController::class);
    Route::apiResource('masters', MasterController::class);
    Route::get('masters/check/{tid}', [MasterController::class, 'checkByTid']);
});
