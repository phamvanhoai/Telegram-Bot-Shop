<?php

use App\Models\DepositRequest;
use App\Models\NotificationRecipient;
use App\Services\TelegramClient;
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

Schedule::call(function (): void {
    NotificationRecipient::query()->with(['broadcast', 'user'])
        // Eight five-second requests fit safely inside the one-minute cron slot.
        ->where('status', 'pending')
        ->whereHas('broadcast', fn ($query) => $query->where('status', 'sending'))
        ->oldest()->limit(8)->get()
        ->each(function (NotificationRecipient $recipient): void {
            $broadcast = $recipient->broadcast->refresh();
            if ($broadcast->status !== 'sending') {
                return;
            }
            $keyboard = $broadcast->button_text && $broadcast->button_url
                ? [[['text' => $broadcast->button_text, 'url' => $broadcast->button_url]]]
                : [];
            try {
                app(TelegramClient::class)->sendBroadcast(
                    $recipient->chat_id ?: $recipient->user?->telegram_id,
                    '<b>'.e($broadcast->title)."</b>\n\n".$broadcast->message,
                    $broadcast->image_url,
                    $keyboard,
                );
                $recipient->update(['status' => 'sent', 'sent_at' => now(), 'attempts' => $recipient->attempts + 1]);
            } catch (Throwable $exception) {
                $attempts = $recipient->attempts + 1;
                $recipient->update([
                    'status' => $attempts >= 3 ? 'failed' : 'pending',
                    'attempts' => $attempts,
                    'error' => str($exception->getMessage())->limit(1000),
                ]);
            }

            $sent = $broadcast->recipients()->where('status', 'sent')->count();
            $failed = $broadcast->recipients()->where('status', 'failed')->count();
            $broadcast->update([
                'sent_count' => $sent,
                'failed_count' => $failed,
                'completed_at' => ($sent + $failed) >= $broadcast->recipient_count ? now() : null,
                'status' => ($sent + $failed) >= $broadcast->recipient_count ? 'completed' : 'sending',
            ]);
        });
})->name('send-telegram-broadcasts')->everyMinute()->withoutOverlapping(5);
