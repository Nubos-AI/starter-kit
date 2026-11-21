<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\Users\ConfirmEmailChangeNotification;
use App\Notifications\Users\UserInvitationNotification;
use Illuminate\Http\Request;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'https://pinned.nubos.test']);
    app('url')->setRequest(Request::create('https://evil.test/anything'));
});

it('pins the invitation link to the configured app url instead of the request host', function (): void {
    $user = ModelStub::make(User::class, ['email' => 'invitee@nubos.test']);

    $mail = (new UserInvitationNotification('invite-token-xyz', 'Chefin', now()->addDay()))->toMail($user);

    expect($mail->actionUrl)->toStartWith('https://pinned.nubos.test/')
        ->and($mail->actionUrl)->toContain('invite-token-xyz')
        ->and($mail->actionUrl)->not->toContain('evil.test');
});

it('pins the email change confirmation link to the configured app url instead of the request host', function (): void {
    $user = ModelStub::make(User::class, ['email' => 'owner@nubos.test']);

    $mail = (new ConfirmEmailChangeNotification('change-token-xyz', now()->addDay()))->toMail($user);

    expect($mail->actionUrl)->toStartWith('https://pinned.nubos.test/')
        ->and($mail->actionUrl)->toContain('change-token-xyz')
        ->and($mail->actionUrl)->not->toContain('evil.test');
});
