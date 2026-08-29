<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_non_admin_cannot_open_admin_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_admin_can_create_a_product(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->post('/admin/products', [
            'name' => 'Premium Account',
            'description' => 'Delivered securely.',
            'price' => '9.99',
            'stock' => 20,
            'warranty_days' => 30,
            'delivery_type' => 'manual',
            'sort_order' => 10,
            'is_active' => '1',
        ])->assertRedirect(route('admin.products.index'));

        $this->assertDatabaseHas(Product::class, ['name' => 'Premium Account', 'stock' => 20, 'is_active' => true]);
    }

    public function test_admin_can_open_deposit_history(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->get('/admin/deposits')->assertOk()->assertSee('Lịch sử nạp tiền');
    }
}
