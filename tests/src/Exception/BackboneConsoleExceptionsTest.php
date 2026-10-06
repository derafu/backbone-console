<?php

declare(strict_types=1);

/**
 * Derafu: Backbone Console - Operations of the backbone as console commands.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsBackboneConsole\Exception;

use Derafu\BackboneConsole\Exception\CommandNotFoundException;
use Derafu\BackboneConsole\Exception\InvalidPayloadException;
use Derafu\BackboneConsole\Service\DefaultExitCodeResolver;
use Derafu\BackboneConsole\Service\GenericOperationCommand;
use Derafu\BackboneConsole\Service\OperationCommandLoader;
use Derafu\BackboneConsole\Service\PayloadCodec;
use Derafu\TestsBackboneConsole\Fixture\Bootstrap;
use Derafu\Translation\Contract\TranslatableInterface;
use Derafu\Xml\Service\XmlDecoder;
use Derafu\Xml\Service\XmlEncoder;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\CommandNotFoundException as SymfonyCommandNotFoundException;
use Throwable;

/**
 * The errors of the package are translatable, say what they always did and are
 * still the exceptions that whoever uses the package catches.
 */
#[CoversClass(OperationCommandLoader::class)]
#[CoversClass(PayloadCodec::class)]
#[CoversClass(CommandNotFoundException::class)]
#[CoversClass(InvalidPayloadException::class)]
#[UsesClass(DefaultExitCodeResolver::class)]
#[UsesClass(GenericOperationCommand::class)]
final class BackboneConsoleExceptionsTest extends TestCase
{
    #[Test]
    public function anUnknownCommandIsATranslatableCommandNotFoundException(): void
    {
        $loader = new OperationCommandLoader(Bootstrap::bootExplorer(), Bootstrap::boot());

        $exception = null;
        try {
            $loader->get('does:not:exist');
        } catch (Throwable $e) {
            $exception = $e;
        }

        // Still the exception that the Symfony interface asks for.
        $this->assertInstanceOf(SymfonyCommandNotFoundException::class, $exception);
        $this->assertInstanceOf(CommandNotFoundException::class, $exception);
        $this->assertInstanceOf(TranslatableInterface::class, $exception);
        $this->assertSame('Command "does:not:exist" does not exist.', $exception->getMessage());
        $this->assertSame([], $exception->getAlternatives());
    }

    #[Test]
    public function malformedJsonIsATranslatableJsonException(): void
    {
        $codec = new PayloadCodec(new XmlDecoder(), new XmlEncoder());

        $exception = null;
        try {
            $codec->decode('{"parameters": {"a": 5,}}');
        } catch (Throwable $e) {
            $exception = $e;
        }

        $this->assertInstanceOf(JsonException::class, $exception);
        $this->assertInstanceOf(InvalidPayloadException::class, $exception);
        $this->assertInstanceOf(TranslatableInterface::class, $exception);
        $this->assertSame('The request is not valid JSON: Syntax error', $exception->getMessage());
        $this->assertSame(JSON_ERROR_SYNTAX, $exception->getCode());
    }
}
