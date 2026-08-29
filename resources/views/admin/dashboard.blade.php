@extends('admin.layout')
@section('title','Tổng quan')
@section('content')
<div class="mb-8"><p class="text-sm font-bold uppercase tracking-[.2em] text-cyan-400">Control center</p><h1 class="mt-2 text-3xl font-black">Tổng quan cửa hàng</h1></div>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
 @foreach(['products'=>'Sản phẩm','orders'=>'Đơn hàng','customers'=>'Khách hàng','revenue'=>'Doanh thu USD','deposits'=>'Đã nạp USDT'] as $key=>$label)
 <div class="rounded-2xl border border-white/10 bg-slate-900 p-5"><p class="text-sm text-slate-400">{{ $label }}</p><p class="mt-3 text-2xl font-black">{{ in_array($key,['revenue','deposits']) ? number_format((float)$stats[$key],2) : $stats[$key] }}</p></div>
 @endforeach
</div>
<div class="mt-8 rounded-2xl border border-white/10 bg-slate-900 p-6"><div class="mb-5 flex items-center justify-between"><h2 class="text-xl font-bold">Đơn hàng gần đây</h2><a class="text-cyan-400" href="{{ route('admin.orders.index') }}">Xem tất cả</a></div>
<div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="text-slate-400"><tr><th class="pb-3">Mã đơn</th><th>Khách</th><th>Tổng</th><th>Trạng thái</th></tr></thead><tbody>@forelse($orders as $order)<tr class="border-t border-white/10"><td class="py-4 font-mono">{{ $order->public_id }}</td><td>{{ $order->user?->name }}</td><td>${{ number_format((float)$order->total,2) }}</td><td>{{ strtoupper($order->status) }}</td></tr>@empty<tr><td colspan="4" class="py-8 text-center text-slate-500">Chưa có đơn hàng.</td></tr>@endforelse</tbody></table></div></div>
@endsection
