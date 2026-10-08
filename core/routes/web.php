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
use App\Http\Controllers\backend\FiscalYearController;
use App\Http\Controllers\backend\PaymentTypeController;
use App\Http\Controllers\backend\SupplierController;
use App\Http\Controllers\backend\UnitController;
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
        Route::get('{unit}/edit-modal', [UnitController::class, 'editModal'])->name('editModal');
        Route::put('{unit}', [UnitController::class, 'update'])->name('update');
        Route::delete('{unit}', [UnitController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('inventory')->name('inventory.')->group(function () {
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
