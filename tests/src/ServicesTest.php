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

use Derafu\BackboneConsole\Exception\InvalidPayloadException;
use Derafu\BackboneConsole\Service\PayloadCodec;
use Derafu\BackboneConsole\Translation\BackboneConsoleTranslationResourceProvider;
use Derafu\Translation\TranslatorFactory;
use Derafu\Xml\Service\XmlDecoder;
use Derafu\Xml\Service\XmlEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Import the provider-only YAML and consume its tagged catalogues.
 */
#[CoversClass(BackboneConsoleTranslationResourceProvider::class)]
#[UsesClass(PayloadCodec::class)]
#[UsesClass(InvalidPayloadException::class)]
final class ServicesTest extends TestCase
{
    private function load(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 2) . '/resources/config')))
            ->load('backbone-console-services.yaml');

        return $container;
    }

    public function testTheConfiguredProviderCanBeConstructed(): void
    {
        $container = $this->load();
        $container->getDefinition(BackboneConsoleTranslationResourceProvider::class)->setPublic(true);
        $container->compile();
        $provider = $container->get(BackboneConsoleTranslationResourceProvider::class);
        $this->assertInstanceOf(BackboneConsoleTranslationResourceProvider::class, $provider);
        $directories = [...$provider->getDirectories()];
        $this->assertCount(1, $directories);
        $this->assertSame(realpath(dirname(__DIR__, 2) . '/resources/translations'), realpath($directories[0]));
    }

    public function testTaggedResourcesTranslateAnExceptionFromTheRealPayloadCodec(): void
    {
        $container = $this->load();
        $this->assertArrayHasKey(
            BackboneConsoleTranslationResourceProvider::class,
            $container->findTaggedServiceIds('derafu_translation.resource_provider')
        );
        $container->register('app.translator', Translator::class)
            ->setFactory([TranslatorFactory::class, 'create'])
            ->setArguments(['es', ['en'], new TaggedIteratorArgument('derafu_translation.resource_provider')])
            ->setPublic(true);
        $container->compile();
        $translator = $container->get('app.translator');
        $this->assertInstanceOf(Translator::class, $translator);

        // The consumer creates the codec; the YAML only registers catalogues.
        $codec = new PayloadCodec(new XmlDecoder(), new XmlEncoder());
        try {
            $codec->decode('{"parameters":');
            $this->fail('A malformed JSON payload was accepted.');
        } catch (InvalidPayloadException $exception) {
            $this->assertSame('The request is not valid JSON: Syntax error', $exception->getMessage());
            $this->assertSame('La solicitud no es un JSON válido: Syntax error', $exception->trans($translator));
        }
    }
}
