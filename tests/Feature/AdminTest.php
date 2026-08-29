<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RequiredChannel;
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

    public function test_admin_can_queue_a_notification_for_telegram_customers(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $customer = User::factory()->create();
        $customer->forceFill(['telegram_id' => 123456])->save();

        $this->actingAs($admin)->post('/admin/notifications', [
            'title' => 'New products',
            'message' => 'Visit the store for our latest products.',
            'audience' => 'users',
            'button_text' => 'Open Store',
            'button_url' => 'https://t.me/example_bot',
        ])->assertRedirect();

        $this->assertDatabaseHas('notification_broadcasts', ['title' => 'New products', 'recipient_count' => 1]);
        $this->assertDatabaseHas('notification_recipients', ['user_id' => $customer->id, 'status' => 'pending']);
    }

    public function test_admin_can_target_channel_and_group_without_private_users(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        RequiredChannel::query()->create(['chat_id' => '-1001', 'name' => 'Shop Channel', 'join_url' => 'https://t.me/shop', 'is_active' => true]);
        RequiredChannel::query()->create(['chat_id' => '-1002', 'name' => 'Shop Group', 'join_url' => 'https://t.me/group', 'is_active' => true]);

        $this->actingAs($admin)->post('/admin/notifications', [
            'title' => 'Community update',
            'message' => 'A new update is available.',
            'audience' => 'communities',
        ])->assertRedirect();

        $this->assertDatabaseHas('notification_recipients', ['chat_id' => '-1001', 'recipient_name' => 'Shop Channel']);
        $this->assertDatabaseHas('notification_recipients', ['chat_id' => '-1002', 'recipient_name' => 'Shop Group']);
        $this->assertDatabaseCount('notification_recipients', 2);
    }
}
