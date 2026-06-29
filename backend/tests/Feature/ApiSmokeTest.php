<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoDataSeeder::class);
    }

    public function test_login_returns_token_and_user_with_roles(): void
    {
        $res = $this->postJson('/api/auth/login', [
            'email' => 'admin@surgical.test', 'password' => 'password',
        ]);

        $res->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'roles']]);

        $this->assertContains(UserRole::Admin->value, $res->json('user.roles'));
    }

    public function test_login_rejects_bad_credentials(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@surgical.test', 'password' => 'wrong',
        ])->assertStatus(422);
    }

    public function test_authenticated_user_can_list_inventory_and_dashboard(): void
    {
        $user = User::where('email', 'admin@surgical.test')->first();

        $this->actingAs($user, 'sanctum')->getJson('/api/inventory')
            ->assertOk()->assertJsonStructure(['data', 'meta']);

        $this->actingAs($user, 'sanctum')->getJson('/api/dashboard')
            ->assertOk()->assertJsonStructure(['inventory', 'transfers', 'stock_counts']);

        $this->actingAs($user, 'sanctum')->getJson('/api/meta/options')
            ->assertOk()->assertJsonStructure(['stock_types', 'locations', 'statuses']);
    }

    public function test_general_user_cannot_manage_hospitals(): void
    {
        $rep = User::where('email', 'mike@surgical.test')->first();

        // Viewing is allowed; creating (manage) is forbidden by policy.
        $this->actingAs($rep, 'sanctum')->getJson('/api/hospitals')->assertOk();
        $this->actingAs($rep, 'sanctum')->postJson('/api/hospitals', [
            'name' => 'New', 'category' => 'private',
        ])->assertForbidden();
    }

    public function test_global_search_groups_results(): void
    {
        $user = User::where('email', 'admin@surgical.test')->first();

        $this->actingAs($user, 'sanctum')->getJson('/api/search?q=Arwyp')
            ->assertOk()->assertJsonPath('query', 'Arwyp');
    }
}
