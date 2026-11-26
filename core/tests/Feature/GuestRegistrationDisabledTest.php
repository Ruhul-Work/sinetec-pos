<?php

namespace Tests\Feature;

use App\Models\backend\Branch;
use App\Models\backend\Role;
use App\Models\backend\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestRegistrationDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_registration_is_unavailable_and_login_still_renders(): void
    {
        $this->get('/backend/register')->assertNotFound();
        $this->post('/backend/register')->assertNotFound();
        $this->get(route('backend.login'))->assertOk()->assertDontSee('Sign Up');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_can_still_create_a_staff_account(): void
    {
        $branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN']);
        $adminRole = Role::query()->create(['name' => 'Super Admin', 'key' => 'super-admin', 'is_super' => true]);
        $staffRole = Role::query()->create(['name' => 'Staff', 'key' => 'staff', 'is_super' => false]);
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('test-admin-password'),
            'branch_id' => $branch->id,
            'role_id' => $adminRole->id,
            'status' => true,
        ]);

        $this->actingAs($admin)->postJson(route('usermanage.users.store'), [
            'name' => 'Staff Member',
            'email' => 'staff@example.test',
            'password' => 'test-staff-password',
            'password_confirmation' => 'test-staff-password',
            'branch_id' => $branch->id,
            'role_id' => $staffRole->id,
            'status' => 1,
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('users', [
            'email' => 'staff@example.test',
            'branch_id' => $branch->id,
            'role_id' => $staffRole->id,
        ]);
    }
}
