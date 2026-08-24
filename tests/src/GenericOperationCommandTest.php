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
use Derafu\BackboneDispatcher\Contract\SafeDispatcherInterface;
use Derafu\TestsBackboneConsole\Fixture\Bootstrap;
use Derafu\Xml\Service\XmlDecoder;
use Derafu\Xml\Service\XmlEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration test: real `SafeDispatcherInterface`/`SafeExplorerInterface`
 * underneath (`Bootstrap`), real `Symfony\Component\Console\Tester\
 * CommandTester` — actually runs the command, no mocks anywhere.
 *
 * The "read from STDIN" path (`input` argument omitted or `"-"`) is not
 * exercised here: `CommandTester` does not simulate the process's real
 * STDIN stream. It is `file_get_contents('php://stdin')`, exercised for
 * real at the `bin/console` integration level of a concrete consumer
 * (e.g. `libredte-lib-core-console`), the same way every other real
 * process-boundary bridge in this ecosystem is verified end to end.
 */
#[CoversClass(GenericOperationCommand::class)]
#[UsesClass(PayloadCodec::class)]
#[UsesClass(DefaultExitCodeResolver::class)]
class GenericOperationCommandTest extends TestCase
{
    private const OPERATION_ID = 'example_package.example_component.example_worker::sum';

    private const FAIL_OPERATION_ID = 'example_package.example_component.example_worker::fail';

    private SafeDispatcherInterface $dispatcher;

    private PayloadCodec $codec;

    protected function setUp(): void
    {
        $this->dispatcher = Bootstrap::boot();
        $this->codec = new PayloadCodec(new XmlDecoder(), new XmlEncoder());
    }

    private function writeTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'backbone-console-test-');
        file_put_contents($path, $content);

        return $path;
    }

    public function testCommandNameMirrorsTheOperationId(): void
    {
        $command = new GenericOperationCommand(
            self::OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $this->assertSame(
            'example_package:example_component:example_worker:sum',
            $command->getName(),
        );
    }

    public function testSuccessfulDispatchWritesTheResultAsJson(): void
    {
        $command = new GenericOperationCommand(
            self::OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $path = $this->writeTempFile('{"parameters": {"a": 5, "b": 7}}');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['input' => $path]);
        unlink($path);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(['data' => 12], json_decode($tester->getDisplay(), true));
    }

    public function testRespectsTheInputFormatByAnsweringInYaml(): void
    {
        $command = new GenericOperationCommand(
            self::OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $path = $this->writeTempFile("parameters:\n    a: 5\n    b: 7\n");
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['input' => $path]);
        unlink($path);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('data: 12', $tester->getDisplay());
    }

    public function testOmittedParametersFallBackToTheOperationsOwnDefault(): void
    {
        $command = new GenericOperationCommand(
            self::OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $path = $this->writeTempFile('{"parameters": {"a": 5}}');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['input' => $path]);
        unlink($path);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(['data' => 15], json_decode($tester->getDisplay(), true));
    }

    public function testFailureWritesTheProblemToStderrAndReturnsTheResolvedExitCode(): void
    {
        $command = new GenericOperationCommand(
            self::FAIL_OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $path = $this->writeTempFile('{}');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(
            ['input' => $path],
            ['capture_stderr_separately' => true],
        );
        unlink($path);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Something went wrong while running the operation.',
            $tester->getErrorOutput(),
        );
    }

    public function testHelpShowsTheRealReflectedParameters(): void
    {
        $doc = Bootstrap::bootExplorer()
            ->getOperation('example_package', 'example_component', 'example_worker', 'sum')
            ->getValue()
        ;

        $command = new GenericOperationCommand(
            $doc['id'],
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
            $doc,
        );

        $help = $command->getHelp();

        $this->assertStringContainsString('a (int, required)', $help);
        $this->assertStringContainsString('b (int, optional)', $help);
        $this->assertStringContainsString('The second addend, defaults to 10.', $help);
    }
}
