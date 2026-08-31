<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Illuminate\Contracts\Foundation\Application;
use Simtabi\Laranail\Ichava\Browser\Http\Middleware\IchavaApiSecurity;

/**
 * Request-scoped CSP nonce.
 *
 * The same nonce is reused for the lifetime of one request so every inline
 * `<script>` and `<style>` tag emitted by Blade can be allow-listed by the
 * `Content-Security-Policy` header set in {@see IchavaApiSecurity}.
 *
 * Use the `@ichava_csp_nonce` Blade directive (registered by core) to emit a
 * `nonce="..."` attribute. The value is 192 bits of CSPRNG output, base64-url
 * encoded, well above the 128-bit minimum recommended by W3C CSP3.
 *
 * Bind as a singleton in the application container; container lifecycle
 * scopes the instance to one request.
 */
final class SecurityNonce
{
    private const ENTROPY_BYTES = 24;

    private ?string $value = null;

    public static function bind(Application $app): void
    {
        $app->scoped(self::class);
    }

    public function value(): string
    {
        return $this->value ??= $this->generate();
    }

    public function attribute(): string
    {
        return ' nonce="' . htmlspecialchars($this->value(), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
    }

    public function reset(): void
    {
        $this->value = null;
    }

    private function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::ENTROPY_BYTES)), '+/', '-_'), '=');
    }
}
