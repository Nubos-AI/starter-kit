<?php

declare(strict_types=1);

use App\Http\Controllers\Users\InvitationAcceptanceController;
use App\Support\Teams\TeamSegment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

Route::get('/', function (Request $request): RedirectResponse|Response {
    if ($request->user() === null) {
        return Inertia::render('Welcome', [
            'canRegister' => Features::enabled(Features::registration()),
        ]);
    }

    $teamId = $request->user()->current_team_id;

    return $teamId === null
        ? redirect()->route('profile.edit')
        : redirect()->route('dashboard', ['activeTeam' => $teamId]);
})->name('home');

Route::get('invitations/{token}', [InvitationAcceptanceController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}', [InvitationAcceptanceController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('invitations.accept');

require __DIR__.'/settings.php';

Route::middleware(['auth', 'verified'])
    ->prefix('{'.TeamSegment::key().'}')
    ->where([TeamSegment::key() => TeamSegment::pattern()])
    ->group(base_path('routes/app.php'));
