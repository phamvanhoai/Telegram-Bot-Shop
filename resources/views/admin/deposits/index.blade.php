@extends('admin.layout')
@section('title','Nạp tiền')
@section('content')
<div class="mb-8"><p class="text-sm font-bold uppercase tracking-[.2em] text-cyan-400">Payments</p><h1 class="mt-2 text-3xl font-black">Lịch sử nạp tiền</h1></div>
<form method="get" class="mb-6 grid gap-3 rounded-2xl border border-white/10 bg-slate-900 p-4 sm:grid-cols-[1fr_200px_auto]">
 <input name="q" value="{{ $search }}" placeholder="Tìm Order ID, tên hoặc username" class="rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-cyan-400">
 <select name="status" class="rounded-xl border border-white/10 bg-slate-950 px-4 py-3"><option value="">Tất cả trạng thái</option>@foreach(['pending'=>'Đang chờ','verifying'=>'Đang kiểm tra','approved'=>'Thành công','rejected'=>'Từ chối','expired'=>'Hết hạn'] as $value=>$label)<option value="{{ $value }}" @selected($status===$value)>{{ $label }}</option>@endforeach</select>
 <button class="rounded-xl bg-white/10 px-5 py-3 font-bold hover:bg-white/20">Lọc</button>
</form>
<div class="overflow-x-auto rounded-2xl border border-white/10 bg-slate-900"><table class="w-full min-w-[1050px] text-left"><thead class="text-sm text-slate-400"><tr><th class="p-5">Khách hàng</th><th>Số tiền</th><th>Binance Order ID</th><th>Phương thức</th><th>Trạng thái</th><th>Thời gian</th></tr></thead><tbody>
@forelse($deposits as $deposit)
<tr class="border-t border-white/10"><td class="p-5"><p class="font-semibold">{{ $deposit->user?->name }}</p><p class="text-sm text-slate-500">{{ $deposit->user?->telegram_username ? '@'.$deposit->user->telegram_username : 'Telegram '.$deposit->user?->telegram_id }}</p></td><td class="font-bold">{{ rtrim(rtrim((string)$deposit->amount,'0'),'.') }} USDT</td><td class="font-mono text-sm">{{ $deposit->txid ?: '—' }}</td><td>{{ $deposit->method?->name ?: '—' }}</td><td>@php($colors=['approved'=>'bg-emerald-400/10 text-emerald-300','pending'=>'bg-amber-400/10 text-amber-300','verifying'=>'bg-cyan-400/10 text-cyan-300','rejected'=>'bg-rose-400/10 text-rose-300','expired'=>'bg-slate-700 text-slate-300'])<span class="rounded-full px-3 py-1 text-xs font-bold {{ $colors[$deposit->status]??'bg-slate-700' }}">{{ strtoupper($deposit->status) }}</span></td><td><p>{{ $deposit->created_at->format('d/m/Y H:i') }}</p>@if($deposit->approved_at)<p class="mt-1 text-xs text-slate-500">Duyệt {{ $deposit->approved_at->format('d/m/Y H:i') }}</p>@endif</td></tr>
@empty<tr><td colspan="6" class="p-10 text-center text-slate-500">Không tìm thấy giao dịch nạp tiền.</td></tr>@endforelse
</tbody></table></div><div class="mt-6">{{ $deposits->links() }}</div>
@endsection
