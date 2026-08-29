@extends('admin.layout')
@section('title','Thông báo')
@section('content')
<div class="mb-8"><p class="text-sm font-bold uppercase tracking-[.2em] text-cyan-400">Broadcast</p><h1 class="mt-2 text-3xl font-black">Gửi thông báo</h1><p class="mt-2 text-slate-400">Hiện có {{ $customerCount }} người dùng có thể nhận thông báo qua chat riêng.</p></div>
<div class="grid gap-8 xl:grid-cols-[minmax(0,1fr)_minmax(500px,1.3fr)]">
 <form method="post" action="{{ route('admin.notifications.store') }}" class="rounded-2xl border border-white/10 bg-slate-900 p-6">@csrf
  <h2 class="mb-6 text-xl font-bold">Soạn thông báo mới</h2>
  @if($errors->any())<div class="mb-5 rounded-xl bg-rose-400/10 p-4 text-rose-300">{{ $errors->first() }}</div>@endif
  <label class="mb-5 block"><span class="mb-2 block text-sm text-slate-400">Đối tượng nhận</span><select name="audience" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"><option value="users">Private Users</option><option value="channel">Channel</option><option value="group">Group</option><option value="communities">Channel + Group</option><option value="all">All — Users + Channel + Group</option></select></label>
  <label class="mb-5 block"><span class="mb-2 block text-sm text-slate-400">Tiêu đề</span><input name="title" maxlength="120" required value="{{ old('title') }}" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"></label>
  <label class="mb-5 block"><span class="mb-2 block text-sm text-slate-400">Nội dung (hỗ trợ HTML Telegram)</span><textarea name="message" maxlength="1000" rows="7" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3">{{ old('message') }}</textarea><span class="mt-2 block text-xs text-slate-500">Có thể dùng &lt;b&gt;, &lt;i&gt;, &lt;code&gt;. Không nhập dữ liệu bí mật.</span></label>
  <label class="mb-5 block"><span class="mb-2 block text-sm text-slate-400">URL ảnh (không bắt buộc)</span><input name="image_url" type="url" value="{{ old('image_url') }}" placeholder="https://..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"></label>
  <div class="grid gap-4 sm:grid-cols-2"><label><span class="mb-2 block text-sm text-slate-400">Chữ trên nút</span><input name="button_text" value="{{ old('button_text') }}" placeholder="Xem ngay" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"></label><label><span class="mb-2 block text-sm text-slate-400">URL của nút</span><input name="button_url" type="url" value="{{ old('button_url') }}" placeholder="https://..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"></label></div>
  <div class="mt-6 rounded-xl border border-amber-400/20 bg-amber-400/5 p-4 text-sm text-amber-200">Thông báo sẽ gửi đúng đối tượng đã chọn. Nội dung shop riêng tư vẫn không được xử lý trong group.</div>
  <button onclick="return confirm('Xác nhận gửi thông báo này cho tất cả người dùng?')" class="mt-6 rounded-xl bg-cyan-400 px-6 py-3 font-bold text-slate-950">Xếp hàng gửi</button>
 </form>
 <div><h2 class="mb-5 text-xl font-bold">Lịch sử gửi</h2><div class="space-y-4">
 @forelse($broadcasts as $item)<div class="rounded-2xl border border-white/10 bg-slate-900 p-5"><div class="flex items-start justify-between gap-4"><div><h3 class="font-bold">{{ $item->title }}</h3><p class="mt-1 text-sm text-slate-500">{{ $item->created_at->format('d/m/Y H:i') }} · {{ strtoupper($item->audience) }}</p></div><span class="rounded-full px-3 py-1 text-xs {{ $item->completed_at?'bg-emerald-400/10 text-emerald-300':'bg-cyan-400/10 text-cyan-300' }}">{{ $item->completed_at?'HOÀN TẤT':'ĐANG GỬI' }}</span></div><p class="mt-4 line-clamp-3 text-sm text-slate-300">{{ strip_tags($item->message) }}</p><div class="mt-4 flex gap-5 text-sm"><span>Người nhận <b>{{ $item->recipient_count }}</b></span><span class="text-emerald-300">Đã gửi <b>{{ $item->sent_count }}</b></span><span class="text-rose-300">Lỗi <b>{{ $item->failed_count }}</b></span></div></div>
 @empty<div class="rounded-2xl border border-dashed border-white/10 p-10 text-center text-slate-500">Chưa gửi thông báo nào.</div>@endforelse
 </div><div class="mt-6">{{ $broadcasts->links() }}</div></div>
</div>
@endsection
