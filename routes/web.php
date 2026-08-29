<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DepositController as AdminDepositController;
use App\Http\Controllers\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['name' => config('app.name'), 'status' => 'ok']);
});

Route::post('/webhooks/telegram', TelegramWebhookController::class);

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', [AdminAuthController::class, 'create'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'store'])->middleware('throttle:6,1')->name('login.store');

    Route::middleware(['auth', 'admin'])->group(function (): void {
        Route::get('/', AdminDashboardController::class)->name('dashboard');
        Route::resource('products', AdminProductController::class)->except('show');
        Route::get('/deposits', [AdminDepositController::class, 'index'])->name('deposits.index');
        Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
        Route::get('/notifications', [AdminNotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications', [AdminNotificationController::class, 'store'])->name('notifications.store');
        Route::patch('/orders/{order}', [AdminOrderController::class, 'update'])->name('orders.update');
        Route::post('/logout', [AdminAuthController::class, 'destroy'])->name('logout');
    });
});
