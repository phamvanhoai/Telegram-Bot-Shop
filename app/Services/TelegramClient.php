<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramClient
{
    private function http(int $timeout = 10, int $retries = 2): PendingRequest
    {
        $token = (string) config('services.telegram.token');
        throw_if($token === '', RuntimeException::class, 'TELEGRAM_BOT_TOKEN is not configured.');

        $request = Http::baseUrl("https://api.telegram.org/bot{$token}")
            ->acceptJson()->asJson()->timeout($timeout);

        return $retries > 0 ? $request->retry($retries, 250) : $request;
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

    public function sendCard(int|string $chatId, string $caption, array $keyboard = []): void
    {
        $payload = [
            'chat_id' => $chatId,
            'photo' => (string) config('services.telegram.card_image_url'),
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ];

        $this->call('sendPhoto', $payload);
    }

    public function sendBroadcast(int|string $chatId, string $message, ?string $imageUrl, array $keyboard = []): void
    {
        if ($imageUrl === null) {
            $payload = ['chat_id' => $chatId, 'text' => $message, 'parse_mode' => 'HTML'];
            if ($keyboard !== []) {
                $payload['reply_markup'] = ['inline_keyboard' => $keyboard];
            }
            $this->callBroadcast('sendMessage', $payload);

            return;
        }

        $payload = ['chat_id' => $chatId, 'photo' => $imageUrl, 'caption' => $message, 'parse_mode' => 'HTML'];
        if ($keyboard !== []) {
            $payload['reply_markup'] = ['inline_keyboard' => $keyboard];
        }
        $this->callBroadcast('sendPhoto', $payload);
    }

    private function callBroadcast(string $method, array $payload): void
    {
        // Broadcast retries are handled by notification_recipients on the next
        // scheduler run. Keep one slow/unreachable chat from blocking a batch.
        $this->http(timeout: 5, retries: 0)->post($method, $payload)->throw();
    }

    public function editCard(int|string $chatId, int $messageId, string $caption, array $keyboard = []): void
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ];

        try {
            $this->call('editMessageCaption', $payload);
        } catch (RequestException $exception) {
            $body = $exception->response->body();
            if (str_contains($body, 'message is not modified')) {
                return;
            }

            if (str_contains($body, 'there is no caption') || str_contains($body, "message can't be edited")) {
                $this->sendCard($chatId, $caption, $keyboard);

                return;
            }

            throw $exception;
        }
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

    public function answerCallback(string $id, ?string $text = null): void
    {
        $payload = ['callback_query_id' => $id];
        if ($text !== null && $text !== '') {
            $payload['text'] = $text;
        }

        $this->call('answerCallbackQuery', $payload);
    }

    public function showCallbackLoading(int|string $chatId, int $messageId, array $keyboard, string $action): void
    {
        foreach ($keyboard as &$row) {
            foreach ($row as &$button) {
                if (($button['callback_data'] ?? null) === $action) {
                    $button['text'] = '⏳ Loading…';
                }
            }
        }
        unset($row, $button);

        $this->call('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ]);
    }

    public function isMember(string $channelId, int $userId): bool
    {
        $result = $this->call('getChatMember', ['chat_id' => $channelId, 'user_id' => $userId]);

        return in_array(data_get($result, 'result.status'), ['creator', 'administrator', 'member', 'restricted'], true)
            && data_get($result, 'result.is_member', true) !== false;
    }
}
