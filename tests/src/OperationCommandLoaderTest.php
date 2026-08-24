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
use Derafu\TestsBackboneConsole\Fixture\Bootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration test: real `SafeExplorerInterface`/`SafeDispatcherInterface`
 * underneath (`Bootstrap`) — no mocks.
 */
#[CoversClass(OperationCommandLoader::class)]
#[UsesClass(GenericOperationCommand::class)]
#[UsesClass(PayloadCodec::class)]
#[UsesClass(DefaultExitCodeResolver::class)]
class OperationCommandLoaderTest extends TestCase
{
    private const COMMAND_NAME = 'example_package:example_component:example_worker:sum';

    private OperationCommandLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new OperationCommandLoader(
            Bootstrap::bootExplorer(),
            Bootstrap::boot(),
        );
    }

    public function testGetNamesIncludesTheRealSumCommand(): void
    {
        $this->assertContains(self::COMMAND_NAME, $this->loader->getNames());
    }

    public function testHasIsTrueForARealCommandAndFalseForAnUnknownOne(): void
    {
        $this->assertTrue($this->loader->has(self::COMMAND_NAME));
        $this->assertFalse($this->loader->has('does:not:exist'));
    }

    public function testGetBuildsARealCommandThatDispatchesSuccessfully(): void
    {
        $command = $this->loader->get(self::COMMAND_NAME);

        $path = tempnam(sys_get_temp_dir(), 'backbone-console-test-');
        file_put_contents($path, '{"parameters": {"a": 5, "b": 7}}');

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['input' => $path]);
        unlink($path);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(['data' => 12], json_decode($tester->getDisplay(), true));
    }

    public function testGetThrowsCommandNotFoundExceptionForAnUnknownName(): void
    {
        $this->expectException(CommandNotFoundException::class);

        $this->loader->get('does:not:exist');
    }
}
