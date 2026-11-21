<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('pins the password reset link to the configured application url instead of the request host', function (): void {
    config(['app.url' => 'https://pinned.nubos.test']);

    $user = ModelStub::make(User::class, ['email' => 'victim@nubos.test']);

    $mail = (new ResetPassword('reset-token-abc123'))->toMail($user);

    expect($mail->actionUrl)->toStartWith('https://pinned.nubos.test/')
        ->and($mail->actionUrl)->toContain('reset-token-abc123')
        ->and($mail->actionUrl)->toContain('email=victim%40nubos.test');
});
