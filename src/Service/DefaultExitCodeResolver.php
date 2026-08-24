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
use Derafu\BackboneDispatcher\Contract\ProblemDetailInterface;
use Derafu\BackboneDispatcher\Exception\ClassNotFoundException;
use Derafu\BackboneDispatcher\Exception\FromArrayMethodNotFoundException;
use Derafu\BackboneDispatcher\Exception\InvalidParameterTypeException;
use Derafu\BackboneDispatcher\Exception\MissingParameterException;
use Derafu\BackboneDispatcher\Exception\NoDeserializerFoundException;
use Derafu\BackboneDispatcher\Exception\OperationNotAllowedException;
use Derafu\BackboneDispatcher\Exception\OperationNotFoundException;

/**
 * Maps `derafu/backbone-dispatcher`'s own generic exceptions to distinct
 * exit codes; anything else (a business-specific exception a consuming
 * project's own Worker throws) falls back to the conventional `1`.
 *
 * These 7 are not business failures — they are outcomes of the dispatch
 * mechanism itself (`DirectDispatcher`/`Resolver`/`Caster`/
 * `ObjectFactoryRegistry`), reachable from *any* Backbone-based project
 * regardless of its domain, so mapping them here (once) is meaningful in a
 * way mapping a business exception here would not be. `derafu/backbone-api`
 * already does the equivalent for HTTP status codes in its own
 * `Documenter::resolveHttpStatus()` — this mirrors that, for exit codes.
 *
 * A project that also wants its *own* exceptions mapped extends this
 * class rather than starting from nothing:
 *
 *   class MyExitCodeResolver extends DefaultExitCodeResolver
 *   {
 *       public function resolve(ProblemDetailInterface $problem): int
 *       {
 *           return match ($problem->getThrowable()->getClass()) {
 *               MyBusinessException::class => 20,
 *               default => parent::resolve($problem),
 *           };
 *       }
 *
 *       public function describe(): array
 *       {
 *           return [MyBusinessException::class => 20] + parent::describe();
 *       }
 *   }
 *
 * The 7 codes below start at `10`, clear of Symfony Console's own reserved
 * `0`/`1`/`2` and of `GenericOperationCommand`'s `sysexits(3)` `64-78`
 * range — see `ExitCodeResolverInterface`.
 */
class DefaultExitCodeResolver implements ExitCodeResolverInterface
{
    public const OPERATION_NOT_FOUND = 10;

    public const OPERATION_NOT_ALLOWED = 11;

    public const MISSING_PARAMETER = 12;

    public const INVALID_PARAMETER_TYPE = 13;

    public const CLASS_NOT_FOUND = 14;

    public const FROM_ARRAY_METHOD_NOT_FOUND = 15;

    public const NO_DESERIALIZER_FOUND = 16;

    /**
     * @var array<class-string, int>
     */
    private const EXIT_CODES = [
        OperationNotFoundException::class => self::OPERATION_NOT_FOUND,
        OperationNotAllowedException::class => self::OPERATION_NOT_ALLOWED,
        MissingParameterException::class => self::MISSING_PARAMETER,
        InvalidParameterTypeException::class => self::INVALID_PARAMETER_TYPE,
        ClassNotFoundException::class => self::CLASS_NOT_FOUND,
        FromArrayMethodNotFoundException::class => self::FROM_ARRAY_METHOD_NOT_FOUND,
        NoDeserializerFoundException::class => self::NO_DESERIALIZER_FOUND,
    ];

    /**
     * {@inheritDoc}
     */
    public function resolve(ProblemDetailInterface $problem): int
    {
        return self::EXIT_CODES[$problem->getThrowable()->getClass()] ?? 1;
    }

    /**
     * {@inheritDoc}
     */
    public function describe(): array
    {
        return self::EXIT_CODES;
    }
}
