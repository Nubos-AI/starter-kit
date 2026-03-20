<?php

declare(strict_types=1);

use App\Http\Middleware\SetCurrent{{Model}};
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', SetCurrent{{Model}}::class])
    ->prefix('{{models}}/{{{model}}}')
    ->group(function (): void {
        Route::get('/dashboard', function () {
            return inertia('Dashboard');
        })->name('dashboard');
    });
