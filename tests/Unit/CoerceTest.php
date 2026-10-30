<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Support\Coerce;

/**
 * Coerce replaced bare casts on untyped config, env and console values when
 * src/Providers, Commands, Jobs, Listeners, View and Facades came under
 * PHPStan max. It must return exactly what the cast did for every value a
 * cast is defined on, or the narrowing changed behaviour.
 */
it('matches the cast for every scalar and null', function (): void {
    $values = [0, 7, -3, 1.9, '42', '3.5', 'abc', '', true, false, null];

    foreach ($values as $value) {
        $this->assertSame((int) $value, Coerce::int($value), 'int of ' . var_export($value, true));
        $this->assertSame((float) $value, Coerce::float($value), 'float of ' . var_export($value, true));
        $this->assertSame((string) $value, Coerce::string($value), 'string of ' . var_export($value, true));
    }
});

it('falls back to the default for values a cast is not meant for', function (): void {
    $this->assertSame(5, Coerce::int(['a'], 5));
    $this->assertSame(2.5, Coerce::float(new stdClass, 2.5));
    $this->assertSame('none', Coerce::string(['a'], 'none'));
});

it('casts a Stringable to its string', function (): void {
    $value = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'ichava';
        }
    };

    $this->assertSame('ichava', Coerce::string($value));
});

it('keeps only strings as an optional string', function (): void {
    $this->assertSame('pack', Coerce::stringOrNull('pack'));
    $this->assertSame('', Coerce::stringOrNull(''));
    $this->assertNull(Coerce::stringOrNull(null));
    $this->assertNull(Coerce::stringOrNull(true));
    $this->assertNull(Coerce::stringOrNull(['pack']));
});
