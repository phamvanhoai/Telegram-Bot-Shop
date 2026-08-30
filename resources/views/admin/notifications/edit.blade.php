@extends('admin.layout')
@section('title', 'Edit Broadcast')
@section('content')
<div class="mx-auto max-w-3xl">
 <div class="mb-8"><p class="text-sm font-bold uppercase tracking-[.2em] text-cyan-400">Broadcast</p><h1 class="mt-2 text-3xl font-black">Edit announcement</h1><p class="mt-2 text-slate-400">Changes apply only to recipients who have not received this broadcast yet.</p></div>
 <form method="post" action="{{ route('admin.notifications.update', $notification) }}" class="rounded-2xl border border-white/10 bg-slate-900 p-6">@csrf @method('PUT')
  @if($errors->any())<div class="mb-5 rounded-xl bg-rose-400/10 p-4 text-rose-300">{{ $errors->first() }}</div>@endif
  <label class="mb-5 block"><span class="mb-2 block text-sm text-slate-400">Title</span><input name="title" maxlength="120" required value="{{ old('title', $notification->title) }}" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"></label>
  <label class="mb-5 block"><span class="mb-2 block text-sm text-slate-400">Message (Telegram HTML supported)</span><textarea name="message" maxlength="1000" rows="8" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3">{{ old('message', $notification->message) }}</textarea></label>
  <label class="mb-5 block"><span class="mb-2 block text-sm text-slate-400">Image URL</span><input name="image_url" type="url" value="{{ old('image_url', $notification->image_url) }}" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"></label>
  <div class="grid gap-4 sm:grid-cols-2"><label><span class="mb-2 block text-sm text-slate-400">Button text</span><input name="button_text" value="{{ old('button_text', $notification->button_text) }}" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"></label><label><span class="mb-2 block text-sm text-slate-400">Button URL</span><input name="button_url" type="url" value="{{ old('button_url', $notification->button_url) }}" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"></label></div>
  <div class="mt-6 flex gap-3"><button class="rounded-xl bg-cyan-400 px-6 py-3 font-bold text-slate-950">Save changes</button><a href="{{ route('admin.notifications.index') }}" class="rounded-xl border border-white/10 px-6 py-3 font-bold">Cancel</a></div>
 </form>
</div>
@endsection
