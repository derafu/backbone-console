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
use Derafu\BackboneDispatcher\ValueObject\OperationRequest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs exactly one Backbone operation, given a `SafeDispatcherInterface`
 * and the operation's own id — the same class serves every operation a
 * `SafeExplorerInterface` can discover, one instance per operation, never
 * one hand-written `Command` subclass per operation.
 *
 * The request is always a single JSON/YAML/XML file (or STDIN) — never
 * one CLI argument per operation parameter, since a `Worker`'s operations
 * can take arbitrarily different parameters that only reflection (via
 * `SafeExplorerInterface`) knows about at runtime, not this class. The
 * response is written in that same format: whatever format the request
 * came in as.
 */
class GenericOperationCommand extends Command
{
    public function __construct(
        private readonly string $operationId,
        private readonly SafeDispatcherInterface $dispatcher,
        private readonly PayloadCodecInterface $codec,
        private readonly ExitCodeResolverInterface $exitCodeResolver,
        private readonly ?array $operationDoc = null,
    ) {
        parent::__construct(self::commandNameFor($operationId));
    }

    /**
     * Converts a Backbone operation id into a Symfony Console command name.
     *
     * `"package.component.worker::operation"` becomes
     * `"package:component:worker:operation"` — both the `.` hierarchy
     * separator and the `::` operation separator become `:`, the same
     * namespace separator Symfony Console itself already uses by
     * convention (`app:do-something`).
     *
     * @param string $operationId
     * @return string
     */
    public static function commandNameFor(string $operationId): string
    {
        return str_replace(['.', '::'], ':', $operationId);
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        if ($this->operationDoc !== null) {
            $this->setDescription((string) ($this->operationDoc['summary'] ?? ''));
            $this->setHelp($this->buildHelp());
        }

        $this->addArgument(
            'input',
            InputArgument::OPTIONAL,
            'Path to a JSON/YAML/XML file with the request (parameters go '
                . 'under the "parameters" key). Omitted, or "-", reads from STDIN.',
        );
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $this->readInput($input);
        [$format, $data] = $this->codec->decode($raw);
        $parameters = is_array($data['parameters'] ?? null) ? $data['parameters'] : [];

        $request = OperationRequest::fromId($this->operationId, $parameters);
        $result = $this->dispatcher->dispatch($request);

        if ($result->isSuccess()) {
            $output->writeln($this->codec->encode(['data' => $result->getValue()], $format));

            return self::SUCCESS;
        }

        $problem = $result->getProblem();
        $this->errorOutput($output)->writeln($this->codec->encode($problem->toArray(), $format));

        return $this->exitCodeResolver->resolve($problem);
    }

    /**
     * Reads the raw request content from the `input` argument, or STDIN
     * when it is omitted or explicitly `"-"`.
     *
     * @param InputInterface $input
     * @return string
     */
    private function readInput(InputInterface $input): string
    {
        $path = $input->getArgument('input');

        if ($path === null || $path === '-') {
            return (string) file_get_contents('php://stdin');
        }

        return (string) file_get_contents($path);
    }

    /**
     * The dedicated STDERR stream when available, or `$output` itself
     * otherwise — `Application::run()` always provides a
     * `ConsoleOutputInterface` in real usage, this fallback only matters
     * for tests that pass a plain `OutputInterface`.
     *
     * @param OutputInterface $output
     * @return OutputInterface
     */
    private function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;
    }

    /**
     * Builds the `--help` text from the operation's own reflected doc —
     * every parameter's name/type/required/description, since none of
     * them become individual CLI arguments.
     *
     * @return string
     */
    private function buildHelp(): string
    {
        $lines = [];

        $description = $this->operationDoc['description'] ?? null;
        if (!empty($description)) {
            $lines[] = $description;
            $lines[] = '';
        }

        $parameters = $this->operationDoc['parameters'] ?? [];
        if ($parameters === []) {
            return implode("\n", $lines);
        }

        $lines[] = 'Parameters (under the "parameters" key of the input):';
        foreach ($parameters as $parameter) {
            $requirement = ($parameter['required'] ?? false) ? 'required' : 'optional';
            $line = sprintf(
                '  - %s (%s, %s)',
                $parameter['name'] ?? '?',
                $parameter['type'] ?? 'mixed',
                $requirement,
            );

            if (!empty($parameter['description'])) {
                $line .= ': ' . $parameter['description'];
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
