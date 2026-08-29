<?php

namespace App\Services;

use App\Models\DepositRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletService
{
    public function approveDeposit(DepositRequest $deposit, array $providerPayload): DepositRequest
    {
        return DB::transaction(function () use ($deposit, $providerPayload): DepositRequest {
            $locked = DepositRequest::query()->lockForUpdate()->findOrFail($deposit->id);
            if ($locked->status === 'approved') {
                return $locked;
            }
            throw_unless($locked->status === 'verifying', RuntimeException::class, 'Deposit cannot be approved.');

            $user = User::query()->lockForUpdate()->findOrFail($locked->user_id);
            $before = (string) $user->balance;
            $after = bcadd($before, (string) $locked->amount, 8);
            $user->forceFill(['balance' => $after])->save();
            $locked->update(['status' => 'approved', 'approved_at' => now(), 'provider_payload' => $providerPayload]);
            WalletTransaction::query()->create([
                'user_id' => $user->id, 'type' => 'deposit', 'amount' => $locked->amount,
                'balance_before' => $before, 'balance_after' => $after,
                'reference_type' => DepositRequest::class, 'reference_id' => $locked->id,
                'description' => 'Binance Pay deposit '.$locked->txid,
            ]);

            return $locked->refresh();
        });
    }
}
