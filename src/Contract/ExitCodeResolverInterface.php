<?php

declare(strict_types=1);

/**
 * Derafu: Backbone Console - Generic Symfony Console Bridge for Backbone Services.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\BackboneConsole\Contract;

use Derafu\BackboneDispatcher\Contract\ProblemDetailInterface;

/**
 * Resolves the CLI exit code for a failed operation.
 *
 * A successful operation always exits `Command::SUCCESS` (0) — resolving is
 * only ever needed for a failure, from whatever `ProblemDetailInterface`
 * `SafeDispatcherInterface::dispatch()` produced.
 */
interface ExitCodeResolverInterface
{
    /**
     * @param ProblemDetailInterface $problem
     * @return int A non-zero exit code.
     */
    public function resolve(ProblemDetailInterface $problem): int;
}
