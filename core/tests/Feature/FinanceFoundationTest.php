<?php

namespace Tests\Feature;

use App\Enums\Finance\FinanceMappingKey;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Http\Controllers\admin\BranchController;
use App\Http\Controllers\backend\AccountController;
use App\Http\Controllers\backend\BranchAccountController;
use App\Http\Controllers\backend\FinanceAccountMappingController;
use App\Http\Controllers\backend\FiscalYearController;
use App\Models\backend\Account;
use App\Models\backend\AccountType;
use App\Models\backend\Branch;
use App\Models\backend\BranchAccount;
use App\Models\backend\FiscalYear;
use App\Models\backend\Role;
use App\Models\backend\User;
use App\Models\backend\VoucherType;
use App\Services\Finance\FinanceAccountMappingService;
use Database\Seeders\CorePermissionSeeder;
use Database\Seeders\FinanceFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinanceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_starter_chart_is_idempotent_and_assigned_to_existing_branches(): void
    {
        $branch = $this->branch();
        $this->seed(FinanceFoundationSeeder::class);
        $this->seed(FinanceFoundationSeeder::class);

        $this->assertSame(5, AccountType::query()->count());
        $this->assertSame(7, Account::query()->count());
        $this->assertSame(7, BranchAccount::query()->where('branch_id', $branch->id)->count());
        $this->assertDatabaseHas('account_types', ['code' => 'ASSET', 'normal_balance' => 'DEBIT', 'is_system' => true]);
        $this->assertDatabaseHas('account_types', ['code' => 'LIABILITY', 'normal_balance' => 'CREDIT', 'is_system' => true]);
        $this->assertDatabaseHas('accounts', ['code' => '1100', 'name' => 'Raw Material Inventory', 'is_system' => true]);
        $this->assertDatabaseHas('branch_accounts', [
            'branch_id' => $branch->id,
            'account_id' => Account::query()->where('code', '1000')->value('id'),
            'is_default' => true,
        ]);
    }

    public function test_branch_override_resolves_before_company_default(): void
    {
        $branch = $this->branch();
        $other = $this->branch('Other Branch', 'BR-002');
        $this->seed(FinanceFoundationSeeder::class);
        $service = app(FinanceAccountMappingService::class);
        $default = Account::query()->where('code', '1100')->firstOrFail();
        $override = Account::query()->create([
            'account_type_id' => AccountType::query()->where('code', 'ASSET')->value('id'),
            'name' => 'Branch Raw Inventory',
            'code' => '1101',
            'currency' => 'BDT',
            'is_active' => true,
        ]);
        BranchAccount::query()->create(['branch_id' => $branch->id, 'account_id' => $override->id, 'is_active' => true]);
        $service->saveCompanyMappings([FinanceMappingKey::RawMaterialInventory->value => $default->id]);
        $service->saveBranchMappings($branch, [FinanceMappingKey::RawMaterialInventory->value => $override->id]);

        $this->assertSame($override->id, $service->resolveRequired(FinanceMappingKey::RawMaterialInventory, $branch->id)->id);
        $this->assertSame($default->id, $service->resolveRequired(FinanceMappingKey::RawMaterialInventory, $other->id)->id);
        $this->assertDatabaseCount('finance_account_mappings', 2);
    }

    public function test_mapping_rejects_incompatible_or_unassigned_account(): void
    {
        $branch = $this->branch();
        $this->seed(FinanceFoundationSeeder::class);
        $service = app(FinanceAccountMappingService::class);
        $payable = Account::query()->where('code', '2000')->firstOrFail();

        try {
            $service->saveCompanyMappings([FinanceMappingKey::RawMaterialInventory->value => $payable->id]);
            $this->fail('A liability account must not be accepted as inventory.');
        } catch (FinanceConfigurationException $exception) {
            $this->assertStringContainsString('ASSET', $exception->getMessage());
        }

        $unassigned = Account::query()->create([
            'account_type_id' => AccountType::query()->where('code', 'ASSET')->value('id'),
            'name' => 'Unassigned Inventory',
            'code' => '1199',
            'currency' => 'BDT',
            'is_active' => true,
        ]);
        $this->expectException(FinanceConfigurationException::class);
        $this->expectExceptionMessage('must be assigned');
        $service->saveBranchMappings($branch, [FinanceMappingKey::PartInventory->value => $unassigned->id]);
    }

    public function test_company_mapping_is_unique_and_assigns_control_account_to_all_branches(): void
    {
        $branch = $this->branch();
        $this->seed(FinanceFoundationSeeder::class);
        $service = app(FinanceAccountMappingService::class);
        $account = Account::query()->where('code', '1110')->firstOrFail();
        BranchAccount::query()->where('branch_id', $branch->id)->where('account_id', $account->id)->delete();

        $service->saveCompanyMappings([FinanceMappingKey::PartInventory->value => $account->id]);
        $service->saveCompanyMappings([FinanceMappingKey::PartInventory->value => $account->id]);

        $this->assertDatabaseCount('finance_account_mappings', 1);
        $this->assertDatabaseHas('branch_accounts', ['branch_id' => $branch->id, 'account_id' => $account->id, 'is_active' => true]);
    }

    public function test_new_branch_receives_starter_and_company_mapped_accounts(): void
    {
        $this->branch();
        $this->seed(FinanceFoundationSeeder::class);

        $customInventory = Account::query()->create([
            'account_type_id' => AccountType::query()->where('code', 'ASSET')->value('id'),
            'name' => 'Company Inventory Control',
            'code' => '1180',
            'currency' => 'BDT',
            'is_active' => true,
        ]);
        app(FinanceAccountMappingService::class)->saveCompanyMappings([
            FinanceMappingKey::RawMaterialInventory->value => $customInventory->id,
        ]);

        $response = app(BranchController::class)->store(Request::create('/branches', 'POST', [
            'name' => 'New Branch',
            'code' => 'BR-NEW',
            'is_active' => 1,
        ]));
        $branchId = (int) $response->getData(true)['id'];

        $this->assertSame(8, BranchAccount::query()->where('branch_id', $branchId)->count());
        $this->assertDatabaseHas('branch_accounts', [
            'branch_id' => $branchId,
            'account_id' => $customInventory->id,
            'is_active' => true,
        ]);
    }

    public function test_branch_assignment_cannot_remove_an_effective_mapped_account(): void
    {
        $branch = $this->branch();
        $this->seed(FinanceFoundationSeeder::class);
        $inventory = Account::query()->where('code', '1100')->firstOrFail();
        $cash = Account::query()->where('code', '1000')->firstOrFail();
        app(FinanceAccountMappingService::class)->saveCompanyMappings([
            FinanceMappingKey::RawMaterialInventory->value => $inventory->id,
        ]);

        $request = $this->requestAs($this->userForBranch($branch, true), '/branch-accounts/assign', 'POST', [
            'branch_id' => $branch->id,
            'account_ids' => [$cash->id],
            'default_account_id' => $cash->id,
        ]);

        $this->expectException(ValidationException::class);
        app(BranchAccountController::class)->assign($request);
    }

    public function test_fiscal_year_ranges_cannot_overlap_and_only_one_is_active(): void
    {
        $controller = app(FiscalYearController::class);
        $controller->store(Request::create('/fiscal-years', 'POST', [
            'name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => 1,
        ]));
        $controller->store(Request::create('/fiscal-years', 'POST', [
            'name' => 'FY 2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'is_active' => 1,
        ]));
        $this->assertSame(1, FiscalYear::query()->where('is_active', true)->count());
        $this->assertDatabaseHas('fiscal_years', ['name' => 'FY 2027', 'is_active' => true]);

        $this->expectException(ValidationException::class);
        $controller->store(Request::create('/fiscal-years', 'POST', [
            'name' => 'Overlap', 'start_date' => '2026-12-01', 'end_date' => '2027-06-30', 'is_active' => 0,
        ]));
    }

    public function test_account_balance_uses_only_posted_rows_and_normal_balance(): void
    {
        $branch = $this->branch();
        $this->seed(FinanceFoundationSeeder::class);
        $payable = Account::query()->where('code', '2000')->firstOrFail();
        $year = FiscalYear::query()->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $voucher = VoucherType::query()->where('code', 'PURCHASE')->firstOrFail();
        foreach (['DRAFT' => 50, 'POSTED' => 125, 'VOID' => 75] as $status => $credit) {
            $journalId = DB::table('journal_entries')->insertGetId([
                'voucher_no' => $status, 'posting_key' => 'BALANCE-TEST-'.$status,
                'voucher_type_id' => $voucher->id, 'branch_id' => $branch->id,
                'fiscal_year_id' => $year->id, 'entry_date' => '2026-11-01', 'status' => $status,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('journal_entry_lines')->insert([
                'journal_entry_id' => $journalId, 'account_id' => $payable->id, 'branch_id' => $branch->id,
                'debit' => 0, 'credit' => $credit, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $response = app(AccountController::class)->balance($payable)->getData(true);
        $this->assertSame('125.0000', $response['balance']);
        $this->assertSame('CREDIT', $response['normal_balance']);
    }

    public function test_account_hierarchy_cycle_and_mapped_account_disable_are_blocked(): void
    {
        $this->branch();
        $this->seed(FinanceFoundationSeeder::class);
        $assetType = AccountType::query()->where('code', 'ASSET')->firstOrFail();
        $parent = Account::query()->create([
            'account_type_id' => $assetType->id, 'name' => 'Custom Parent', 'code' => '1500',
            'currency' => 'BDT', 'is_active' => true,
        ]);
        $child = Account::query()->create([
            'account_type_id' => $assetType->id, 'parent_id' => $parent->id, 'name' => 'Custom Child', 'code' => '1510',
            'currency' => 'BDT', 'is_active' => true,
        ]);
        $cycleResponse = app(AccountController::class)->update(Request::create('/accounts/'.$parent->id, 'POST', [
            'name' => $parent->name, 'code' => $parent->code, 'account_type_id' => $assetType->id,
            'parent_id' => $child->id, 'allow_negative' => 0, 'is_active' => 1,
        ]), $parent);
        $this->assertSame(422, $cycleResponse->status());
        $this->assertNull($parent->fresh()->parent_id);

        $mapped = Account::query()->where('code', '1100')->firstOrFail();
        app(FinanceAccountMappingService::class)->saveCompanyMappings([
            FinanceMappingKey::RawMaterialInventory->value => $mapped->id,
        ]);
        $disableResponse = app(AccountController::class)->destroy($mapped);
        $this->assertSame(422, $disableResponse->status());
        $this->assertTrue($mapped->fresh()->is_active);
    }

    public function test_mapping_routes_have_independent_permission_and_view_renders(): void
    {
        $branch = $this->branch();
        $this->seed(FinanceFoundationSeeder::class);
        $this->seed(CorePermissionSeeder::class);
        $permissionId = DB::table('permissions')->where('key', 'finance.account_mappings')->value('id');

        $this->assertNotNull($permissionId);
        $this->assertSame(4, DB::table('permission_routes')->where('permission_id', $permissionId)->count());
        $this->assertDatabaseHas('permission_routes', ['permission_id' => $permissionId, 'route_name' => 'finance.account-mappings.company.update']);
        $request = $this->requestAs($this->userForBranch($branch, true), '/finance/account-mappings');
        $html = app(FinanceAccountMappingController::class)->index($request)->render();
        $this->assertStringContainsString('Company Default', $html);
        $this->assertStringContainsString('Branch Override', $html);
        $this->assertStringContainsString('RAW_MATERIAL_INVENTORY', $html);
    }

    public function test_non_super_user_is_restricted_to_own_branch_finance_settings(): void
    {
        $ownBranch = $this->branch();
        $otherBranch = $this->branch('Other Branch', 'BR-002');
        $this->seed(FinanceFoundationSeeder::class);
        $user = $this->userForBranch($ownBranch);
        $mappingController = app(FinanceAccountMappingController::class);
        $branchAccountController = app(BranchAccountController::class);

        $indexRequest = $this->requestAs($user, '/finance/account-mappings');
        $mappingHtml = $mappingController->index($indexRequest)->render();
        $branchAccountHtml = $branchAccountController->index($indexRequest)->render();

        $this->assertStringContainsString($ownBranch->name, $mappingHtml);
        $this->assertStringNotContainsString($otherBranch->name, $mappingHtml);
        $this->assertStringNotContainsString('Company Default</button>', $mappingHtml);
        $this->assertStringContainsString($ownBranch->name, $branchAccountHtml);
        $this->assertStringNotContainsString($otherBranch->name, $branchAccountHtml);

        $ownRequest = $this->requestAs($user, "/finance/account-mappings/branches/{$ownBranch->id}");
        $this->assertSame(200, $mappingController->branchData($ownRequest, $ownBranch)->status());

        $this->assertForbidden(fn () => $mappingController->branchData(
            $this->requestAs($user, "/finance/account-mappings/branches/{$otherBranch->id}"),
            $otherBranch,
        ));
        $this->assertForbidden(fn () => $mappingController->updateBranch(
            $this->requestAs($user, "/finance/account-mappings/branches/{$otherBranch->id}", 'PUT'),
            $otherBranch,
        ));
        $this->assertForbidden(fn () => $mappingController->updateCompany(
            $this->requestAs($user, '/finance/account-mappings/company', 'PUT'),
        ));
        $this->assertForbidden(fn () => $branchAccountController->assignedAccounts(
            $this->requestAs($user, "/branch-accounts/{$otherBranch->id}/accounts"),
            $otherBranch,
        ));
    }

    private function branch(string $name = 'Main Branch', string $code = 'BR-001'): Branch
    {
        return Branch::query()->create(['name' => $name, 'code' => $code, 'is_active' => true]);
    }

    private function userForBranch(Branch $branch, bool $super = false): User
    {
        $role = Role::query()->create([
            'name' => $super ? 'Super User' : 'Branch User',
            'key' => $super ? 'super-user' : 'branch-user',
            'is_super' => $super,
        ]);

        return User::query()->create([
            'name' => $super ? 'Super User' : 'Branch User',
            'email' => $super ? 'super@example.test' : 'branch@example.test',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'status' => true,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function requestAs(User $user, string $uri, string $method = 'GET', array $data = []): Request
    {
        $request = Request::create($uri, $method, $data);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function assertForbidden(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a forbidden response.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
