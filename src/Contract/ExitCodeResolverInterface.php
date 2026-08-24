<?php

declare(strict_types=1);

/**
 * Derafu: Backbone Console - Generic Symfony Console Bridge for Backbone Services.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\BackboneConsole\Contract;

use Derafu\BackboneDispatcher\Contract\ProblemDetailInterface;

/**
 * Resolves the CLI exit code for a failed operation.
 *
 * A successful operation always exits `Command::SUCCESS` (0) — resolving is
 * only ever needed for a failure, from whatever `ProblemDetailInterface`
 * `SafeDispatcherInterface::dispatch()` produced.
 *
 * This is exclusively about the *operation's own* outcome, and covers two
 * different kinds of it:
 *
 *   - Generic outcomes of the dispatch mechanism itself — not found, not
 *     allowed, a missing/invalid parameter — already mapped by
 *     `DefaultExitCodeResolver`, since these mean the same thing for any
 *     Backbone-based project regardless of its domain.
 *   - Business-specific outcomes (a domain exception a consuming
 *     project's own Worker throws), which only that project can map — see
 *     `DefaultExitCodeResolver`'s own docblock for how to extend it.
 *
 * It is never consulted for a failure of `GenericOperationCommand` itself
 * (e.g. it could not read the request file or write the response) — those
 * use fixed `sysexits(3)` codes (`GenericOperationCommand::EX_NOINPUT`
 * (66), `::EX_CANTCREAT` (73)) instead, since they are not a
 * `ProblemDetailInterface` to begin with.
 *
 * `1` remains available as the conventional "something went wrong" code —
 * `DefaultExitCodeResolver` itself falls back to it for anything it does
 * not recognize. A resolver that maps additional exceptions of its own
 * should give them codes `>= 10` (`DefaultExitCodeResolver`'s own `10-16`
 * are already taken), avoiding `2` (Symfony Console's own
 * `Command::INVALID`) and the `64-78` range (`sysexits(3)`, already used
 * internally as above).
 */
interface ExitCodeResolverInterface
{
    /**
     * @param ProblemDetailInterface $problem
     * @return int A non-zero exit code.
     */
    public function resolve(ProblemDetailInterface $problem): int;

    /**
     * Describes every non-default mapping this resolver knows about, for
     * documentation purposes only (`GenericOperationCommand` renders this
     * in a command's own `--help`, alongside the fixed framework codes).
     *
     * `resolve()` remains the actual authority: this does not have to be
     * exhaustive, and nothing but `--help` text depends on it staying in
     * sync — but it should. A resolver with nothing beyond the generic
     * `1` for every failure it does not otherwise recognize (there is no
     * requirement to map anything at all) returns `[]` for those.
     *
     * @return array<class-string, int> Exception FQCN => the exit code
     * `resolve()` returns for it.
     */
    public function describe(): array;
}
