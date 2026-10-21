<?php

declare(strict_types=1);

/**
 * Derafu: Backbone Console - Operations of the backbone as console commands.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

return [
    'The console {name} path must be string|null; {type} given.' =>
        'La ruta de consola {name} debe ser string|null; se recibió {type}.',
    'A failed operation result must contain problem details.' =>
        'El resultado de una operación fallida debe contener los detalles del problema.',
    'The {field} metadata must be an array; {type} given.' =>
        'Los metadatos de {field} deben ser un arreglo; se recibió {type}.',
    'The {field} metadata must be a string; {type} given.' =>
        'Los metadatos de {field} deben ser un string; se recibió {type}.',
    'The {field} metadata must be a boolean; {type} given.' =>
        'Los metadatos de {field} deben ser un booleano; se recibió {type}.',
    'Command "{name}" does not exist.' =>
        'El comando "{name}" no existe.',
    'The request is not valid JSON: {message}' =>
        'La solicitud no es un JSON válido: {message}',
];
