<?php

declare(strict_types=1);

use App\Exceptions\Tenancy\DnsLookupFailedException;
use App\Support\Tenancy\DnsLookup;
use Illuminate\Console\Command;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config(['tenancy.welcome.base_domain' => 'nubos.test']);

    $this->dns = Mockery::mock(DnsLookup::class);
    app()->instance(DnsLookup::class, $this->dns);
    $this->probe = Mockery::pattern('/^nubos-dns-probe-[a-z0-9]{12}\.nubos\.test$/');
});

afterEach(function (): void {
    Mockery::close();
});

it('confirms the wildcard record once an unused subdomain resolves to the addresses of the platform domain', function (): void {
    $this->dns->shouldReceive('addressesOf')->with('nubos.test')->andReturn(['203.0.113.10']);
    $this->dns->shouldReceive('addressesOf')->with($this->probe)->andReturn(['203.0.113.10']);

    $this->artisan('tenancy:check-dns')
        ->expectsOutput('Der Wildcard-Eintrag *.nubos.test zeigt auf 203.0.113.10.')
        ->assertExitCode(Command::SUCCESS);
});

it('names the missing wildcard record when an unused subdomain does not resolve', function (): void {
    $this->dns->shouldReceive('addressesOf')->with('nubos.test')->andReturn(['203.0.113.10']);
    $this->dns->shouldReceive('addressesOf')->with($this->probe)->andReturn([]);

    $this->artisan('tenancy:check-dns')
        ->expectsOutput('Für *.nubos.test fehlt der Wildcard-Eintrag. Legen Sie *.nubos.test als A- bzw. AAAA-Eintrag auf 203.0.113.10 an.')
        ->assertExitCode(Command::FAILURE);
});

it('names both address sets when the wildcard record points elsewhere', function (): void {
    $this->dns->shouldReceive('addressesOf')->with('nubos.test')->andReturn(['203.0.113.10']);
    $this->dns->shouldReceive('addressesOf')->with($this->probe)->andReturn(['198.51.100.7']);

    $this->artisan('tenancy:check-dns')
        ->expectsOutput('Der Wildcard-Eintrag *.nubos.test zeigt auf 198.51.100.7, die Plattform liegt auf 203.0.113.10.')
        ->assertExitCode(Command::FAILURE);
});

it('fails while the platform domain itself does not resolve', function (): void {
    $this->dns->shouldReceive('addressesOf')->with('nubos.test')->andReturn([]);

    $this->artisan('tenancy:check-dns')
        ->expectsOutput('Die Basis-Domain nubos.test zeigt auf keine Adresse.')
        ->assertExitCode(Command::FAILURE);
});

it('fails with a hint when the name service cannot be asked', function (): void {
    $this->dns->shouldReceive('addressesOf')->andThrow(new DnsLookupFailedException('nubos.test'));

    $this->artisan('tenancy:check-dns')
        ->expectsOutput('Der DNS-Eintrag konnte gerade nicht abgefragt werden. Bitte versuchen Sie es später erneut.')
        ->assertExitCode(Command::FAILURE);
});
