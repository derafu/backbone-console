<?php

declare(strict_types=1);

/**
 * Derafu: Backbone Console - Generic Symfony Console Bridge for Backbone Services.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\BackboneConsole\Service;

use Derafu\BackboneConsole\Contract\ExitCodeResolverInterface;
use Derafu\BackboneDispatcher\Contract\ProblemDetailInterface;

/**
 * Resolves every failure to the same generic exit code.
 *
 * A starting point, not a final answer: a consumer that wants a specific
 * PHP exception class to map to its own exit code implements
 * `ExitCodeResolverInterface` on its own, matching on
 * `$problem->getThrowable()->getClass()` — the same pattern
 * `derafu/backbone-api`'s `Documenter::resolveHttpStatus()` already uses
 * for HTTP status codes, just for exit codes instead.
 */
class DefaultExitCodeResolver implements ExitCodeResolverInterface
{
    /**
     * {@inheritDoc}
     */
    public function resolve(ProblemDetailInterface $problem): int
    {
        return match ($problem->getThrowable()->getClass()) {
            default => 1,
        };
    }
}
