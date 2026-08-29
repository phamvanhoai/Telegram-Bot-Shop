<?php

namespace App\Http\Controllers;

use App\Models\DepositMethod;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\RequiredChannel;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\BinancePayClient;
use App\Services\TelegramClient;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            $telegram->sendMessage($chatId, "❌ <b>Action Cancelled</b>\n\nNo changes were made.", $this->homeButton());

            return;
        }
        if (str_starts_with((string) $action, 'product:')) {
            $this->productDetail($telegram, $chatId, (int) Str::after((string) $action, 'product:'));

            return;
        }
        if (str_starts_with((string) $action, 'buy:')) {
            $this->beginPurchase($telegram, $user, $chatId, (int) Str::after((string) $action, 'buy:'));

            return;
        }
        if (str_starts_with((string) $action, 'quantity:')) {
            $state = Cache::get('telegram-state:'.$user->id, []);
            $this->checkout($telegram, $user, $chatId, $state, Str::after((string) $action, 'quantity:'));

            return;
        }

        match ($action) {
            '/start', 'verify' => $this->verifyAndWelcome($telegram, $user, $chatId),
            'home' => $this->welcome($telegram, $chatId),
            'products' => $this->products($telegram, $chatId),
            'balance' => $this->balance($telegram, $user, $chatId),
            'deposit' => $this->beginDeposit($telegram, $user, $chatId),
            'track_order' => $this->beginOrderTracking($telegram, $user, $chatId),
            'support' => $this->support($telegram, $chatId),
            'confirm_purchase' => $this->confirmPurchase($telegram, $user, $chatId),
            default => $message ? $this->handleTextInput($telegram, $user, $chatId, (string) $action) : null,
        };
    }

    private function beginDeposit(TelegramClient $telegram, User $user, int|string $chatId): void
    {
        Cache::put('telegram-state:'.$user->id, ['step' => 'deposit_amount'], now()->addMinutes(30));
        $telegram->sendMessage($chatId, "➕ <b>DEPOSIT BALANCE</b>\n\n💵 Currency: <b>USDT</b>\n📌 Minimum: <b>0.10 USDT</b>\n\nSend the amount you want to deposit.\nExample: <code>25</code>", [
            [['text' => '❌ Cancel', 'callback_data' => 'cancel']],
        ]);
    }

    private function handleTextInput(TelegramClient $telegram, User $user, int|string $chatId, string $input): void
    {
        $key = 'telegram-state:'.$user->id;
        $state = Cache::get($key);
        if (! is_array($state)) {
            return;
        }

        if (($state['step'] ?? null) === 'track_order') {
            $this->trackOrder($telegram, $user, $chatId, trim($input));

            return;
        }

        if (($state['step'] ?? null) === 'purchase_quantity') {
            $this->checkout($telegram, $user, $chatId, $state, trim($input));

            return;
        }

        if (($state['step'] ?? null) === 'deposit_amount') {
            $amount = trim($input);
            if (! preg_match('/^\d+(\.\d{1,8})?$/', $amount) || bccomp($amount, '0.1', 8) < 0 || bccomp($amount, '100000', 8) > 0) {
                $telegram->sendMessage($chatId, "⚠️ <b>Invalid Amount</b>\n\nEnter a value from <b>0.10</b> to <b>100,000 USDT</b>.");

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
            $telegram->sendMessage($chatId, "💳 <b>BINANCE PAY</b>\n\n💵 Send exactly: <b>{$amount} USDT</b>\n🆔 Binance Pay ID:\n<code>".e((string) config('services.binance.pay_id'))."</code>\n\n⏳ <b>Time limit: 30 minutes</b>\n⚠️ Sending another amount may delay verification.\n\nAfter payment, copy and send the <b>Transaction ID</b> here.", [
                [['text' => '❌ Cancel Payment', 'callback_data' => 'cancel']],
            ]);

            return;
        }

        if (($state['step'] ?? null) !== 'deposit_txid') {
            return;
        }
        $txid = trim($input);
        if (! preg_match('/^[A-Za-z0-9_-]{8,100}$/', $txid)) {
            $telegram->sendMessage($chatId, "⚠️ <b>Invalid Transaction ID</b>\n\nCopy the ID from your Binance Pay transaction details and try again.");

            return;
        }
        if (DepositRequest::query()->where('txid', $txid)->exists()) {
            $telegram->sendMessage($chatId, "🚫 <b>Transaction Already Used</b>\n\nEach Transaction ID can only be credited once.");

            return;
        }

        $deposit = DepositRequest::query()->findOrFail($state['deposit_id']);
        if ($deposit->expires_at->isPast()) {
            $deposit->update(['status' => 'expired']);
            Cache::forget($key);
            $telegram->sendMessage($chatId, "⌛ <b>Payment Request Expired</b>\n\nPlease create a new deposit request.", $this->homeButton());

            return;
        }
        $deposit->update(['txid' => $txid, 'status' => 'verifying']);
        $transaction = app(BinancePayClient::class)->findIncoming($txid, (string) $deposit->amount, $deposit->created_at);
        if ($transaction === null) {
            $deposit->update(['txid' => null, 'status' => 'pending']);
            $telegram->sendMessage($chatId, "🔎 <b>Payment Not Found</b>\n\nCheck the Transaction ID, amount and currency. If you have just paid, wait a moment and send the ID again.");

            return;
        }

        app(WalletService::class)->approveDeposit($deposit, $transaction);
        Cache::forget($key);
        $telegram->sendMessage($chatId, "✅ <b>DEPOSIT APPROVED</b>\n\n💵 Amount: <b>{$deposit->amount} USDT</b>\n💰 Your wallet balance has been updated successfully.", [
            [['text' => '🛍 Browse Products', 'callback_data' => 'products'], ['text' => '💰 My Balance', 'callback_data' => 'balance']],
            [['text' => '🏠 Main Menu', 'callback_data' => 'home']],
        ]);
    }

    private function verifyAndWelcome(TelegramClient $telegram, User $user, int|string $chatId): void
    {
        $missingChannels = $this->missingChannels($telegram, $user);
        if ($missingChannels->isNotEmpty()) {
            $this->sendAccessRestricted($telegram, $chatId, $missingChannels);

            return;
        }
        $this->welcome($telegram, $chatId, true);
    }

    private function missingChannels(TelegramClient $telegram, User $user): Collection
    {
        return RequiredChannel::query()->where('is_active', true)->get()
            ->filter(fn (RequiredChannel $channel): bool => ! $telegram->isMember($channel->chat_id, $user->telegram_id));
    }

    private function sendAccessRestricted(TelegramClient $telegram, int|string $chatId, Collection $missingChannels): void
    {
        $keyboard = $missingChannels->map(fn (RequiredChannel $channel): array => [[
            'text' => '📣 Join '.$channel->name, 'url' => $channel->join_url,
        ]])->values()->all();
        $keyboard[] = [['text' => "✅ I've Joined — Verify", 'callback_data' => 'verify']];
        $telegram->sendMessage($chatId, "🔒 <b>ACCESS RESTRICTED</b>\n\nTo use KoDuck Shop, please join all communities listed below.\n\nAfter joining, tap <b>Verify</b> to unlock full access.", $keyboard);
    }

    private function products(TelegramClient $telegram, int|string $chatId): void
    {
        $products = Product::query()->where('is_active', true)->where('stock', '>', 0)->orderBy('sort_order')->limit(50)->get();
        $keyboard = $products->map(fn (Product $product): array => [[
            'text' => '▰ '.$product->name.'  •  $'.number_format((float) $product->price, 2), 'callback_data' => 'product:'.$product->id,
        ]])->all();
        $keyboard[] = [['text' => '🏠 Back to Main Menu', 'callback_data' => 'home']];
        $telegram->sendMessage($chatId, "🛍 <b>KODUCK SHOP — PRODUCT CATALOGUE</b>\n\n📦 Available products: <b>{$products->count()}</b>\n⚡ Fast ordering  •  🛡 Warranty covered\n🔐 Secure wallet checkout\n\n👇 Select a product to view full details.", $keyboard);
    }

    private function welcome(TelegramClient $telegram, int|string $chatId, bool $verified = false): void
    {
        $prefix = $verified ? "✅ <b>Verification successful!</b>\n\n" : '';
        $telegram->sendMessage($chatId, $prefix."👋 <b>WELCOME TO KODUCK SHOP</b>\n\n🚀 Your premium digital services marketplace\n\n💎 Premium Products\n⚡ Fast Delivery\n🔐 Secure Payments\n🛡 Trusted Support\n\nChoose a service below to get started.", [
            [['text' => '🛍 Browse Products', 'callback_data' => 'products']],
            [['text' => '💰 My Balance', 'callback_data' => 'balance'], ['text' => '➕ Deposit Funds', 'callback_data' => 'deposit']],
            [['text' => '📦 Track My Order', 'callback_data' => 'track_order'], ['text' => '🆘 Customer Support', 'callback_data' => 'support']],
        ]);
    }

    private function balance(TelegramClient $telegram, User $user, int|string $chatId): void
    {
        $telegram->sendMessage($chatId, "💰 <b>MY WALLET</b>\n\n💵 Available balance:\n<b>USD ".number_format((float) $user->balance, 2)."</b>\n\nYour wallet can be used for instant and secure checkout.", [
            [['text' => '➕ Deposit Funds', 'callback_data' => 'deposit'], ['text' => '🛍 Browse Products', 'callback_data' => 'products']],
            [['text' => '🏠 Main Menu', 'callback_data' => 'home']],
        ]);
    }

    private function productDetail(TelegramClient $telegram, int|string $chatId, int $productId): void
    {
        $product = Product::query()->where('is_active', true)->find($productId);
        if (! $product) {
            $telegram->sendMessage($chatId, "⚠️ <b>Product Unavailable</b>\n\nThis product may have been removed or temporarily disabled.", $this->homeButton());

            return;
        }
        $delivery = $product->delivery_type === 'automatic' ? 'Automatic delivery' : 'Manual delivery by admin';
        $telegram->sendMessage($chatId, '📦 <b>'.e($product->name)."</b>\n\n📝 <b>Product Details</b>\n".e($product->description ?: 'Premium digital product with reliable support.')."\n\n💵 <b>Price:</b> USD ".number_format((float) $product->price, 2)." / item\n📦 <b>Availability:</b> ".($product->stock > 0 ? "In stock ({$product->stock})" : 'Out of stock')."\n🛡 <b>Warranty:</b> {$product->warranty_days} days\n⚡ <b>Delivery:</b> {$delivery}\n\n🔐 Secure checkout from your wallet balance.", [
            [['text' => '🛒 Buy Now', 'callback_data' => 'buy:'.$product->id]],
            [['text' => '💰 My Balance', 'callback_data' => 'balance'], ['text' => '➕ Deposit', 'callback_data' => 'deposit']],
            [['text' => '⬅️ Back to Products', 'callback_data' => 'products']],
        ]);
    }

    private function beginOrderTracking(TelegramClient $telegram, User $user, int|string $chatId): void
    {
        Cache::put('telegram-state:'.$user->id, ['step' => 'track_order'], now()->addMinutes(10));
        $telegram->sendMessage($chatId, "📦 <b>TRACK YOUR ORDER</b>\n\nSend your Order ID to view its latest status.\nExample: <code>8641234567</code>\n\nOrder IDs are shown in your confirmation message.", [
            [['text' => '❌ Cancel', 'callback_data' => 'cancel']],
        ]);
    }

    private function beginPurchase(TelegramClient $telegram, User $user, int|string $chatId, int $productId): void
    {
        $product = Product::query()->where('is_active', true)->where('stock', '>', 0)->find($productId);
        if (! $product) {
            $telegram->sendMessage($chatId, "⚠️ <b>Product Unavailable</b>\n\nThis product is currently out of stock.", $this->homeButton());

            return;
        }
        Cache::put('telegram-state:'.$user->id, ['step' => 'purchase_quantity', 'product_id' => $product->id], now()->addMinutes(15));
        $telegram->sendMessage($chatId, "🧾 <b>SELECT QUANTITY</b>\n\n📦 Product: <b>".e($product->name)."</b>\n💵 Unit price: <b>USD ".number_format((float) $product->price, 2)."</b>\n📊 Available stock: <b>{$product->stock}</b>\n\nSend the quantity you want to purchase.", [
            [['text' => '1 item', 'callback_data' => 'quantity:1'], ['text' => '2 items', 'callback_data' => 'quantity:2']],
            [['text' => '❌ Cancel', 'callback_data' => 'cancel']],
        ]);
    }

    private function checkout(TelegramClient $telegram, User $user, int|string $chatId, array $state, string $input): void
    {
        if (($state['step'] ?? null) !== 'purchase_quantity' || ! isset($state['product_id'])) {
            $telegram->sendMessage($chatId, "⌛ <b>Selection Expired</b>\n\nPlease choose the product again.", $this->homeButton());

            return;
        }
        if (! ctype_digit($input) || (int) $input < 1) {
            $telegram->sendMessage($chatId, "⚠️ <b>Invalid Quantity</b>\n\nSend a whole number greater than zero.");

            return;
        }
        $product = Product::query()->findOrFail($state['product_id']);
        $quantity = (int) $input;
        if ($quantity > $product->stock) {
            $telegram->sendMessage($chatId, "⚠️ <b>Insufficient Stock</b>\n\nOnly <b>{$product->stock}</b> item(s) are currently available.");

            return;
        }
        $total = bcmul((string) $product->price, (string) $quantity, 8);
        Cache::put('telegram-state:'.$user->id, [
            'step' => 'purchase_confirm', 'product_id' => $product->id, 'quantity' => $quantity,
        ], now()->addMinutes(15));
        $telegram->sendMessage($chatId, "🧾 <b>SECURE CHECKOUT</b>\n\n📦 Product: <b>".e($product->name)."</b>\n🔢 Quantity: <b>{$quantity}</b>\n💵 Subtotal: <b>USD ".number_format((float) $total, 2)."</b>\n💰 Wallet balance: <b>USD ".number_format((float) $user->balance, 2)."</b>\n\nConfirm your purchase below.", [
            [['text' => '✅ Confirm Purchase', 'callback_data' => 'confirm_purchase']],
            [['text' => '➕ Deposit Funds', 'callback_data' => 'deposit'], ['text' => '❌ Cancel', 'callback_data' => 'cancel']],
        ]);
    }

    private function confirmPurchase(TelegramClient $telegram, User $user, int|string $chatId): void
    {
        $key = 'telegram-state:'.$user->id;
        $state = Cache::get($key);
        if (($state['step'] ?? null) !== 'purchase_confirm') {
            $telegram->sendMessage($chatId, "⌛ <b>Checkout Expired</b>\n\nPlease select the product again.", $this->homeButton());

            return;
        }

        $result = DB::transaction(function () use ($user, $state): array {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $product = Product::query()->lockForUpdate()->findOrFail($state['product_id']);
            $quantity = (int) $state['quantity'];
            $total = bcmul((string) $product->price, (string) $quantity, 8);
            if (! $product->is_active || $product->stock < $quantity) {
                return ['error' => 'stock'];
            }
            if (bccomp((string) $lockedUser->balance, $total, 8) < 0) {
                return ['error' => 'balance', 'need' => bcsub($total, (string) $lockedUser->balance, 8)];
            }
            $before = (string) $lockedUser->balance;
            $after = bcsub($before, $total, 8);
            $publicId = (string) random_int(1000000000, 9999999999);
            $order = Order::query()->create([
                'public_id' => $publicId, 'user_id' => $lockedUser->id, 'subtotal' => $total,
                'discount' => 0, 'total' => $total, 'status' => 'processing',
            ]);
            DB::table('order_items')->insert([
                'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name,
                'unit_price' => $product->price, 'quantity' => $quantity, 'total' => $total,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $product->decrement('stock', $quantity);
            $lockedUser->update(['balance' => $after]);
            WalletTransaction::query()->create([
                'user_id' => $lockedUser->id, 'type' => 'purchase', 'amount' => '-'.$total,
                'balance_before' => $before, 'balance_after' => $after,
                'reference_type' => Order::class, 'reference_id' => (string) $order->id,
                'description' => 'Purchase order '.$publicId,
            ]);

            return ['order' => $order, 'product' => $product, 'quantity' => $quantity, 'balance' => $after];
        });

        if (($result['error'] ?? null) === 'stock') {
            $telegram->sendMessage($chatId, "⚠️ <b>Insufficient Stock</b>\n\nThe available quantity changed. Please try again.", $this->homeButton());

            return;
        }
        if (($result['error'] ?? null) === 'balance') {
            $telegram->sendMessage($chatId, "⚠️ <b>INSUFFICIENT BALANCE</b>\n\nYou need an additional <b>USD ".number_format((float) $result['need'], 2).'</b> to complete this purchase.', [
                [['text' => '➕ Deposit Now', 'callback_data' => 'deposit']],
                [['text' => '⬅️ Back to Products', 'callback_data' => 'products']],
            ]);

            return;
        }

        Cache::forget($key);
        $order = $result['order'];
        $telegram->sendMessage($chatId, "✅ <b>ORDER CONFIRMED</b>\n\n🆔 Order ID: <code>{$order->public_id}</code>\n📦 Product: <b>".e($result['product']->name)."</b>\n🔢 Quantity: <b>{$result['quantity']}</b>\n💵 Paid: <b>USD ".number_format((float) $order->total, 2)."</b>\n💰 Remaining balance: <b>USD ".number_format((float) $result['balance'], 2)."</b>\n\n⏳ Status: <b>Processing</b> — our team will deliver your product shortly.", [
            [['text' => '📦 Track This Order', 'callback_data' => 'track_order'], ['text' => '🆘 Support', 'callback_data' => 'support']],
            [['text' => '🏠 Main Menu', 'callback_data' => 'home']],
        ]);
    }

    private function trackOrder(TelegramClient $telegram, User $user, int|string $chatId, string $publicId): void
    {
        $order = Order::query()->where('user_id', $user->id)->where('public_id', $publicId)->first();
        if (! $order) {
            $telegram->sendMessage($chatId, "🔎 <b>ORDER NOT FOUND</b>\n\nCheck the Order ID and try again. Only orders belonging to your account can be viewed.");

            return;
        }
        Cache::forget('telegram-state:'.$user->id);
        $status = strtoupper(str_replace('_', ' ', $order->status));
        $telegram->sendMessage($chatId, "📦 <b>ORDER TRACKING</b>\n\n🆔 Order ID: <code>".e($order->public_id)."</code>\n💵 Total: <b>USD ".number_format((float) $order->total, 2)."</b>\n📌 Status: <b>{$status}</b>\n🕒 Last updated: {$order->updated_at->format('Y-m-d H:i T')}", $this->homeButton());
    }

    private function support(TelegramClient $telegram, int|string $chatId): void
    {
        $telegram->sendMessage($chatId, "🆘 <b>CUSTOMER SUPPORT</b>\n\nNeed help with a payment, product or order? Our support team is ready to assist you.\n\nPlease include your Order ID or Deposit ID for faster service.", [
            [['text' => '💬 Open Support', 'url' => config('services.telegram.support_url') ?: 'https://t.me/telegram']],
            [['text' => '🏠 Main Menu', 'callback_data' => 'home']],
        ]);
    }

    private function homeButton(): array
    {
        return [[['text' => '🏠 Main Menu', 'callback_data' => 'home']]];
    }
}
