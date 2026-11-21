<?php

declare(strict_types=1);

use App\Http\Controllers\Scim\ScimDiscoveryController;
use App\Http\Controllers\Scim\ScimGroupsController;
use App\Http\Controllers\Scim\ScimUsersController;
use Illuminate\Support\Facades\Route;

Route::get('ServiceProviderConfig', [ScimDiscoveryController::class, 'serviceProviderConfig'])->name('service-provider-config');
Route::get('ResourceTypes', [ScimDiscoveryController::class, 'resourceTypes'])->name('resource-types');
Route::get('Schemas', [ScimDiscoveryController::class, 'schemas'])->name('schemas');

Route::get('Users', [ScimUsersController::class, 'index'])->name('users.index');
Route::post('Users', [ScimUsersController::class, 'store'])->name('users.store');
Route::get('Users/{user}', [ScimUsersController::class, 'show'])->name('users.show');
Route::put('Users/{user}', [ScimUsersController::class, 'replace'])->name('users.replace');
Route::patch('Users/{user}', [ScimUsersController::class, 'update'])->name('users.update');
Route::delete('Users/{user}', [ScimUsersController::class, 'destroy'])->name('users.destroy');

Route::get('Groups', [ScimGroupsController::class, 'index'])->name('groups.index');
Route::post('Groups', [ScimGroupsController::class, 'store'])->name('groups.store');
Route::get('Groups/{group}', [ScimGroupsController::class, 'show'])->name('groups.show');
Route::put('Groups/{group}', [ScimGroupsController::class, 'replace'])->name('groups.replace');
Route::patch('Groups/{group}', [ScimGroupsController::class, 'update'])->name('groups.update');
Route::delete('Groups/{group}', [ScimGroupsController::class, 'destroy'])->name('groups.destroy');
