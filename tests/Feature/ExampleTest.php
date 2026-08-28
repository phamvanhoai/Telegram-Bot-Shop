<?php

namespace Tests\Feature;

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
        config(['services.telegram.token' => 'test-token', 'services.telegram.webhook_secret' => 'valid-secret']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'valid-secret')->postJson('/webhooks/telegram', [
            'update_id' => 1,
            'message' => ['text' => '/start', 'chat' => ['id' => 123], 'from' => ['id' => 123, 'first_name' => 'Test', 'username' => 'tester']],
        ])->assertOk();
        $this->assertDatabaseHas('users', ['telegram_id' => 123, 'telegram_username' => 'tester']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/sendMessage'));
    }
}
