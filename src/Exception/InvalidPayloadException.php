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
use JsonException;

/**
 * The payload of the request looks like JSON, but it is not valid.
 *
 * It is a `JsonException`, with a translatable message. The code is the one of
 * `json_last_error()`.
 */
final class InvalidPayloadException extends JsonException implements TranslatableInterface, TranslatableMessageAwareInterface
{
    use TranslatableExceptionTrait;
}
