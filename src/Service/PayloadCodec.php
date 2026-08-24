<?php

declare(strict_types=1);

/**
 * Derafu: Backbone Console - Generic Symfony Console Bridge for Backbone Services.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\BackboneConsole\Service;

use Derafu\BackboneConsole\Contract\PayloadCodecInterface;
use Derafu\BackboneConsole\ValueObject\PayloadFormat;
use Derafu\Xml\Contract\XmlDecoderInterface;
use Derafu\Xml\Contract\XmlEncoderInterface;
use Derafu\Xml\XmlDocument;
use JsonException;
use JsonSerializable;
use Symfony\Component\Yaml\Yaml;

/**
 * Detects a request payload's format by its content, decodes it, and
 * encodes a response back into that same format.
 *
 * JSON is tried before YAML on purpose: YAML is a syntactic superset of
 * JSON, so every valid JSON document also parses as YAML — trying YAML
 * first would mean JSON is never actually detected as its own format.
 *
 * Content starting with `{` or `[` is treated as an unambiguous signal
 * that JSON was intended: if `json_decode()` fails on it, a `JsonException`
 * is thrown rather than silently falling through to `Yaml::parse()`. YAML's
 * flow-style mappings/sequences use that same syntax (loosely — trailing
 * commas and unquoted keys are valid YAML but not valid JSON), so without
 * this, some malformed JSON silently parses into a different structure
 * than intended instead of failing loudly (e.g. `{"a": 5,}` — a trailing
 * comma, invalid JSON — is valid YAML flow-style, parsing into a document
 * that is simply missing whatever came after the comma). The trade-off is
 * deliberate: a hand-written top-level YAML flow-style document (rather
 * than YAML's more common block style) is no longer accepted — considered
 * acceptable, since nothing in this ecosystem produces requests that way.
 * Content that isn't XML and doesn't start with `{`/`[` is never affected
 * by this and keeps falling through to `Yaml::parse()` as before.
 *
 * XML always needs exactly one root element, unlike JSON/YAML — so on the
 * way in, `XmlDecoderInterface::decode()` keys its result by whatever that
 * root tag was named, and this class unwraps it (the caller's data is the
 * root's *children*, not the root itself, whatever it was called). On the
 * way out, the same convention applies in reverse: the data is wrapped
 * under a single fixed root tag (`ROOT_TAG`) before encoding, since
 * `XmlEncoderInterface` cannot produce a document with more than one
 * top-level element.
 *
 * Every parse failure — JSON (as above), YAML (`Yaml::parse()` throws
 * `ParseException` on genuinely malformed content, e.g. an unclosed flow
 * sequence), and XML (`XmlDocument::loadXml()` throws `XmlParseException`,
 * with no silent fallback at all: XML is never reinterpreted as anything
 * else) — is a real, uncaught exception. `decode()` never swallows one
 * into an empty/partial result; callers (e.g.
 * `GenericOperationCommand::execute()`) are expected to catch `Throwable`
 * around this call.
 */
class PayloadCodec implements PayloadCodecInterface
{
    /**
     * The root XML tag a response is wrapped under before encoding.
     */
    private const ROOT_TAG = 'response';

    public function __construct(
        private readonly XmlDecoderInterface $xmlDecoder,
        private readonly XmlEncoderInterface $xmlEncoder,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function decode(string $content): array
    {
        $trimmed = ltrim($content);

        if ($trimmed !== '' && $trimmed[0] === '<') {
            $document = new XmlDocument();
            $document->loadXml($content);

            $decoded = $this->xmlDecoder->decode($document);
            $root = reset($decoded);

            return [PayloadFormat::Xml, is_array($root) ? $root : []];
        }

        $looksLikeJson = $trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[');

        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return [PayloadFormat::Json, $decoded];
        }

        if ($looksLikeJson) {
            throw new JsonException(json_last_error_msg(), json_last_error());
        }

        $parsed = Yaml::parse($content);

        return [PayloadFormat::Yaml, is_array($parsed) ? $parsed : []];
    }

    /**
     * {@inheritDoc}
     */
    public function encode(array $data, PayloadFormat $format): string
    {
        $data = $this->normalize($data);

        return match ($format) {
            PayloadFormat::Json => (string) json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            PayloadFormat::Yaml => Yaml::dump($data, 4, 2),
            PayloadFormat::Xml => $this->xmlEncoder
                ->encode([self::ROOT_TAG => $data])
                ->getXml()
            ,
        };
    }

    /**
     * Recursively resolves `JsonSerializable` values into plain arrays.
     *
     * `json_encode()` already does this on its own, but `Yaml::dump()` and
     * `XmlEncoderInterface::encode()` do not: a `JsonSerializable` object
     * (e.g. `ProblemDetailInterface::getThrowable()`) either gets silently
     * dumped as `null` (YAML, without a flag this class does not want to
     * depend on — and `Yaml::DUMP_OBJECT_AS_MAP` does not help either,
     * since it only special-cases `stdClass`/`ArrayObject`) or flattened
     * into a single opaque string through `Stringable::__toString()` (XML,
     * losing all structure). Normalizing here, once, before any encoder
     * runs, keeps the three formats consistent with each other and with
     * what `json_encode()` already does implicitly.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalize(mixed $value): mixed
    {
        if ($value instanceof JsonSerializable) {
            return $this->normalize($value->jsonSerialize());
        }

        if (is_array($value)) {
            return array_map($this->normalize(...), $value);
        }

        return $value;
    }
}
