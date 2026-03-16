<?php

declare(strict_types=1);

namespace App\Http\Controllers\User;

use App\Actions\User\UpdatePassword;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/Password');
    }

    public function update(Request $request, UpdatePassword $updater): RedirectResponse
    {
        $updater->execute($request->user(), $request->all());

        return back();
    }
}
