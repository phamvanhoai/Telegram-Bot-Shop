<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'stats' => [
                'products' => Product::query()->count(),
                'orders' => Order::query()->count(),
                'customers' => User::query()->whereNotNull('telegram_id')->count(),
                'revenue' => Order::query()->whereNotIn('status', ['cancelled', 'refunded'])->sum('total'),
                'deposits' => DepositRequest::query()->where('status', 'approved')->sum('amount'),
            ],
            'orders' => Order::query()->with('user')->latest()->limit(8)->get(),
        ]);
    }
}
