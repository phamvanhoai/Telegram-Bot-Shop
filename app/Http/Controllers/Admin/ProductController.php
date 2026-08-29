<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(): View
    {
        return view('admin.products.index', ['products' => Product::query()->orderBy('sort_order')->latest('id')->paginate(20)]);
    }

    public function create(): View
    {
        return view('admin.products.form', ['product' => new Product]);
    }

    public function store(Request $request): RedirectResponse
    {
        Product::query()->create($this->validated($request));

        return redirect()->route('admin.products.index')->with('success', 'Đã thêm sản phẩm.');
    }

    public function edit(Product $product): View
    {
        return view('admin.products.form', compact('product'));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $product->update($this->validated($request));

        return redirect()->route('admin.products.index')->with('success', 'Đã cập nhật sản phẩm.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        if ($product->orderItems()->exists()) {
            $product->update(['is_active' => false]);

            return back()->with('success', 'Sản phẩm đã có đơn nên được chuyển sang trạng thái ẩn.');
        }
        $product->delete();

        return back()->with('success', 'Đã xóa sản phẩm.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'warranty_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'delivery_type' => ['required', Rule::in(['manual', 'automatic'])],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
