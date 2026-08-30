@extends('admin.layout')
@section('title','Users')
@section('content')
<div class="mb-8"><p class="text-sm font-bold uppercase tracking-[.2em] text-cyan-400">Customers</p><h1 class="mt-2 text-3xl font-black">Telegram Users</h1></div>
<form method="get" class="mb-6 grid gap-3 rounded-2xl border border-white/10 bg-slate-900 p-4 sm:grid-cols-[1fr_200px_auto]">
 <input name="q" value="{{ $search }}" placeholder="Name, username or Telegram ID" class="rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-cyan-400">
 <select name="status" class="rounded-xl border border-white/10 bg-slate-950 px-4 py-3"><option value="">All users</option><option value="active" @selected($status==='active')>Active</option><option value="blocked" @selected($status==='blocked')>Blocked</option></select>
 <button class="rounded-xl bg-white/10 px-5 py-3 font-bold hover:bg-white/20">Search</button>
</form>
<div class="overflow-x-auto rounded-2xl border border-white/10 bg-slate-900"><table class="w-full min-w-[950px] text-left"><thead class="text-sm text-slate-400"><tr><th class="p-5">User</th><th>Telegram ID</th><th>Wallet</th><th>Deposited</th><th>Orders</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($users as $user)<tr class="border-t border-white/10"><td class="p-5"><p class="font-bold">{{ $user->name }}</p><p class="text-sm text-slate-500">{{ $user->telegram_username ? '@'.$user->telegram_username : 'No username' }}</p></td><td class="font-mono text-sm">{{ $user->telegram_id }}</td><td class="font-bold">${{ number_format((float)$user->balance,2) }}</td><td>{{ number_format((float)($user->approved_deposits??0),2) }} USDT</td><td>{{ $user->orders_count }}</td><td><span class="rounded-full px-3 py-1 text-xs {{ $user->is_blocked?'bg-rose-400/10 text-rose-300':'bg-emerald-400/10 text-emerald-300' }}">{{ $user->is_blocked?'BLOCKED':'ACTIVE' }}</span></td><td class="pr-5 text-right"><a class="text-cyan-400" href="{{ route('admin.users.show',$user) }}">View</a></td></tr>
@empty<tr><td colspan="7" class="p-10 text-center text-slate-500">No users found.</td></tr>@endforelse
</tbody></table></div><div class="mt-6">{{ $users->links() }}</div>
@endsection
