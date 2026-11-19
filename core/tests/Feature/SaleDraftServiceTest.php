<?php

namespace Tests\Feature;

use App\Enums\Sales\SaleChannel;
use App\Enums\Sales\SaleStatus;
use App\Exceptions\Sales\InvalidSaleCheckoutException;
use App\Exceptions\Sales\InvalidSaleException;
use App\Http\Controllers\backend\CustomerController;
use App\Http\Controllers\backend\CustomerReceivableController;
use App\Http\Controllers\backend\PosSaleController;
use App\Http\Controllers\backend\WholesaleSaleController;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\Customer;
use App\Models\backend\FinishedProduct;
use App\Models\backend\FinishedProductCategory;
use App\Models\backend\FiscalYear;
use App\Models\backend\Unit;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use App\Models\Inventory\FinishedProductStockBalance;
use App\Services\Sales\CustomerReceiptService;
use App\Services\Sales\SaleCheckoutService;
use App\Services\Sales\SaleDraftService;
use Database\Seeders\FinanceFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class SaleDraftServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_draft_uses_finished_product_price_and_server_calculated_totals(): void
    {
        $setup = $this->setupSalesFoundation();

        $sale = app(SaleDraftService::class)->createDraft(
            [
                'channel' => 'POS',
                'warehouse_id' => $setup['warehouse']->id,
                'sale_at' => '2026-11-15 10:00:00',
                'discount_type' => 'PERCENT',
                'discount_value' => '10',
                'tax' => '5',
            ],
            [[
                'finished_product_id' => $setup['product']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => '2',
            ]],
            null,
        );

        $this->assertSame(SaleChannel::Pos, $sale->channel);
        $this->assertSame(SaleStatus::Draft, $sale->status);
        $this->assertSame('200.0000', $sale->subtotal);
        $this->assertSame('20.0000', $sale->discount);
        $this->assertSame('185.0000', $sale->total);
        $this->assertSame('185.0000', $sale->due_amount);
        $this->assertSame('100.0000', $sale->items->first()->unit_price);
    }

    public function test_wholesale_draft_requires_an_active_customer(): void
    {
        $setup = $this->setupSalesFoundation();

        $this->expectException(InvalidSaleException::class);
        $this->expectExceptionMessage('Wholesale Sale requires a customer.');

        app(SaleDraftService::class)->createDraft(
            [
                'channel' => 'WHOLESALE',
                'warehouse_id' => $setup['warehouse']->id,
            ],
            [[
                'finished_product_id' => $setup['product']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => '1',
            ]],
            null,
        );
    }

    public function test_wholesale_draft_uses_wholesale_price_when_customer_is_selected(): void
    {
        $setup = $this->setupSalesFoundation();
        $customer = Customer::query()->create([
            'code' => 'CUS-001',
            'name' => 'Wholesale Customer',
            'is_active' => true,
        ]);

        $sale = app(SaleDraftService::class)->createDraft(
            [
                'channel' => 'WHOLESALE',
                'warehouse_id' => $setup['warehouse']->id,
                'customer_id' => $customer->id,
                'sale_at' => '2026-11-15 10:00:00',
                'due_date' => '2026-12-15',
                'customer_reference' => 'PO-1001',
            ],
            [[
                'finished_product_id' => $setup['product']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => '3',
            ]],
            null,
        );

        $this->assertSame(SaleChannel::Wholesale, $sale->channel);
        $this->assertSame($customer->id, $sale->customer_id);
        $this->assertSame('PO-1001', $sale->customer_reference);
        $this->assertSame('2026-12-15', $sale->due_date->format('Y-m-d'));
        $this->assertSame('270.0000', $sale->total);
        $this->assertSame('90.0000', $sale->items->first()->unit_price);
    }

    public function test_pos_draft_screen_and_catalog_use_finished_product_stock(): void
    {
        $setup = $this->setupSalesFoundation();
        FinishedProductStockBalance::query()->create([
            'finished_product_id' => $setup['product']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => '7',
            'version' => 1,
        ]);

        $controller = app(PosSaleController::class);
        $catalogResponse = $controller->catalog(Request::create('/catalog', 'GET', [
            'warehouse_id' => $setup['warehouse']->id,
        ]));
        $catalog = $catalogResponse->getData(true);
        view()->share('errors', new ViewErrorBag);
        $html = $controller->create()->render();

        $this->assertCount(1, $catalog['results']);
        $this->assertSame('FG-001', $catalog['results'][0]['code']);
        $this->assertSame('7', $catalog['results'][0]['stock_quantity']);
        $this->assertTrue($catalog['results'][0]['in_stock']);
        $this->assertStringContainsString('pos-sale-workspace', $html);
        $this->assertStringContainsString('Save Draft', $html);
        $this->assertStringContainsString('Payment / Review', $html);
        $this->assertStringContainsString('Continue to Review', $html);
        $this->assertStringContainsString('Walk-in Customer', $html);
        $this->assertStringContainsString('posBarcodeInput', $html);
    }

    public function test_pos_catalog_can_find_a_product_by_exact_barcode_for_scanning(): void
    {
        $setup = $this->setupSalesFoundation();
        $barcode = '0123456789012';
        $setup['product']->forceFill(['barcode' => $barcode])->save();
        FinishedProductStockBalance::query()->create([
            'finished_product_id' => $setup['product']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => '7',
            'version' => 1,
        ]);
        FinishedProduct::query()->create([
            'product_code' => 'FG-002',
            'barcode' => '990123456789012',
            'name' => 'Similar Barcode Product',
            'finished_product_category_id' => $setup['category']->id,
            'base_unit_id' => $setup['unit']->id,
            'retail_price' => '100',
            'wholesale_price' => '90',
            'is_active' => true,
        ]);

        $response = app(PosSaleController::class)->catalog(Request::create('/catalog', 'GET', [
            'warehouse_id' => $setup['warehouse']->id,
            'barcode' => $barcode,
        ]));
        $catalog = $response->getData(true);

        $this->assertCount(1, $catalog['results']);
        $this->assertSame($setup['product']->id, $catalog['results'][0]['id']);
        $this->assertTrue($catalog['results'][0]['in_stock']);
    }

    public function test_pos_routes_are_isolated_under_their_own_permission_prefix(): void
    {
        foreach (['index', 'store', 'catalog', 'show', 'update', 'destroy', 'reopen', 'post', 'receipt'] as $action) {
            $route = Route::getRoutes()->getByName("sales.pos.{$action}");

            $this->assertNotNull($route);
            $this->assertContains('perm', $route->gatherMiddleware());
        }

        foreach (['index', 'list.ajax', 'collect-modal', 'collect', 'receipt'] as $action) {
            $route = Route::getRoutes()->getByName("sales.customer-receivables.{$action}");

            $this->assertNotNull($route);
            $this->assertContains('perm', $route->gatherMiddleware());
        }

        foreach (
            ['index', 'store', 'products', 'show', 'update', 'destroy', 'reopen', 'post', 'invoice'] as $action
        ) {
            $route = Route::getRoutes()->getByName("sales.wholesale.{$action}");

            $this->assertNotNull($route);
            $this->assertContains('perm', $route->gatherMiddleware());
        }
    }

    public function test_wholesale_screen_uses_compact_product_search_and_wholesale_price(): void
    {
        $setup = $this->setupSalesFoundation();
        FinishedProductStockBalance::query()->create([
            'finished_product_id' => $setup['product']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => '12',
            'version' => 1,
        ]);

        $controller = app(WholesaleSaleController::class);
        $response = $controller->products(Request::create('/products', 'GET', [
            'warehouse_id' => $setup['warehouse']->id,
        ]));
        $payload = $response->getData(true);
        view()->share('errors', new ViewErrorBag);
        $html = $controller->create()->render();

        $this->assertSame('90.00', $payload['results'][0]['wholesale_price']);
        $this->assertSame('12', $payload['results'][0]['stock_quantity']);
        $this->assertStringContainsString('wholesale-line-header', $html);
        $this->assertStringContainsString('Customer PO / Reference', $html);
        $this->assertStringContainsString('Payment & Review', $html);
        $this->assertStringContainsString('pos-checkout-modal', $html);
        $this->assertStringContainsString('Change Due', $html);
        $this->assertStringNotContainsString('pos-catalog-grid', $html);
    }

    public function test_customer_receivable_uses_the_wholesale_details_route_for_wholesale_sales(): void
    {
        $setup = $this->setupSalesFoundation();
        $customer = Customer::query()->create([
            'code' => 'CUS-WHOLESALE-DUE',
            'name' => 'Wholesale Due Customer',
            'is_active' => true,
        ]);
        $sale = app(SaleDraftService::class)->createDraft(
            [
                'channel' => 'WHOLESALE',
                'warehouse_id' => $setup['warehouse']->id,
                'customer_id' => $customer->id,
                'sale_at' => '2026-11-18 10:00:00',
                'due_date' => '2026-12-18',
            ],
            [[
                'finished_product_id' => $setup['product']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => '1',
            ]],
            null,
        );
        $sale->forceFill([
            'status' => SaleStatus::Posted,
            'paid_amount' => '0',
            'due_amount' => $sale->total,
        ])->save();

        $response = app(CustomerReceivableController::class)->listAjax(
            Request::create('/sales/customer-receivables/list', 'POST', [
                'draw' => 1,
                'start' => 0,
                'length' => 10,
            ]),
        );
        $row = $response->getData(true)['aaData'][0];

        $this->assertStringContainsString("/sales/wholesale/{$sale->id}", $row[1]);
        $this->assertStringNotContainsString("/sales/pos/{$sale->id}", $row[1]);
    }

    public function test_quick_customer_modal_creates_an_active_pos_customer(): void
    {
        $controller = app(CustomerController::class);
        $html = $controller->createModal()->render();
        $response = $controller->store(Request::create('/customer/customers', 'POST', [
            'name' => 'POS Customer',
            'phone' => '01700000001',
            'email' => '',
            'address' => '',
            'is_active' => '1',
        ]));
        $payload = $response->getData(true);

        $this->assertStringContainsString('data-ajax="true"', $html);
        $this->assertStringContainsString('Add Customer', $html);
        $this->assertTrue($payload['ok']);
        $this->assertSame('POS Customer', $payload['name']);
        $this->assertDatabaseHas('customers', [
            'name' => 'POS Customer',
            'phone' => '01700000001',
            'is_active' => true,
        ]);
    }

    public function test_pos_review_then_confirmation_posts_split_payment_stock_and_finance_atomically(): void
    {
        $setup = $this->setupSalesFoundation();
        FinishedProductStockBalance::query()->create([
            'finished_product_id' => $setup['product']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => '5',
            'version' => 1,
        ]);
        FiscalYear::query()->create([
            'name' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);
        $this->seed(FinanceFoundationSeeder::class);
        $cashTypeId = DB::table('payment_types')->insertGetId([
            'name' => 'Cash',
            'slug' => 'cash',
            'allows_change' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $mobileTypeId = DB::table('payment_types')->insertGetId([
            'name' => 'Mobile Banking',
            'slug' => 'mobile-banking',
            'allows_change' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::query()->create([
            'name' => 'POS Operator',
            'email' => 'pos@example.test',
            'password' => Hash::make('password'),
            'branch_id' => $setup['branch']->id,
        ]);
        $accounts = Account::query()->pluck('id', 'code');
        $sale = app(SaleDraftService::class)->createDraft(
            [
                'channel' => 'POS',
                'warehouse_id' => $setup['warehouse']->id,
                'sale_at' => '2026-11-15 10:00:00',
            ],
            [[
                'finished_product_id' => $setup['product']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => '1',
            ]],
            $user->id,
        );

        $workflow = app(SaleCheckoutService::class);
        $prepared = $workflow->prepare($sale, [
            [
                'payment_type_id' => $cashTypeId,
                'account_id' => $accounts['1000'],
                'amount' => '60',
            ],
            [
                'payment_type_id' => $mobileTypeId,
                'account_id' => $accounts['1010'],
                'amount' => '40',
                'reference_no' => 'BKASH-001',
            ],
        ], $user->id);

        $this->assertSame(SaleStatus::ReadyToPost, $prepared->status);
        $this->assertDatabaseHas('finished_product_stock_balances', [
            'finished_product_id' => $setup['product']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => 5,
        ]);
        $this->assertDatabaseHas('sale_payments', [
            'sale_id' => $sale->id,
            'status' => 'DRAFT',
        ]);
        $this->assertDatabaseCount('journal_entries', 0);

        view()->share('errors', new ViewErrorBag);
        $reviewHtml = app(PosSaleController::class)->show($prepared->id)->render();
        $this->assertStringContainsString('Review before posting', $reviewHtml);
        $this->assertStringContainsString('Ready to Post', $reviewHtml);
        $this->assertStringContainsString('BKASH-001', $reviewHtml);

        $posted = $workflow->post($prepared, $user->id);

        $this->assertSame(SaleStatus::Posted, $posted->status);
        $this->assertSame('100.0000', $posted->paid_amount);
        $this->assertSame('0.0000', $posted->due_amount);
        $this->assertDatabaseHas('finished_product_stock_balances', [
            'finished_product_id' => $setup['product']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => 4,
        ]);
        $this->assertDatabaseCount('sale_payments', 2);
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'sale',
            'source_id' => $sale->id,
            'status' => 'POSTED',
        ]);
        $journalId = DB::table('journal_entries')->where('source_type', 'sale')->value('id');
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $journalId,
            'account_id' => $accounts['1000'],
            'debit' => '60.0000',
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $journalId,
            'account_id' => $accounts['1010'],
            'debit' => '40.0000',
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $journalId,
            'account_id' => $accounts['4000'],
            'credit' => '100.0000',
        ]);

        $detailsHtml = app(PosSaleController::class)->show($posted->id)->render();
        $this->assertStringContainsString('Payment Breakdown', $detailsHtml);
        $this->assertStringContainsString('BKASH-001', $detailsHtml);
        $this->assertStringContainsString('POSTED', $detailsHtml);

        $receiptHtml = app(PosSaleController::class)->receipt($posted->id)->render();
        $this->assertStringContainsString('Sales Receipt', $receiptHtml);
        $this->assertStringContainsString($posted->sale_no, $receiptHtml);
        $this->assertStringContainsString('80mm', $receiptHtml);
    }

    public function test_walk_in_checkout_rejects_due_without_changing_stock_or_finance(): void
    {
        $setup = $this->setupSalesFoundation();
        FinishedProductStockBalance::query()->create([
            'finished_product_id' => $setup['product']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => '5',
            'version' => 1,
        ]);
        $user = User::query()->create([
            'name' => 'POS Operator',
            'email' => 'walk-in@example.test',
            'password' => Hash::make('password'),
            'branch_id' => $setup['branch']->id,
        ]);
        $sale = app(SaleDraftService::class)->createDraft(
            [
                'channel' => 'POS',
                'warehouse_id' => $setup['warehouse']->id,
                'sale_at' => '2026-11-15 10:00:00',
            ],
            [[
                'finished_product_id' => $setup['product']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => '1',
            ]],
            $user->id,
        );

        try {
            app(SaleCheckoutService::class)->prepare($sale, [], $user->id);
            $this->fail('Walk-in review should require full payment.');
        } catch (InvalidSaleCheckoutException $exception) {
            $this->assertStringContainsString('Walk-in Customer cannot keep a due balance', $exception->getMessage());
        }

        $this->assertSame(SaleStatus::Draft, $sale->fresh()->status);
        $this->assertDatabaseHas('finished_product_stock_balances', [
            'finished_product_id' => $setup['product']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => 5,
        ]);
        $this->assertDatabaseCount('finished_product_stock_movements', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_ready_sale_can_return_to_draft_without_stock_or_finance_effect(): void
    {
        $setup = $this->setupSalesFoundation();
        $customer = Customer::query()->create([
            'code' => 'CUS-REVIEW',
            'name' => 'Review Customer',
            'is_active' => true,
        ]);
        $user = User::query()->create([
            'name' => 'POS Operator',
            'email' => 'reopen@example.test',
            'password' => Hash::make('password'),
            'branch_id' => $setup['branch']->id,
        ]);
        $sale = app(SaleDraftService::class)->createDraft(
            [
                'channel' => 'POS',
                'warehouse_id' => $setup['warehouse']->id,
                'customer_id' => $customer->id,
            ],
            [[
                'finished_product_id' => $setup['product']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => '1',
            ]],
            $user->id,
        );

        $workflow = app(SaleCheckoutService::class);
        $prepared = $workflow->prepare($sale, [], $user->id);
        $reopened = $workflow->reopen($prepared);

        $this->assertSame(SaleStatus::Draft, $reopened->status);
        $this->assertSame('0.0000', $reopened->paid_amount);
        $this->assertSame('100.0000', $reopened->due_amount);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('finished_product_stock_movements', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_cash_tender_above_total_records_change_but_posts_only_applied_amount(): void
    {
        $setup = $this->setupSalesFoundation();
        FinishedProductStockBalance::query()->create([
            'finished_product_id' => $setup['product']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => '5',
            'version' => 1,
        ]);
        FiscalYear::query()->create([
            'name' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);
        $this->seed(FinanceFoundationSeeder::class);
        $cashTypeId = DB::table('payment_types')->insertGetId([
            'name' => 'Cash',
            'slug' => 'cash',
            'allows_change' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $mobileTypeId = DB::table('payment_types')->insertGetId([
            'name' => 'Mobile Banking',
            'slug' => 'mobile-banking',
            'allows_change' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::query()->create([
            'name' => 'POS Operator',
            'email' => 'cash-change@example.test',
            'password' => Hash::make('password'),
            'branch_id' => $setup['branch']->id,
        ]);
        $accounts = Account::query()->pluck('id', 'code');
        $sale = app(SaleDraftService::class)->createDraft(
            [
                'channel' => 'POS',
                'warehouse_id' => $setup['warehouse']->id,
                'sale_at' => '2026-11-17 10:00:00',
            ],
            [[
                'finished_product_id' => $setup['product']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => '1',
            ]],
            $user->id,
        );

        $workflow = app(SaleCheckoutService::class);
        $prepared = $workflow->prepare($sale, [[
            'payment_type_id' => $cashTypeId,
            'account_id' => $accounts['1000'],
            'amount' => '150',
        ]], $user->id);

        $this->assertSame(SaleStatus::ReadyToPost, $prepared->status);
        $this->assertSame('100.0000', $prepared->paid_amount);
        $this->assertSame('0.0000', $prepared->due_amount);
        $this->assertDatabaseHas('sale_payments', [
            'sale_id' => $sale->id,
            'amount' => '100.0000',
            'tendered_amount' => '150.0000',
            'change_amount' => '50.0000',
            'status' => 'DRAFT',
        ]);

        $posted = $workflow->post($prepared, $user->id);
        $journalId = DB::table('journal_entries')->where('source_type', 'sale')->value('id');
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $journalId,
            'account_id' => $accounts['1000'],
            'debit' => '100.0000',
        ]);

        view()->share('errors', new ViewErrorBag);
        $detailsHtml = app(PosSaleController::class)->show($posted->id)->render();
        $this->assertStringContainsString('150.00', $detailsHtml);
        $this->assertStringContainsString('50.00', $detailsHtml);

        $secondSale = app(SaleDraftService::class)->createDraft(
            [
                'channel' => 'POS',
                'warehouse_id' => $setup['warehouse']->id,
                'sale_at' => '2026-11-17 10:15:00',
            ],
            [[
                'finished_product_id' => $setup['product']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => '1',
            ]],
            $user->id,
        );

        try {
            $workflow->prepare($secondSale, [[
                'payment_type_id' => $mobileTypeId,
                'account_id' => $accounts['1010'],
                'amount' => '150',
            ]], $user->id);
            $this->fail('A non-cash payment must not create change.');
        } catch (InvalidSaleCheckoutException $exception) {
            $this->assertStringContainsString('cannot exceed the remaining Sale amount', $exception->getMessage());
        }

        $this->assertSame(SaleStatus::Draft, $secondSale->fresh()->status);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_registered_customer_checkout_posts_partial_payment_and_receivable(): void
    {
        $setup = $this->setupSalesFoundation();
        FinishedProductStockBalance::query()->create([
            'finished_product_id' => $setup['product']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => '5',
            'version' => 1,
        ]);
        FiscalYear::query()->create([
            'name' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);
        $this->seed(FinanceFoundationSeeder::class);
        $cashTypeId = DB::table('payment_types')->insertGetId([
            'name' => 'Cash',
            'slug' => 'cash',
            'allows_change' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customer = Customer::query()->create([
            'code' => 'CUS-DUE',
            'name' => 'Due Customer',
            'phone' => '01700000002',
            'is_active' => true,
        ]);
        $user = User::query()->create([
            'name' => 'POS Operator',
            'email' => 'partial@example.test',
            'password' => Hash::make('password'),
            'branch_id' => $setup['branch']->id,
        ]);
        $accounts = Account::query()->pluck('id', 'code');
        $sale = app(SaleDraftService::class)->createDraft(
            [
                'channel' => 'POS',
                'warehouse_id' => $setup['warehouse']->id,
                'customer_id' => $customer->id,
                'sale_at' => '2026-11-15 10:00:00',
            ],
            [[
                'finished_product_id' => $setup['product']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => '1',
            ]],
            $user->id,
        );

        $workflow = app(SaleCheckoutService::class);
        $prepared = $workflow->prepare($sale, [[
            'payment_type_id' => $cashTypeId,
            'account_id' => $accounts['1000'],
            'amount' => '60',
        ]], $user->id);

        $this->assertSame(SaleStatus::ReadyToPost, $prepared->status);
        $this->assertDatabaseCount('journal_entries', 0);

        $posted = $workflow->post($prepared, $user->id);
        $this->assertSame('60.0000', $posted->paid_amount);
        $this->assertSame('40.0000', $posted->due_amount);
        $journalId = DB::table('journal_entries')->where('source_type', 'sale')->value('id');
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $journalId,
            'account_id' => $accounts['1210'],
            'debit' => '40.0000',
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $journalId,
            'status' => 'POSTED',
        ]);

        $receipt = app(CustomerReceiptService::class)->collect(
            $posted,
            '2026-11-16 11:00:00',
            [[
                'payment_type_id' => $cashTypeId,
                'account_id' => $accounts['1000'],
                'amount' => '40',
            ]],
            'Remaining POS due',
            $user->id,
        );

        $paidSale = $posted->fresh();
        $this->assertSame('100.0000', $paidSale->paid_amount);
        $this->assertSame('0.0000', $paidSale->due_amount);
        $this->assertDatabaseHas('customer_receipts', [
            'id' => $receipt->id,
            'sale_id' => $posted->id,
            'amount' => '40.0000',
            'due_before' => '40.0000',
            'due_after' => '0.0000',
            'status' => 'POSTED',
        ]);
        $this->assertDatabaseHas('sale_payments', [
            'sale_id' => $posted->id,
            'customer_receipt_id' => $receipt->id,
            'amount' => '40.0000',
            'status' => 'POSTED',
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'customer_receipt',
            'source_id' => $receipt->id,
            'status' => 'POSTED',
        ]);
        $receiptJournalId = DB::table('journal_entries')
            ->where('source_type', 'customer_receipt')
            ->where('source_id', $receipt->id)
            ->value('id');
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $receiptJournalId,
            'account_id' => $accounts['1000'],
            'debit' => '40.0000',
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $receiptJournalId,
            'account_id' => $accounts['1210'],
            'credit' => '40.0000',
        ]);

        view()->share('errors', new ViewErrorBag);
        $detailsHtml = app(PosSaleController::class)->show($posted->id)->render();
        $this->assertStringContainsString('Paid', $detailsHtml);
        $this->assertStringContainsString('Due Collection', $detailsHtml);

        $receiptHtml = app(CustomerReceivableController::class)
            ->receipt($receipt->id)
            ->render();
        $this->assertStringContainsString('Customer Payment Receipt', $receiptHtml);
        $this->assertStringContainsString($receipt->receipt_no, $receiptHtml);
        $this->assertStringContainsString('Due Before', $receiptHtml);
    }

    /** @return array<string, mixed> */
    private function setupSalesFoundation(): array
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
            'product_code' => 'FG-001',
            'name' => 'Control Unit',
            'finished_product_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'retail_price' => '100',
            'wholesale_price' => '90',
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

        return compact('unit', 'category', 'product', 'branch', 'warehouse');
    }
}
