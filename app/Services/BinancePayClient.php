<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BinancePayClient
{
    public function transactions(CarbonInterface $from, CarbonInterface $to): array
    {
        $key = (string) config('services.binance.api_key');
        $secret = (string) config('services.binance.api_secret');
        throw_if($key === '' || $secret === '', RuntimeException::class, 'Binance API is not configured.');

        $parameters = [
            'startTime' => $from->getTimestampMs(),
            'endTime' => $to->getTimestampMs(),
            'limit' => 100,
            'timestamp' => now()->getTimestampMs(),
        ];
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $parameters['signature'] = hash_hmac('sha256', $query, $secret);

        $response = Http::withHeader('X-MBX-APIKEY', $key)->timeout(15)->retry(2, 300)
            ->get('https://api.binance.com/sapi/v1/pay/transactions', $parameters)->throw()->json();

        throw_if(($response['code'] ?? null) !== '000000', RuntimeException::class, 'Binance rejected the transaction query.');

        return $response['data'] ?? [];
    }

    public function findIncoming(string $transactionId, string $amount, CarbonInterface $createdAt): ?array
    {
        $currency = strtoupper((string) config('services.binance.currency', 'USDT'));
        $from = $createdAt->copy()->subMinutes(5);
        if ($from->isAfter(now()->subDay())) {
            $from = now()->subDay();
        }

        foreach ($this->transactions($from, now()) as $transaction) {
            if (! hash_equals(strtolower(trim((string) ($transaction['transactionId'] ?? ''))), strtolower(trim($transactionId)))) {
                continue;
            }
            if (strtoupper((string) ($transaction['currency'] ?? '')) !== $currency) {
                return null;
            }
            if (bccomp((string) ($transaction['amount'] ?? '0'), $amount, 8) !== 0) {
                return null;
            }

            // Binance identifies received payments with transactionType=RECEIVE.
            // receiverInfo fields are not Pay IDs and vary by account type, so
            // comparing them with the configured Pay ID rejects valid receipts.
            if (strtoupper((string) ($transaction['transactionType'] ?? '')) !== 'RECEIVE') {
                return null;
            }

            return $transaction;
        }

        return null;
    }
}
