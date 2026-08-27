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
use Derafu\BackboneConsole\ValueObject\PayloadFormat;
use Derafu\BackboneDispatcher\Contract\SafeDispatcherInterface;
use Derafu\BackboneDispatcher\ValueObject\OperationRequest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

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
 *
 * The response payload's shape does not depend on where it is written
 * (STDOUT/STDERR vs `--output`/`--error-output`) — only on `-v`/
 * `--verbose`, and only for how *much* ends up in it, never whether the
 * envelope itself is there. A successful response is always
 * `{"meta": {"timestamp": ..., "data_type": ...}, "data": ...}` — the
 * same envelope [Backbone API](/docs/core/backbone-api) uses, so a caller
 * does not have to special-case which transport it is talking to. A
 * failed one is always `OperationResultInterface::getProblem()->toArray()`
 * (`extensions.timestamp`/`extensions.data_type` included there too,
 * `data_type` always `null` — there is no value to describe). With `-v`,
 * `OperationResultInterface::getMetadata()`'s remaining fields (timing,
 * memory, CPU, load average) are merged in — into `meta` on success,
 * into `extensions` on failure (next to the already-present
 * `debug`/`context`/`throwable`) — never introducing a new top-level key.
 */
class GenericOperationCommand extends Command
{
    /**
     * `sysexits(3)`: "An input file did not exist or was not readable."
     */
    public const EX_NOINPUT = 66;

    /**
     * `sysexits(3)`: "A (user specified) output file cannot be created."
     */
    public const EX_CANTCREAT = 73;

    /**
     * `sysexits(3)`: "The input data was incorrect in some way."
     */
    public const EX_DATAERR = 65;

    /**
     * `sysexits(3)`: "An internal software error has been detected."
     *
     * The last-resort exit code: anything genuinely unexpected — a bug,
     * not a usage or business-level problem — that would otherwise escape
     * uncaught and let Symfony Console's own default exception rendering
     * take over, breaking the promise that a caller (of any language,
     * across a process boundary) always gets a structured response.
     */
    public const EX_SOFTWARE = 70;

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
        }

        $this->setHelp($this->buildHelp());

        $this->addArgument(
            'input',
            InputArgument::OPTIONAL,
            'Path to a JSON/YAML/XML file with the request (parameters go '
                . 'under the "parameters" key). Omitted, or "-", reads from STDIN.',
        );

        $this->addOption(
            'output',
            null,
            InputOption::VALUE_REQUIRED,
            'Path to write the result to on success, instead of STDOUT. Omitted, or "-", writes to STDOUT. '
                . 'The format is inferred from the extension (.json/.yaml/.yml/.xml); an unrecognized or '
                . 'missing one falls back to the request\'s own format.',
        );

        $this->addOption(
            'error-output',
            null,
            InputOption::VALUE_REQUIRED,
            'Path to write the problem detail to on failure, instead of STDERR. Omitted, or "-", writes to '
                . 'STDERR. Same format-inference rule as --output. Never used on success.',
        );
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $this->readInput($input, $output);
        if ($raw === null) {
            return self::EX_NOINPUT;
        }

        try {
            [$format, $data] = $this->codec->decode($raw);
        } catch (Throwable $e) {
            $this->errorOutput($output)->writeln(sprintf(
                'Could not parse the request: %s',
                $e->getMessage(),
            ));

            return self::EX_DATAERR;
        }

        // From here on, nothing should throw: dispatching itself never
        // does (that is the whole point of SafeDispatcherInterface), and
        // OperationRequest::fromId()/encode()/the exit code resolver only
        // could on a genuine bug (a malformed operation id from a broken
        // SafeExplorerInterface wiring, a business value this codec's own
        // encoders cannot represent, a resolver that misbehaves) rather
        // than anything a caller did wrong. Caught here as a last resort
        // so it still degrades into a structured message instead of
        // Symfony Console's own default exception rendering.
        try {
            $parameters = is_array($data['parameters'] ?? null) ? $data['parameters'] : [];

            $request = OperationRequest::fromId($this->operationId, $parameters);
            $result = $this->dispatcher->dispatch($request);

            if ($result->isSuccess()) {
                $outputPath = $input->getOption('output');
                $meta = ['timestamp' => $result->getMetadata()->getTimestamp()];
                if ($output->isVerbose()) {
                    $meta += $result->getMetadata()->toArray();
                }
                $meta['data_type'] = $result->getDataType();

                $content = $this->codec->encode(
                    ['meta' => $meta, 'data' => $result->getValue()],
                    $this->resolveFormat($outputPath, $format),
                );

                if (!$this->write($outputPath, $content, $output)) {
                    return self::EX_CANTCREAT;
                }

                return self::SUCCESS;
            }

            $problem = $result->getProblem();
            $payload = $problem->toArray();
            if ($output->isVerbose()) {
                $payload['extensions'] += $result->getMetadata()->toArray();
            }

            $errorOutputPath = $input->getOption('error-output');
            $content = $this->codec->encode(
                $payload,
                $this->resolveFormat($errorOutputPath, $format),
            );

            if (!$this->write($errorOutputPath, $content, $output, toErrorStream: true)) {
                return self::EX_CANTCREAT;
            }

            return $this->exitCodeResolver->resolve($problem);
        } catch (Throwable $e) {
            $this->errorOutput($output)->writeln(sprintf(
                'Unexpected error: %s',
                $e->getMessage(),
            ));

            return self::EX_SOFTWARE;
        }
    }

    /**
     * Resolves the format a response should be written in: the given
     * path's own extension if recognized, or the request's format
     * otherwise (including when no path was given at all, i.e. STDOUT/
     * STDERR).
     *
     * @param string|null $path
     * @param PayloadFormat $requestFormat
     * @return PayloadFormat
     */
    private function resolveFormat(?string $path, PayloadFormat $requestFormat): PayloadFormat
    {
        if ($path === null || $path === '-') {
            return $requestFormat;
        }

        return PayloadFormat::fromExtension($path) ?? $requestFormat;
    }

    /**
     * Writes `$content` to `$path`, or to STDOUT/STDERR when `$path` is
     * `null` or `"-"`.
     *
     * A failure to write to a real file is never silent: it is reported to
     * the real STDERR (regardless of `$toErrorStream`, since that flag only
     * picks the *default* stream for "no path given" — a write failure is
     * a failure of this command itself, not of the operation it ran), and
     * this method returns `false` so the caller never reports success.
     *
     * @param string|null $path
     * @param string $content
     * @param OutputInterface $output
     * @param bool $toErrorStream Whether the default stream (used when
     * `$path` is `null`/`"-"`) should be STDERR instead of STDOUT.
     * @return bool `true` on success, `false` if writing to `$path` failed.
     */
    private function write(
        ?string $path,
        string $content,
        OutputInterface $output,
        bool $toErrorStream = false,
    ): bool {
        if ($path === null || $path === '-') {
            ($toErrorStream ? $this->errorOutput($output) : $output)->writeln($content);

            return true;
        }

        if (@file_put_contents($path, $content . "\n") === false) {
            $reason = error_get_last()['message'] ?? 'unknown error';
            $this->errorOutput($output)->writeln(sprintf(
                'Could not write to "%s": %s',
                $path,
                $reason,
            ));

            return false;
        }

        return true;
    }

    /**
     * Reads the raw request content from the `input` argument, or STDIN
     * when it is omitted or explicitly `"-"`.
     *
     * A failure to read a real file is never silent: `file_get_contents()`
     * would otherwise return `false`, which a blind `(string)` cast turns
     * into `""` — silently proceeding with an empty request instead of
     * the one actually asked for, surfacing later (if at all) as a
     * misleading business-level `ProblemDetail` (e.g. "missing parameter")
     * that has nothing to do with the real cause. Reported to the real
     * STDERR, and `null` returned so the caller never proceeds with it.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return string|null
     */
    private function readInput(InputInterface $input, OutputInterface $output): ?string
    {
        $path = $input->getArgument('input');

        if ($path === null || $path === '-') {
            return (string) file_get_contents('php://stdin');
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            $reason = error_get_last()['message'] ?? 'unknown error';
            $this->errorOutput($output)->writeln(sprintf(
                'Could not read from "%s": %s',
                $path,
                $reason,
            ));

            return null;
        }

        return $content;
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
     * Builds the `--help` text out of independent blocks (description,
     * parameters, returns, throws, exit codes, links, the verbose note),
     * each either the operation's own reflected doc (`$operationDoc`, the
     * same dict `Documenter` in `derafu/backbone-api` reads — `returns`/
     * `throws`/`links` included) or fixed text this class always shows.
     * A block that has nothing to say returns `[]` and is dropped
     * entirely — no empty section header, no stray blank line — with
     * exactly one blank line separating whichever blocks did produce
     * something.
     *
     * @return string
     */
    private function buildHelp(): string
    {
        $description = $this->operationDoc['description'] ?? null;

        $blocks = [
            !empty($description) ? [$description] : [],
            $this->buildParametersHelp(),
            $this->buildReturnsHelp(),
            $this->buildThrowsHelp(),
            $this->buildExitCodesHelp(),
            $this->buildLinksHelp(),
            [
                'With -v/--verbose, the response also includes execution metadata '
                    . '(timing, memory, CPU, load average): "metadata" alongside "data" on '
                    . 'success, "extensions.metadata" on failure.',
            ],
        ];

        $lines = [];
        foreach (array_filter($blocks) as $block) {
            if ($lines !== []) {
                $lines[] = '';
            }
            array_push($lines, ...$block);
        }

        return implode("\n", $lines);
    }

    /**
     * Builds the parameters section of `--help`: every parameter's own
     * name/type/required/description, since none of them become
     * individual CLI arguments.
     *
     * @return list<string>
     */
    private function buildParametersHelp(): array
    {
        $parameters = $this->operationDoc['parameters'] ?? [];
        if ($parameters === []) {
            return [];
        }

        $lines = ['Parameters (under the "parameters" key of the input):'];
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

        return $lines;
    }

    /**
     * Builds the "Returns:" section of `--help`: the operation's reflected
     * return type and `@return` description (`Inspector`'s `returns`) —
     * skipped entirely for a `void`- or `never`-returning operation, since
     * there is nothing to describe in either case (the second one never
     * even completes normally). When `#[Operation(results: ['success' =>
     * ['example' => ...]])]` provides one, a JSON-encoded example follows
     * — the only source of a realistic response example, the same way
     * `parameters[x]['example']` is attribute-only (reflection/PHPDoc
     * alone can never produce one).
     *
     * @return list<string>
     */
    private function buildReturnsHelp(): array
    {
        $returns = $this->operationDoc['returns'] ?? null;
        if (empty($returns['type']) || in_array($returns['type'], ['void', 'never'], true)) {
            return [];
        }

        $lines = ['Returns:'];

        $line = '  ' . $returns['type'];
        if (!empty($returns['description'])) {
            $line .= ' — ' . $returns['description'];
        }
        $lines[] = $line;

        $results = $this->operationDoc['operation']['results'] ?? [];
        $example = $results['success']['example'] ?? null;
        if ($example !== null) {
            $lines[] = '  Example:';
            $encoded = json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            foreach (explode("\n", (string) $encoded) as $exampleLine) {
                $lines[] = '    ' . $exampleLine;
            }
        }

        return $lines;
    }

    /**
     * Builds the "Throws:" section of `--help`: every declared `@throws`
     * with its description — distinct from "Exit codes:" below, which
     * maps each one to the numeric code a caller actually gets back, not
     * to *why* it happens.
     *
     * @return list<string>
     */
    private function buildThrowsHelp(): array
    {
        $throws = $this->operationDoc['throws'] ?? [];
        if ($throws === []) {
            return [];
        }

        $lines = ['Throws:'];
        foreach ($throws as $throw) {
            $line = '  - ' . ($throw['type'] ?? '?');
            if (!empty($throw['description'])) {
                $line .= ': ' . $throw['description'];
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Builds the exit codes section of `--help`: the fixed codes this
     * class itself can return (`SUCCESS`, the generic `FAILURE`, and the
     * `sysexits(3)` ones for its own execution failures), plus whatever
     * `ExitCodeResolverInterface::describe()` reports for this specific
     * command's injected resolver — combining the framework's defaults
     * with what a project registered on top of them, so neither has to be
     * looked up separately to know the full picture. Always shown, even
     * without an `$operationDoc`, since the codes are a property of this
     * class and the injected `ExitCodeResolverInterface`, not of the
     * operation itself.
     *
     * @return list<string>
     */
    private function buildExitCodesHelp(): array
    {
        $lines = [
            'Exit codes:',
            sprintf('  %d - Success.', self::SUCCESS),
            sprintf('  %d - The operation failed.', self::FAILURE),
        ];

        foreach ($this->exitCodeResolver->describe() as $exceptionClass => $code) {
            $lines[] = sprintf('  %d - %s.', $code, $exceptionClass);
        }

        $lines[] = sprintf(
            '  %d - Malformed request data (could not parse it as JSON/YAML/XML).',
            self::EX_DATAERR,
        );
        $lines[] = sprintf(
            '  %d - The input file does not exist or is not readable.',
            self::EX_NOINPUT,
        );
        $lines[] = sprintf('  %d - Unexpected internal error.', self::EX_SOFTWARE);
        $lines[] = sprintf('  %d - The output file could not be created.', self::EX_CANTCREAT);

        return $lines;
    }

    /**
     * Builds the "Links:" section of `--help`: every `@link` on the
     * operation, plain text — unlike `derafu/backbone-api`'s `Documenter`,
     * there is no OpenAPI `externalDocs` single-slot constraint to work
     * around here (a CLI's `--help` is not a rich-text renderer either),
     * so every link is always listed the same way regardless of how many
     * there are.
     *
     * @return list<string>
     */
    private function buildLinksHelp(): array
    {
        $links = $this->operationDoc['links'] ?? [];
        if ($links === []) {
            return [];
        }

        $lines = ['Links:'];
        foreach ($links as $link) {
            $line = '  - ' . ($link['url'] ?? '');
            if (!empty($link['description'])) {
                $line .= ': ' . $link['description'];
            }
            $lines[] = $line;
        }

        return $lines;
    }
}
