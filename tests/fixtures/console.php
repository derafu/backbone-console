<?php

declare(strict_types=1);

/**
 * Derafu: Backbone Console - Generic Symfony Console Bridge for Backbone Services.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

use Derafu\BackboneConsole\Service\DefaultExitCodeResolver;
use Derafu\BackboneConsole\Service\GenericOperationCommand;
use Derafu\BackboneConsole\Service\PayloadCodec;
use Derafu\TestsBackboneConsole\Fixture\Bootstrap;
use Derafu\Xml\Service\XmlDecoder;
use Derafu\Xml\Service\XmlEncoder;
use Symfony\Component\Console\Application;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$operationId = 'example_package.example_component.example_worker::sum';
$command = new GenericOperationCommand(
    $operationId,
    Bootstrap::boot(),
    new PayloadCodec(new XmlDecoder(), new XmlEncoder()),
    new DefaultExitCodeResolver(),
);

$application = new Application('Backbone console fixture');
$application->addCommand($command);
$application->setDefaultCommand(GenericOperationCommand::commandNameFor($operationId), true);
exit($application->run());
