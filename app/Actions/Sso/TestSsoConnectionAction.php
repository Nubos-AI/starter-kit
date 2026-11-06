<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\DTOs\Sso\OidcProviderMetadata;
use App\Exceptions\Sso\OidcProviderException;
use App\Support\Sso\OidcProviderClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TestSsoConnectionAction
{
    public function __construct(private readonly OidcProviderClient $provider) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function execute(array $data): OidcProviderMetadata
    {
        $validated = Validator::make($data, ['issuer_url' => ['required', 'string', 'url', 'max:2048']])->validate();

        try {
            $metadata = $this->provider->metadata((string) $validated['issuer_url']);
        } catch (OidcProviderException $exception) {
            Log::info('A single sign-on connection test failed.', ['reason' => $exception->reason]);

            throw ValidationException::withMessages(['issuer_url' => $exception->userMessage()]);
        }

        Log::info('A single sign-on connection test succeeded.');

        return $metadata;
    }
}
