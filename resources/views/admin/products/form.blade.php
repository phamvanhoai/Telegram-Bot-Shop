@extends('admin.layout')
@section('title',$product->exists?'Sửa sản phẩm':'Thêm sản phẩm')
@section('content')
<div class="mb-8"><a href="{{ route('admin.products.index') }}" class="text-sm text-cyan-400">‹ Danh sách sản phẩm</a><h1 class="mt-3 text-3xl font-black">{{ $product->exists?'Sửa sản phẩm':'Thêm sản phẩm' }}</h1></div>
<form method="post" action="{{ $product->exists?route('admin.products.update',$product):route('admin.products.store') }}" class="max-w-3xl rounded-2xl border border-white/10 bg-slate-900 p-6 md:p-8">@csrf @if($product->exists)@method('PUT')@endif
@if($errors->any())<div class="mb-6 rounded-xl bg-rose-400/10 p-4 text-rose-300">{{ $errors->first() }}</div>@endif
<div class="grid gap-5 md:grid-cols-2">
 <label class="md:col-span-2"><span class="mb-2 block text-sm text-slate-400">Tên sản phẩm</span><input name="name" required value="{{ old('name',$product->name) }}" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"></label>
 <label class="md:col-span-2"><span class="mb-2 block text-sm text-slate-400">Mô tả</span><textarea name="description" rows="5" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3">{{ old('description',$product->description) }}</textarea></label>
 <label><span class="mb-2 block text-sm text-slate-400">Giá USD</span><input name="price" type="number" step="0.00000001" min="0.01" required value="{{ old('price',$product->price) }}" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"></label>
 <label><span class="mb-2 block text-sm text-slate-400">Tồn kho</span><input name="stock" type="number" min="0" required value="{{ old('stock',$product->stock??0) }}" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"></label>
 <label><span class="mb-2 block text-sm text-slate-400">Bảo hành (ngày)</span><input name="warranty_days" type="number" min="0" required value="{{ old('warranty_days',$product->warranty_days??0) }}" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"></label>
 <label><span class="mb-2 block text-sm text-slate-400">Kiểu giao hàng</span><select name="delivery_type" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"><option value="manual" @selected(old('delivery_type',$product->delivery_type)==='manual')>Thủ công</option><option value="automatic" @selected(old('delivery_type',$product->delivery_type)==='automatic')>Tự động</option></select></label>
 <label><span class="mb-2 block text-sm text-slate-400">Thứ tự</span><input name="sort_order" type="number" min="0" required value="{{ old('sort_order',$product->sort_order??0) }}" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"></label>
 <label class="flex items-center gap-3 self-end pb-3"><input name="is_active" type="checkbox" value="1" @checked(old('is_active',$product->exists?$product->is_active:true))><span>Hiển thị trên bot</span></label>
</div><button class="mt-8 rounded-xl bg-cyan-400 px-6 py-3 font-bold text-slate-950">Lưu sản phẩm</button></form>
@endsection
