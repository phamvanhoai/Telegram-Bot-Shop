<?php

namespace Tests\Feature;

use App\Models\DepositMethod;
use App\Models\DepositRequest;
use App\Models\User;
use App\Services\BinancePayClient;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_telegram_webhook_rejects_an_invalid_secret(): void
    {
        config(['services.telegram.webhook_secret' => 'valid-secret']);
        $this->postJson('/webhooks/telegram', [])->assertForbidden();
    }

    public function test_start_creates_user_and_sends_welcome_message(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.telegram.webhook_secret' => 'valid-secret',
            'services.binance.api_key' => 'key',
            'services.binance.api_secret' => 'secret',
        ]);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
            'api.binance.com/*' => Http::response(['code' => '000000', 'data' => []]),
        ]);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'valid-secret')->postJson('/webhooks/telegram', [
            'update_id' => 1,
            'message' => ['text' => '/start', 'chat' => ['id' => 123, 'type' => 'private'], 'from' => ['id' => 123, 'first_name' => 'Test', 'username' => 'tester']],
        ])->assertOk();
        $this->assertDatabaseHas('users', ['telegram_id' => 123, 'telegram_username' => 'tester']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/sendPhoto')
            && str_ends_with($request['photo'], '/images/koduck-interface-cover.png'));
    }

    public function test_binance_received_payment_is_matched_without_comparing_receiver_account_to_pay_id(): void
    {
        config([
            'services.binance.api_key' => 'key',
            'services.binance.api_secret' => 'secret',
            'services.binance.pay_id' => 'pay-id-is-not-account-id',
            'services.binance.currency' => 'USDT',
        ]);
        Http::fake(['api.binance.com/*' => Http::response([
            'code' => '000000',
            'data' => [[
                'orderId' => '451258665332137984',
                'transactionId' => 'P_A_INTERNAL_REFERENCE',
                'amount' => '25.00000000',
                'currency' => 'USDT',
                'receiverInfo' => ['accountId' => 'different-account-id'],
            ]],
        ])]);

        $transaction = app(BinancePayClient::class)->findIncoming('451258665332137984', '25', now());

        $this->assertSame('P_A_INTERNAL_REFERENCE', $transaction['transactionId']);
    }

    public function test_transaction_id_can_resume_a_pending_deposit_after_cache_is_cleared(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.telegram.webhook_secret' => 'valid-secret',
            'services.binance.api_key' => 'key',
            'services.binance.api_secret' => 'secret',
        ]);
        $user = User::query()->create(['telegram_id' => 123, 'name' => 'Test', 'balance' => 0]);
        $method = DepositMethod::query()->create(['code' => 'binance_pay', 'name' => 'Binance Pay', 'is_active' => true]);
        DepositRequest::query()->create([
            'user_id' => $user->id,
            'deposit_method_id' => $method->id,
            'amount' => 0.5,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(20),
        ]);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
            'api.binance.com/*' => Http::response(['code' => '000000', 'data' => []]),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'valid-secret')->postJson('/webhooks/telegram', [
            'update_id' => 2,
            'message' => ['text' => 'invalid-order-id', 'chat' => ['id' => 123, 'type' => 'private'], 'from' => ['id' => 123, 'first_name' => 'Test']],
        ])->assertOk();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Payment Not Found'));
    }

    public function test_approving_a_deposit_really_updates_the_user_balance(): void
    {
        $user = User::query()->create(['telegram_id' => 456, 'name' => 'Wallet Test']);
        $method = DepositMethod::query()->create(['code' => 'wallet_test', 'name' => 'Wallet Test', 'is_active' => true]);
        $deposit = DepositRequest::query()->create([
            'user_id' => $user->id,
            'deposit_method_id' => $method->id,
            'amount' => 0.5,
            'txid' => 'wallet-test-transaction',
            'status' => 'verifying',
            'expires_at' => now()->addMinutes(20),
        ]);

        app(WalletService::class)->approveDeposit($deposit, ['orderId' => 'wallet-test-transaction']);

        $this->assertSame('0.50000000', (string) $user->refresh()->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id,
            'balance_after' => 0.5,
        ]);
    }

    public function test_blockchain_deposit_matches_tx_hash_network_address_amount_and_status(): void
    {
        config([
            'services.binance.api_key' => 'key',
            'services.binance.api_secret' => 'secret',
            'services.binance.currency' => 'USDT',
        ]);
        Http::fake(['api.binance.com/*' => Http::response([[
            'txId' => 'ABCDEF1234567890',
            'coin' => 'USDT',
            'network' => 'TRX',
            'address' => 'TRON-DEPOSIT-ADDRESS',
            'amount' => '12.50000000',
            'status' => 6,
        ]])]);

        $deposit = app(BinancePayClient::class)->findBlockchainDeposit(
            'abcdef1234567890',
            '12.5',
            'TRX',
            'TRON-DEPOSIT-ADDRESS',
            now(),
        );

        $this->assertSame('ABCDEF1234567890', $deposit['txId']);
    }

    public function test_shop_bot_does_not_reply_inside_groups(): void
    {
        config(['services.telegram.token' => 'test-token', 'services.telegram.webhook_secret' => 'valid-secret']);
        Http::fake();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'valid-secret')->postJson('/webhooks/telegram', [
            'update_id' => 3,
            'message' => [
                'text' => '/start',
                'chat' => ['id' => -100123, 'type' => 'supergroup'],
                'from' => ['id' => 789, 'first_name' => 'Group User'],
            ],
        ])->assertOk();

        Http::assertNothingSent();
    }

    public function test_store_command_opens_the_product_catalog(): void
    {
        config(['services.telegram.token' => 'test-token', 'services.telegram.webhook_secret' => 'valid-secret']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'valid-secret')->postJson('/webhooks/telegram', [
            'update_id' => 4,
            'message' => [
                'text' => '/store',
                'chat' => ['id' => 987, 'type' => 'private'],
                'from' => ['id' => 987, 'first_name' => 'Command User'],
            ],
        ])->assertOk();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/sendPhoto')
            && str_contains((string) $request['caption'], 'STORE / CATALOG'));
    }

    public function test_callback_spinner_is_stopped_after_rendering(): void
    {
        config(['services.telegram.token' => 'test-token', 'services.telegram.webhook_secret' => 'valid-secret']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'valid-secret')->postJson('/webhooks/telegram', [
            'update_id' => 5,
            'callback_query' => [
                'id' => 'callback-123',
                'data' => 'home',
                'from' => ['id' => 654, 'first_name' => 'Button User'],
                'message' => [
                    'message_id' => 99,
                    'chat' => ['id' => 654, 'type' => 'private'],
                    'reply_markup' => ['inline_keyboard' => [[['text' => 'Dashboard', 'callback_data' => 'home']]]],
                ],
            ],
        ])->assertOk();

        $requests = Http::recorded();
        $loadingRequest = $requests->first()[0];
        $this->assertStringContainsString('/editMessageReplyMarkup', $loadingRequest->url());
        $this->assertSame('⏳ Loading…', $loadingRequest['reply_markup']['inline_keyboard'][0][0]['text']);
        $lastRequest = $requests->last()[0];
        $this->assertStringContainsString('/answerCallbackQuery', $lastRequest->url());
        $this->assertArrayNotHasKey('text', $lastRequest->data());
    }
}
