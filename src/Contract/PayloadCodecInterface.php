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

use Derafu\BackboneConsole\ValueObject\PayloadFormat;

/**
 * Decodes a request payload (JSON, YAML or XML) into a plain array, and
 * encodes a response back into whichever of those formats the request came
 * in as — so a command's output always matches its own input's format.
 */
interface PayloadCodecInterface
{
    /**
     * Detects the format of `$content` and decodes it into a plain array.
     *
     * Detection is by content, not by file extension: input can come from
     * STDIN, which has none.
     *
     * @param string $content
     * @return array{0: PayloadFormat, 1: array} The detected format and the
     * decoded data.
     */
    public function decode(string $content): array;

    /**
     * Encodes `$data` into `$format`.
     *
     * @param array $data
     * @param PayloadFormat $format
     * @return string
     */
    public function encode(array $data, PayloadFormat $format): string;
}
