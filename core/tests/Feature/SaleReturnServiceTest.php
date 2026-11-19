<?php

namespace Tests\Feature;

use App\Enums\Sales\SaleChannel;
use App\Enums\Sales\SaleReturnStatus;
use App\Enums\Sales\SaleStatus;
use App\Exceptions\Sales\InvalidSaleReturnException;
use App\Http\Controllers\backend\SaleReturnController;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\FinishedProduct;
use App\Models\backend\FinishedProductCategory;
use App\Models\backend\FiscalYear;
use App\Models\backend\Unit;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use App\Models\Inventory\FinishedProductStockBalance;
use App\Models\Sales\Sale;
use App\Models\Sales\SaleItem;
use App\Models\Sales\SaleReturn;
use App\Services\Sales\SaleReturnService;
use Database\Seeders\FinanceFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class SaleReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_uses_original_sale_unit_rate_without_changing_stock(): void
    {
        $setup = $this->setupPostedSale('5', '125');
        FinishedProductStockBalance::query()->create([
            'finished_product_id' => $setup['product']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => '5',
            'version' => 1,
        ]);

        $saleReturn = app(SaleReturnService::class)->createDraft(
            [
                'sale_id' => $setup['sale']->id,
                'return_at' => '2026-11-02T10:00',
                'reason' => 'Customer returned an unopened item.',
            ],
            [[
                'sale_item_id' => $setup['saleItem']->id,
                'quantity' => '2',
                'unit_price' => '1',
            ]],
            null,
        );

        $this->assertSame(SaleReturnStatus::Draft, $saleReturn->status);
        $this->assertSame('125.0000', $saleReturn->items->first()->unit_price);
        $this->assertSame('250.0000', $saleReturn->total);
        $this->assertDatabaseHas('finished_product_stock_balances', [
            'finished_product_id' => $setup['product']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => '5.000000',
        ]);
        $this->assertDatabaseCount('finished_product_stock_movements', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_return_quantity_cannot_exceed_quantity_left_after_posted_returns(): void
    {
        $setup = $this->setupPostedSale('5', '100');
        $postedReturn = SaleReturn::query()->create([
            'return_no' => 'SRT-POSTED-001',
            'sale_id' => $setup['sale']->id,
            'branch_id' => $setup['branch']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'return_at' => '2026-11-02 09:00:00',
            'status' => SaleReturnStatus::Posted,
            'total' => '300',
            'reason' => 'Earlier return',
        ]);
        $postedReturn->items()->create([
            'sale_item_id' => $setup['saleItem']->id,
            'finished_product_id' => $setup['product']->id,
            'unit_id' => $setup['unit']->id,
            'quantity' => '3',
            'base_quantity' => '3',
            'unit_price' => '100',
            'line_total' => '300',
        ]);

        $this->expectException(InvalidSaleReturnException::class);
        $this->expectExceptionMessage('exceeds the currently returnable Sale quantity');

        app(SaleReturnService::class)->createDraft(
            [
                'sale_id' => $setup['sale']->id,
                'return_at' => '2026-11-03T10:00',
                'reason' => 'Return too many',
            ],
            [[
                'sale_item_id' => $setup['saleItem']->id,
                'quantity' => '3',
            ]],
            null,
        );
    }

    public function test_draft_reverses_original_discount_and_tax_proportionally(): void
    {
        $setup = $this->setupPostedSale('2', '100');
        $setup['sale']->update([
            'discount_type' => 'FLAT',
            'discount_value' => '20',
            'discount' => '20',
            'tax' => '18',
            'total' => '198',
            'paid_amount' => '198',
        ]);

        $saleReturn = app(SaleReturnService::class)->createDraft(
            [
                'sale_id' => $setup['sale']->id,
                'return_at' => '2026-11-02T10:00',
                'reason' => 'Proportional return test',
            ],
            [[
                'sale_item_id' => $setup['saleItem']->id,
                'quantity' => '1',
            ]],
            null,
        );

        $this->assertSame('100.0000', $saleReturn->subtotal);
        $this->assertSame('10.0000', $saleReturn->discount);
        $this->assertSame('9.0000', $saleReturn->tax);
        $this->assertSame('99.0000', $saleReturn->total);
    }

    public function test_non_posted_sale_cannot_be_used_as_return_source(): void
    {
        $setup = $this->setupPostedSale('2', '100');
        $setup['sale']->update(['status' => SaleStatus::Draft]);

        $this->expectException(InvalidSaleReturnException::class);
        $this->expectExceptionMessage('Only a Posted POS or Wholesale Sale can be returned.');

        app(SaleReturnService::class)->createDraft(
            [
                'sale_id' => $setup['sale']->id,
                'return_at' => '2026-11-02T10:00',
                'reason' => 'Invalid source',
            ],
            [[
                'sale_item_id' => $setup['saleItem']->id,
                'quantity' => '1',
            ]],
            null,
        );
    }

    public function test_posted_return_restocks_product_refunds_cash_and_reverses_finance(): void
    {
        $setup = $this->setupPostedSale('5', '100');
        $finance = $this->setupFinance($setup['branch']);
        $saleReturn = app(SaleReturnService::class)->createDraft(
            [
                'sale_id' => $setup['sale']->id,
                'return_at' => '2026-11-02T10:00',
                'reason' => 'Customer refund test',
            ],
            [[
                'sale_item_id' => $setup['saleItem']->id,
                'quantity' => '2',
            ]],
            $finance['user']->id,
        );

        $posted = app(SaleReturnService::class)->post(
            $saleReturn,
            [[
                'payment_type_id' => $finance['payment_type_id'],
                'account_id' => $finance['cash_account_id'],
                'amount' => '200',
                'reference_no' => 'REFUND-001',
            ]],
            $finance['user']->id,
        );

        $this->assertSame(SaleReturnStatus::Posted, $posted->status);
        $this->assertSame('200.0000', $posted->refund_amount);
        $this->assertSame('0.0000', $posted->due_adjustment);
        $this->assertDatabaseHas('finished_product_stock_balances', [
            'finished_product_id' => $setup['product']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => '2.000000',
        ]);
        $this->assertDatabaseHas('finished_product_stock_movements', [
            'source_type' => 'sale_return',
            'source_id' => $posted->id,
            'direction' => 'IN',
            'quantity' => '2.000000',
        ]);
        $this->assertDatabaseHas('sale_return_payments', [
            'sale_return_id' => $posted->id,
            'account_id' => $finance['cash_account_id'],
            'amount' => '200.0000',
        ]);
        $this->assertSame('300.0000', $setup['sale']->fresh()->paid_amount);
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'sale_return',
            'source_id' => $posted->id,
            'status' => 'POSTED',
        ]);
    }

    public function test_posted_return_adjusts_customer_due_before_requiring_refund(): void
    {
        $setup = $this->setupPostedSale('5', '100');
        $setup['sale']->update(['paid_amount' => '300', 'due_amount' => '200']);
        $finance = $this->setupFinance($setup['branch']);
        $saleReturn = app(SaleReturnService::class)->createDraft(
            [
                'sale_id' => $setup['sale']->id,
                'return_at' => '2026-11-02T10:00',
                'reason' => 'Due adjustment test',
            ],
            [[
                'sale_item_id' => $setup['saleItem']->id,
                'quantity' => '1',
            ]],
            $finance['user']->id,
        );

        $posted = app(SaleReturnService::class)->post(
            $saleReturn,
            [],
            $finance['user']->id,
        );

        $this->assertSame('100.0000', $posted->due_adjustment);
        $this->assertSame('0.0000', $posted->refund_amount);
        $this->assertSame('100.0000', $setup['sale']->fresh()->due_amount);
        $this->assertDatabaseCount('sale_return_payments', 0);
        $receivableAccountId = Account::query()->where('code', '1210')->value('id');
        $journalId = DB::table('journal_entries')
            ->where('source_type', 'sale_return')
            ->where('source_id', $posted->id)
            ->value('id');
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $journalId,
            'account_id' => $receivableAccountId,
            'credit' => '100.0000',
        ]);
    }

    public function test_sales_return_routes_and_draft_screen_are_available(): void
    {
        foreach (
            ['index', 'list.ajax', 'create', 'store', 'show', 'edit', 'update', 'post-modal', 'post', 'destroy'] as $action
        ) {
            $route = Route::getRoutes()->getByName("sales.returns.{$action}");

            $this->assertNotNull($route);
            $this->assertContains('perm', $route->gatherMiddleware());
        }

        view()->share('errors', new ViewErrorBag);
        $html = app(SaleReturnController::class)->create(request())->render();

        $this->assertStringContainsString('Create Sales Return', $html);
        $this->assertStringContainsString('Select a Posted Sale', $html);
        $this->assertStringContainsString('View Draft rules', $html);
    }

    public function test_selected_posted_sale_renders_returnable_invoice_lines(): void
    {
        $setup = $this->setupPostedSale('4', '125');
        view()->share('errors', new ViewErrorBag);

        $request = Request::create('/sales/returns/create', 'GET', [
            'sale_id' => $setup['sale']->id,
        ]);
        $html = app(SaleReturnController::class)->create($request)->render();

        $this->assertStringContainsString('Return Items', $html);
        $this->assertStringContainsString('Return Test Product', $html);
        $this->assertStringContainsString('Original Rate', $html);
        $this->assertStringContainsString('4 PCS', $html);
        $this->assertStringContainsString('max="4.000000"', $html);
        $this->assertStringContainsString('Maximum returnable quantity is 4 PCS.', $html);
    }

    /** @return array<string, mixed> */
    private function setupPostedSale(string $quantity, string $unitPrice): array
    {
        $unit = Unit::query()->create([
            'name' => 'Piece',
            'code' => 'PCS',
            'precision' => 0,
            'is_active' => true,
        ]);
        $category = FinishedProductCategory::query()->create([
            'code' => 'FINISHED',
            'name' => 'Finished Products',
            'is_active' => true,
        ]);
        $product = FinishedProduct::query()->create([
            'product_code' => 'FG-RETURN-001',
            'name' => 'Return Test Product',
            'finished_product_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'retail_price' => $unitPrice,
            'wholesale_price' => $unitPrice,
            'is_active' => true,
        ]);
        $branch = Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Main Showroom',
            'code' => 'SHOWROOM',
            'type' => 'showroom',
            'is_default' => true,
            'is_active' => true,
        ]);
        $lineTotal = bcmul($quantity, $unitPrice, 4);
        $sale = Sale::query()->create([
            'sale_no' => 'SAL-RETURN-001',
            'branch_id' => $branch->id,
            'warehouse_id' => $warehouse->id,
            'channel' => SaleChannel::Pos,
            'sale_at' => '2026-11-01 10:00:00',
            'status' => SaleStatus::Posted,
            'subtotal' => $lineTotal,
            'discount_type' => 'FLAT',
            'discount_value' => '0',
            'discount' => '0',
            'tax' => '0',
            'total' => $lineTotal,
            'paid_amount' => $lineTotal,
            'due_amount' => '0',
        ]);
        $saleItem = SaleItem::query()->create([
            'sale_id' => $sale->id,
            'finished_product_id' => $product->id,
            'unit_id' => $unit->id,
            'conversion_to_base' => '1',
            'quantity' => $quantity,
            'base_quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount' => '0',
            'tax' => '0',
            'line_total' => $lineTotal,
        ]);

        return compact('unit', 'category', 'product', 'branch', 'warehouse', 'sale', 'saleItem');
    }

    /** @return array{user: User, payment_type_id: int, cash_account_id: int} */
    private function setupFinance(Branch $branch): array
    {
        FiscalYear::query()->create([
            'name' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);
        $this->seed(FinanceFoundationSeeder::class);
        $paymentTypeId = DB::table('payment_types')->insertGetId([
            'name' => 'Cash',
            'slug' => 'cash',
            'allows_change' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::query()->create([
            'name' => 'Return Operator',
            'email' => 'return-operator@example.test',
            'password' => Hash::make('password'),
            'branch_id' => $branch->id,
        ]);

        return [
            'user' => $user,
            'payment_type_id' => $paymentTypeId,
            'cash_account_id' => (int) Account::query()->where('code', '1000')->value('id'),
        ];
    }
}
