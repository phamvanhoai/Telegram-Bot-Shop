<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramClient
{
    private function http(): PendingRequest
    {
        $token = (string) config('services.telegram.token');
        throw_if($token === '', RuntimeException::class, 'TELEGRAM_BOT_TOKEN is not configured.');

        return Http::baseUrl("https://api.telegram.org/bot{$token}")
            ->acceptJson()->asJson()->timeout(10)->retry(2, 250);
    }

    public function call(string $method, array $payload = []): array
    {
        return $this->http()->post($method, $payload)->throw()->json();
    }

    public function sendMessage(int|string $chatId, string $text, array $keyboard = []): void
    {
        $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($keyboard !== []) {
            $payload['reply_markup'] = ['inline_keyboard' => $keyboard];
        }
        $this->call('sendMessage', $payload);
    }

    public function editMessage(int|string $chatId, int $messageId, string $text, array $keyboard = []): void
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ];

        try {
            $this->call('editMessageText', $payload);
        } catch (RequestException $exception) {
            if (! str_contains($exception->response->body(), 'message is not modified')) {
                throw $exception;
            }
        }
    }

    public function answerCallback(string $id): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $id]);
    }

    public function isMember(string $channelId, int $userId): bool
    {
        $result = $this->call('getChatMember', ['chat_id' => $channelId, 'user_id' => $userId]);

        return in_array(data_get($result, 'result.status'), ['creator', 'administrator', 'member', 'restricted'], true)
            && data_get($result, 'result.is_member', true) !== false;
    }
}
