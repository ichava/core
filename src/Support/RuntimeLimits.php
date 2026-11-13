<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Simtabi\Laranail\Package\Tools\Support\RuntimeConfigurator;

/**
 * The PHP limits Ichava's own heavy operations run under.
 *
 * `ichava.ichava-core.runtime.*` raises `memory_limit` and `max_execution_time`
 * for seeding, watching and cache builds only. Each operation runs inside
 * scope(), which restores the host's values when it returns or throws, so a
 * host request or command that never calls into those operations keeps its
 * own limits. The config is read at call time, after it has merged.
 */
final readonly class RuntimeLimits
{
    public function __construct(
        private ConfigRepository $config,
        private Application $app,
    ) {}

    /**
     * The configurator for one operation, built from the merged config.
     * $timeout overrides `runtime.max_execution_time` for that operation only.
     */
    public function configurator(?int $timeout = null): RuntimeConfigurator
    {
        $configurator = RuntimeConfigurator::make()
            ->memory(Coerce::string($this->config->get('ichava.ichava-core.runtime.memory_limit', '2G')))
            ->timeout($timeout ?? Coerce::int($this->config->get('ichava.ichava-core.runtime.max_execution_time', 0)));

        if ($this->config->get('ichava.ichava-core.runtime.disable_telescope_in_queue', true) && $this->app->runningInConsole()) {
            $configurator->disableTelescope();
        }

        return $configurator;
    }

    /**
     * Run $operation under Ichava's limits and restore the previous values after.
     *
     * @template TResult
     *
     * @param callable(): TResult $operation
     *
     * @return TResult
     */
    public function scope(callable $operation, ?int $timeout = null): mixed
    {
        return $this->configurator($timeout)->scope($operation);
    }
}
