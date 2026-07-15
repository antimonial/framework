<?php

declare(strict_types=1);

namespace Antimonial\Core;

use RuntimeException;

/**
 * Thrown when request validation fails.
 *
 * Contains an array of field-level error messages, keyed by field name.
 * The exception code is always 422.
 */
class ValidationException extends RuntimeException
{
    /**
     * @var array<string, string[]> Validation errors keyed by field name
     */
    private array $errors;

    /**
     * @param array<string, string[]> $errors Validation errors
     */
    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct('Validation failed', 422);
    }

    /**
     * Get the validation errors.
     *
     * @return array<string, string[]>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
