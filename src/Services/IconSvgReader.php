<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Throwable;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Drivers\SvgDriver;
use Simtabi\Laranail\Ichava\Support\AuditLogger;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Contracts\IconDriverInterface;

/**
 * Reads one SVG file of an icon and returns its served form: read safely,
 * ids namespaced, sizing normalised, sanitised, and cached by key.
 *
 * Extracted from Icon::processedSvg() (C-11) for one stated reason: it needs
 * four collaborators, which an Eloquent model can only fetch from the
 * container, and here they are injected and testable. The model's accessor
 * keeps its behaviour and forwards here.
 */
final readonly class IconSvgReader
{
    public function __construct(
        private IconCacheService $cache,
        private IconDriverInterface $driver,
        private SvgProcessingService $processor,
        private AuditLogger $audit,
    ) {}

    /**
     * The served form of the file at $path, or null when the cache holds
     * something that is not a string; '' when the file is refused.
     *
     * @param string $storedPath the path as the icon row stores it, for ids and audit lines
     * @param string|null $baseDir the pack directory the file must stay inside
     *
     * @see Icon::svgContent()
     */
    public function read(string $path, string $storedPath, string $cacheKey, IconKind $kind, string $package, ?string $baseDir): ?string
    {
        /*
         * Sanitise here, at the single point every consumer reads through.
         *
         * This used to be a bare `File::get()`. The SVG endpoint's own comment
         * claimed the content "has been sanitised by SvgProcessingService" and
         * added nosniff plus a restrictive CSP as defence in depth -- but nothing
         * had sanitised it, so those headers were the only defence, and they only
         * apply to that one route.
         *
         * The path that mattered more is JSON: `IconBrowserService` puts
         * `svg_content` straight into an API payload, where no response header
         * helps, and the client injects it into the DOM. A pack shipping a hostile
         * file reached the browser verbatim -- and packs do contain
         * `foreignObject`, `script` and `image` elements today.
         *
         * The result is cached post-sanitisation, so this costs one pass per icon
         * per cache lifetime rather than one per request.
         */
        $content = $this->cache->remember(
            $cacheKey,
            function () use ($path, $storedPath, $kind, $package, $baseDir): string {
                /*
                 * The kind's byte budget is enforced at seed time, but the
                 * file is read again here, on every cache miss -- and it may
                 * have been replaced or grown since. SvgDriver::load() is the
                 * one safe reader (C-12): it refuses a symlink, an escape from
                 * the pack's directory, a file under the minimum size and one
                 * over this kind's budget, before reading (S2). A refusal
                 * fails closed, as a sanitiser rejection does.
                 */
                try {
                    $raw = $this->driver->load($path, baseDir: $baseDir, maxBytes: $kind->maxFileSize());
                } catch (IchavaException $e) {
                    $this->audit->warning('svg.sanitiser_rejected', [
                        'path'    => $storedPath,
                        'package' => $package,
                        'reason'  => $e->getMessage(),
                    ]);

                    return '';
                }

                try {
                    $svg = $this->processor;

                    /*
                     * Namespace ids before sanitising, and inside the cache,
                     * so it costs one pass per icon per cache lifetime.
                     *
                     * SVG ids are page-scoped in practice. 2,157 files in the
                     * corpus share `_Transparent_Rectangle_` and 261 share
                     * `Layer_1`; render two of them together and the second
                     * icon's `url(#Layer_1)` resolves to the first one's
                     * definition, silently. The browser renders 60+ at once.
                     * The prefix is derived from the path, not the content, so
                     * it survives an upstream refresh and stays deterministic
                     * for the content-addressed cache (CR1).
                     */
                    $raw = $svg->namespaceIds($raw, $storedPath);

                    /*
                     * Hand sizing back to the component. An icon shipping
                     * both a viewBox and hard width/height ignores the size
                     * it is asked for; 6,146 tabler icons alone ship that
                     * pair. An icon with neither is
                     * reported rather than dropped -- it still renders, it
                     * just has no intrinsic size to scale against.
                     */
                    $raw = $svg->normaliseSizing($raw, function (string $reason) use ($storedPath, $package): void {
                        $this->audit->warning('svg.unusable_sizing', [
                            'path'    => $storedPath,
                            'package' => $package,
                            'reason'  => $reason,
                        ]);
                    });

                    /*
                     * Opt-in (B7): a monochrome icon that hardcodes black
                     * vanishes on a dark background. Off by default; the flag
                     * is part of render_version, so turning it on re-keys this
                     * cache entry by itself.
                     */
                    if ($svg->convertsMonochromeToCurrentColor()) {
                        $raw = $svg->monochromeToCurrentColor($raw);
                    }

                    return $svg->process($raw, [], false);
                } catch (Throwable $e) {
                    /*
                     * A file the sanitiser rejects is not served raw as a
                     * fallback. Failing closed is the point: the rejection means
                     * it could not be made safe.
                     */
                    $this->audit->warning('svg.sanitiser_rejected', [
                        'path'    => $storedPath,
                        'package' => $package,
                        'reason'  => $e->getMessage(),
                    ]);

                    return '';
                }
            },
            expects: 'string',
        );

        return is_string($content) ? $content : null;
    }
}
