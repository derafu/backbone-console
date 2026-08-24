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

use Derafu\BackboneConsole\Service\PayloadCodec;
use Derafu\BackboneConsole\ValueObject\PayloadFormat;
use Derafu\Xml\Service\XmlDecoder;
use Derafu\Xml\Service\XmlEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PayloadCodec::class)]
class PayloadCodecTest extends TestCase
{
    private PayloadCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new PayloadCodec(new XmlDecoder(), new XmlEncoder());
    }

    public function testDecodesJsonAndDetectsItAsJson(): void
    {
        [$format, $data] = $this->codec->decode('{"parameters": {"a": 5}}');

        $this->assertSame(PayloadFormat::Json, $format);
        $this->assertSame(['parameters' => ['a' => 5]], $data);
    }

    public function testDecodesYamlAndDetectsItAsYaml(): void
    {
        [$format, $data] = $this->codec->decode("parameters:\n    a: 5\n");

        $this->assertSame(PayloadFormat::Yaml, $format);
        $this->assertSame(['parameters' => ['a' => 5]], $data);
    }

    public function testDecodesXmlAndDetectsItAsXml(): void
    {
        [$format, $data] = $this->codec->decode(
            '<?xml version="1.0"?><request><parameters><a>5</a></parameters></request>',
        );

        $this->assertSame(PayloadFormat::Xml, $format);
        $this->assertSame('5', $data['parameters']['a']);
    }

    public function testEncodesToJson(): void
    {
        $encoded = $this->codec->encode(['data' => 15], PayloadFormat::Json);

        $this->assertSame(['data' => 15], json_decode($encoded, true));
    }

    public function testEncodesToYaml(): void
    {
        $encoded = $this->codec->encode(['data' => 15], PayloadFormat::Yaml);

        $this->assertStringContainsString('data: 15', $encoded);
    }

    public function testEncodesToXml(): void
    {
        $encoded = $this->codec->encode(['data' => 15], PayloadFormat::Xml);

        $this->assertStringContainsString('<data>15</data>', $encoded);
    }

    public function testJsonIsDetectedBeforeYamlSinceJsonIsValidYamlToo(): void
    {
        // A bare JSON object is also syntactically valid YAML — this proves
        // the codec still reports it as JSON, not as a YAML fallback.
        [$format] = $this->codec->decode('{"parameters": {}}');

        $this->assertSame(PayloadFormat::Json, $format);
    }
}
