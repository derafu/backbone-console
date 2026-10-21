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
use Derafu\BackboneConsole\ValueObject\PayloadFormat;
use Derafu\BackboneDispatcher\Contract\SafeDispatcherInterface;
use Derafu\TestsBackboneConsole\Fixture\Bootstrap;
use Derafu\TestsBackboneConsole\Fixture\MisreportingDispatcher;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Derafu\Translation\TranslatorFactory;
use Derafu\Xml\Service\XmlDecoder;
use Derafu\Xml\Service\XmlEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(GenericOperationCommand::class)]
#[UsesClass(DefaultExitCodeResolver::class)]
#[UsesClass(PayloadCodec::class)]
#[UsesClass(PayloadFormat::class)]
#[UsesClass(BackboneConsoleTranslationResourceProvider::class)]
final class ConsolePathValidationTest extends TestCase
{
    private function command(string $operation = 'sum', ?SafeDispatcherInterface $dispatcher = null): GenericOperationCommand
    {
        return new GenericOperationCommand(
            'example_package.example_component.example_worker::' . $operation,
            $dispatcher ?? Bootstrap::boot(),
            new PayloadCodec(new XmlDecoder(), new XmlEncoder()),
            new DefaultExitCodeResolver(),
        );
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPaths(): iterable
    {
        yield 'integer' => [123];
        yield 'float' => [1.5];
        yield 'boolean' => [false];
        yield 'array' => [['file.json']];
        yield 'object' => [new stdClass()];
    }

    #[DataProvider('invalidPaths')]
    public function testInvalidInputPathsThrowATranslatableException(mixed $path): void
    {
        $tester = new CommandTester($this->command());

        try {
            $tester->execute(['input' => $path]);
            $this->fail('An invalid input path must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'The console input path must be string|null; ' . get_debug_type($path) . ' given.',
                $exception->getMessage(),
            );
            $translator = TranslatorFactory::create('es', resourceProviders: [new BackboneConsoleTranslationResourceProvider()]);
            $this->assertSame(
                'La ruta de consola input debe ser string|null; se recibió ' . get_debug_type($path) . '.',
                $exception->trans($translator),
            );
        }
    }

    /** @return iterable<string, array{string, string, mixed}> */
    public static function invalidOutputPaths(): iterable
    {
        foreach (self::invalidPaths() as $type => [$path]) {
            yield "output/$type" => ['sum', 'output', $path];
            yield "error-output/$type" => ['fail', 'error-output', $path];
        }
    }

    #[DataProvider('invalidOutputPaths')]
    public function testInvalidOutputPathsAreReportedAsInternalErrors(string $operation, string $option, mixed $path): void
    {
        $input = tempnam(sys_get_temp_dir(), 'console-path-');
        file_put_contents($input, '{"parameters":{"a":5,"b":7}}');
        try {
            $tester = new CommandTester($this->command($operation));
            $exit = $tester->execute(['input' => $input, '--' . $option => $path], ['capture_stderr_separately' => true]);

            $this->assertSame(GenericOperationCommand::EX_SOFTWARE, $exit);
            $this->assertSame('', $tester->getDisplay());
            $this->assertStringContainsString(
                "Unexpected error: The console $option path must be string|null; " . get_debug_type($path) . ' given.',
                $tester->getErrorOutput(),
            );
        } finally {
            unlink($input);
        }
    }

    public function testAFailedResultWithoutProblemDetailsIsReportedAsAnInternalError(): void
    {
        $input = tempnam(sys_get_temp_dir(), 'console-result-');
        file_put_contents($input, '{"parameters":{"a":5,"b":7}}');
        try {
            $tester = new CommandTester($this->command(dispatcher: new MisreportingDispatcher()));
            $exit = $tester->execute(['input' => $input], ['capture_stderr_separately' => true]);

            $this->assertSame(GenericOperationCommand::EX_SOFTWARE, $exit);
            $this->assertSame('', $tester->getDisplay());
            $this->assertStringContainsString(
                'Unexpected error: A failed operation result must contain problem details.',
                $tester->getErrorOutput(),
            );
        } finally {
            unlink($input);
        }
    }

    public function testAFailedWriteOfTheProblemDetailReturnsExCantcreat(): void
    {
        $input = tempnam(sys_get_temp_dir(), 'console-error-output-');
        file_put_contents($input, '{}');
        $output = $input . '/missing/problem.json';
        try {
            $tester = new CommandTester($this->command('fail'));
            $exit = $tester->execute(['input' => $input, '--error-output' => $output], ['capture_stderr_separately' => true]);

            $this->assertSame(GenericOperationCommand::EX_CANTCREAT, $exit);
            $this->assertSame('', $tester->getDisplay());
            $this->assertStringContainsString('Could not write to "' . $output . '"', $tester->getErrorOutput());
        } finally {
            unlink($input);
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testADashErrorOutputKeepsTheProblemOnStderr(bool $verbose): void
    {
        $input = tempnam(sys_get_temp_dir(), 'console-error-stream-');
        file_put_contents($input, '{}');
        try {
            $tester = new CommandTester($this->command('fail'));
            $exit = $tester->execute(['input' => $input, '--error-output' => '-'], [
                'capture_stderr_separately' => true,
                'verbosity' => $verbose ? OutputInterface::VERBOSITY_VERBOSE : OutputInterface::VERBOSITY_NORMAL,
            ]);
            $this->assertSame(1, $exit);
            $this->assertSame('', $tester->getDisplay());
            $problem = json_decode($tester->getErrorOutput(), true);
            $this->assertIsArray($problem);
            $this->assertIsArray($problem['extensions']);
            $this->assertNull($problem['extensions']['data_type']);
        } finally {
            unlink($input);
        }
    }
}
