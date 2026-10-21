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
use Derafu\BackboneConsole\Exception\CommandNotFoundException;
use Derafu\BackboneDispatcher\Contract\SafeDispatcherInterface;
use Derafu\BackboneDispatcher\Contract\SafeExplorerInterface;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Derafu\Xml\Service\XmlDecoder;
use Derafu\Xml\Service\XmlEncoder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

/**
 * Builds one `GenericOperationCommand` per real operation a
 * `SafeExplorerInterface` discovers — on demand, never all of them at
 * once: a business library can expose hundreds of operations, and a
 * single `bin/console` invocation only ever needs one command built.
 */
class OperationCommandLoader implements CommandLoaderInterface
{
    /**
     * @var array<string, array{id: string}>|null Command name => the operation's own
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
            throw new CommandNotFoundException([
                'Command "{name}" does not exist.',
                'name' => $name,
            ]);
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
     * Walks `SafeExplorerInterface::tree()`'s `'packages'` once, mapping
     * every operation found to its own Symfony Console command name — the
     * sibling `'description'` key (about the whole package registry, not
     * any one operation) is not relevant here.
     *
     * @return array<string, array{id: string}>
     */
    private function getOperations(): array
    {
        if ($this->operations !== null) {
            return $this->operations;
        }

        $result = $this->explorer->tree();
        $packages = [];
        if ($result->isSuccess()) {
            $tree = $this->metadataArray($result->getValue(), 'tree');
            $packages = $this->metadataArray($tree['packages'] ?? null, 'packages');
        }

        $operations = [];
        foreach ($packages as $package) {
            $package = $this->metadataArray($package, 'package');
            foreach ($this->metadataArray($package['components'] ?? [], 'components') as $component) {
                $component = $this->metadataArray($component, 'component');
                foreach ($this->metadataArray($component['workers'] ?? [], 'workers') as $worker) {
                    $worker = $this->metadataArray($worker, 'worker');
                    foreach ($this->metadataArray($worker['operations'] ?? [], 'operations') as $operation) {
                        $operation = $this->metadataArray($operation, 'operation');
                        if (!isset($operation['id']) || !is_string($operation['id'])) {
                            throw new InvalidArgumentException([
                                'The {field} metadata must be a string; {type} given.',
                                'field' => 'operation.id',
                                'type' => get_debug_type($operation['id'] ?? null),
                            ]);
                        }
                        $name = GenericOperationCommand::commandNameFor($operation['id']);
                        $operations[$name] = $operation;
                    }
                }
            }
        }

        return $this->operations = $operations;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function metadataArray(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException([
                'The {field} metadata must be an array; {type} given.',
                'field' => $field,
                'type' => get_debug_type($value),
            ]);
        }

        return $value;
    }
}
