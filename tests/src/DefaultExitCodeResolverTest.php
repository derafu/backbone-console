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
use Derafu\BackboneDispatcher\ValueObject\ProblemDetail;
use Derafu\BackboneDispatcher\ValueObject\SafeThrowable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DefaultExitCodeResolver::class)]
class DefaultExitCodeResolverTest extends TestCase
{
    public function testResolvesAnyProblemToOne(): void
    {
        $resolver = new DefaultExitCodeResolver();
        $problem = new ProblemDetail(
            detail: 'Oops.',
            throwable: SafeThrowable::fromThrowable(new RuntimeException('Oops.')),
            timestamp: date(DATE_ATOM),
            environment: 'test',
        );

        $this->assertSame(1, $resolver->resolve($problem));
    }
}
