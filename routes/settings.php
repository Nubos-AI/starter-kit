<?php

declare(strict_types=1);

use App\Http\Controllers\Notifications\NotificationRulePageController;
use App\Http\Controllers\Notifications\NotificationSettingsController;
use App\Http\Controllers\Settings\AbsencesController;
use App\Http\Controllers\Settings\ApiTokensController;
use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\ProfilesController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfilesController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfilesController::class, 'update'])->name('profile.update');

    Route::delete('settings/profile', [ProfilesController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', [AppearanceController::class, 'edit'])->name('appearance.edit');

    Route::get('settings/notifications', [NotificationSettingsController::class, 'edit'])->name('notifications.edit');

    Route::get('settings/api-tokens', [ApiTokensController::class, 'index'])->name('settings.api-tokens.index');
    Route::post('settings/api-tokens', [ApiTokensController::class, 'store'])->name('settings.api-tokens.store');
    Route::post('settings/api-tokens/{token}/rotate', [ApiTokensController::class, 'rotate'])->whereNumber('token')->name('settings.api-tokens.rotate');
    Route::delete('settings/api-tokens/{token}', [ApiTokensController::class, 'destroy'])->whereNumber('token')->name('settings.api-tokens.destroy');

    Route::get('settings/absences', [AbsencesController::class, 'index'])->name('settings.absences.index');
    Route::post('settings/absences', [AbsencesController::class, 'store'])->name('settings.absences.store');
    Route::post('settings/absences/bulk-delete', [AbsencesController::class, 'bulkDestroy'])->name('settings.absences.bulkDestroy');
    Route::put('settings/absences/{absence}', [AbsencesController::class, 'update'])->whereUlid('absence')->name('settings.absences.update');
    Route::delete('settings/absences/{absence}', [AbsencesController::class, 'destroy'])->whereUlid('absence')->name('settings.absences.destroy');

    Route::get('settings/notification-rules/{objectType:slug}', [NotificationRulePageController::class, 'index'])->middleware(['permission:{objectType}.rules.manage', 'capability:records'])->name('notification-rules.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
