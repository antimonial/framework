<?php

declare(strict_types=1);

namespace Antimonial\Security;

use RuntimeException;

/**
 * Thrown when a CSRF token check fails.
 *
 * Mirrors Laravel's Illuminate\Session\TokenMismatchException: a simple,
 * typed exception the framework (or app) can catch and turn into a 419.
 */
final class TokenMismatchException extends RuntimeException {}
