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
use Symfony\Component\Yaml\Yaml;

/**
 * Detects a request payload's format by its content, decodes it, and
 * encodes a response back into that same format.
 *
 * JSON is tried before YAML on purpose: YAML is a syntactic superset of
 * JSON, so every valid JSON document also parses as YAML — trying YAML
 * first would mean JSON is never actually detected as its own format.
 *
 * XML always needs exactly one root element, unlike JSON/YAML — so on the
 * way in, `XmlDecoderInterface::decode()` keys its result by whatever that
 * root tag was named, and this class unwraps it (the caller's data is the
 * root's *children*, not the root itself, whatever it was called). On the
 * way out, the same convention applies in reverse: the data is wrapped
 * under a single fixed root tag (`ROOT_TAG`) before encoding, since
 * `XmlEncoderInterface` cannot produce a document with more than one
 * top-level element.
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

        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return [PayloadFormat::Json, $decoded];
        }

        $parsed = Yaml::parse($content);

        return [PayloadFormat::Yaml, is_array($parsed) ? $parsed : []];
    }

    /**
     * {@inheritDoc}
     */
    public function encode(array $data, PayloadFormat $format): string
    {
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
}
