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

use Derafu\BackboneDispatcher\Contract\OperationRequestInterface;
use Derafu\BackboneDispatcher\Contract\OperationResultInterface;
use Derafu\BackboneDispatcher\Contract\SafeDispatcherInterface;

/**
 * Reproduces a producer that reports a successful result as failed.
 */
final class MisreportingDispatcher implements SafeDispatcherInterface
{
    public function dispatch(OperationRequestInterface $request): OperationResultInterface
    {
        return new MisreportedOperationResult(Bootstrap::boot()->dispatch($request));
    }
}
