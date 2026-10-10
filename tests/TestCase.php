<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Symfony's Request::create() sends "Accept-Language: en-us" by default, which
     * SetLocale would follow; test requests speak the application's default locale.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withServerVariables(['HTTP_ACCEPT_LANGUAGE' => (string) config('app.default_locale')]);
    }
}
