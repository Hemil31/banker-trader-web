<?php

namespace Tests\Feature;

use App\Models\TradingConfig;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('admin.dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_regular_users_cannot_visit_the_admin_console()
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->get(route('admin.dashboard'));
        $response->assertForbidden();
    }

    public function test_admins_can_visit_the_admin_console()
    {
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user);

        $response = $this->get(route('admin.dashboard'));
        $response->assertOk();
    }

    public function test_regular_users_cannot_visit_the_settings_page()
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->get(route('admin.settings'))->assertForbidden();
    }

    public function test_admins_can_view_system_settings(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin);

        $response = $this->get(route('admin.settings'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('admin/settings')
            ->has('settings', fn ($settings) => $settings->where('0.key', 'news.api_key')->etc())
        );
    }

    public function test_admins_can_update_a_system_setting(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin);

        $this->post(route('admin.settings.update'), [
            'key' => 'news.api_key',
            'value' => 'real-news-key-123',
        ])->assertRedirect();

        $this->assertSame('real-news-key-123', TradingConfig::get('news.api_key'));
    }

    public function test_admins_cannot_update_a_per_user_editable_key_from_this_endpoint(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin);

        $before = TradingConfig::get('risk.capital');

        $this->post(route('admin.settings.update'), [
            'key' => 'risk.capital',
            'value' => '999999',
        ])->assertSessionHasErrors('key');

        $this->assertEquals($before, TradingConfig::get('risk.capital'));
    }

    public function test_regular_users_cannot_update_a_system_setting()
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->post(route('admin.settings.update'), [
            'key' => 'news.api_key',
            'value' => 'sneaky-key',
        ])->assertForbidden();
    }
}
