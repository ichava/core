<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

/**
 * Decides whether a CSS value inside an SVG may survive sanitisation.
 *
 * Applied to the `style` attribute, to the text of a `<style>` element, and to
 * every presentation attribute whose value the browser parses as CSS (`fill`,
 * `stroke`, `filter`, `clip-path`, `mask`, `marker-*`, ...).
 *
 * The guard it replaces matched the literal characters `url(`. A browser does
 * not read CSS that way: it removes comments and decodes escapes first, so
 * `\75rl(`, `u\rl(` and `u/**\/rl(` are all `url(` to it, and `image-set(`,
 * `src(` and `cross-fade(` fetch without any `url(` at all (S1, 2026-11-04).
 * So this class tokenises the value the way CSS Syntax Level 3 §4.3 does --
 * comments removed, escapes decoded (§4.3.7, including the one optional
 * whitespace after a hex escape), names lowercased -- and then applies an
 * allow-list to the tokens:
 *
 * - `url()` only when its argument, quoted or not, is a same-document
 *   fragment (`#id`). A fragment cannot fetch.
 * - any other function only when its name is in {@see self::ALLOWED_FUNCTIONS}:
 *   colour, `var()`, maths, transform and easing functions. Everything that can
 *   fetch, run script or reach another document is absent by construction.
 * - no at-rule, no `behavior` and no `-moz-binding`, which are script sinks.
 * - anything the tokeniser cannot read cleanly (a bad string, a bad url, a
 *   stray escape) is refused, because a browser's error recovery is exactly
 *   where a differential would hide.
 *
 * Refusing is all-or-nothing for the value: the caller removes the attribute or
 * the element, which is what the sanitiser has always done with a bad `url()`.
 */
final class SvgCssGuard
{
    /**
     * Functions that cannot fetch, execute or reference another document.
     * Names are lowercase, as the tokeniser emits them.
     */
    public const ALLOWED_FUNCTIONS = [
        // Colour.
        'rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'lab', 'lch', 'oklab', 'oklch',
        'color', 'color-mix',
        // Custom properties and maths.
        'var', 'calc', 'min', 'max', 'clamp',
        // Transforms.
        'matrix', 'matrix3d', 'translate', 'translatex', 'translatey', 'translatez',
        'translate3d', 'scale', 'scalex', 'scaley', 'scalez', 'scale3d', 'rotate',
        'rotatex', 'rotatey', 'rotatez', 'rotate3d', 'skew', 'skewx', 'skewy',
        'perspective',
        // Easing.
        'cubic-bezier', 'steps',
    ];

    /** Identifiers that are script sinks in legacy engines. */
    private const FORBIDDEN_IDENTIFIERS = ['behavior', '-moz-binding'];

    private const REPLACEMENT = "\u{FFFD}";

    /** @var list<string> */
    private array $chars = [];

    private int $length = 0;

    private int $pos = 0;

    /**
     * Whether the CSS text may be kept as it is.
     */
    public static function isSafe(string $css): bool
    {
        return (new self)->check($css);
    }

    private static function isFragment(string $target): bool
    {
        return str_starts_with($target, '#');
    }

    private static function isWhitespace(string $c): bool
    {
        return $c === ' ' || $c === "\t" || $c === "\n";
    }

    private static function isNameStart(string $c): bool
    {
        return $c !== '' && (ctype_alpha($c) || $c === '_' || strlen($c) > 1);
    }

    private static function isNameChar(string $c): bool
    {
        return self::isNameStart($c) || ctype_digit($c) || $c === '-';
    }

    private static function isNonPrintable(string $c): bool
    {
        if (strlen($c) !== 1) {
            return false;
        }

        $o = ord($c);

        return $o <= 0x08 || $o === 0x0B || ($o >= 0x0E && $o <= 0x1F) || $o === 0x7F;
    }

    private function check(string $css): bool
    {
        // §3.3 preprocessing: one newline form, and no NUL.
        $css = str_replace(["\r\n", "\r", "\f", "\0"], ["\n", "\n", "\n", self::REPLACEMENT], $css);

        $chars = preg_split('//u', $css, -1, PREG_SPLIT_NO_EMPTY);

        if ($chars === false) {
            return false; // Not valid UTF-8: nothing to reason about.
        }

        $this->chars = $chars;
        $this->length = count($chars);
        $this->pos = 0;

        $tokens = [];

        // Every token consumes at least one character, so more tokens than
        // characters means one did not: fail closed rather than loop.
        $budget = $this->length + 1;

        while (($token = $this->nextToken()) !== null) {
            if ($token[0] === 'bad' || --$budget < 0) {
                return false;
            }

            if ($token[0] !== 'ws') {
                $tokens[] = $token;
            }
        }

        return $this->tokensAreSafe($tokens);
    }

    /**
     * @param list<array{0: string, 1: string}> $tokens
     */
    private function tokensAreSafe(array $tokens): bool
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$type, $value] = $tokens[$i];

            switch ($type) {
                case 'at':
                    return false;

                case 'ident':
                    if (in_array($value, self::FORBIDDEN_IDENTIFIERS, true)) {
                        return false;
                    }

                    break;

                case 'delim':
                    // `name /**/ (` is not a function token to CSS, but it is
                    // one to a lenient legacy parser. Nothing legitimate in an
                    // icon writes it, so it is refused rather than reasoned about.
                    if ($value === '(' && ($tokens[$i - 1][0] ?? '') === 'ident') {
                        return false;
                    }

                    break;

                case 'url':
                    if (! self::isFragment($value)) {
                        return false;
                    }

                    break;

                case 'function':
                    if ($value === 'url') {
                        // url( <string> ) -- the quoted form is a function token.
                        $argument = $tokens[$i + 1] ?? null;
                        $close = $tokens[$i + 2] ?? null;

                        if ($argument === null || $argument[0] !== 'string' || ! self::isFragment($argument[1])) {
                            return false;
                        }

                        if ($close !== null && $close !== ['delim', ')']) {
                            return false;
                        }

                        $i += 2;

                        break;
                    }

                    if (in_array($value, self::FORBIDDEN_IDENTIFIERS, true)
                        || ! in_array($value, self::ALLOWED_FUNCTIONS, true)) {
                        return false;
                    }

                    break;
            }
        }

        return true;
    }

    /**
     * The next token as [type, value], or null at the end of input.
     *
     * Types: ws, string, ident, function, url, at, delim, bad. Numbers,
     * hashes and punctuation are emitted as single-character delims or idents;
     * none of them can fetch, so only the token boundaries that can are exact.
     *
     * @return array{0: string, 1: string}|null
     */
    private function nextToken(): ?array
    {
        // Comments are skipped wherever a token may start (§4.3.2).
        while ($this->peek() === '/' && $this->peek(1) === '*') {
            $this->pos += 2;

            while ($this->pos < $this->length && ! ($this->peek() === '*' && $this->peek(1) === '/')) {
                $this->pos++;
            }

            $this->pos = min($this->pos + 2, $this->length);
        }

        if ($this->pos >= $this->length) {
            return null;
        }

        $c = $this->chars[$this->pos];

        if (self::isWhitespace($c)) {
            while ($this->pos < $this->length && self::isWhitespace($this->chars[$this->pos])) {
                $this->pos++;
            }

            return ['ws', ' '];
        }

        if ($c === '"' || $c === "'") {
            $this->pos++;

            return $this->consumeString($c);
        }

        if ($c === '@') {
            // Any at-keyword is refused, and a bare `@` has no use in an icon.
            $this->pos++;

            return ['at', '@'];
        }

        if ($this->startsIdentifier($this->pos)) {
            return $this->consumeIdentLike();
        }

        if ($c === '\\') {
            // A backslash that does not start a valid escape (§4.3.8).
            $this->pos++;

            return ['bad', '\\'];
        }

        $this->pos++;

        return ['delim', $c];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function consumeIdentLike(): array
    {
        $name = mb_strtolower($this->consumeName());

        if ($this->peek() !== '(') {
            return ['ident', $name];
        }

        $this->pos++;

        if ($name !== 'url') {
            return ['function', $name];
        }

        while ($this->pos < $this->length && self::isWhitespace($this->chars[$this->pos])) {
            $this->pos++;
        }

        if ($this->peek() === '"' || $this->peek() === "'") {
            return ['function', 'url'];
        }

        return $this->consumeUrl();
    }

    /**
     * An unquoted url token (§4.3.6), whitespace before it already consumed.
     *
     * @return array{0: string, 1: string}
     */
    private function consumeUrl(): array
    {
        $value = '';

        while ($this->pos < $this->length) {
            $c = $this->chars[$this->pos++];

            if ($c === ')') {
                return ['url', $value];
            }

            if (self::isWhitespace($c)) {
                while ($this->pos < $this->length && self::isWhitespace($this->chars[$this->pos])) {
                    $this->pos++;
                }

                if ($this->pos >= $this->length || $this->chars[$this->pos] === ')') {
                    $this->pos++;

                    return ['url', $value];
                }

                return ['bad', $value];
            }

            if ($c === '"' || $c === "'" || $c === '(' || self::isNonPrintable($c)) {
                return ['bad', $value];
            }

            if ($c === '\\') {
                if (! $this->isValidEscape($this->pos - 1)) {
                    return ['bad', $value];
                }

                $value .= $this->consumeEscape();

                continue;
            }

            $value .= $c;
        }

        // End of input inside url( ... is a parse error, but still a url.
        return ['url', $value];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function consumeString(string $quote): array
    {
        $value = '';

        while ($this->pos < $this->length) {
            $c = $this->chars[$this->pos++];

            if ($c === $quote) {
                return ['string', $value];
            }

            if ($c === "\n") {
                return ['bad', $value];
            }

            if ($c === '\\') {
                if ($this->pos >= $this->length) {
                    continue;
                }

                if ($this->chars[$this->pos] === "\n") {
                    $this->pos++; // Line continuation.

                    continue;
                }

                $value .= $this->consumeEscape();

                continue;
            }

            $value .= $c;
        }

        return ['string', $value];
    }

    private function consumeName(): string
    {
        $name = '';

        while ($this->pos < $this->length) {
            $c = $this->chars[$this->pos];

            if (self::isNameChar($c)) {
                $name .= $c;
                $this->pos++;

                continue;
            }

            if ($this->isValidEscape($this->pos)) {
                $this->pos++;
                $name .= $this->consumeEscape();

                continue;
            }

            break;
        }

        return $name;
    }

    /**
     * §4.3.7, with the backslash already consumed: up to six hex digits and
     * one optional whitespace, or the next code point taken literally.
     */
    private function consumeEscape(): string
    {
        if ($this->pos >= $this->length) {
            return self::REPLACEMENT;
        }

        $c = $this->chars[$this->pos];

        if (! ctype_xdigit($c)) {
            $this->pos++;

            return $c;
        }

        $hex = '';

        while ($this->pos < $this->length && strlen($hex) < 6 && ctype_xdigit($this->chars[$this->pos])) {
            $hex .= $this->chars[$this->pos++];
        }

        if ($this->pos < $this->length && self::isWhitespace($this->chars[$this->pos])) {
            $this->pos++;
        }

        $code = (int) hexdec($hex);

        if ($code === 0 || ($code >= 0xD800 && $code <= 0xDFFF) || $code > 0x10FFFF) {
            return self::REPLACEMENT;
        }

        return mb_chr($code, 'UTF-8') ?: self::REPLACEMENT;
    }

    /** §4.3.9: would an identifier start here? */
    private function startsIdentifier(int $at): bool
    {
        $c = $this->chars[$at] ?? '';

        if ($c === '-') {
            $next = $this->chars[$at + 1] ?? '';

            return self::isNameStart($next) || $next === '-' || $this->isValidEscape($at + 1);
        }

        if (self::isNameStart($c)) {
            return true;
        }

        return $c === '\\' && $this->isValidEscape($at);
    }

    /** §4.3.8 */
    private function isValidEscape(int $at): bool
    {
        return ($this->chars[$at] ?? '') === '\\'
            && isset($this->chars[$at + 1])
            && $this->chars[$at + 1] !== "\n";
    }

    private function peek(int $offset = 0): string
    {
        return $this->chars[$this->pos + $offset] ?? '';
    }
}
