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

use Derafu\BackboneDispatcher\Contract\DiscoveryResultInterface;
use Derafu\BackboneDispatcher\Service\Discovery\Explorer;
use Derafu\BackboneDispatcher\Service\Discovery\SafeExplorer;
use Derafu\BackboneDispatcher\Service\Reflection\Inspector;

/**
 * Supplies a discovery result to exercise the console's boundary validation.
 */
final class FixedTreeExplorer extends SafeExplorer
{
    public int $treeCalls = 0;

    public function __construct(private readonly DiscoveryResultInterface $result)
    {
        parent::__construct(new Explorer(new ExamplePackageRegistry(), new Inspector()));
    }

    public function tree(?string $id = null): DiscoveryResultInterface
    {
        ++$this->treeCalls;

        return $this->result;
    }
}
