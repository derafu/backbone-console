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

use Composer\InstalledVersions;
use Derafu\BackboneConsole\Contract\PayloadCodecInterface;
use Derafu\BackboneConsole\Exception\InvalidPayloadException;
use Derafu\BackboneConsole\Service\DefaultExitCodeResolver;
use Derafu\BackboneConsole\Service\GenericOperationCommand;
use Derafu\BackboneConsole\Service\OperationCommandLoader;
use Derafu\BackboneConsole\Service\PayloadCodec;
use Derafu\BackboneConsole\Translation\BackboneConsoleTranslationResourceProvider;
use Derafu\BackboneConsole\ValueObject\PayloadFormat;
use Derafu\BackboneDispatcher\Exception\MissingParameterException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Exercises real command discovery and execution through the production YAML.
 */
#[CoversClass(BackboneConsoleTranslationResourceProvider::class)]
#[UsesClass(PayloadCodec::class)]
#[UsesClass(DefaultExitCodeResolver::class)]
#[UsesClass(GenericOperationCommand::class)]
#[UsesClass(OperationCommandLoader::class)]
#[UsesClass(PayloadFormat::class)]
#[UsesClass(InvalidPayloadException::class)]
final class ServicesTest extends TestCase
{
    /** @var list<string> */
    private array $inputFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->inputFiles as $file) {
            unlink($file);
        }
    }

    private function inputFile(string $payload): string
    {
        $file = tempnam(sys_get_temp_dir(), 'backbone-console-services-');
        $this->assertNotFalse($file);
        $this->inputFiles[] = $file;
        $this->assertNotFalse(file_put_contents($file, $payload));

        return $file;
    }

    private function load(string $configuration = 'services.yaml'): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new YamlFileLoader($container, new FileLocator([
            dirname(__DIR__, 2) . '/tests/fixtures/config',
            (InstalledVersions::getInstallPath('derafu/backbone-dispatcher')
                ?? throw new RuntimeException('derafu/backbone-dispatcher must be installed for these tests.')) . '/resources/config',
            (InstalledVersions::getInstallPath('derafu/xml')
                ?? throw new RuntimeException('derafu/xml must be installed for these tests.')) . '/resources/config',
        ])))
            ->load($configuration);
        $container->getDefinition(BackboneConsoleTranslationResourceProvider::class)->setPublic(true);
        $container->getDefinition(PayloadCodecInterface::class)->setPublic(true);
        $container->getDefinition('app.translator')->setPublic(true);

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
        $container->compile();
        $translator = $container->get('app.translator');
        $this->assertInstanceOf(Translator::class, $translator);

        $codec = $container->get(PayloadCodecInterface::class);
        $this->assertInstanceOf(PayloadCodecInterface::class, $codec);
        try {
            $codec->decode('{"parameters":');
            $this->fail('A malformed JSON payload was accepted.');
        } catch (InvalidPayloadException $exception) {
            $this->assertSame('The request is not valid JSON: Syntax error', $exception->getMessage());
            $this->assertSame('La solicitud no es un JSON válido: Syntax error', $exception->trans($translator));
        }
    }

    public function testConfiguredLoaderDiscoversOnlyOperationsAllowedByTheSharedPolicy(): void
    {
        $container = $this->load();
        $container->compile();
        $loader = $container->get(CommandLoaderInterface::class);
        $this->assertInstanceOf(CommandLoaderInterface::class, $loader);
        $this->assertContains('example_package:example_component:example_worker:sum', $loader->getNames());
        $this->assertContains('example_package:example_component:example_worker:message', $loader->getNames());
        $this->assertFalse($loader->has('example_package:example_component:example_worker:getName'));
    }

    public function testApplicationCanAttachTheConfiguredLoaderAndExecuteARealOperation(): void
    {
        $container = $this->load();
        $container->compile();
        $loader = $container->get(CommandLoaderInterface::class);
        $this->assertInstanceOf(CommandLoaderInterface::class, $loader);
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCommandLoader($loader);
        $tester = new ApplicationTester($application);
        $status = $tester->run([
            'command' => 'example_package:example_component:example_worker:sum',
            'input' => $this->inputFile('{"parameters":{"a":5,"b":7}}'),
        ]);
        $this->assertSame(0, $status);
        $response = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($response);
        $this->assertSame(12, $response['data']);
    }

    /** @return iterable<string, array{string, PayloadFormat}> */
    public static function textPayloads(): iterable
    {
        yield 'json' => ['{"parameters":{"text":"Hello"}}', PayloadFormat::Json];
        yield 'yaml' => ["parameters:\n  text: Hello\n", PayloadFormat::Yaml];
        yield 'xml' => ['<request><parameters><text>Hello</text></parameters></request>', PayloadFormat::Xml];
    }

    #[DataProvider('textPayloads')]
    public function testConfiguredCodecAndXmlServicesSupportRealCommandRoundTrips(string $payload, PayloadFormat $format): void
    {
        $container = $this->load();
        $container->compile();
        $loader = $container->get(CommandLoaderInterface::class);
        $this->assertInstanceOf(CommandLoaderInterface::class, $loader);
        $tester = new CommandTester($loader->get('example_package:example_component:example_worker:message'));
        $this->assertSame(0, $tester->execute(['input' => $this->inputFile($payload)]));
        $codec = $container->get(PayloadCodecInterface::class);
        $this->assertInstanceOf(PayloadCodecInterface::class, $codec);
        [$actualFormat, $response] = $codec->decode($tester->getDisplay());
        $this->assertSame($format, $actualFormat);
        $this->assertSame('Hello', $response['data']);
    }

    public function testDefaultResolverReportsAMissingOperationParameter(): void
    {
        $container = $this->load();
        $container->compile();
        $loader = $container->get(CommandLoaderInterface::class);
        $this->assertInstanceOf(CommandLoaderInterface::class, $loader);
        $tester = new CommandTester($loader->get('example_package:example_component:example_worker:sum'));
        $status = $tester->execute(['input' => $this->inputFile('{}')], ['capture_stderr_separately' => true]);
        $this->assertSame(DefaultExitCodeResolver::MISSING_PARAMETER, $status);
        $problem = json_decode($tester->getErrorOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($problem);
        $this->assertSame(MissingParameterException::class, $problem['title']);
        $this->assertSame('The parameter a of type int is missing from the request data.', $problem['detail']);
    }

    public function testApplicationResolverOverrideAffectsExecutionAndHelp(): void
    {
        $container = $this->load('business-exit-codes.yaml');
        $container->compile();
        $loader = $container->get(CommandLoaderInterface::class);
        $this->assertInstanceOf(CommandLoaderInterface::class, $loader);
        $command = $loader->get('example_package:example_component:example_worker:fail');
        $this->assertStringContainsString('20 - RuntimeException.', $command->getHelp());
        $tester = new CommandTester($command);
        $status = $tester->execute(['input' => $this->inputFile('{}')], ['capture_stderr_separately' => true]);
        $this->assertSame(20, $status);
        $this->assertStringContainsString('Something went wrong while running the operation.', $tester->getErrorOutput());
    }
}
