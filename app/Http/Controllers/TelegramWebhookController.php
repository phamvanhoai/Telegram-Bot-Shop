<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\RequiredChannel;
use App\Models\User;
use App\Services\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramClient $telegram): JsonResponse
    {
        $expected = (string) config('services.telegram.webhook_secret');
        abort_if($expected === '' || ! hash_equals($expected, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        try {
            $this->handle($request->all(), $telegram);
        } catch (Throwable $exception) {
            Log::error('Telegram update failed', ['update_id' => $request->integer('update_id'), 'exception' => $exception]);
        }

        return response()->json(['ok' => true]);
    }

    private function handle(array $update, TelegramClient $telegram): void
    {
        $message = $update['message'] ?? null;
        $callback = $update['callback_query'] ?? null;
        $from = $message['from'] ?? $callback['from'] ?? null;
        if (! is_array($from) || ! isset($from['id'])) {
            return;
        }

        $user = User::query()->updateOrCreate(['telegram_id' => $from['id']], [
            'name' => trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: 'Telegram User',
            'telegram_username' => $from['username'] ?? null,
            'locale' => substr($from['language_code'] ?? 'en', 0, 10),
        ]);
        if ($user->is_blocked) {
            return;
        }

        $chatId = $message['chat']['id'] ?? $callback['message']['chat']['id'] ?? null;
        $action = $callback['data'] ?? $message['text'] ?? '';
        if ($callback) {
            $telegram->answerCallback($callback['id']);
        }

        match ($action) {
            '/start', 'verify' => $this->verifyAndWelcome($telegram, $user, $chatId),
            'products' => $this->products($telegram, $chatId),
            'balance' => $telegram->sendMessage($chatId, '💰 <b>My Balance</b>'.PHP_EOL.PHP_EOL.'Available: USD '.number_format((float) $user->balance, 2)),
            default => null,
        };
    }

    private function verifyAndWelcome(TelegramClient $telegram, User $user, int|string $chatId): void
    {
        $missing = RequiredChannel::query()->where('is_active', true)->get()
            ->filter(fn (RequiredChannel $channel): bool => ! $telegram->isMember($channel->chat_id, $user->telegram_id));
        if ($missing->isNotEmpty()) {
            $keyboard = $missing->map(fn (RequiredChannel $channel): array => [[
                'text' => '📣 Join '.$channel->name, 'url' => $channel->join_url,
            ]])->values()->all();
            $keyboard[] = [['text' => "✅ I've Joined - Verify", 'callback_data' => 'verify']];
            $telegram->sendMessage($chatId, "🔒 <b>Access Restricted</b>\n\nPlease join our channel(s) below, then tap <b>Verify</b>.", $keyboard);

            return;
        }

        $telegram->sendMessage($chatId, "✅ Verified! You now have full access.\n\n👋 <b>Welcome to our Digital Store</b>\n\n🚀 Your Premium Digital Services Marketplace", [
            [['text' => '🛍 Products', 'callback_data' => 'products']],
            [['text' => '💰 My Balance', 'callback_data' => 'balance'], ['text' => '➕ Deposit', 'callback_data' => 'deposit']],
            [['text' => '📦 Track Order', 'callback_data' => 'track_order'], ['text' => '🆘 Support', 'url' => config('services.telegram.support_url') ?: 'https://t.me/telegram']],
        ]);
    }

    private function products(TelegramClient $telegram, int|string $chatId): void
    {
        $products = Product::query()->where('is_active', true)->where('stock', '>', 0)->orderBy('sort_order')->limit(50)->get();
        $keyboard = $products->map(fn (Product $product): array => [[
            'text' => $product->name.' | USD '.number_format((float) $product->price, 2),
            'callback_data' => 'product:'.$product->id,
        ]])->all();
        $telegram->sendMessage($chatId, "🛍 <b>OUR STORE — PRODUCT CATALOGUE</b>\n\n📦 Available items: <b>{$products->count()}</b>\n👇 Select a product below to see full details.", $keyboard);
    }
}
