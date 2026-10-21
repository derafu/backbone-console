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

use Derafu\BackboneConsole\Service\GenericOperationCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(GenericOperationCommand::class)]
final class ConsoleStdinTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function testARealConsoleProcessReadsTheRequestFromStdin(bool $explicitDash): void
    {
        $command = [PHP_BINARY, dirname(__DIR__) . '/fixtures/console.php'];
        if ($explicitDash) {
            $command[] = '-';
        }
        $process = proc_open($command, [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($process);

        fwrite($pipes[0], '{"parameters":{"a":5,"b":7}}');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $this->assertSame(0, $exit);
        $this->assertSame('', $stderr);
        $this->assertIsString($stdout);
        $response = json_decode($stdout, true);
        $this->assertIsArray($response);
        $this->assertSame(12, $response['data']);
        $this->assertIsArray($response['meta']);
        $this->assertSame('integer', $response['meta']['data_type']);
        $this->assertIsFloat($response['meta']['timestamp']);
    }
}
