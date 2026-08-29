<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BinancePayClient
{
    public function transactions(CarbonInterface $from, CarbonInterface $to): array
    {
        $parameters = [
            'startTime' => $from->getTimestampMs(),
            'endTime' => $to->getTimestampMs(),
            'limit' => 100,
            'timestamp' => now()->getTimestampMs(),
        ];
        $response = $this->signedRequest('get', '/sapi/v1/pay/transactions', $parameters);

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
            $submittedId = strtolower(trim($transactionId));
            $providerIds = array_filter([
                strtolower(trim((string) ($transaction['transactionId'] ?? ''))),
                strtolower(trim((string) ($transaction['orderId'] ?? ''))),
            ]);
            if (! in_array($submittedId, $providerIds, true)) {
                continue;
            }
            if (strtoupper((string) ($transaction['currency'] ?? '')) !== $currency) {
                return null;
            }
            if (bccomp((string) ($transaction['amount'] ?? '0'), $amount, 8) !== 0) {
                return null;
            }

            // Some Binance Pay history responses omit transactionType. In those
            // responses incoming payments have a positive amount and outgoing
            // payments have a negative amount.
            $transactionType = strtoupper((string) ($transaction['transactionType'] ?? ''));
            $isIncoming = $transactionType !== ''
                ? $transactionType === 'RECEIVE'
                : bccomp((string) ($transaction['amount'] ?? '0'), '0', 8) > 0;
            if (! $isIncoming) {
                return null;
            }

            return $transaction;
        }

        return null;
    }

    public function depositAddress(string $coin, string $network): array
    {
        $response = $this->signedRequest('get', '/sapi/v1/capital/deposit/address', [
            'coin' => strtoupper($coin),
            'network' => strtoupper($network),
        ]);

        throw_unless(isset($response['address']), RuntimeException::class, 'Binance did not return a deposit address.');

        return $response;
    }

    public function deposits(string $coin, CarbonInterface $from, CarbonInterface $to): array
    {
        $response = $this->signedRequest('get', '/sapi/v1/capital/deposit/hisrec', [
            'coin' => strtoupper($coin),
            'startTime' => $from->getTimestampMs(),
            'endTime' => $to->getTimestampMs(),
            'limit' => 1000,
        ]);

        return array_is_list($response) ? $response : [];
    }

    public function findBlockchainDeposit(string $txId, string $amount, string $network, string $address, CarbonInterface $createdAt): ?array
    {
        $coin = strtoupper((string) config('services.binance.currency', 'USDT'));
        $from = $createdAt->copy()->subHour();
        if ($from->isAfter(now()->subDay())) {
            $from = now()->subDay();
        }

        foreach ($this->deposits($coin, $from, now()) as $deposit) {
            if (strcasecmp(trim((string) ($deposit['txId'] ?? '')), trim($txId)) !== 0) {
                continue;
            }
            if (strtoupper((string) ($deposit['coin'] ?? '')) !== $coin
                || strtoupper((string) ($deposit['network'] ?? '')) !== strtoupper($network)
                || strcasecmp(trim((string) ($deposit['address'] ?? '')), trim($address)) !== 0
                || bccomp((string) ($deposit['amount'] ?? '0'), $amount, 8) !== 0
                || ! in_array((int) ($deposit['status'] ?? -1), [1, 6], true)) {
                return null;
            }

            return $deposit;
        }

        return null;
    }

    private function signedRequest(string $method, string $path, array $parameters): array
    {
        $key = (string) config('services.binance.api_key');
        $secret = (string) config('services.binance.api_secret');
        throw_if($key === '' || $secret === '', RuntimeException::class, 'Binance API is not configured.');

        $parameters['timestamp'] = now()->getTimestampMs();
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $parameters['signature'] = hash_hmac('sha256', $query, $secret);

        return Http::withHeader('X-MBX-APIKEY', $key)->timeout(15)->retry(2, 300)
            ->{$method}('https://api.binance.com'.$path, $parameters)->throw()->json();
    }
}
