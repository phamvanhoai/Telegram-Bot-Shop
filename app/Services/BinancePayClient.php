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
        $payId = (string) config('services.binance.pay_id');

        foreach ($this->transactions($createdAt->copy()->subMinutes(5), now()) as $transaction) {
            if ((string) ($transaction['transactionId'] ?? '') !== $transactionId) {
                continue;
            }
            if (strtoupper((string) ($transaction['currency'] ?? '')) !== $currency) {
                return null;
            }
            if (bccomp((string) ($transaction['amount'] ?? '0'), $amount, 8) !== 0) {
                return null;
            }

            $receiverIds = array_filter([
                data_get($transaction, 'receiverInfo.binanceId'),
                data_get($transaction, 'receiverInfo.accountId'),
                data_get($transaction, 'receiverInfo.uid'),
            ]);
            if ($receiverIds !== [] && ! in_array($payId, array_map('strval', $receiverIds), true)) {
                return null;
            }

            return $transaction;
        }

        return null;
    }
}
