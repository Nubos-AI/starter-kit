<?php

declare(strict_types=1);

use App\Http\Controllers\Tenancy\TlsCertificateApprovalController;
use App\Http\Controllers\Tenancy\WelcomeController;
use App\Http\Controllers\Users\InvitationAcceptanceController;
use App\Support\Teams\TeamSegment;
use App\Support\Tenancy\WelcomeTenantResolver;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('home');

Route::get('tls/ask', TlsCertificateApprovalController::class)
    ->withoutMiddleware('web')
    ->name('tenancy.tls.ask');

Route::get('invitations/{token}', [InvitationAcceptanceController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}', [InvitationAcceptanceController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('invitations.accept');

require __DIR__.'/settings.php';

Route::middleware(['auth', 'verified'])
    ->prefix('{'.TeamSegment::key().'}')
    ->where([TeamSegment::key() => TeamSegment::pattern()])
    ->group(base_path('routes/app.php'));

Route::get('{sitePath}', WelcomeController::class)
    ->where('sitePath', WelcomeTenantResolver::$sitePathPattern)
    ->fallback()
    ->name('home.tenant');
