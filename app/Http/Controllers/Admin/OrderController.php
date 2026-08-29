<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(): View
    {
        return view('admin.orders.index', ['orders' => Order::query()->with('user')->latest()->paginate(25)]);
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['pending', 'paid', 'processing', 'completed', 'cancelled', 'refunded'])]]);
        $data['completed_at'] = $data['status'] === 'completed' ? ($order->completed_at ?? now()) : null;
        $order->update($data);

        return back()->with('success', 'Đã cập nhật trạng thái đơn hàng.');
    }
}
