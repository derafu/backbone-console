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

use Derafu\BackboneConsole\Contract\ExitCodeResolverInterface;
use Derafu\BackboneConsole\Service\DefaultExitCodeResolver;
use Derafu\BackboneConsole\Service\GenericOperationCommand;
use Derafu\BackboneConsole\Service\PayloadCodec;
use Derafu\BackboneConsole\ValueObject\PayloadFormat;
use Derafu\BackboneDispatcher\Contract\ProblemDetailInterface;
use Derafu\BackboneDispatcher\Contract\SafeDispatcherInterface;
use Derafu\BackboneDispatcher\Exception\OperationNotAllowedException;
use Derafu\TestsBackboneConsole\Fixture\Bootstrap;
use Derafu\Xml\Service\XmlDecoder;
use Derafu\Xml\Service\XmlEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
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
#[UsesClass(PayloadFormat::class)]
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

        $response = json_decode($tester->getDisplay(), true);

        $this->assertSame(12, $response['data']);
        $this->assertSame('integer', $response['meta']['data_type']);
        $this->assertIsFloat($response['meta']['timestamp']);
    }

    public function testSuccessfulDispatchOmitsTheRestOfTheMetadataByDefault(): void
    {
        $command = new GenericOperationCommand(
            self::OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $path = $this->writeTempFile('{"parameters": {"a": 5, "b": 7}}');
        $tester = new CommandTester($command);
        $tester->execute(['input' => $path]);
        unlink($path);

        $response = json_decode($tester->getDisplay(), true);

        // "meta" itself is always present (timestamp/data_type), but the
        // rest of ExecutionMetadata is verbose-only.
        $this->assertArrayHasKey('meta', $response);
        $this->assertArrayNotHasKey('realTime', $response['meta']);
        $this->assertArrayNotHasKey('pid', $response['meta']);
    }

    public function testSuccessfulDispatchIncludesRealMetadataWhenVerbose(): void
    {
        $command = new GenericOperationCommand(
            self::OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $path = $this->writeTempFile('{"parameters": {"a": 5, "b": 7}}');
        $tester = new CommandTester($command);
        $tester->execute(
            ['input' => $path],
            ['verbosity' => OutputInterface::VERBOSITY_VERBOSE],
        );
        unlink($path);

        $data = json_decode($tester->getDisplay(), true);

        $this->assertSame(12, $data['data']);
        $this->assertSame('integer', $data['meta']['data_type']);
        $this->assertArrayHasKey('realTime', $data['meta']);
        $this->assertArrayHasKey('pid', $data['meta']);
        $this->assertIsInt($data['meta']['pid']);
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
        $this->assertSame(15, json_decode($tester->getDisplay(), true)['data']);
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

    public function testFailureAlwaysIncludesTimestampAndNullDataTypeButOmitsTheRestByDefault(): void
    {
        $command = new GenericOperationCommand(
            self::FAIL_OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $path = $this->writeTempFile('{}');
        $tester = new CommandTester($command);
        $tester->execute(
            ['input' => $path],
            ['capture_stderr_separately' => true],
        );
        unlink($path);

        $problem = json_decode($tester->getErrorOutput(), true);

        $this->assertIsFloat($problem['extensions']['timestamp']);
        $this->assertNull($problem['extensions']['data_type']);
        $this->assertArrayNotHasKey('realTime', $problem['extensions']);
        $this->assertArrayNotHasKey('pid', $problem['extensions']);
    }

    public function testFailureIncludesRealMetadataWhenVerbose(): void
    {
        $command = new GenericOperationCommand(
            self::FAIL_OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $path = $this->writeTempFile('{}');
        $tester = new CommandTester($command);
        $tester->execute(
            ['input' => $path],
            [
                'capture_stderr_separately' => true,
                'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
            ],
        );
        unlink($path);

        $problem = json_decode($tester->getErrorOutput(), true);

        $this->assertArrayHasKey('realTime', $problem['extensions']);
        $this->assertIsInt($problem['extensions']['pid']);
    }

    public function testOutputOptionWithARecognizedExtensionWritesInThatFormatRegardlessOfTheInputFormat(): void
    {
        $command = new GenericOperationCommand(
            self::OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $inputPath = $this->writeTempFile('{"parameters": {"a": 5, "b": 7}}');
        $outputPath = tempnam(sys_get_temp_dir(), 'backbone-console-test-') . '.yaml';

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['input' => $inputPath, '--output' => $outputPath]);
        unlink($inputPath);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame('', $tester->getDisplay());
        $this->assertStringContainsString('data: 12', (string) file_get_contents($outputPath));

        unlink($outputPath);
    }

    public function testOutputOptionWithAnUnrecognizedExtensionFallsBackToTheInputFormat(): void
    {
        $command = new GenericOperationCommand(
            self::OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $inputPath = $this->writeTempFile('{"parameters": {"a": 5, "b": 7}}');
        $outputPath = tempnam(sys_get_temp_dir(), 'backbone-console-test-') . '.txt';

        $tester = new CommandTester($command);
        $tester->execute(['input' => $inputPath, '--output' => $outputPath]);
        unlink($inputPath);

        $this->assertSame(12, json_decode((string) file_get_contents($outputPath), true)['data']);

        unlink($outputPath);
    }

    public function testOutputOptionAsADashBehavesLikeOmittingIt(): void
    {
        $command = new GenericOperationCommand(
            self::OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $inputPath = $this->writeTempFile('{"parameters": {"a": 5, "b": 7}}');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['input' => $inputPath, '--output' => '-']);
        unlink($inputPath);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(12, json_decode($tester->getDisplay(), true)['data']);
    }

    public function testAFailedWriteToTheOutputPathReturnsFailureAndReportsItOnTheRealStderr(): void
    {
        $command = new GenericOperationCommand(
            self::OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $inputPath = $this->writeTempFile('{"parameters": {"a": 5, "b": 7}}');
        $unwritablePath = sys_get_temp_dir() . '/backbone-console-test-missing-dir-' . uniqid() . '/out.json';

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(
            ['input' => $inputPath, '--output' => $unwritablePath],
            ['capture_stderr_separately' => true],
        );
        unlink($inputPath);

        $this->assertSame(GenericOperationCommand::EX_CANTCREAT, $exitCode);
        $this->assertStringContainsString('Could not write to', $tester->getErrorOutput());
        $this->assertFileDoesNotExist($unwritablePath);
    }

    public function testAMissingInputFileReturnsExNoinputAndReportsItOnTheRealStderrWithoutDispatching(): void
    {
        // OPERATION_ID (the "sum" operation, not FAIL_OPERATION_ID) on
        // purpose: reaching a business-level Problem at all here would
        // mean this dispatched despite the missing input, which is
        // exactly what this test proves does not happen.
        $command = new GenericOperationCommand(
            self::OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $missingPath = sys_get_temp_dir() . '/backbone-console-test-does-not-exist-' . uniqid() . '.json';

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(
            ['input' => $missingPath],
            ['capture_stderr_separately' => true],
        );

        $this->assertSame(GenericOperationCommand::EX_NOINPUT, $exitCode);
        $this->assertStringContainsString('Could not read from', $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
    }

    public function testErrorOutputOptionWritesTheProblemToAFileInsteadOfStderr(): void
    {
        $command = new GenericOperationCommand(
            self::FAIL_OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $inputPath = $this->writeTempFile('{}');
        $errorOutputPath = tempnam(sys_get_temp_dir(), 'backbone-console-test-') . '.xml';

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(
            ['input' => $inputPath, '--error-output' => $errorOutputPath],
            ['capture_stderr_separately' => true],
        );
        unlink($inputPath);

        $this->assertSame(1, $exitCode);
        $this->assertSame('', $tester->getErrorOutput());
        $this->assertStringContainsString(
            'Something went wrong while running the operation.',
            (string) file_get_contents($errorOutputPath),
        );

        unlink($errorOutputPath);
    }

    public function testMalformedYamlReturnsExDataerrInsteadOfLeakingSymfonysDefaultRendering(): void
    {
        $command = new GenericOperationCommand(
            self::OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        // Genuinely broken YAML syntax (unclosed inline sequence), not a
        // bare scalar like "hola" — Yaml::parse() throws on this.
        $inputPath = $this->writeTempFile("parameters:\n  a: [1, 2\n");

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(
            ['input' => $inputPath],
            ['capture_stderr_separately' => true],
        );
        unlink($inputPath);

        $this->assertSame(GenericOperationCommand::EX_DATAERR, $exitCode);
        $this->assertStringContainsString('Could not parse the request', $tester->getErrorOutput());
    }

    public function testMalformedXmlReturnsExDataerrInsteadOfLeakingSymfonysDefaultRendering(): void
    {
        $command = new GenericOperationCommand(
            self::OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $inputPath = $this->writeTempFile('<request><parameters>');

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(
            ['input' => $inputPath],
            ['capture_stderr_separately' => true],
        );
        unlink($inputPath);

        $this->assertSame(GenericOperationCommand::EX_DATAERR, $exitCode);
        $this->assertStringContainsString('Could not parse the request', $tester->getErrorOutput());
    }

    /**
     * Regression: a trailing comma is invalid JSON but valid YAML
     * flow-style, so this used to silently reinterpret as
     * `['parameters' => ['a' => 5]]` (losing the "xml" parameter without
     * any error at all) and fail later with a misleading
     * `MissingParameterException`, instead of failing loudly here.
     */
    public function testMalformedJsonReturnsExDataerrInsteadOfSilentlyLosingData(): void
    {
        $command = new GenericOperationCommand(
            self::OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $inputPath = $this->writeTempFile('{"parameters": {"a": 5,}}');

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(
            ['input' => $inputPath],
            ['capture_stderr_separately' => true],
        );
        unlink($inputPath);

        $this->assertSame(GenericOperationCommand::EX_DATAERR, $exitCode);
        $this->assertStringContainsString('Could not parse the request', $tester->getErrorOutput());
    }

    public function testAnUnexpectedFailureAfterParsingReturnsExSoftwareInsteadOfLeakingSymfonysDefaultRendering(): void
    {
        // A malformed operation id (missing "::") is not something a
        // caller of this command can trigger through the request content
        // — it can only happen if this command were wired up wrong (e.g.
        // a broken SafeExplorerInterface). Real, but a bug in the wiring,
        // not a usage or business-level problem, which is exactly the
        // case EX_SOFTWARE exists for.
        $command = new GenericOperationCommand(
            'not_a_valid_operation_id',
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $inputPath = $this->writeTempFile('{"parameters": {}}');

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(
            ['input' => $inputPath],
            ['capture_stderr_separately' => true],
        );
        unlink($inputPath);

        $this->assertSame(GenericOperationCommand::EX_SOFTWARE, $exitCode);
        $this->assertStringContainsString('Unexpected error', $tester->getErrorOutput());
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

    /**
     * `sum()` is documented with a `@return` description and a
     * `#[Operation(results: ['success' => ['example' => 12]])]` response
     * example — both should reach `--help`, the same reflected/attribute
     * data `Documenter` (in `derafu/backbone-api`) already surfaces in the
     * generated OpenAPI document.
     */
    public function testHelpShowsTheReturnsSectionWithTypeDescriptionAndExample(): void
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

        $this->assertStringContainsString('Returns:', $help);
        $this->assertStringContainsString('int — The sum of both addends.', $help);
        $this->assertStringContainsString('Example:', $help);
        $this->assertStringContainsString('12', $help);
    }

    /**
     * `sum()` declares one `@throws` (`OverflowException`) — it should
     * show up in its own "Throws:" section, with its description, distinct
     * from "Exit codes:" (which maps exceptions to the numeric code a
     * caller gets back, not to why they happen).
     */
    public function testHelpShowsTheThrowsSection(): void
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

        $this->assertStringContainsString('Throws:', $help);
        $this->assertStringContainsString(
            'OverflowException: If the sum exceeds `PHP_INT_MAX`.',
            $help,
        );
    }

    /**
     * `sum()` declares one `@link` — it should show up in a "Links:"
     * section, with its URL and description.
     */
    public function testHelpShowsTheLinksSection(): void
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

        $this->assertStringContainsString('Links:', $help);
        $this->assertStringContainsString(
            'https://example.test/docs/sum: Reference for this operation.',
            $help,
        );
    }

    /**
     * `fail()` has no docblock at all (no `@return`) — the "Returns:"
     * section must not appear for it, same as before this addition, since
     * there is nothing real to say about what it returns (it always
     * throws).
     */
    public function testHelpOmitsTheReturnsSectionWhenNothingIsDocumented(): void
    {
        $doc = Bootstrap::bootExplorer()
            ->getOperation('example_package', 'example_component', 'example_worker', 'fail')
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

        $this->assertStringNotContainsString('Returns:', $help);
        $this->assertStringNotContainsString('Throws:', $help);
        $this->assertStringNotContainsString('Links:', $help);
    }

    public function testHelpListsTheFixedFrameworkExitCodesEvenWithoutAnOperationDoc(): void
    {
        $command = new GenericOperationCommand(
            self::OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $help = $command->getHelp();

        $this->assertStringContainsString('0 - Success.', $help);
        $this->assertStringContainsString('1 - The operation failed.', $help);
        $this->assertStringContainsString('65 - Malformed request data', $help);
        $this->assertStringContainsString('66 - The input file does not exist', $help);
        $this->assertStringContainsString('70 - Unexpected internal error.', $help);
        $this->assertStringContainsString('73 - The output file could not be created.', $help);
    }

    public function testHelpMentionsVerboseRevealsExecutionMetadata(): void
    {
        $command = new GenericOperationCommand(
            self::OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            new DefaultExitCodeResolver(),
        );

        $help = $command->getHelp();

        $this->assertStringContainsString('-v/--verbose', $help);
        $this->assertStringContainsString('execution metadata', $help);
    }

    public function testHelpAlsoListsTheCodesTheInjectedResolverDescribesOnTopOfTheFixedOnes(): void
    {
        $resolver = new class () implements ExitCodeResolverInterface {
            public function resolve(ProblemDetailInterface $problem): int
            {
                return 1;
            }

            public function describe(): array
            {
                return [OperationNotAllowedException::class => 77];
            }
        };

        $command = new GenericOperationCommand(
            self::OPERATION_ID,
            $this->dispatcher,
            $this->codec,
            $resolver,
        );

        $help = $command->getHelp();

        $this->assertStringContainsString('77 - ' . OperationNotAllowedException::class . '.', $help);
        // The fixed codes are still there, not replaced by the custom ones.
        $this->assertStringContainsString('0 - Success.', $help);
        $this->assertStringContainsString('66 - The input file does not exist', $help);
    }
}
