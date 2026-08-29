<?php

use App\Models\DepositRequest;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    DepositRequest::query()
        ->whereIn('status', ['pending', 'verifying'])
        ->where('expires_at', '<=', now())
        ->update(['status' => 'expired']);
})->name('expire-deposit-requests')->everyMinute()->withoutOverlapping();
