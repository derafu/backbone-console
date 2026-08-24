<?php

declare(strict_types=1);

/**
 * Derafu: Backbone Console - Generic Symfony Console Bridge for Backbone Services.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\BackboneConsole\Service;

use Derafu\BackboneConsole\Contract\ExitCodeResolverInterface;
use Derafu\BackboneConsole\Contract\PayloadCodecInterface;
use Derafu\BackboneDispatcher\Contract\SafeDispatcherInterface;
use Derafu\BackboneDispatcher\Contract\SafeExplorerInterface;
use Derafu\Xml\Service\XmlDecoder;
use Derafu\Xml\Service\XmlEncoder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/**
 * Builds one `GenericOperationCommand` per real operation a
 * `SafeExplorerInterface` discovers — on demand, never all of them at
 * once: a business library can expose hundreds of operations, and a
 * single `bin/console` invocation only ever needs one command built.
 */
class OperationCommandLoader implements CommandLoaderInterface
{
    /**
     * @var array<string, array>|null Command name => the operation's own
     * doc array (as `SafeExplorerInterface::tree()` returns it, `'id'`
     * included) — built once, on first use.
     */
    private ?array $operations = null;

    public function __construct(
        private readonly SafeExplorerInterface $explorer,
        private readonly SafeDispatcherInterface $dispatcher,
        private readonly PayloadCodecInterface $codec = new PayloadCodec(
            new XmlDecoder(),
            new XmlEncoder(),
        ),
        private readonly ExitCodeResolverInterface $exitCodeResolver = new DefaultExitCodeResolver(),
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $name): bool
    {
        return isset($this->getOperations()[$name]);
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $name): Command
    {
        $operation = $this->getOperations()[$name] ?? null;

        if ($operation === null) {
            throw new CommandNotFoundException(
                sprintf('Command "%s" does not exist.', $name),
            );
        }

        return new GenericOperationCommand(
            $operation['id'],
            $this->dispatcher,
            $this->codec,
            $this->exitCodeResolver,
            $operation,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getNames(): array
    {
        return array_keys($this->getOperations());
    }

    /**
     * Walks `SafeExplorerInterface::tree()` once, mapping every operation
     * found to its own Symfony Console command name.
     *
     * @return array<string, array>
     */
    private function getOperations(): array
    {
        if ($this->operations !== null) {
            return $this->operations;
        }

        $result = $this->explorer->tree();
        $tree = $result->isSuccess() ? $result->getValue() : [];

        $operations = [];
        foreach ($tree as $package) {
            foreach ($package['components'] ?? [] as $component) {
                foreach ($component['workers'] ?? [] as $worker) {
                    foreach ($worker['operations'] ?? [] as $operation) {
                        $name = GenericOperationCommand::commandNameFor($operation['id']);
                        $operations[$name] = $operation;
                    }
                }
            }
        }

        return $this->operations = $operations;
    }
}
