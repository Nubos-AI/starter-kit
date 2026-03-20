<?php

declare(strict_types=1);

use App\Http\Middleware\SetCurrentTeam;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', SetCurrentWorkspace::class, SetCurrentTeam::class])
    ->prefix('workspaces/{workspace}/teams/{team}')
    ->group(function (): void {
        Route::get('/dashboard', function () {
            return inertia('Dashboard');
        })->name('team.dashboard');
    });
