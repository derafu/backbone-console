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

use Derafu\Backbone\Attribute\Operation;
use Derafu\Backbone\Contract\WorkerInterface;
use Derafu\Backbone\Trait\HandlersAwareTrait;
use Derafu\Backbone\Trait\JobsAwareTrait;
use Derafu\Config\Trait\OptionsAwareTrait;
use RuntimeException;

/**
 * A real worker, with one real, tagged operation used to exercise a real
 * `GenericOperationCommand`/`OperationCommandLoader` end to end.
 */
class ExampleWorker implements WorkerInterface
{
    use JobsAwareTrait;
    use HandlersAwareTrait;
    use OptionsAwareTrait;

    public function getId(): int|string
    {
        return 'example_worker';
    }

    public function getName(): string
    {
        return 'Example Worker';
    }

    public function getDescription(): ?string
    {
        return 'A worker with one real, tagged operation.';
    }

    public function __toString(): string
    {
        return $this->getName();
    }

    /**
     * Adds two integers together.
     *
     * @param int $a The first addend.
     * @param int $b The second addend, defaults to 10.
     */
    #[Operation(
        parameters: [
            'a' => ['example' => 5],
            'b' => ['description' => 'The second addend, defaults to 10.'],
        ],
    )]
    public function sum(int $a, int $b = 10): int
    {
        return $a + $b;
    }

    /**
     * An operation that always fails, used to exercise the failure path.
     */
    #[Operation]
    public function fail(): never
    {
        throw new RuntimeException('Something went wrong while running the operation.');
    }
}
