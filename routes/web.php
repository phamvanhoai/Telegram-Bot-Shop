<?php

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['name' => config('app.name'), 'status' => 'ok']);
});

Route::post('/webhooks/telegram', TelegramWebhookController::class);
