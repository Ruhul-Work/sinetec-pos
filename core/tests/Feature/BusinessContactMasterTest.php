<?php

namespace Tests\Feature;

use App\Enums\Purchase\PurchaseOrderStatus;
use App\Enums\Sales\SaleChannel;
use App\Enums\Sales\SaleStatus;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureBranchScope;
use App\Models\backend\Branch;
use App\Models\backend\Customer;
use App\Models\backend\Supplier;
use App\Models\backend\Warehouse;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Sales\Sale;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessContactMasterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            Authenticate::class,
            CheckPermission::class,
            EnsureBranchScope::class,
        ]);
    }

    public function test_supplier_master_uses_one_modal_for_create_and_edit(): void
    {
        $this->get(route('supplier.index'))
            ->assertOk()
            ->assertSee('Supplier List');

        $this->get(route('supplier.createModal'))
            ->assertOk()
            ->assertSee('Add Supplier')
            ->assertSee('data-ajax="true"', false);

        $response = $this->postJson(route('supplier.store'), [
            'code' => 'sup-001',
            'name' => 'Delta Components',
            'phone' => '01710000001',
            'email' => 'supplier@example.com',
            'address' => 'Dhaka',
            'postal_code' => '1200',
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('supplier.name', 'Delta Components');

        $supplier = Supplier::query()->findOrFail($response->json('id'));

        $this->get(route('supplier.edit', $supplier))
            ->assertOk()
            ->assertSee('Edit Supplier')
            ->assertSee('Delta Components');

        $this->putJson(route('supplier.update', $supplier), [
            'code' => 'sup-001',
            'name' => 'Delta Components Ltd',
            'phone' => '01710000001',
            'email' => 'supplier@example.com',
            'address' => 'Tejgaon, Dhaka',
            'postal_code' => '1208',
            'is_active' => false,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'code' => 'SUP-001',
            'name' => 'Delta Components Ltd',
            'is_active' => false,
        ]);
    }

    public function test_customer_master_uses_one_modal_for_create_and_edit(): void
    {
        $this->get(route('customer.index'))
            ->assertOk()
            ->assertSee('Customer List');

        $this->get(route('customer.createModal'))
            ->assertOk()
            ->assertSee('Add Customer')
            ->assertSee('data-ajax="true"', false);

        $response = $this->postJson(route('customer.store'), [
            'code' => 'cus-001',
            'name' => 'Rahim Enterprise',
            'phone' => '01810000001',
            'email' => 'customer@example.com',
            'address' => 'Paltan, Dhaka',
            'postal_code' => '1000',
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('name', 'Rahim Enterprise');

        $customer = Customer::query()->findOrFail($response->json('id'));

        $this->get(route('customer.edit', $customer))
            ->assertOk()
            ->assertSee('Edit Customer')
            ->assertSee('Rahim Enterprise');

        $this->putJson(route('customer.update', $customer), [
            'code' => 'cus-001',
            'name' => 'Rahim Enterprise Ltd',
            'phone' => '01810000001',
            'email' => 'customer@example.com',
            'alternate_phone' => '01910000001',
            'birth_date' => '1990-01-01',
            'address' => 'Motijheel, Dhaka',
            'postal_code' => '1000',
            'is_active' => false,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'code' => 'CUS-001',
            'name' => 'Rahim Enterprise Ltd',
            'is_active' => false,
        ]);
    }

    public function test_used_supplier_and_customer_must_be_made_inactive_instead_of_deleted(): void
    {
        [$branch, $warehouse] = $this->location();
        $supplier = Supplier::query()->create([
            'name' => 'Used Supplier',
            'phone' => '01720000001',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'name' => 'Used Customer',
            'phone' => '01820000001',
            'is_active' => true,
        ]);

        PurchaseOrder::query()->create([
            'order_no' => 'PO-CONTACT-001',
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => '2026-11-19',
            'status' => PurchaseOrderStatus::Draft,
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'total' => 0,
        ]);
        Sale::query()->create([
            'sale_no' => 'SALE-CONTACT-001',
            'branch_id' => $branch->id,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customer->id,
            'channel' => SaleChannel::Pos,
            'sale_at' => '2026-11-19 10:00:00',
            'status' => SaleStatus::Draft,
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
        ]);

        $this->deleteJson(route('supplier.destroy', $supplier))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
        $this->deleteJson(route('customer.destroy', $customer))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertNull($supplier->fresh()->deleted_at);
        $this->assertNull($customer->fresh()->deleted_at);
    }

    public function test_status_filters_keep_inactive_contacts_manageable(): void
    {
        Supplier::query()->create(['name' => 'Active Supplier', 'is_active' => true]);
        Supplier::query()->create(['name' => 'Inactive Supplier', 'is_active' => false]);
        Customer::query()->create([
            'name' => 'Active Customer',
            'phone' => '01830000001',
            'is_active' => true,
        ]);
        Customer::query()->create([
            'name' => 'Inactive Customer',
            'phone' => '01830000002',
            'is_active' => false,
        ]);

        $supplierRows = $this->postJson(route('supplier.list.ajax'), [
            'draw' => 1,
            'status' => 'INACTIVE',
            'start' => 0,
            'length' => 10,
        ])->assertOk()->json('aaData');
        $customerRows = $this->postJson(route('customer.list.ajax'), [
            'draw' => 1,
            'status' => 'INACTIVE',
            'start' => 0,
            'length' => 10,
        ])->assertOk()->json('aaData');

        $this->assertCount(1, $supplierRows);
        $this->assertStringContainsString('Inactive Supplier', $supplierRows[0][1]);
        $this->assertCount(1, $customerRows);
        $this->assertStringContainsString('Inactive Customer', $customerRows[0][1]);
    }

    /** @return array{Branch, Warehouse} */
    private function location(): array
    {
        $branch = Branch::query()->create([
            'name' => 'Paltan',
            'code' => 'PLT-CONTACT',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Paltan Warehouse',
            'code' => 'PLW-CONTACT',
            'type' => 'store',
            'is_default' => true,
            'is_active' => true,
        ]);

        return [$branch, $warehouse];
    }
}
