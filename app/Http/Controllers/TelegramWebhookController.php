<?php

namespace App\Http\Controllers;

use App\Models\DepositMethod;
use App\Models\DepositRequest;
use App\Models\Product;
use App\Models\RequiredChannel;
use App\Models\User;
use App\Services\BinancePayClient;
use App\Services\TelegramClient;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramClient $telegram): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        abort_if($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

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
        if (! in_array($action, ['/start', 'verify'], true)) {
            $missingChannels = $this->missingChannels($telegram, $user);
            if ($missingChannels->isNotEmpty()) {
                $this->sendAccessRestricted($telegram, $chatId, $missingChannels);

                return;
            }
        }
        if ($action === 'cancel') {
            Cache::forget('telegram-state:'.$user->id);
            $telegram->sendMessage($chatId, 'Payment cancelled.');

            return;
        }

        match ($action) {
            '/start', 'verify' => $this->verifyAndWelcome($telegram, $user, $chatId),
            'products' => $this->products($telegram, $chatId),
            'balance' => $telegram->sendMessage($chatId, '<b>My Balance</b>'.PHP_EOL.PHP_EOL.'Available: USD '.number_format((float) $user->balance, 2)),
            'deposit' => $this->beginDeposit($telegram, $user, $chatId),
            default => $message ? $this->handleDepositInput($telegram, $user, $chatId, (string) $action) : null,
        };
    }

    private function beginDeposit(TelegramClient $telegram, User $user, int|string $chatId): void
    {
        Cache::put('telegram-state:'.$user->id, ['step' => 'deposit_amount'], now()->addMinutes(30));
        $telegram->sendMessage($chatId, "+ <b>Deposit Balance</b>\n\nSend deposit amount in USDT.\nExample: <code>25</code>", [
            [['text' => 'Cancel', 'callback_data' => 'cancel']],
        ]);
    }

    private function handleDepositInput(TelegramClient $telegram, User $user, int|string $chatId, string $input): void
    {
        $key = 'telegram-state:'.$user->id;
        $state = Cache::get($key);
        if (! is_array($state)) {
            return;
        }

        if (($state['step'] ?? null) === 'deposit_amount') {
            $amount = trim($input);
            if (! preg_match('/^\d+(\.\d{1,8})?$/', $amount) || bccomp($amount, '0.1', 8) < 0 || bccomp($amount, '100000', 8) > 0) {
                $telegram->sendMessage($chatId, 'Invalid amount. Enter a value from 0.10 to 100,000 USDT.');

                return;
            }
            $method = DepositMethod::query()->firstOrCreate(['code' => 'binance_pay'], [
                'name' => 'Binance Pay', 'verification' => 'automatic',
                'settings' => ['pay_id' => config('services.binance.pay_id')], 'is_active' => true,
            ]);
            $deposit = DepositRequest::query()->create([
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'deposit_method_id' => $method->id,
                'amount' => $amount, 'status' => 'pending', 'expires_at' => now()->addMinutes(30),
            ]);
            Cache::put($key, ['step' => 'deposit_txid', 'deposit_id' => $deposit->id], $deposit->expires_at);
            $telegram->sendMessage($chatId, "<b>Binance Pay</b>\n\nSend exactly <b>{$amount} USDT</b> to Binance Pay ID:\n<code>".e((string) config('services.binance.pay_id'))."</code>\n\nTime limit: 30 minutes.\nAfter payment, send the Transaction ID here.", [
                [['text' => 'Cancel Payment', 'callback_data' => 'cancel']],
            ]);

            return;
        }

        if (($state['step'] ?? null) !== 'deposit_txid') {
            return;
        }
        $txid = trim($input);
        if (! preg_match('/^[A-Za-z0-9_-]{8,100}$/', $txid)) {
            $telegram->sendMessage($chatId, 'Invalid Transaction ID. Copy it from Binance Pay transaction details.');

            return;
        }
        if (DepositRequest::query()->where('txid', $txid)->exists()) {
            $telegram->sendMessage($chatId, 'This Transaction ID has already been used.');

            return;
        }

        $deposit = DepositRequest::query()->findOrFail($state['deposit_id']);
        if ($deposit->expires_at->isPast()) {
            $deposit->update(['status' => 'expired']);
            Cache::forget($key);
            $telegram->sendMessage($chatId, 'This deposit request has expired. Please create a new one.');

            return;
        }
        $deposit->update(['txid' => $txid, 'status' => 'verifying']);
        $transaction = app(BinancePayClient::class)->findIncoming($txid, (string) $deposit->amount, $deposit->created_at);
        if ($transaction === null) {
            $deposit->update(['txid' => null, 'status' => 'pending']);
            $telegram->sendMessage($chatId, 'Payment is not confirmed yet. Check the ID, amount and currency, then try again shortly.');

            return;
        }

        app(WalletService::class)->approveDeposit($deposit, $transaction);
        Cache::forget($key);
        $telegram->sendMessage($chatId, "<b>Deposit Approved</b>\n\nAmount: {$deposit->amount} USDT\nYour balance has been updated.", [
            [['text' => 'Products', 'callback_data' => 'products'], ['text' => 'My Balance', 'callback_data' => 'balance']],
        ]);
    }

    private function verifyAndWelcome(TelegramClient $telegram, User $user, int|string $chatId): void
    {
        $missingChannels = $this->missingChannels($telegram, $user);
        if ($missingChannels->isNotEmpty()) {
            $this->sendAccessRestricted($telegram, $chatId, $missingChannels);

            return;
        }
        $telegram->sendMessage($chatId, "<b>Verified! You now have full access.</b>\n\nWelcome to our Digital Store", [
            [['text' => 'Products', 'callback_data' => 'products']],
            [['text' => 'My Balance', 'callback_data' => 'balance'], ['text' => 'Deposit', 'callback_data' => 'deposit']],
            [['text' => 'Track Order', 'callback_data' => 'track_order'], ['text' => 'Support', 'url' => config('services.telegram.support_url') ?: 'https://t.me/telegram']],
        ]);
    }

    private function missingChannels(TelegramClient $telegram, User $user): Collection
    {
        return RequiredChannel::query()->where('is_active', true)->get()
            ->filter(fn (RequiredChannel $channel): bool => ! $telegram->isMember($channel->chat_id, $user->telegram_id));
    }

    private function sendAccessRestricted(TelegramClient $telegram, int|string $chatId, Collection $missingChannels): void
    {
        $keyboard = $missingChannels->map(fn (RequiredChannel $channel): array => [[
            'text' => 'Join '.$channel->name, 'url' => $channel->join_url,
        ]])->values()->all();
        $keyboard[] = [['text' => "I've Joined - Verify", 'callback_data' => 'verify']];
        $telegram->sendMessage($chatId, "<b>Access Restricted</b>\n\nPlease join all required channels and groups, then tap <b>Verify</b>.", $keyboard);
    }

    private function products(TelegramClient $telegram, int|string $chatId): void
    {
        $products = Product::query()->where('is_active', true)->where('stock', '>', 0)->orderBy('sort_order')->limit(50)->get();
        $keyboard = $products->map(fn (Product $product): array => [[
            'text' => $product->name.' | USD '.number_format((float) $product->price, 2), 'callback_data' => 'product:'.$product->id,
        ]])->all();
        $telegram->sendMessage($chatId, "<b>OUR STORE - PRODUCT CATALOGUE</b>\n\nAvailable items: <b>{$products->count()}</b>\nSelect a product below.", $keyboard);
    }
}
