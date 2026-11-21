<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Actions\Notifications\RegisterPushSubscriptionAction;
use App\Actions\Notifications\UnregisterPushSubscriptionAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Traits\Http\RespondsWithValidationErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PushSubscriptionsController extends Controller
{
    use RespondsWithValidationErrors;

    public function __construct(
        private readonly RegisterPushSubscriptionAction $registerSubscription,
        private readonly UnregisterPushSubscriptionAction $unregisterSubscription,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $this->actingUser($request);

        try {
            $subscription = $this->registerSubscription->execute($user, $request->all());
        } catch (ValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return new JsonResponse(['id' => $subscription->getKey()], Response::HTTP_CREATED);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->actingUser($request);

        try {
            $this->unregisterSubscription->execute($user, $request->all());
        } catch (ValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
