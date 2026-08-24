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

    /**
     * Resolves a format from a file path's extension.
     *
     * Returns `null` for an unrecognized or missing extension, rather than
     * a default — the caller decides what "no signal" means for it (e.g.
     * `GenericOperationCommand` falls back to the request's own format).
     *
     * @param string $path
     * @return self|null
     */
    public static function fromExtension(string $path): ?self
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'json' => self::Json,
            'yaml', 'yml' => self::Yaml,
            'xml' => self::Xml,
            default => null,
        };
    }
}
