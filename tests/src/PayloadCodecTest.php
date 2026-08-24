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
use Derafu\BackboneDispatcher\ValueObject\SafeThrowable;
use Derafu\Xml\Service\XmlDecoder;
use Derafu\Xml\Service\XmlEncoder;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException as YamlParseException;

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

    /**
     * Regression: a `JsonSerializable` value nested inside the data (e.g.
     * `ProblemDetailInterface::getThrowable()`) used to be silently dumped
     * as `null` by `Yaml::dump()`, which does not know about
     * `JsonSerializable` the way `json_encode()` does.
     */
    public function testEncodesANestedJsonSerializableValueToYamlInsteadOfNull(): void
    {
        $throwable = SafeThrowable::fromThrowable(new RuntimeException('boom', 42));

        $encoded = $this->codec->encode(['throwable' => $throwable], PayloadFormat::Yaml);

        $this->assertStringNotContainsString('throwable: null', $encoded);
        $this->assertStringContainsString('class: RuntimeException', $encoded);
        $this->assertStringContainsString('message: boom', $encoded);
    }

    /**
     * Regression: the same nested `JsonSerializable` value used to reach
     * `XmlEncoderInterface::encode()` as a raw object, which flattened it
     * into a single opaque string via `Stringable::__toString()` instead
     * of one node per field.
     */
    public function testEncodesANestedJsonSerializableValueToXmlWithOneNodePerField(): void
    {
        $throwable = SafeThrowable::fromThrowable(new RuntimeException('boom', 42));

        $encoded = $this->codec->encode(['throwable' => $throwable], PayloadFormat::Xml);

        $this->assertStringContainsString('<class>RuntimeException</class>', $encoded);
        $this->assertStringContainsString('<message>boom</message>', $encoded);
        $this->assertStringContainsString('<code>42</code>', $encoded);
    }

    /**
     * Regression: content starting with `{`/`[` used to silently fall
     * through to `Yaml::parse()` when it failed as JSON — a trailing comma
     * is invalid JSON but valid YAML flow-style, so this used to parse
     * into `['parameters' => ['a' => 5]]` (silently dropping whatever came
     * after the comma) instead of failing loudly.
     */
    public function testMalformedJsonThrowsInsteadOfSilentlyReinterpretingAsYaml(): void
    {
        $this->expectException(JsonException::class);

        $this->codec->decode('{"parameters": {"a": 5,}}');
    }

    public function testTruncatedJsonThrowsInsteadOfSilentlyReinterpretingAsYaml(): void
    {
        $this->expectException(JsonException::class);

        $this->codec->decode('{"parameters": {"xml": "abc"');
    }

    public function testMalformedYamlThatDoesNotLookLikeJsonStillThrowsTheUnderlyingYamlException(): void
    {
        $this->expectException(YamlParseException::class);

        $this->codec->decode("parameters:\n  a: [1, 2\n");
    }
}
