@extends('admin.layout')
@section('title','Đơn hàng')
@section('content')
<div class="mb-8"><p class="text-sm font-bold uppercase tracking-[.2em] text-cyan-400">Fulfillment</p><h1 class="mt-2 text-3xl font-black">Đơn hàng</h1></div>
<div class="overflow-x-auto rounded-2xl border border-white/10 bg-slate-900"><table class="w-full min-w-[900px] text-left"><thead class="text-sm text-slate-400"><tr><th class="p-5">Mã đơn</th><th>Khách hàng</th><th>Tổng</th><th>Ngày tạo</th><th>Trạng thái</th></tr></thead><tbody>
@forelse($orders as $order)<tr class="border-t border-white/10"><td class="p-5 font-mono">{{ $order->public_id }}</td><td><p>{{ $order->user?->name }}</p><p class="text-sm text-slate-500">{{ $order->user?->telegram_username ? '@'.$order->user->telegram_username : $order->user?->telegram_id }}</p></td><td>${{ number_format((float)$order->total,2) }}</td><td>{{ $order->created_at->format('d/m/Y H:i') }}</td><td><form method="post" action="{{ route('admin.orders.update',$order) }}" class="flex gap-2">@csrf @method('PATCH')<select name="status" class="rounded-lg border border-white/10 bg-slate-950 px-3 py-2">@foreach(['pending','paid','processing','completed','cancelled','refunded'] as $status)<option value="{{ $status }}" @selected($order->status===$status)>{{ strtoupper($status) }}</option>@endforeach</select><button class="rounded-lg bg-white/10 px-3 hover:bg-white/20">Lưu</button></form></td></tr>
@empty<tr><td colspan="5" class="p-10 text-center text-slate-500">Chưa có đơn hàng.</td></tr>@endforelse
</tbody></table></div><div class="mt-6">{{ $orders->links() }}</div>
@endsection
