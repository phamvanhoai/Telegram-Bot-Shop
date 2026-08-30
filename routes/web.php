<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DepositController as AdminDepositController;
use App\Http\Controllers\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
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
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::patch('/users/{user}/block', [AdminUserController::class, 'toggleBlock'])->name('users.block');
        Route::post('/users/{user}/balance', [AdminUserController::class, 'adjustBalance'])->name('users.balance');
        Route::get('/deposits', [AdminDepositController::class, 'index'])->name('deposits.index');
        Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
        Route::get('/notifications', [AdminNotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications', [AdminNotificationController::class, 'store'])->name('notifications.store');
        Route::get('/notifications/{notification}/edit', [AdminNotificationController::class, 'edit'])->name('notifications.edit');
        Route::put('/notifications/{notification}', [AdminNotificationController::class, 'update'])->name('notifications.update');
        Route::patch('/notifications/{notification}/pause', [AdminNotificationController::class, 'pause'])->name('notifications.pause');
        Route::patch('/notifications/{notification}/resume', [AdminNotificationController::class, 'resume'])->name('notifications.resume');
        Route::patch('/notifications/{notification}/cancel', [AdminNotificationController::class, 'cancel'])->name('notifications.cancel');
        Route::patch('/orders/{order}', [AdminOrderController::class, 'update'])->name('orders.update');
        Route::post('/logout', [AdminAuthController::class, 'destroy'])->name('logout');
    });
});
