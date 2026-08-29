<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>KoDuck Admin</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body class="grid min-h-screen place-items-center bg-slate-950 p-5 text-slate-100">
<form method="post" action="{{ route('admin.login.store') }}" class="w-full max-w-md rounded-3xl border border-white/10 bg-slate-900 p-8 shadow-2xl">@csrf
 <div class="mb-8"><div class="mb-5 grid size-14 place-items-center rounded-2xl bg-cyan-400 text-2xl font-black text-slate-950">K</div><h1 class="text-3xl font-black">KoDuck Admin</h1><p class="mt-2 text-slate-400">Quản lý cửa hàng Telegram an toàn.</p></div>
 <label class="mb-2 block text-sm text-slate-300">Email</label><input name="email" type="email" value="{{ old('email') }}" required autofocus class="mb-5 w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-cyan-400">
 <label class="mb-2 block text-sm text-slate-300">Mật khẩu</label><input name="password" type="password" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-cyan-400">
 @error('email')<p class="mt-3 text-sm text-rose-400">{{ $message }}</p>@enderror
 <label class="mt-5 flex items-center gap-2 text-sm text-slate-400"><input type="checkbox" name="remember" value="1"> Ghi nhớ đăng nhập</label>
 <button class="mt-6 w-full rounded-xl bg-cyan-400 px-4 py-3 font-bold text-slate-950 hover:bg-cyan-300">Đăng nhập</button>
</form></body></html>
