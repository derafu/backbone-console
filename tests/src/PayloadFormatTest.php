<?php

declare(strict_types=1);

/**
 * Derafu: Backbone Console - Generic Symfony Console Bridge for Backbone Services.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsBackboneConsole;

use Derafu\BackboneConsole\ValueObject\PayloadFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(PayloadFormat::class)]
final class PayloadFormatTest extends TestCase
{
    #[TestWith(['/tmp/request.json', PayloadFormat::Json])]
    #[TestWith(['/tmp/request.JSON', PayloadFormat::Json])]
    #[TestWith(['/tmp/request.yaml', PayloadFormat::Yaml])]
    #[TestWith(['/tmp/request.yml', PayloadFormat::Yaml])]
    #[TestWith(['/tmp/request.YML', PayloadFormat::Yaml])]
    #[TestWith(['/tmp/request.xml', PayloadFormat::Xml])]
    #[TestWith(['/tmp/request.XML', PayloadFormat::Xml])]
    #[TestWith(['/tmp/request.txt', null])]
    #[TestWith(['/tmp/request', null])]
    #[TestWith(['/tmp/json/request', null])]
    public function testResolvesSupportedExtensionsWithoutInventingADefault(string $path, ?PayloadFormat $expected): void
    {
        $this->assertSame($expected, PayloadFormat::fromExtension($path));
    }
}
