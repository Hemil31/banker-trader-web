<?php

namespace Tests\Feature;

use App\Models\User;
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
}
