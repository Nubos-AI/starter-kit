<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\DeleteOwnAccountAction;
use App\Actions\Users\UpdateProfileAction;
use App\Enums\Ui\ToastType;
use App\Enums\Users\Salutation;
use App\Exceptions\Authorization\SelfLockoutException;
use App\Http\Controllers\Abstracts\Controller;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfilesController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'salutations' => Salutation::options(),
        ]);
    }

    public function update(Request $request, UpdateProfileAction $updateProfile): RedirectResponse
    {
        $updateProfile->execute($request->user(), $request->all());

        Inertia::flash('toast', ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.settings.profiles_controller.profile_saved')]);

        return to_route('profile.edit');
    }

    public function destroy(Request $request, DeleteOwnAccountAction $deleteOwnAccount): RedirectResponse
    {
        try {
            $deleteOwnAccount->execute($this->actingUser($request), $request->all());
        } catch (SelfLockoutException $exception) {
            throw ValidationException::withMessages(['user' => $exception->getMessage()]);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('home');
    }
}
