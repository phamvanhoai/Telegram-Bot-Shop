<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationBroadcast;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(): View
    {
        return view('admin.notifications.index', [
            'broadcasts' => NotificationBroadcast::query()->latest()->paginate(15),
            'customerCount' => User::query()->whereNotNull('telegram_id')->where('is_blocked', false)->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:1000'],
            'image_url' => ['nullable', 'url:https', 'max:2000'],
            'button_text' => ['nullable', 'required_with:button_url', 'string', 'max:64'],
            'button_url' => ['nullable', 'required_with:button_text', 'url:https', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $data): void {
            $broadcast = NotificationBroadcast::query()->create($data + ['created_by' => $request->user()->id]);
            User::query()->whereNotNull('telegram_id')->where('is_blocked', false)->select('id')->chunkById(500, function ($users) use ($broadcast): void {
                $now = now();
                DB::table('notification_recipients')->insert($users->map(fn (User $user): array => [
                    'notification_broadcast_id' => $broadcast->id,
                    'user_id' => $user->id,
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
            $broadcast->update(['recipient_count' => $broadcast->recipients()->count()]);
        });

        return back()->with('success', 'Thông báo đã được đưa vào hàng đợi gửi.');
    }
}
