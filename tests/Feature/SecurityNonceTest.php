<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Support\SecurityNonce;

it('generates a base64url nonce of at least 128 bits', function (): void {
    SecurityNonce::bind($this->app);
    $nonce = $this->app->make(SecurityNonce::class);

    $value = $nonce->value();

    expect($value)->toMatch('/^[A-Za-z0-9_-]+$/');
    // 24 bytes = 192 bits, base64url-encoded, no padding => 32 chars.
    expect(strlen($value))->toBeGreaterThanOrEqual(32);
});

it('reuses the same nonce within a request scope', function (): void {
    SecurityNonce::bind($this->app);
    $nonce = $this->app->make(SecurityNonce::class);

    $first = $nonce->value();
    $second = $nonce->value();

    expect($first)->toBe($second);
});

it('issues a fresh nonce for a new container scope', function (): void {
    SecurityNonce::bind($this->app);

    $first = $this->app->make(SecurityNonce::class)->value();
    $this->app->forgetScopedInstances();
    $second = $this->app->make(SecurityNonce::class)->value();

    expect($first)->not->toBe($second);
});

it('renders an HTML attribute that escapes the value', function (): void {
    SecurityNonce::bind($this->app);
    $nonce = $this->app->make(SecurityNonce::class);

    $attribute = $nonce->attribute();

    expect($attribute)->toStartWith(' nonce="');
    expect($attribute)->toEndWith('"');
});
