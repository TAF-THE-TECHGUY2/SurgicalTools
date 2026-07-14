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

    public function test_my_inventory_is_scoped_to_the_linked_location(): void
    {
        $josh = User::where('email', 'josh@surgical.test')->first();

        $res = $this->actingAs($josh, 'sanctum')->getJson('/api/inventory/my')->assertOk();

        $this->assertSame('Josh Boot', $res->json('location.name'));
        $names = collect($res->json('items'))->pluck('name');
        $this->assertTrue($names->contains('Guide Wire'));
        $guideWire = collect($res->json('items'))->firstWhere('name', 'Guide Wire');
        $this->assertSame(4, $guideWire['quantity']);
        $this->assertCount(4, $guideWire['units']);
    }

    public function test_locations_list_contains_the_five_entities(): void
    {
        $user = User::where('email', 'mike@surgical.test')->first();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/locations')->assertOk();
        $names = collect($res->json('data'))->pluck('name');

        foreach (['Zamokuhle Hospital', 'Mike Boot', 'Josh Boot', 'JHB Office', 'Netcare Montana'] as $expected) {
            $this->assertTrue($names->contains($expected), "missing location: {$expected}");
        }
    }

    public function test_removed_demo_products_are_not_seeded(): void
    {
        $this->assertDatabaseMissing('stock_items', ['name' => 'Trochar']);
        $this->assertDatabaseMissing('stock_items', ['name' => 'Mesh']);
        $this->assertDatabaseHas('stock_items', ['name' => 'Guide Wire']);
    }

    public function test_location_inventory_supports_search(): void
    {
        $user = User::where('email', 'admin@surgical.test')->first();
        $office = \App\Models\Location::where('name', 'JHB Office')->first();

        $res = $this->actingAs($user, 'sanctum')
            ->getJson("/api/locations/{$office->id}/inventory?q=Guide")->assertOk();

        $items = collect($res->json('items'));
        $this->assertCount(1, $items);
        $this->assertSame('Guide Wire', $items[0]['name']);
    }

    public function test_general_user_cannot_manage_locations_or_users(): void
    {
        $mike = User::where('email', 'mike@surgical.test')->first();

        $this->actingAs($mike, 'sanctum')->getJson('/api/locations')->assertOk();
        $this->actingAs($mike, 'sanctum')->postJson('/api/locations', ['name' => 'X', 'type' => 'other'])
            ->assertForbidden();
        $this->actingAs($mike, 'sanctum')->getJson('/api/users')->assertForbidden();
    }

    public function test_dashboard_and_search_respond(): void
    {
        $user = User::where('email', 'admin@surgical.test')->first();

        $this->actingAs($user, 'sanctum')->getJson('/api/dashboard')
            ->assertOk()->assertJsonStructure(['inventory', 'transfers', 'stock_counts']);

        $this->actingAs($user, 'sanctum')->getJson('/api/search?q=Guide')
            ->assertOk()->assertJsonPath('query', 'Guide');
    }
}
