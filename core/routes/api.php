<?php
use App\Http\Controllers\backend\UnitController;
use Illuminate\Support\Facades\Route;

// Route::prefix('units')->group(function () {
//     Route::get('/quick', [UnitController::class, 'quick']);

//     Route::get('/{unit}', [UnitController::class, 'show']);

//     Route::get('/', [UnitController::class, 'index'])->name('units.index');

// // DataTables
//     Route::post('/units/list', [UnitController::class, 'listAjax'])
//         ->name('units.list.ajax');

// // AjaxModal forms
//     Route::get('/units/create-modal', [UnitController::class, 'createModal'])
//         ->name('units.createModal');
//     Route::get('/units/{unit}/edit-modal', [UnitController::class, 'editModal'])
//         ->name('units.editModal');

//     Route::post('/units', [UnitController::class, 'store'])->name('units.store');
//     Route::put('/units/{unit}', [UnitController::class, 'update'])->name('units.update');
//     Route::delete('/units/{unit}', [UnitController::class, 'destroy'])->name('units.destroy');
// });
