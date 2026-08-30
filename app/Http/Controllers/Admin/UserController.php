<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim($request->string('q')->toString());
        $status = $request->string('status')->toString();
        $users = User::query()->whereNotNull('telegram_id')
            ->withCount('orders')
            ->withSum(['deposits as approved_deposits' => fn (Builder $query) => $query->where('status', 'approved')], 'amount')
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('telegram_username', 'like', "%{$search}%")
                    ->orWhere('telegram_id', 'like', "%{$search}%");
            }))
            ->when($status === 'active', fn (Builder $query) => $query->where('is_blocked', false))
            ->when($status === 'blocked', fn (Builder $query) => $query->where('is_blocked', true))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.users.index', compact('users', 'search', 'status'));
    }

    public function show(User $user): View
    {
        abort_if($user->telegram_id === null, 404);

        return view('admin.users.show', [
            'customer' => $user,
            'orders' => $user->orders()->latest()->limit(10)->get(),
            'deposits' => $user->deposits()->with('method')->latest()->limit(10)->get(),
            'transactions' => $user->walletTransactions()->latest()->limit(20)->get(),
        ]);
    }

    public function toggleBlock(User $user): RedirectResponse
    {
        abort_if($user->is_admin, 422, 'Administrator accounts cannot be blocked here.');
        $user->forceFill(['is_blocked' => ! $user->is_blocked])->save();

        return back()->with('success', $user->is_blocked ? 'Đã khóa người dùng.' : 'Đã mở khóa người dùng.');
    }

    public function adjustBalance(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0', 'between:-1000000,1000000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($request, $user, $data): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $before = (string) $locked->balance;
            $after = bcadd($before, (string) $data['amount'], 8);
            abort_if(bccomp($after, '0', 8) < 0, 422, 'Balance cannot become negative.');
            $locked->forceFill(['balance' => $after])->save();
            WalletTransaction::query()->create([
                'user_id' => $locked->id,
                'type' => 'adjustment',
                'amount' => $data['amount'],
                'balance_before' => $before,
                'balance_after' => $after,
                'reference_type' => User::class,
                'reference_id' => (string) $request->user()->id,
                'description' => 'Admin adjustment: '.$data['reason'],
            ]);
        });

        return back()->with('success', 'Đã điều chỉnh số dư và ghi lịch sử giao dịch.');
    }
}
