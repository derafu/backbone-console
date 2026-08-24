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
use Derafu\BackboneDispatcher\Exception\ClassNotFoundException;
use Derafu\BackboneDispatcher\Exception\FromArrayMethodNotFoundException;
use Derafu\BackboneDispatcher\Exception\InvalidParameterTypeException;
use Derafu\BackboneDispatcher\Exception\MissingParameterException;
use Derafu\BackboneDispatcher\Exception\NoDeserializerFoundException;
use Derafu\BackboneDispatcher\Exception\OperationNotAllowedException;
use Derafu\BackboneDispatcher\Exception\OperationNotFoundException;
use Derafu\BackboneDispatcher\ValueObject\ProblemDetail;
use Derafu\BackboneDispatcher\ValueObject\SafeThrowable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

#[CoversClass(DefaultExitCodeResolver::class)]
class DefaultExitCodeResolverTest extends TestCase
{
    private function problemFor(Throwable $throwable): ProblemDetail
    {
        return new ProblemDetail(
            detail: $throwable->getMessage(),
            throwable: SafeThrowable::fromThrowable($throwable),
            timestamp: microtime(true),
            environment: 'test',
        );
    }

    public function testResolvesAnUnrecognizedExceptionToTheGenericOne(): void
    {
        $resolver = new DefaultExitCodeResolver();

        $this->assertSame(1, $resolver->resolve($this->problemFor(new RuntimeException('Oops.'))));
    }

    /**
     * @return array<string, array{class-string<Throwable>, int}>
     */
    public static function dispatcherExceptions(): array
    {
        return [
            'not found' => [OperationNotFoundException::class, DefaultExitCodeResolver::OPERATION_NOT_FOUND],
            'not allowed' => [OperationNotAllowedException::class, DefaultExitCodeResolver::OPERATION_NOT_ALLOWED],
            'missing parameter' => [MissingParameterException::class, DefaultExitCodeResolver::MISSING_PARAMETER],
            'invalid parameter type' => [InvalidParameterTypeException::class, DefaultExitCodeResolver::INVALID_PARAMETER_TYPE],
            'class not found' => [ClassNotFoundException::class, DefaultExitCodeResolver::CLASS_NOT_FOUND],
            'from array method not found' => [FromArrayMethodNotFoundException::class, DefaultExitCodeResolver::FROM_ARRAY_METHOD_NOT_FOUND],
            'no deserializer found' => [NoDeserializerFoundException::class, DefaultExitCodeResolver::NO_DESERIALIZER_FOUND],
        ];
    }

    #[DataProvider('dispatcherExceptions')]
    public function testResolvesEachOfTheDispatchersOwnGenericExceptionsToItsOwnCode(
        string $exceptionClass,
        int $expectedCode,
    ): void {
        $resolver = new DefaultExitCodeResolver();

        // Constructed via reflection, not `new $exceptionClass(...)`: each
        // one has its own real constructor shape (some take a translated
        // message, others take the id/class involved), which is not this
        // test's concern — only that resolve() keys off the class, not
        // the constructor arguments.
        $throwable = (new ReflectionClass($exceptionClass))
            ->newInstanceWithoutConstructor()
        ;

        $this->assertSame($expectedCode, $resolver->resolve($this->problemFor($throwable)));
    }

    public function testDescribesAllSevenDispatcherExceptions(): void
    {
        $resolver = new DefaultExitCodeResolver();

        $this->assertCount(7, $resolver->describe());
        $this->assertSame(
            DefaultExitCodeResolver::OPERATION_NOT_FOUND,
            $resolver->describe()[OperationNotFoundException::class],
        );
    }
}
