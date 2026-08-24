<?php

declare(strict_types=1);

/**
 * Derafu: Backbone Console - Generic Symfony Console Bridge for Backbone Services.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsBackboneConsole\Fixture;

use Derafu\BackboneDispatcher\Contract\SafeDispatcherInterface;
use Derafu\BackboneDispatcher\Contract\SafeExplorerInterface;
use Derafu\BackboneDispatcher\Service\Deserialization\FromArrayDeserializer;
use Derafu\BackboneDispatcher\Service\Deserialization\ObjectFactoryRegistry;
use Derafu\BackboneDispatcher\Service\Discovery\Explorer;
use Derafu\BackboneDispatcher\Service\Discovery\SafeExplorer;
use Derafu\BackboneDispatcher\Service\Dispatch\DirectDispatcher;
use Derafu\BackboneDispatcher\Service\Dispatch\SafeDispatcher;
use Derafu\BackboneDispatcher\Service\Dispatch\TypedDispatcher;
use Derafu\BackboneDispatcher\Service\Reflection\Inspector;
use Derafu\BackboneDispatcher\Service\Resolution\Caster;
use Derafu\BackboneDispatcher\Service\Resolution\Resolver;
use Derafu\BackboneDispatcher\Service\Resolution\Validator;
use Derafu\BackboneDispatcher\Service\Serialization\Serializer;

/**
 * Builds a real `SafeDispatcherInterface`/`SafeExplorerInterface` pair,
 * wired by hand (no DI container needed, on purpose: this fixture only
 * exists to give this package's own test suite something real to
 * dispatch/explore against, not to exercise DI wiring).
 */
final class Bootstrap
{
    public static function boot(): SafeDispatcherInterface
    {
        [$registry, $inspector] = self::buildRegistry();

        $directDispatcher = new DirectDispatcher(
            $registry,
            $inspector,
            new Resolver(
                $inspector,
                new Caster(new ObjectFactoryRegistry(fallback: new FromArrayDeserializer())),
                new Validator(),
            ),
        );

        return new SafeDispatcher(
            new TypedDispatcher($directDispatcher),
            new Serializer(),
            'test',
            true,
        );
    }

    public static function bootExplorer(): SafeExplorerInterface
    {
        [$registry, $inspector] = self::buildRegistry();

        return new SafeExplorer(
            new Explorer($registry, $inspector),
            environment: 'test',
            debug: true,
        );
    }

    private static function buildRegistry(): array
    {
        $worker = new ExampleWorker();
        $component = new ExampleComponent(['example_worker' => $worker]);
        $package = new ExamplePackage(['example_component' => $component]);

        $registry = new ExamplePackageRegistry();
        $registry->registerPackage('example_package', $package);

        return [$registry, new Inspector()];
    }
}
