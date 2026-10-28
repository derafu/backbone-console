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

/**
 * Provides a text operation whose typed payload is supported by all three codecs.
 */
final class WiringWorker extends ExampleWorker
{
    /**
     * Returns the received text unchanged for a transport round trip.
     */
    #[Operation]
    public function message(string $text): string
    {
        return $text;
    }
}
