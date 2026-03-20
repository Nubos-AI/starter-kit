<?php

declare(strict_types=1);

use App\Http\Middleware\TenantIdentification;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', TenantIdentification::class])
    ->group(function (): void {
        Route::get('/dashboard', function () {
            return inertia('Dashboard');
        })->name('dashboard');
    });
