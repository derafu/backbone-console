<?php

declare(strict_types=1);

/**
 * Derafu: Backbone Console - Generic Symfony Console Bridge for Backbone Services.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\BackboneConsole\ValueObject;

/**
 * The formats a request/response payload can be encoded in.
 */
enum PayloadFormat: string
{
    case Json = 'json';
    case Yaml = 'yaml';
    case Xml = 'xml';
}
