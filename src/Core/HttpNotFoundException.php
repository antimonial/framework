<?php

declare(strict_types=1);

namespace Antimonial\Core;

use RuntimeException;

/**
 * Thrown when a requested route does not match any defined route.
 *
 * The exception code is always 404.
 */
class HttpNotFoundException extends RuntimeException
{
    /**
     * @param string $message Description of the missing resource
     */
    public function __construct(string $message = 'Not Found')
    {
        parent::__construct($message, 404);
    }
}
