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
    private ?int $callbackMessageId = null;

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
        $chatType = $message['chat']['type'] ?? $callback['message']['chat']['type'] ?? null;
        $this->callbackMessageId = isset($callback['message']['message_id']) ? (int) $callback['message']['message_id'] : null;
        $action = $callback['data'] ?? $message['text'] ?? '';
        if ($callback) {
            $telegram->answerCallback($callback['id']);
        }
        if ($chatType !== 'private') {
            return;
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
            $this->respond($telegram, $chatId, "❌ <b>Action Cancelled</b>\n\nNo changes were made.", $this->homeButton());

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
        if (str_starts_with((string) $action, 'deposit_method:')) {
            $this->beginDepositAmount($telegram, $user, $chatId, Str::after((string) $action, 'deposit_method:'));

            return;
        }

        match ($action) {
            '/start', 'verify' => $this->verifyAndWelcome($telegram, $user, $chatId),
            'home' => $this->welcome($telegram, $user, $chatId),
            'products' => $this->products($telegram, $chatId),
            'balance' => $this->balance($telegram, $user, $chatId),
            'deposit' => $this->beginDeposit($telegram, $user, $chatId),
            'track_order' => $this->beginOrderTracking($telegram, $user, $chatId),
            'orders' => $this->orders($telegram, $user, $chatId),
            'account' => $this->account($telegram, $user, $chatId),
            'support' => $this->support($telegram, $chatId),
            'confirm_purchase' => $this->confirmPurchase($telegram, $user, $chatId),
            default => $message ? $this->handleTextInput($telegram, $user, $chatId, (string) $action) : null,
        };
    }

    private function beginDeposit(TelegramClient $telegram, User $user, int|string $chatId): void
    {
        $this->respond($telegram, $chatId, "<b>DEPOSIT / METHOD</b>\n\nChoose how you want to send USDT. Every payment is verified automatically with Binance before your wallet is credited.", [
            [['text' => 'Binance Pay', 'callback_data' => 'deposit_method:PAY']],
            [['text' => 'USDT · TRC20', 'callback_data' => 'deposit_method:TRX'], ['text' => 'USDT · BEP20', 'callback_data' => 'deposit_method:BSC']],
            [['text' => 'USDT · ERC20', 'callback_data' => 'deposit_method:ETH']],
            [['text' => '‹ Dashboard', 'callback_data' => 'home']],
        ]);
    }

    private function beginDepositAmount(TelegramClient $telegram, User $user, int|string $chatId, string $method): void
    {
        $method = strtoupper($method);
        if (! in_array($method, ['PAY', 'TRX', 'BSC', 'ETH'], true)) {
            $this->beginDeposit($telegram, $user, $chatId);

            return;
        }

        $state = ['step' => 'deposit_amount', 'payment_method' => $method];
        if ($method !== 'PAY') {
            try {
                $address = app(BinancePayClient::class)->depositAddress('USDT', $method);
            } catch (Throwable $exception) {
                Log::warning('Unable to load Binance deposit address', ['network' => $method, 'exception' => $exception]);
                $this->respond($telegram, $chatId, "<b>NETWORK / UNAVAILABLE</b>\n\nThe deposit address could not be loaded from Binance. Please try again shortly or use Binance Pay.", [
                    [['text' => 'Use Binance Pay', 'callback_data' => 'deposit_method:PAY']],
                    [['text' => 'Choose Another Method', 'callback_data' => 'deposit']],
                ]);

                return;
            }
            $state['address'] = (string) $address['address'];
            $state['tag'] = (string) ($address['tag'] ?? '');
        }
        Cache::put('telegram-state:'.$user->id, $state, now()->addMinutes(30));
        $label = $method === 'PAY' ? 'Binance Pay' : "USDT · {$method}";
        $this->respond($telegram, $chatId, "<b>DEPOSIT / AMOUNT</b>\n\nMethod\n<b>{$label}</b>\n\nMinimum deposit\n<b>0.10 USDT</b>\n\nSend the exact amount you want to add.\nExample: <code>25</code>", [
            [['text' => 'Change Method', 'callback_data' => 'deposit']],
            [['text' => 'Cancel', 'callback_data' => 'cancel']],
        ]);
    }

    private function handleTextInput(TelegramClient $telegram, User $user, int|string $chatId, string $input): void
    {
        $key = 'telegram-state:'.$user->id;
        $state = Cache::get($key);
        if (! is_array($state)) {
            $pendingDeposit = DepositRequest::query()
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->latest()
                ->first();

            if ($pendingDeposit && preg_match('/^[A-Za-z0-9_-]{8,100}$/', trim($input))) {
                $state = ['step' => 'deposit_txid', 'deposit_id' => $pendingDeposit->id];
                Cache::put($key, $state, $pendingDeposit->expires_at);
            } else {
                $this->respond($telegram, $chatId, "<b>SESSION / EXPIRED</b>\n\nThere is no active action waiting for this message. Start a new deposit or return to the dashboard.", [
                    [['text' => 'New Deposit', 'callback_data' => 'deposit']],
                    [['text' => 'Dashboard', 'callback_data' => 'home']],
                ]);

                return;
            }
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
                $this->respond($telegram, $chatId, "⚠️ <b>Invalid Amount</b>\n\nEnter a value from <b>0.10</b> to <b>100,000 USDT</b>.");

                return;
            }
            $paymentMethod = strtoupper((string) ($state['payment_method'] ?? 'PAY'));
            $isBinancePay = $paymentMethod === 'PAY';
            $methodCode = $isBinancePay ? 'binance_pay' : 'usdt_'.strtolower($paymentMethod);
            $methodName = $isBinancePay ? 'Binance Pay' : "USDT {$paymentMethod}";
            $settings = $isBinancePay
                ? ['pay_id' => config('services.binance.pay_id')]
                : ['coin' => 'USDT', 'network' => $paymentMethod, 'address' => $state['address'], 'tag' => $state['tag'] ?? ''];
            $method = DepositMethod::query()->updateOrCreate(['code' => $methodCode], [
                'name' => $methodName, 'verification' => 'automatic', 'settings' => $settings, 'is_active' => true,
            ]);
            $deposit = DepositRequest::query()->create([
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'deposit_method_id' => $method->id,
                'amount' => $amount, 'status' => 'pending', 'expires_at' => now()->addMinutes(30),
            ]);
            Cache::put($key, ['step' => 'deposit_txid', 'deposit_id' => $deposit->id], $deposit->expires_at);
            if ($isBinancePay) {
                $instructions = "<b>PAYMENT / BINANCE PAY</b>\n\nExact amount\n<b>{$amount} USDT</b>\n\nBinance Pay ID\n<code>".e((string) config('services.binance.pay_id'))."</code>\n\nValid for <b>30 minutes</b>. Sending a different amount can delay verification.\n\nAfter payment, send the <b>Order ID</b> here.";
            } else {
                $networkLabels = ['TRX' => 'TRON (TRC20)', 'BSC' => 'BNB Smart Chain (BEP20)', 'ETH' => 'Ethereum (ERC20)'];
                $tag = ($state['tag'] ?? '') !== '' ? "\n\nMemo / Tag\n<code>".e($state['tag']).'</code>' : '';
                $instructions = "<b>PAYMENT / USDT {$paymentMethod}</b>\n\nExact amount\n<b>{$amount} USDT</b>\n\nNetwork\n<b>{$networkLabels[$paymentMethod]}</b>\n\nDeposit address\n<code>".e($state['address'])."</code>{$tag}\n\nSend only USDT on this network. After Binance credits the deposit, send the <b>TxHash</b> here.";
            }
            $this->respond($telegram, $chatId, $instructions, [
                [['text' => 'Cancel Payment', 'callback_data' => 'cancel']],
            ]);

            return;
        }

        if (($state['step'] ?? null) !== 'deposit_txid') {
            return;
        }
        $txid = trim($input);
        if (! preg_match('/^[A-Za-z0-9_-]{8,100}$/', $txid)) {
            $this->respond($telegram, $chatId, "⚠️ <b>Invalid Transaction ID</b>\n\nCopy the ID from your Binance Pay transaction details and try again.");

            return;
        }
        if (DepositRequest::query()->where('txid', $txid)->exists()) {
            $this->respond($telegram, $chatId, "🚫 <b>Transaction Already Used</b>\n\nEach Transaction ID can only be credited once.");

            return;
        }

        $deposit = DepositRequest::query()->findOrFail($state['deposit_id']);
        if ($deposit->expires_at->isPast()) {
            $deposit->update(['status' => 'expired']);
            Cache::forget($key);
            $this->respond($telegram, $chatId, "⌛ <b>Payment Request Expired</b>\n\nPlease create a new deposit request.", $this->homeButton());

            return;
        }
        $deposit->update(['txid' => $txid, 'status' => 'verifying']);
        $deposit->load('method');
        $settings = $deposit->method?->settings ?? [];
        $transaction = $deposit->method?->code === 'binance_pay'
            ? app(BinancePayClient::class)->findIncoming($txid, (string) $deposit->amount, $deposit->created_at)
            : app(BinancePayClient::class)->findBlockchainDeposit(
                $txid,
                (string) $deposit->amount,
                (string) ($settings['network'] ?? ''),
                (string) ($settings['address'] ?? ''),
                $deposit->created_at,
            );
        if ($transaction === null) {
            $deposit->update(['txid' => null, 'status' => 'pending']);
            $this->respond($telegram, $chatId, "🔎 <b>Payment Not Found</b>\n\nCheck the Transaction ID, amount and currency. If you have just paid, wait a moment and send the ID again.");

            return;
        }

        app(WalletService::class)->approveDeposit($deposit, $transaction);
        Cache::forget($key);
        $creditedAmount = $this->formatCryptoAmount((string) $deposit->amount);
        $this->respond($telegram, $chatId, "<b>DEPOSIT / APPROVED</b>\n\nAmount credited\n<b>{$creditedAmount} USDT</b>\n\nYour wallet is ready to use.", [
            [['text' => 'Explore Store', 'callback_data' => 'products'], ['text' => 'Wallet', 'callback_data' => 'balance']],
            [['text' => 'Dashboard', 'callback_data' => 'home']],
        ]);
    }

    private function verifyAndWelcome(TelegramClient $telegram, User $user, int|string $chatId): void
    {
        $missingChannels = $this->missingChannels($telegram, $user);
        if ($missingChannels->isNotEmpty()) {
            $this->sendAccessRestricted($telegram, $chatId, $missingChannels);

            return;
        }
        $this->welcome($telegram, $user, $chatId, true);
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
        $keyboard[] = [['text' => "I've Joined — Verify Access", 'callback_data' => 'verify']];
        $this->respond($telegram, $chatId, "<b>MEMBERSHIP / REQUIRED</b>\n\nJoin the KoDuck communities below to unlock the store.\n\nWhen finished, verify your access.", $keyboard);
    }

    private function products(TelegramClient $telegram, int|string $chatId): void
    {
        $products = Product::query()->where('is_active', true)->orderBy('sort_order')->limit(50)->get();
        $available = $products->where('stock', '>', 0)->count();
        $keyboard = $products->map(fn (Product $product): array => [[
            'text' => $product->stock > 0
                ? $product->name.' · $'.number_format((float) $product->price, 2).' · '.$product->stock.' left'
                : $product->name.' · SOLD OUT',
            'callback_data' => 'product:'.$product->id,
            'style' => $product->stock > 0 ? 'success' : 'danger',
        ]])->all();
        $keyboard[] = [['text' => '‹ Dashboard', 'callback_data' => 'home']];
        $this->respond($telegram, $chatId, "<b>STORE / CATALOG</b>\n\n{$available} product(s) currently available\n\nStock levels are updated after every purchase. Sold-out products remain visible so you can check availability later.\n\nSelect a product to continue.", $keyboard);
    }

    private function welcome(TelegramClient $telegram, User $user, int|string $chatId, bool $verified = false): void
    {
        $activeOrders = Order::query()->where('user_id', $user->id)->whereIn('status', ['paid', 'processing'])->count();
        $prefix = $verified ? "<b>ACCESS GRANTED</b>\nYour membership has been verified.\n\n" : '';
        $name = e(Str::before($user->name, ' '));
        $this->respond($telegram, $chatId, $prefix."<b>KODUCK / DIGITAL STORE</b>\n\nWelcome back, <b>{$name}</b>.\n\n<b>WALLET</b>\n$".number_format((float) $user->balance, 2)." available\n\n<b>ACTIVITY</b>\n{$activeOrders} active order(s)\n\n<blockquote>Premium digital products, delivered simply and securely.</blockquote>", [
            [['text' => 'Explore Store', 'callback_data' => 'products']],
            [['text' => 'Wallet', 'callback_data' => 'balance'], ['text' => 'My Orders', 'callback_data' => 'orders']],
            [['text' => 'Deposit', 'callback_data' => 'deposit'], ['text' => 'Account', 'callback_data' => 'account']],
        ]);
    }

    private function balance(TelegramClient $telegram, User $user, int|string $chatId): void
    {
        $this->respond($telegram, $chatId, "<b>WALLET / OVERVIEW</b>\n\nAvailable balance\n<b>$".number_format((float) $user->balance, 2)." USD</b>\n\nFunds are credited after payment verification and can be used instantly at checkout.", [
            [['text' => 'Add Funds', 'callback_data' => 'deposit'], ['text' => 'Explore Store', 'callback_data' => 'products']],
            [['text' => '‹ Dashboard', 'callback_data' => 'home']],
        ]);
    }

    private function productDetail(TelegramClient $telegram, int|string $chatId, int $productId): void
    {
        $product = Product::query()->where('is_active', true)->find($productId);
        if (! $product) {
            $this->respond($telegram, $chatId, "⚠️ <b>Product Unavailable</b>\n\nThis product may have been removed or temporarily disabled.", $this->homeButton());

            return;
        }
        $delivery = $product->delivery_type === 'automatic' ? 'Automatic delivery' : 'Manual delivery by admin';
        $keyboard = $product->stock > 0
            ? [
                [['text' => 'Continue to Purchase', 'callback_data' => 'buy:'.$product->id, 'style' => 'primary']],
                [['text' => 'Wallet', 'callback_data' => 'balance'], ['text' => 'Add Funds', 'callback_data' => 'deposit']],
                [['text' => '‹ Catalog', 'callback_data' => 'products']],
            ]
            : [
                [['text' => 'SOLD OUT', 'callback_data' => 'product:'.$product->id, 'style' => 'danger']],
                [['text' => '‹ Catalog', 'callback_data' => 'products']],
            ];
        $availability = $product->stock > 0 ? "{$product->stock} remaining" : 'Currently sold out';
        $this->respond($telegram, $chatId, '<b>PRODUCT / '.e($product->name)."</b>\n\n".e($product->description ?: 'Premium digital product with reliable support.')."\n\n<b>USD ".number_format((float) $product->price, 2)."</b> per item\n{$availability} · {$product->warranty_days}-day warranty\n{$delivery}\n\n".($product->stock > 0 ? 'Secure wallet checkout.' : 'Please check the catalog again later for restocks.'), $keyboard);
    }

    private function beginOrderTracking(TelegramClient $telegram, User $user, int|string $chatId): void
    {
        Cache::put('telegram-state:'.$user->id, ['step' => 'track_order'], now()->addMinutes(10));
        $this->respond($telegram, $chatId, "📦 <b>TRACK YOUR ORDER</b>\n\nSend your Order ID to view its latest status.\nExample: <code>8641234567</code>\n\nOrder IDs are shown in your confirmation message.", [
            [['text' => '❌ Cancel', 'callback_data' => 'cancel']],
        ]);
    }

    private function beginPurchase(TelegramClient $telegram, User $user, int|string $chatId, int $productId): void
    {
        $product = Product::query()->where('is_active', true)->where('stock', '>', 0)->find($productId);
        if (! $product) {
            $this->respond($telegram, $chatId, "⚠️ <b>Product Unavailable</b>\n\nThis product is currently out of stock.", $this->homeButton());

            return;
        }
        Cache::put('telegram-state:'.$user->id, ['step' => 'purchase_quantity', 'product_id' => $product->id], now()->addMinutes(15));
        $this->respond($telegram, $chatId, "<b>ORDER / QUANTITY</b>\n\nProduct\n<b>".e($product->name)."</b>\n\nUnit price · <b>USD ".number_format((float) $product->price, 2)."</b>\nAvailable · <b>{$product->stock}</b>\n\nChoose below or send another quantity.", [
            [['text' => '1 item', 'callback_data' => 'quantity:1'], ['text' => '2 items', 'callback_data' => 'quantity:2']],
            [['text' => 'Cancel', 'callback_data' => 'cancel']],
        ]);
    }

    private function checkout(TelegramClient $telegram, User $user, int|string $chatId, array $state, string $input): void
    {
        if (($state['step'] ?? null) !== 'purchase_quantity' || ! isset($state['product_id'])) {
            $this->respond($telegram, $chatId, "⌛ <b>Selection Expired</b>\n\nPlease choose the product again.", $this->homeButton());

            return;
        }
        if (! ctype_digit($input) || (int) $input < 1) {
            $this->respond($telegram, $chatId, "⚠️ <b>Invalid Quantity</b>\n\nSend a whole number greater than zero.");

            return;
        }
        $product = Product::query()->findOrFail($state['product_id']);
        $quantity = (int) $input;
        if ($quantity > $product->stock) {
            $this->respond($telegram, $chatId, "⚠️ <b>Insufficient Stock</b>\n\nOnly <b>{$product->stock}</b> item(s) are currently available.");

            return;
        }
        $total = bcmul((string) $product->price, (string) $quantity, 8);
        Cache::put('telegram-state:'.$user->id, [
            'step' => 'purchase_confirm', 'product_id' => $product->id, 'quantity' => $quantity,
        ], now()->addMinutes(15));
        $this->respond($telegram, $chatId, "<b>CHECKOUT / REVIEW</b>\n\n".e($product->name)." · {$quantity} item(s)\n\nTotal\n<b>USD ".number_format((float) $total, 2)."</b>\n\nWallet available · USD ".number_format((float) $user->balance, 2)."\n\nConfirm to place your order securely.", [
            [['text' => 'Confirm & Pay', 'callback_data' => 'confirm_purchase']],
            [['text' => 'Add Funds', 'callback_data' => 'deposit'], ['text' => 'Cancel', 'callback_data' => 'cancel']],
        ]);
    }

    private function confirmPurchase(TelegramClient $telegram, User $user, int|string $chatId): void
    {
        $key = 'telegram-state:'.$user->id;
        $state = Cache::get($key);
        if (($state['step'] ?? null) !== 'purchase_confirm') {
            $this->respond($telegram, $chatId, "⌛ <b>Checkout Expired</b>\n\nPlease select the product again.", $this->homeButton());

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
            $lockedUser->forceFill(['balance' => $after])->save();
            WalletTransaction::query()->create([
                'user_id' => $lockedUser->id, 'type' => 'purchase', 'amount' => '-'.$total,
                'balance_before' => $before, 'balance_after' => $after,
                'reference_type' => Order::class, 'reference_id' => (string) $order->id,
                'description' => 'Purchase order '.$publicId,
            ]);

            return ['order' => $order, 'product' => $product, 'quantity' => $quantity, 'balance' => $after];
        });

        if (($result['error'] ?? null) === 'stock') {
            $this->respond($telegram, $chatId, "⚠️ <b>Insufficient Stock</b>\n\nThe available quantity changed. Please try again.", $this->homeButton());

            return;
        }
        if (($result['error'] ?? null) === 'balance') {
            $this->respond($telegram, $chatId, "⚠️ <b>INSUFFICIENT BALANCE</b>\n\nYou need an additional <b>USD ".number_format((float) $result['need'], 2).'</b> to complete this purchase.', [
                [['text' => '➕ Deposit Now', 'callback_data' => 'deposit']],
                [['text' => '⬅️ Back to Products', 'callback_data' => 'products']],
            ]);

            return;
        }

        Cache::forget($key);
        $order = $result['order'];
        $deliveryNotice = $result['product']->delivery_type === 'manual'
            ? "<b>Waiting for admin delivery</b>\nMost manual orders are delivered within a few hours."
            : "<b>Preparing automatic delivery</b>\nYour product should be available shortly.";
        $this->respond($telegram, $chatId, "<b>ORDER / CONFIRMED</b>\n\nOrder ID\n<code>{$order->public_id}</code>\n\nProduct · <b>".e($result['product']->name)."</b>\nQuantity · <b>{$result['quantity']}</b>\nPaid · <b>USD ".number_format((float) $order->total, 2)."</b>\nBalance · <b>USD ".number_format((float) $result['balance'], 2)."</b>\n\n{$deliveryNotice}\n\nYou can track the latest status at any time.", [
            [['text' => 'Track This Order', 'callback_data' => 'track_order'], ['text' => 'Support', 'callback_data' => 'support']],
            [['text' => 'Dashboard', 'callback_data' => 'home']],
        ]);
    }

    private function trackOrder(TelegramClient $telegram, User $user, int|string $chatId, string $publicId): void
    {
        $order = Order::query()->with('items.product')->where('user_id', $user->id)->where('public_id', $publicId)->first();
        if (! $order) {
            $this->respond($telegram, $chatId, "🔎 <b>ORDER NOT FOUND</b>\n\nCheck the Order ID and try again. Only orders belonging to your account can be viewed.");

            return;
        }
        Cache::forget('telegram-state:'.$user->id);
        $isManual = $order->items->contains(fn ($item): bool => $item->product?->delivery_type !== 'automatic');
        $status = match ($order->status) {
            'pending', 'paid', 'processing' => $isManual
                ? "<b>Waiting for admin delivery</b>\nMost orders are delivered within a few hours. You will receive the delivery in this private chat."
                : "<b>Preparing automatic delivery</b>\nThe system is processing your product and should deliver it shortly.",
            'completed' => "<b>Delivered</b>\nThis order has been completed successfully.",
            'cancelled' => "<b>Cancelled</b>\nThis order is no longer being processed.",
            'refunded' => "<b>Refunded</b>\nThe payment for this order has been returned.",
            default => '<b>'.e(strtoupper(str_replace('_', ' ', $order->status))).'</b>',
        };
        $this->respond($telegram, $chatId, "<b>ORDER / TRACKING</b>\n\nOrder ID\n<code>".e($order->public_id)."</code>\n\nTotal · <b>USD ".number_format((float) $order->total, 2)."</b>\nLast updated · {$order->updated_at->format('Y-m-d H:i T')}\n\n<b>DELIVERY STATUS</b>\n{$status}\n\nIf the expected time has passed, contact Support with your Order ID.", [
            [['text' => 'Contact Support', 'callback_data' => 'support']],
            [['text' => '‹ Dashboard', 'callback_data' => 'home']],
        ]);
    }

    private function support(TelegramClient $telegram, int|string $chatId): void
    {
        $this->respond($telegram, $chatId, "<b>SUPPORT / HELP DESK</b>\n\nOur team can help with payments, products and order delivery.\n\nInclude your Order ID or Deposit ID so we can resolve the request faster.", [
            [['text' => 'Open Support Chat ↗', 'url' => config('services.telegram.support_url') ?: 'https://t.me/telegram']],
            [['text' => '‹ Dashboard', 'callback_data' => 'home']],
        ]);
    }

    private function orders(TelegramClient $telegram, User $user, int|string $chatId): void
    {
        $orders = Order::query()->where('user_id', $user->id)->latest()->limit(5)->get();
        if ($orders->isEmpty()) {
            $this->respond($telegram, $chatId, "<b>ORDERS / HISTORY</b>\n\nNo orders yet. Your recent purchases and delivery status will appear here.", [
                [['text' => 'Explore Store', 'callback_data' => 'products']],
                [['text' => '‹ Dashboard', 'callback_data' => 'home']],
            ]);

            return;
        }
        $lines = $orders->map(fn (Order $order): string => '<code>'.$order->public_id.'</code>  ·  '.strtoupper($order->status).'  ·  $'.number_format((float) $order->total, 2))->implode("\n");
        $this->respond($telegram, $chatId, "<b>ORDERS / RECENT</b>\n\n{$lines}\n\nSelect Track Order to inspect a specific purchase.", [
            [['text' => 'Track Order', 'callback_data' => 'track_order']],
            [['text' => 'Explore Store', 'callback_data' => 'products'], ['text' => '‹ Dashboard', 'callback_data' => 'home']],
        ]);
    }

    private function account(TelegramClient $telegram, User $user, int|string $chatId): void
    {
        $username = $user->telegram_username ? '@'.e($user->telegram_username) : 'Not set';
        $this->respond($telegram, $chatId, "<b>ACCOUNT / PROFILE</b>\n\n<b>".e($user->name)."</b>\n{$username}\n\nCustomer ID\n<code>KD-".str_pad((string) $user->id, 6, '0', STR_PAD_LEFT)."</code>\n\nMembership\n<b>VERIFIED</b>\n\nNeed assistance with your account or an order? Contact our support team.", [
            [['text' => 'Customer Support', 'callback_data' => 'support']],
            [['text' => 'My Orders', 'callback_data' => 'orders'], ['text' => '‹ Dashboard', 'callback_data' => 'home']],
        ]);
    }

    private function respond(TelegramClient $telegram, int|string $chatId, string $text, array $keyboard = []): void
    {
        $isVisualScreen = $keyboard !== [] && mb_strlen(strip_tags($text)) <= 1024;

        if ($this->callbackMessageId !== null && $isVisualScreen) {
            $telegram->editCard($chatId, $this->callbackMessageId, $text, $keyboard);

            return;
        }

        if ($this->callbackMessageId !== null) {
            $telegram->editMessage($chatId, $this->callbackMessageId, $text, $keyboard);

            return;
        }

        if ($isVisualScreen) {
            $telegram->sendCard($chatId, $text, $keyboard);

            return;
        }

        $telegram->sendMessage($chatId, $text);
    }

    private function homeButton(): array
    {
        return [[['text' => '‹ Dashboard', 'callback_data' => 'home']]];
    }

    private function formatCryptoAmount(string $amount): string
    {
        if (! str_contains($amount, '.')) {
            return $amount;
        }

        $formatted = rtrim(rtrim($amount, '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
