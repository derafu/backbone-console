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

use Derafu\BackboneConsole\Service\DefaultExitCodeResolver;
use Derafu\BackboneConsole\Service\GenericOperationCommand;
use Derafu\BackboneConsole\Service\OperationCommandLoader;
use Derafu\BackboneConsole\Service\PayloadCodec;
use Derafu\BackboneConsole\Translation\BackboneConsoleTranslationResourceProvider;
use Derafu\BackboneDispatcher\ValueObject\DiscoveryResult;
use Derafu\TestsBackboneConsole\Fixture\Bootstrap;
use Derafu\TestsBackboneConsole\Fixture\FixedTreeExplorer;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Derafu\Translation\TranslatorFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(OperationCommandLoader::class)]
#[UsesClass(GenericOperationCommand::class)]
#[UsesClass(DefaultExitCodeResolver::class)]
#[UsesClass(PayloadCodec::class)]
#[UsesClass(BackboneConsoleTranslationResourceProvider::class)]
final class OperationCommandLoaderValidationTest extends TestCase
{
    /** @return iterable<string, array{mixed, string, string}> */
    public static function invalidTrees(): iterable
    {
        yield 'missing packages' => [[], 'packages', 'array'];
        yield 'null packages' => [['packages' => null], 'packages', 'array'];
        yield 'null tree' => [null, 'tree', 'array'];
        foreach ([false, 1, 1.5, 'invalid', new stdClass()] as $value) {
            $type = get_debug_type($value);
            $worker = ['operations' => $value];
            $component = ['workers' => $value];
            $package = ['components' => $value];
            yield "tree/$type" => [$value, 'tree', 'array'];
            yield "packages/$type" => [['packages' => $value], 'packages', 'array'];
            yield "package/$type" => [['packages' => [$value]], 'package', 'array'];
            yield "components/$type" => [['packages' => [$package]], 'components', 'array'];
            yield "component/$type" => [['packages' => [['components' => [$value]]]], 'component', 'array'];
            yield "workers/$type" => [['packages' => [['components' => [$component]]]], 'workers', 'array'];
            yield "worker/$type" => [['packages' => [['components' => [['workers' => [$value]]]]]], 'worker', 'array'];
            yield "operations/$type" => [['packages' => [['components' => [['workers' => [$worker]]]]]], 'operations', 'array'];
            yield "operation/$type" => [self::operationTree($value), 'operation', 'array'];
        }
        foreach ([null, false, 1, 1.5, [], new stdClass()] as $value) {
            yield 'id/' . get_debug_type($value) => [self::operationTree(['id' => $value]), 'operation.id', 'string'];
        }
        yield 'missing id' => [self::operationTree([]), 'operation.id', 'string'];
        yield 'null package' => [['packages' => [null]], 'package', 'array'];
        yield 'null component' => [['packages' => [['components' => [null]]]], 'component', 'array'];
        yield 'null worker' => [['packages' => [['components' => [['workers' => [null]]]]]], 'worker', 'array'];
        yield 'null operation' => [self::operationTree(null), 'operation', 'array'];
    }

    /** @return array<string, mixed> */
    private static function operationTree(mixed $operation): array
    {
        return ['packages' => [['components' => [['workers' => [['operations' => [$operation]]]]]]]];
    }

    #[DataProvider('invalidTrees')]
    public function testRejectsMalformedDiscoveryMetadataWithATranslatableException(mixed $tree, string $field, string $expected): void
    {
        $explorer = new FixedTreeExplorer(DiscoveryResult::success($tree));
        $loader = new OperationCommandLoader($explorer, Bootstrap::boot());

        try {
            $loader->getNames();
            $this->fail('Malformed discovery metadata must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $article = $expected === 'array' ? 'an' : 'a';
            $this->assertStringStartsWith("The $field metadata must be $article $expected;", $exception->getMessage());
            $translator = TranslatorFactory::create('es', resourceProviders: [new BackboneConsoleTranslationResourceProvider()]);
            $this->assertStringStartsWith("Los metadatos de $field deben ser", $exception->trans($translator));
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function optionalCollections(): iterable
    {
        yield 'no packages' => [['packages' => []]];
        yield 'missing components' => [['packages' => [[]]]];
        yield 'null components' => [['packages' => [['components' => null]]]];
        yield 'missing workers' => [['packages' => [['components' => [[]]]]]];
        yield 'null workers' => [['packages' => [['components' => [['workers' => null]]]]]];
        yield 'missing operations' => [['packages' => [['components' => [['workers' => [[]]]]]]]];
        yield 'null operations' => [['packages' => [['components' => [['workers' => [['operations' => null]]]]]]]];
    }

    /** @param array<string, mixed> $tree */
    #[DataProvider('optionalCollections')]
    public function testMissingAndNullOptionalCollectionsStillProduceNoCommands(array $tree): void
    {
        $loader = new OperationCommandLoader(new FixedTreeExplorer(DiscoveryResult::success($tree)), Bootstrap::boot());

        $this->assertSame([], $loader->getNames());
        $this->assertFalse($loader->has('unknown'));
    }

    public function testADiscoveryFailureStillProducesNoCommands(): void
    {
        $failure = Bootstrap::bootExplorer()->getPackage('unknown');
        $this->assertFalse($failure->isSuccess());
        $explorer = new FixedTreeExplorer($failure);
        $loader = new OperationCommandLoader($explorer, Bootstrap::boot());

        $this->assertSame([[], []], [$loader->getNames(), $loader->getNames()]);
        $this->assertSame(1, $explorer->treeCalls);
    }

    public function testValidatedOperationsKeepTheirMetadataAndAreCached(): void
    {
        $operation = [
            'id' => 'example_package.example_component.example_worker::sum',
            'summary' => 'Adds two numbers.',
            'description' => 'Preserved discovery description.',
            'parameters' => [['name' => 'a', 'type' => 'int', 'required' => true]],
            'returns' => ['type' => 'int'],
            'operation' => ['results' => ['success' => ['example' => 12]]],
        ];
        $explorer = new FixedTreeExplorer(DiscoveryResult::success(self::operationTree($operation)));
        $loader = new OperationCommandLoader($explorer, Bootstrap::boot());
        $name = 'example_package:example_component:example_worker:sum';

        $this->assertSame([$name], $loader->getNames());
        $this->assertTrue($loader->has($name));
        $command = $loader->get($name);
        $this->assertSame('Adds two numbers.', $command->getDescription());
        $this->assertStringContainsString('Preserved discovery description.', $command->getHelp());
        $this->assertStringContainsString('a (int, required)', $command->getHelp());
        $this->assertStringContainsString("Example:\n    12", $command->getHelp());
        $this->assertSame(1, $explorer->treeCalls);
    }
}
