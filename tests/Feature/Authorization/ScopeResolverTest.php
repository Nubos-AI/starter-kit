<?php

declare(strict_types=1);

use App\Contracts\Authorization\ScopeResolverInterface;
use App\Services\Authorization\ScopeResolver;

it('is bound in the container', function (): void {
    $resolver = app(ScopeResolverInterface::class);

    expect($resolver)->toBeInstanceOf(ScopeResolver::class);
});

it('returns empty array by default', function (): void {
    $resolver = app(ScopeResolverInterface::class);

    expect($resolver->resolve())->toBe([]);
});
