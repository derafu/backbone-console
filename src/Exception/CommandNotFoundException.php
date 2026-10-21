<?php

declare(strict_types=1);

/**
 * Derafu: Backbone Console - Operations of the backbone as console commands.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\BackboneConsole\Exception;

use Derafu\Translation\Contract\TranslatableInterface;
use Derafu\Translation\Contract\TranslatableMessageAwareInterface;
use Derafu\Translation\Trait\TranslatableExceptionTrait;
use Symfony\Component\Console\Exception\CommandNotFoundException as SymfonyCommandNotFoundException;
use Throwable;

/**
 * The command does not exist.
 *
 * It is the exception that `CommandLoaderInterface::get()` of Symfony asks for,
 * with a translatable message.
 */
final class CommandNotFoundException extends SymfonyCommandNotFoundException implements TranslatableInterface, TranslatableMessageAwareInterface
{
    use TranslatableExceptionTrait;

    /**
     * The constructor of the trait does not fit here: the one of Symfony has
     * the alternatives between the message and the code.
     *
     * @param string|array<array-key, mixed>|TranslatableInterface $message The message.
     * @param string[] $alternatives Names of similar commands.
     */
    public function __construct(
        string|array|TranslatableInterface $message,
        array $alternatives = [],
        int $code = 0,
        ?Throwable $previous = null
    ) {
        SymfonyCommandNotFoundException::__construct(
            $this->normalizeMessage($message),
            $alternatives,
            $code,
            $previous
        );
    }
}
