<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title') · KoDuck Admin</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
<div class="min-h-screen lg:flex">
 <aside class="border-b border-white/10 bg-slate-900/80 p-5 lg:min-h-screen lg:w-64 lg:border-b-0 lg:border-r">
  <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3 text-xl font-black"><span class="grid size-10 place-items-center rounded-2xl bg-cyan-400 text-slate-950">K</span> KoDuck</a>
  <nav class="mt-8 flex gap-2 lg:flex-col">
   <a class="rounded-xl px-4 py-3 hover:bg-white/10" href="{{ route('admin.dashboard') }}">Tổng quan</a>
   <a class="rounded-xl px-4 py-3 hover:bg-white/10" href="{{ route('admin.products.index') }}">Sản phẩm</a>
   <a class="rounded-xl px-4 py-3 hover:bg-white/10" href="{{ route('admin.deposits.index') }}">Nạp tiền</a>
   <a class="rounded-xl px-4 py-3 hover:bg-white/10" href="{{ route('admin.orders.index') }}">Đơn hàng</a>
  </nav>
  <form method="post" action="{{ route('admin.logout') }}" class="mt-6">@csrf<button class="text-sm text-slate-400 hover:text-white">Đăng xuất</button></form>
 </aside>
 <main class="flex-1 p-5 md:p-8 lg:p-10"><div class="mx-auto max-w-7xl">
  @if(session('success'))<div class="mb-6 rounded-2xl border border-emerald-400/30 bg-emerald-400/10 px-5 py-4 text-emerald-200">{{ session('success') }}</div>@endif
  @yield('content')
 </div></main>
</div></body></html>
