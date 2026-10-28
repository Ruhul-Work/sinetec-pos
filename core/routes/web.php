<?php

use App\Http\Controllers\backend\AccountController;
use App\Http\Controllers\backend\AccountTypeController;
use App\Http\Controllers\backend\BranchAccountController;
use App\Http\Controllers\backend\BrandController;
use App\Http\Controllers\backend\CompanySettingController;
use App\Http\Controllers\backend\CountryController;
use App\Http\Controllers\backend\CustomerController;
use App\Http\Controllers\backend\DistrictController;
use App\Http\Controllers\backend\DivisionController;
use App\Http\Controllers\backend\DomainUnitMappingController;
use App\Http\Controllers\backend\FinishedProductCategoryController;
use App\Http\Controllers\backend\FinishedProductController;
use App\Http\Controllers\backend\FinishedProductReorderLevelController;
use App\Http\Controllers\backend\FiscalYearController;
use App\Http\Controllers\backend\OpeningStockController;
use App\Http\Controllers\backend\PartCategoryController;
use App\Http\Controllers\backend\PartController;
use App\Http\Controllers\backend\PartReorderLevelController;
use App\Http\Controllers\backend\PartSupplierController;
use App\Http\Controllers\backend\PaymentTypeController;
use App\Http\Controllers\backend\RawMaterialCategoryController;
use App\Http\Controllers\backend\RawMaterialController;
use App\Http\Controllers\backend\RawMaterialReorderLevelController;
use App\Http\Controllers\backend\RawMaterialSupplierController;
use App\Http\Controllers\backend\StockTransferController;
use App\Http\Controllers\backend\SupplierController;
use App\Http\Controllers\backend\UnitController;
use App\Http\Controllers\backend\UnitConversionController;
use App\Http\Controllers\backend\UpazilaController;
use App\Http\Controllers\backend\WarehouseController;
use Illuminate\Support\Facades\Route;

/*
| Legacy product, purchase, stock, POS, old finance posting, report and
| e-commerce routes are intentionally unavailable while the electronics
| manufacturing modules are rebuilt.
*/
Route::middleware(['web', 'auth', 'perm', 'branchscope'])->group(function () {
    Route::prefix('country')->name('country.')->group(function () {
        Route::get('countries', [CountryController::class, 'index'])->name('countries.index');
        Route::get('countries/create-modal', [CountryController::class, 'createModal'])->name('countries.createModal');
        Route::post('countries/list', [CountryController::class, 'listAjax'])->name('countries.list.ajax');
        Route::post('countries', [CountryController::class, 'store'])->name('countries.store');
        Route::get('countries/{country}/edit-modal', [CountryController::class, 'editModal'])->whereNumber('country')->name('countries.editModal');
        Route::get('countries/{country}', [CountryController::class, 'show'])->name('countries.show');
        Route::put('countries/{country}', [CountryController::class, 'update'])->name('countries.update');
        Route::delete('countries/{country}', [CountryController::class, 'destroy'])->name('countries.destroy');
    });

    Route::prefix('division')->name('division.')->group(function () {
        Route::get('divisions', [DivisionController::class, 'index'])->name('divisions.index');
        Route::get('divisions/create-modal', [DivisionController::class, 'createModal'])->name('divisions.createModal');
        Route::post('divisions/list', [DivisionController::class, 'listAjax'])->name('divisions.list.ajax');
        Route::post('divisions', [DivisionController::class, 'store'])->name('divisions.store');
        Route::get('divisions/{division}/edit-modal', [DivisionController::class, 'editModal'])->whereNumber('division')->name('divisions.editModal');
        Route::get('divisions/{division}', [DivisionController::class, 'show'])->name('divisions.show');
        Route::put('divisions/{division}', [DivisionController::class, 'update'])->name('divisions.update');
        Route::delete('divisions/{division}', [DivisionController::class, 'destroy'])->name('divisions.destroy');
    });

    Route::prefix('district')->name('district.')->group(function () {
        Route::get('districts', [DistrictController::class, 'index'])->name('districts.index');
        Route::get('districts/create-modal', [DistrictController::class, 'createModal'])->name('districts.createModal');
        Route::post('districts/list', [DistrictController::class, 'listAjax'])->name('districts.list.ajax');
        Route::post('districts', [DistrictController::class, 'store'])->name('districts.store');
        Route::get('districts/{district}/edit-modal', [DistrictController::class, 'editModal'])->whereNumber('district')->name('districts.editModal');
        Route::get('districts/{district}', [DistrictController::class, 'show'])->name('districts.show');
        Route::put('districts/{district}', [DistrictController::class, 'update'])->name('districts.update');
        Route::delete('districts/{district}', [DistrictController::class, 'destroy'])->name('districts.destroy');
    });

    Route::prefix('upazila')->name('upazila.')->group(function () {
        Route::get('upazilas', [UpazilaController::class, 'index'])->name('upazilas.index');
        Route::get('upazilas/create-modal', [UpazilaController::class, 'createModal'])->name('upazilas.createModal');
        Route::post('upazilas/list', [UpazilaController::class, 'listAjax'])->name('upazilas.list.ajax');
        Route::post('upazilas', [UpazilaController::class, 'store'])->name('upazilas.store');
        Route::get('upazilas/{upazila}/edit-modal', [UpazilaController::class, 'editModal'])->whereNumber('upazila')->name('upazilas.editModal');
        Route::get('upazilas/{upazila}', [UpazilaController::class, 'show'])->name('upazilas.show');
        Route::put('upazilas/{upazila}', [UpazilaController::class, 'update'])->name('upazilas.update');
        Route::delete('upazilas/{upazila}', [UpazilaController::class, 'destroy'])->name('upazilas.destroy');
    });

    Route::prefix('brands')->name('brand.brands.')->group(function () {
        Route::get('/', [BrandController::class, 'index'])->name('index');
        Route::get('create', [BrandController::class, 'createModal'])->name('create');
        Route::post('list', [BrandController::class, 'listAjax'])->name('list.ajax');
        Route::post('/', [BrandController::class, 'store'])->name('store');
        Route::get('{brand}/edit', [BrandController::class, 'editModal'])->whereNumber('brand')->name('edit');
        Route::put('{brand}', [BrandController::class, 'update'])->name('update');
        Route::get('{brand}', [BrandController::class, 'show'])->name('show');
        Route::delete('{brand}', [BrandController::class, 'destroy'])->name('destroy');
        Route::get('select2/type', [BrandController::class, 'select2'])->name('select2');
    });

    Route::prefix('units')->name('units.')->group(function () {
        Route::get('/', [UnitController::class, 'index'])->name('index');
        Route::post('list', [UnitController::class, 'listAjax'])->name('list.ajax');
        Route::get('create-modal', [UnitController::class, 'createModal'])->name('createModal');
        Route::post('/', [UnitController::class, 'store'])->name('store');
        Route::get('quick', [UnitController::class, 'quick'])->name('quick');
        Route::get('select2', [UnitController::class, 'select2'])->name('select2');

        Route::prefix('conversions')->name('conversions.')->group(function () {
            Route::get('/', [UnitConversionController::class, 'index'])->name('index');
            Route::post('list', [UnitConversionController::class, 'listAjax'])->name('list.ajax');
            Route::get('create-modal', [UnitConversionController::class, 'createModal'])->name('createModal');
            Route::post('/', [UnitConversionController::class, 'store'])->name('store');
            Route::get('{unitConversion}/edit-modal', [UnitConversionController::class, 'editModal'])->name('editModal');
            Route::put('{unitConversion}', [UnitConversionController::class, 'update'])->name('update');
            Route::delete('{unitConversion}', [UnitConversionController::class, 'destroy'])->name('destroy');
        });

        Route::get('{unit}/edit-modal', [UnitController::class, 'editModal'])->name('editModal');
        Route::put('{unit}', [UnitController::class, 'update'])->name('update');
        Route::delete('{unit}', [UnitController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('inventory')->name('inventory.')->group(function () {
        $categoryRoutes = function (string $uri, string $name, string $controller): void {
            Route::prefix($uri)->name($name.'.')->controller($controller)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('list', 'listAjax')->name('list.ajax');
                Route::get('create-modal', 'createModal')->name('createModal');
                Route::post('/', 'store')->name('store');
                Route::get('select2', 'select2')->name('select2');
                Route::get('{category}/edit-modal', 'editModal')->whereNumber('category')->name('editModal');
                Route::put('{category}', 'update')->whereNumber('category')->name('update');
                Route::delete('{category}', 'destroy')->whereNumber('category')->name('destroy');
            });
        };

        $categoryRoutes('raw-material-categories', 'raw-material-categories', RawMaterialCategoryController::class);
        $categoryRoutes('part-categories', 'part-categories', PartCategoryController::class);
        $categoryRoutes('finished-product-categories', 'finished-product-categories', FinishedProductCategoryController::class);

        Route::prefix('raw-materials')->name('raw-materials.')->controller(RawMaterialController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('list', 'listAjax')->name('list.ajax');
            Route::get('create-modal', 'createModal')->name('createModal');
            Route::post('/', 'store')->name('store');
            Route::get('select2', 'select2')->name('select2');
            Route::get('{rawMaterial}/edit-modal', 'editModal')->whereNumber('rawMaterial')->name('editModal');
            Route::put('{rawMaterial}', 'update')->whereNumber('rawMaterial')->name('update');
            Route::delete('{rawMaterial}', 'destroy')->whereNumber('rawMaterial')->name('destroy');
        });

        Route::prefix('parts')->name('parts.')->controller(PartController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('list', 'listAjax')->name('list.ajax');
            Route::get('create-modal', 'createModal')->name('createModal');
            Route::post('/', 'store')->name('store');
            Route::get('select2', 'select2')->name('select2');
            Route::get('{part}/edit-modal', 'editModal')->whereNumber('part')->name('editModal');
            Route::put('{part}', 'update')->whereNumber('part')->name('update');
            Route::delete('{part}', 'destroy')->whereNumber('part')->name('destroy');
        });

        Route::prefix('finished-products')->name('finished-products.')->controller(FinishedProductController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('list', 'listAjax')->name('list.ajax');
            Route::get('create-modal', 'createModal')->name('createModal');
            Route::post('/', 'store')->name('store');
            Route::get('select2', 'select2')->name('select2');
            Route::get('{finishedProduct}/edit-modal', 'editModal')->whereNumber('finishedProduct')->name('editModal');
            Route::put('{finishedProduct}', 'update')->whereNumber('finishedProduct')->name('update');
            Route::delete('{finishedProduct}', 'destroy')->whereNumber('finishedProduct')->name('destroy');
        });

        Route::prefix('item-units/{domain}')
            ->name('item-units.')
            ->controller(DomainUnitMappingController::class)
            ->where(['domain' => 'raw-materials|parts|finished-products'])
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('list', 'listAjax')->name('list.ajax');
                Route::get('create-modal', 'createModal')->name('createModal');
                Route::post('/', 'store')->name('store');
                Route::get('item/{item}/base-unit', 'itemBaseUnit')->whereNumber('item')->name('item-base-unit');
                Route::get('{mapping}/edit-modal', 'editModal')->whereNumber('mapping')->name('editModal');
                Route::put('{mapping}', 'update')->whereNumber('mapping')->name('update');
                Route::delete('{mapping}', 'destroy')->whereNumber('mapping')->name('destroy');
            });

        Route::prefix('raw-material-suppliers')->name('raw-material-suppliers.')->controller(RawMaterialSupplierController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('list', 'listAjax')->name('list.ajax');
            Route::get('create-modal', 'createModal')->name('createModal');
            Route::post('/', 'store')->name('store');
            Route::get('items/select2', 'itemSelect2')->name('items.select2');
            Route::get('suppliers/select2', 'supplierSelect2')->name('suppliers.select2');
            Route::get('{mapping}/edit-modal', 'editModal')->whereNumber('mapping')->name('editModal');
            Route::put('{mapping}', 'update')->whereNumber('mapping')->name('update');
            Route::delete('{mapping}', 'destroy')->whereNumber('mapping')->name('destroy');
        });

        Route::prefix('part-suppliers')->name('part-suppliers.')->controller(PartSupplierController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('list', 'listAjax')->name('list.ajax');
            Route::get('create-modal', 'createModal')->name('createModal');
            Route::post('/', 'store')->name('store');
            Route::get('items/select2', 'itemSelect2')->name('items.select2');
            Route::get('suppliers/select2', 'supplierSelect2')->name('suppliers.select2');
            Route::get('{mapping}/edit-modal', 'editModal')->whereNumber('mapping')->name('editModal');
            Route::put('{mapping}', 'update')->whereNumber('mapping')->name('update');
            Route::delete('{mapping}', 'destroy')->whereNumber('mapping')->name('destroy');
        });

        Route::prefix('raw-material-reorder-levels')->name('raw-material-reorder-levels.')->controller(RawMaterialReorderLevelController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('list', 'listAjax')->name('list.ajax');
            Route::get('create-modal', 'createModal')->name('createModal');
            Route::post('/', 'store')->name('store');
            Route::get('items/select2', 'itemSelect2')->name('items.select2');
            Route::get('warehouses/select2', 'warehouseSelect2')->name('warehouses.select2');
            Route::get('{level}/edit-modal', 'editModal')->whereNumber('level')->name('editModal');
            Route::put('{level}', 'update')->whereNumber('level')->name('update');
            Route::delete('{level}', 'destroy')->whereNumber('level')->name('destroy');
        });

        Route::prefix('part-reorder-levels')->name('part-reorder-levels.')->controller(PartReorderLevelController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('list', 'listAjax')->name('list.ajax');
            Route::get('create-modal', 'createModal')->name('createModal');
            Route::post('/', 'store')->name('store');
            Route::get('items/select2', 'itemSelect2')->name('items.select2');
            Route::get('warehouses/select2', 'warehouseSelect2')->name('warehouses.select2');
            Route::get('{level}/edit-modal', 'editModal')->whereNumber('level')->name('editModal');
            Route::put('{level}', 'update')->whereNumber('level')->name('update');
            Route::delete('{level}', 'destroy')->whereNumber('level')->name('destroy');
        });

        Route::prefix('finished-product-reorder-levels')->name('finished-product-reorder-levels.')->controller(FinishedProductReorderLevelController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('list', 'listAjax')->name('list.ajax');
            Route::get('create-modal', 'createModal')->name('createModal');
            Route::post('/', 'store')->name('store');
            Route::get('items/select2', 'itemSelect2')->name('items.select2');
            Route::get('warehouses/select2', 'warehouseSelect2')->name('warehouses.select2');
            Route::get('{level}/edit-modal', 'editModal')->whereNumber('level')->name('editModal');
            Route::put('{level}', 'update')->whereNumber('level')->name('update');
            Route::delete('{level}', 'destroy')->whereNumber('level')->name('destroy');
        });

        Route::prefix('opening-stocks')->name('opening-stocks.')->controller(OpeningStockController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('list', 'listAjax')->name('list.ajax');
            Route::get('create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('items/select2', 'itemSelect2')->name('items.select2');
            Route::get('units/select2', 'unitSelect2')->name('units.select2');
            Route::get('{openingStock}', 'show')->whereNumber('openingStock')->name('show');
            Route::get('{openingStock}/edit', 'edit')->whereNumber('openingStock')->name('edit');
            Route::put('{openingStock}', 'update')->whereNumber('openingStock')->name('update');
            Route::patch('{openingStock}/approve', 'approve')->whereNumber('openingStock')->name('approve');
            Route::patch('{openingStock}/post', 'post')->whereNumber('openingStock')->name('post');
            Route::delete('{openingStock}', 'destroy')->whereNumber('openingStock')->name('destroy');
        });

        Route::prefix('stock-transfers')->name('stock-transfers.')->controller(StockTransferController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('list', 'listAjax')->name('list.ajax');
            Route::get('create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('items/select2', 'itemSelect2')->name('items.select2');
            Route::get('units/select2', 'unitSelect2')->name('units.select2');
            Route::get('{stockTransfer}', 'show')->whereNumber('stockTransfer')->name('show');
            Route::get('{stockTransfer}/edit', 'edit')->whereNumber('stockTransfer')->name('edit');
            Route::put('{stockTransfer}', 'update')->whereNumber('stockTransfer')->name('update');
            Route::patch('{stockTransfer}/post', 'post')->whereNumber('stockTransfer')->name('post');
            Route::delete('{stockTransfer}', 'destroy')->whereNumber('stockTransfer')->name('destroy');
        });

        Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
        Route::post('warehouses/list', [WarehouseController::class, 'listAjax'])->name('warehouses.list.ajax');
        Route::get('warehouses/create-modal', [WarehouseController::class, 'createModal'])->name('warehouses.createModal');
        Route::get('warehouses/{warehouse}/edit-modal', [WarehouseController::class, 'editModal'])->name('warehouses.editModal');
        Route::post('warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
        Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
        Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->name('warehouses.destroy');
        Route::get('warehouses/select2', [WarehouseController::class, 'select2'])->name('warehouses.select2');
        Route::get('warehouses/{warehouse}/ajax', [WarehouseController::class, 'showForAjax'])->name('warehouses.showForAjax');
    });

    Route::prefix('supplier')->name('supplier.')->group(function () {
        Route::get('suppliers', [SupplierController::class, 'index'])->name('index');
        Route::get('suppliers/create', [SupplierController::class, 'create'])->name('create');
        Route::get('suppliers/create-modal', [SupplierController::class, 'createModal'])->name('createModal');
        Route::post('suppliers/list', [SupplierController::class, 'listAjax'])->name('list.ajax');
        Route::post('suppliers', [SupplierController::class, 'store'])->name('store');
        Route::get('suppliers/edit/{supplier}', [SupplierController::class, 'editModal'])->whereNumber('supplier')->name('edit');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('update');
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('show');
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('destroy');
        Route::get('suppliers/select2/type', [SupplierController::class, 'select2'])->name('select2');
        Route::get('suppliers/import_csv/file', [SupplierController::class, 'importCsvModal'])->name('import_csv');
        Route::post('suppliers/handle/upload_csv', [SupplierController::class, 'importCsv'])->name('handle_csv');
    });

    Route::prefix('customer')->name('customer.')->group(function () {
        Route::get('customers', [CustomerController::class, 'index'])->name('index');
        Route::get('customers/create', [CustomerController::class, 'createModal'])->name('create');
        Route::post('customers/list', [CustomerController::class, 'listAjax'])->name('list.ajax');
        Route::post('customers', [CustomerController::class, 'store'])->name('store');
        Route::get('customers/edit/{customer}', [CustomerController::class, 'editModal'])->whereNumber('customer')->name('edit');
        Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('update');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('show');
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('destroy');
        Route::get('customers/select2/type', [CustomerController::class, 'select2'])->name('select2');
        Route::get('customers/import_csv/file', [CustomerController::class, 'importCsvModal'])->name('import_csv');
        Route::post('customers/handle/upload_csv', [CustomerController::class, 'importCsv'])->name('handle_csv');
    });

    Route::prefix('payment-types')->name('paymentTypes.')->group(function () {
        Route::get('/', [PaymentTypeController::class, 'index'])->name('index');
        Route::get('create', [PaymentTypeController::class, 'createModal'])->name('create');
        Route::post('list', [PaymentTypeController::class, 'listAjax'])->name('list.ajax');
        Route::post('/', [PaymentTypeController::class, 'store'])->name('store');
        Route::get('select2', [PaymentTypeController::class, 'select2'])->name('select2');
        Route::get('{paymentType}/edit', [PaymentTypeController::class, 'editModal'])->whereNumber('paymentType')->name('edit');
        Route::put('{paymentType}', [PaymentTypeController::class, 'update'])->name('update');
        Route::delete('{paymentType}', [PaymentTypeController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('company-settings')->name('company_setting.')->group(function () {
        Route::get('/', [CompanySettingController::class, 'index'])->name('index');
        Route::get('create', [CompanySettingController::class, 'create'])->name('create');
        Route::post('list', [CompanySettingController::class, 'listAjax'])->name('list.ajax');
        Route::post('/', [CompanySettingController::class, 'store'])->name('store');
        Route::get('{companySetting}/edit', [CompanySettingController::class, 'edit'])->name('edit');
        Route::put('{companySetting}', [CompanySettingController::class, 'update'])->name('update');
        Route::delete('{companySetting}', [CompanySettingController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('fiscal-years')->name('fiscal-years.')->group(function () {
        Route::get('/', [FiscalYearController::class, 'index'])->name('index');
        Route::post('list/ajax', [FiscalYearController::class, 'listAjax'])->name('list.ajax');
        Route::get('create/modal', [FiscalYearController::class, 'createModal'])->name('createModal');
        Route::post('/', [FiscalYearController::class, 'store'])->name('store');
        Route::get('{fiscalYear}/edit/modal', [FiscalYearController::class, 'editModal'])->name('editModal');
        Route::post('{fiscalYear}', [FiscalYearController::class, 'update'])->name('update');
        Route::delete('{fiscalYear}', [FiscalYearController::class, 'destroy'])->name('destroy');
        Route::get('select2', [FiscalYearController::class, 'select2'])->name('select2');
    });

    Route::prefix('account-types')->name('account-types.')->group(function () {
        Route::get('/', [AccountTypeController::class, 'index'])->name('index');
        Route::post('list/ajax', [AccountTypeController::class, 'listAjax'])->name('list.ajax');
        Route::get('create/modal', [AccountTypeController::class, 'createModal'])->name('createModal');
        Route::post('/', [AccountTypeController::class, 'store'])->name('store');
        Route::get('{type}/edit/modal', [AccountTypeController::class, 'editModal'])->name('editModal');
        Route::post('{type}', [AccountTypeController::class, 'update'])->name('update');
        Route::delete('{type}', [AccountTypeController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('accounts')->name('accounts.')->group(function () {
        Route::get('/', [AccountController::class, 'index'])->name('index');
        Route::post('list/ajax', [AccountController::class, 'listAjax'])->name('list.ajax');
        Route::get('create/modal', [AccountController::class, 'createModal'])->name('createModal');
        Route::post('/', [AccountController::class, 'store'])->name('store');
        Route::get('{account}/edit/modal', [AccountController::class, 'editModal'])->name('editModal');
        Route::post('{account}', [AccountController::class, 'update'])->name('update');
        Route::delete('{account}', [AccountController::class, 'destroy'])->name('destroy');
        Route::get('{account}/balance', [AccountController::class, 'balance'])->name('balance');
    });

    Route::prefix('branch-accounts')->name('branch-accounts.')->group(function () {
        Route::get('/', [BranchAccountController::class, 'index'])->name('index');
        Route::post('assign', [BranchAccountController::class, 'assign'])->name('assign');
        Route::get('{branch}/accounts', [BranchAccountController::class, 'assignedAccounts'])->name('assigned');
    });
});
