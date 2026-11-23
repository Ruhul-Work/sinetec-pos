<?php

namespace Tests\Feature;

use App\Models\backend\Branch;
use App\Models\backend\Role;
use App\Models\backend\User;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporting_dashboard_lists_available_report_cards_and_period_controls(): void
    {
        $branch = Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $role = Role::query()->create([
            'name' => 'Super User',
            'key' => 'super-user',
            'is_super' => true,
        ]);
        $user = User::query()->create([
            'name' => 'Reporting Administrator',
            'email' => 'reporting-admin@example.test',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'status' => true,
        ]);
        $this->seed(CorePermissionSeeder::class);

        $this->actingAs($user)
            ->get(route('reporting.dashboard.index'))
            ->assertOk()
            ->assertSee('Stock Ledger')
            ->assertSee('Supplier Due Summary')
            ->assertSee('Purchase Price History')
            ->assertSee('Customer Receivables')
            ->assertSee('Sales / Return Reconciliation')
            ->assertSee('Cash / Bank Book')
            ->assertSee('Account Ledger')
            ->assertSee('Trial Balance')
            ->assertSee('Monthly Rate Sheets')
            ->assertSee('Final Monthly Snapshots')
            ->assertSee('Reporting Periods');

        $this->get(route('reporting.snapshots.index'))
            ->assertOk()
            ->assertSee('Monthly Snapshot Register');
    }
}
