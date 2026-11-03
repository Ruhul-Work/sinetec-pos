<?php

namespace Tests\Feature;

use App\Models\backend\Branch;
use App\Models\backend\Role;
use App\Models\backend\User;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_menu_destination_shows_create_action_and_can_create_manager_role(): void
    {
        $branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true]);
        $superRole = Role::query()->create(['name' => 'Super User', 'key' => 'super-user', 'is_super' => true]);
        $user = User::query()->create([
            'name' => 'Administrator',
            'email' => 'admin@example.test',
            'password' => bcrypt('password'),
            'role_id' => $superRole->id,
            'branch_id' => $branch->id,
            'status' => true,
        ]);
        $this->seed(CorePermissionSeeder::class);

        $this->actingAs($user)
            ->get(route('rbac.role.list'))
            ->assertOk()
            ->assertSee('Add New Role');

        $this->actingAs($user)
            ->post(route('rbac.role.store'), ['name' => 'Manager', 'key' => 'manager'])
            ->assertRedirect();

        $this->assertDatabaseHas('roles', [
            'name' => 'Manager',
            'key' => 'manager',
            'is_super' => false,
        ]);

        $manager = Role::query()->where('key', 'manager')->firstOrFail();
        $this->actingAs($user)
            ->get(route('rbac.role.matrix', $manager))
            ->assertOk()
            ->assertSee('Manage Role Permissions')
            ->assertSee('Role permission guide');
    }
}
