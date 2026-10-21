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
use Derafu\BackboneConsole\Service\PayloadCodec;
use Derafu\BackboneConsole\Translation\BackboneConsoleTranslationResourceProvider;
use Derafu\TestsBackboneConsole\Fixture\Bootstrap;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Derafu\Translation\TranslatorFactory;
use Derafu\Xml\Service\XmlDecoder;
use Derafu\Xml\Service\XmlEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(GenericOperationCommand::class)]
#[UsesClass(DefaultExitCodeResolver::class)]
#[UsesClass(PayloadCodec::class)]
#[UsesClass(BackboneConsoleTranslationResourceProvider::class)]
final class CommandMetadataValidationTest extends TestCase
{
    /** @param array<array-key, mixed>|null $doc */
    private function command(?array $doc): GenericOperationCommand
    {
        return new GenericOperationCommand(
            'example_package.example_component.example_worker::sum',
            Bootstrap::boot(),
            new PayloadCodec(new XmlDecoder(), new XmlEncoder()),
            new DefaultExitCodeResolver(),
            $doc,
        );
    }

    /** @return iterable<string, array{array<array-key, mixed>, string, string}> */
    public static function invalidMetadata(): iterable
    {
        foreach ([false, 1, 1.5, [], new stdClass()] as $value) {
            $type = get_debug_type($value);
            foreach (['summary', 'description'] as $field) {
                yield "$field/$type" => [[$field => $value], $field, 'string'];
            }
            foreach (['name', 'type', 'description'] as $field) {
                yield "parameter.$field/$type" => [['parameters' => [[$field => $value]]], "parameter.$field", 'string'];
            }
            yield "returns.type/$type" => [['returns' => ['type' => $value]], 'returns.type', 'string'];
            yield "returns.description/$type" => [['returns' => ['type' => 'int', 'description' => $value]], 'returns.description', 'string'];
            foreach (['type', 'description'] as $field) {
                yield "throw.$field/$type" => [['throws' => [[$field => $value]]], "throw.$field", 'string'];
            }
            foreach (['url', 'description'] as $field) {
                yield "link.$field/$type" => [['links' => [[$field => $value]]], "link.$field", 'string'];
            }
        }
        foreach ([false, 1, 1.5, 'invalid', new stdClass()] as $value) {
            $type = get_debug_type($value);
            foreach (['parameters', 'returns', 'throws', 'links', 'operation'] as $field) {
                $doc = [$field => $value];
                if ($field === 'operation') {
                    $doc['returns'] = ['type' => 'int'];
                }
                yield "$field/$type" => [$doc, $field, 'array'];
            }
            foreach (['parameters' => 'parameter', 'throws' => 'throw', 'links' => 'link'] as $field => $item) {
                yield "$item/$type" => [[$field => [$value]], $item, 'array'];
            }
            yield "results/$type" => [['returns' => ['type' => 'int'], 'operation' => ['results' => $value]], 'operation.results', 'array'];
            yield "success/$type" => [['returns' => ['type' => 'int'], 'operation' => ['results' => ['success' => $value]]], 'operation.results.success', 'array'];
        }
        foreach ([1, 1.5, 'true', [], new stdClass()] as $value) {
            yield 'required/' . get_debug_type($value) => [['parameters' => [['required' => $value]]], 'parameter.required', 'boolean'];
        }
    }

    /** @param array<array-key, mixed> $doc */
    #[DataProvider('invalidMetadata')]
    public function testRejectsIncorrectMetadataTypesWithATranslatableException(array $doc, string $field, string $expected): void
    {
        try {
            $this->command($doc);
            $this->fail('Incorrect metadata must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $article = $expected === 'array' ? 'an' : 'a';
            $this->assertStringStartsWith("The $field metadata must be $article $expected;", $exception->getMessage());
            $translator = TranslatorFactory::create('es', resourceProviders: [new BackboneConsoleTranslationResourceProvider()]);
            $this->assertStringStartsWith("Los metadatos de $field deben ser", $exception->trans($translator));
        }
    }

    public function testMissingAndNullOptionalFieldsKeepTheirExistingDefaults(): void
    {
        $this->assertSame($this->command(null)->getHelp(), $this->command([
            'summary' => null,
            'description' => null,
            'parameters' => null,
            'returns' => null,
            'throws' => null,
            'links' => null,
        ])->getHelp());

        $command = $this->command([
            'summary' => '',
            'parameters' => [['name' => null, 'type' => null, 'required' => null, 'description' => null]],
            'returns' => ['type' => 'int', 'description' => null],
            'operation' => ['results' => ['success' => null]],
            'throws' => [['type' => null, 'description' => null]],
            'links' => [['url' => null, 'description' => null]],
        ]);

        $this->assertSame('', $command->getDescription());
        $this->assertStringContainsString('? (mixed, optional)', $command->getHelp());
        $this->assertStringContainsString("Returns:\n  int", $command->getHelp());
        $this->assertStringContainsString("Throws:\n  - ?", $command->getHelp());
        $this->assertStringNotContainsString('Example:', $command->getHelp());
    }

    public function testRequiredParametersAndZeroStringDescriptionsKeepTheirExistingMeaning(): void
    {
        $command = $this->command([
            'description' => '0',
            'parameters' => [['name' => 'a', 'type' => 'int', 'required' => true, 'description' => '0']],
            'returns' => ['type' => 'int', 'description' => '0'],
            'operation' => ['results' => ['success' => ['example' => false]]],
            'throws' => [['type' => 'RuntimeException', 'description' => '0']],
            'links' => [['url' => 'https://example.test', 'description' => '0']],
        ]);

        $this->assertStringContainsString('a (int, required)', $command->getHelp());
        $this->assertStringContainsString("Example:\n    false", $command->getHelp());
        $this->assertStringNotContainsString(': 0', $command->getHelp());
        $this->assertStringNotContainsString(' — 0', $command->getHelp());
    }
}
