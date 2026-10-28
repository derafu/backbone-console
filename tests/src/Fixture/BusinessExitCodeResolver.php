<?php

declare(strict_types=1);

/**
 * Derafu: Backbone Console - Generic Symfony Console Bridge for Backbone Services.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsBackboneConsole\Fixture;

use Derafu\BackboneConsole\Service\DefaultExitCodeResolver;
use Derafu\BackboneDispatcher\Contract\ProblemDetailInterface;
use RuntimeException;

/**
 * Contributes a business failure code while preserving the generic mappings.
 */
final class BusinessExitCodeResolver extends DefaultExitCodeResolver
{
    /**
     * {@inheritDoc}
     */
    public function resolve(ProblemDetailInterface $problem): int
    {
        return $problem->getThrowable()->getClass() === RuntimeException::class
            ? 20
            : parent::resolve($problem);
    }

    /**
     * {@inheritDoc}
     */
    public function describe(): array
    {
        return [RuntimeException::class => 20] + parent::describe();
    }
}
