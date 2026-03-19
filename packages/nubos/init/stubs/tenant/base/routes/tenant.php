<?php

declare(strict_types=1);

use App\Http\Controllers\Tenants\TenantController;
use Illuminate\Support\Facades\Route;

Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
Route::get('tenants/create', [TenantController::class, 'create'])->name('tenants.create');
Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
Route::get('tenants/{tenant}/settings', [TenantController::class, 'settings'])->name('tenants.settings');
Route::put('tenants/{tenant}/settings', [TenantController::class, 'update'])->name('tenants.update');
Route::delete('tenants/{tenant}', [TenantController::class, 'destroy'])->name('tenants.destroy');
