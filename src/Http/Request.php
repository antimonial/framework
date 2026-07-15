<?php

declare(strict_types=1);

namespace Antimonial\Http;

/**
 * HTTP request wrapper.
 *
 * A thin, concrete wrapper around PHP superglobals ($_GET, $_POST,
 * $_SERVER, $_COOKIE, $_FILES). No PSR-7 inheritance — just the
 * methods you actually need.
 *
 * Request objects also carry an "attributes" bag used to pass data
 * between middleware and controllers (e.g. route parameters, the
 * authenticated user, etc.).
 *
 * @example $request = Request::fromGlobals();
 *
 * @see Response
 * @see \Antimonial\Routing\Router::dispatch()
 */
class Request
{
    /**
     * @var array<string, mixed> Query string parameters ($_GET)
     */
    private array $get;

    /**
     * @var array<string, mixed> POST body parameters ($_POST)
     */
    private array $post;

    /**
     * @var array<string, mixed> Server and environment variables ($_SERVER)
     */
    private array $server;

    /**
     * @var array<string, mixed> Cookie values ($_COOKIE)
     */
    private array $cookies;

    /**
     * @var array<string, array> Uploaded files ($_FILES)
     */
    private array $files;

    /**
     * @var string HTTP method (GET, POST, PUT, DELETE, PATCH)
     */
    private string $method;

    /**
     * @var string Request URI path (no query string)
     */
    private string $uri;

    /**
     * Arbitrary attributes (route params, middleware data).
     *
     * @var array<string, mixed>
     */
    private array $attributes = [];

    /**
     * Raw JSON body cache.
     *
     * @var array<string, mixed>|null
     */
    private ?array $jsonBody = null;

    /**
     * Private constructor — use fromGlobals() factory.
     *
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param array<string, mixed> $cookies
     * @param array<string, array> $files
     */
    private function __construct(
        array $get,
        array $post,
        array $server,
        array $cookies,
        array $files,
    ) {
        $this->get = $get;
        $this->post = $post;
        $this->server = $server;
        $this->cookies = $cookies;
        $this->files = $files;
        $this->method = $this->detectMethod();
        $this->uri = $this->detectUri();
    }

    /**
     * Create a Request from PHP superglobals.
     *
     * @return static
     */
    public static function fromGlobals(): static
    {
        return new static(
            $_GET,
            self::parseInput(),
            $_SERVER,
            $_COOKIE,
            $_FILES,
        );
    }

    /**
     * Build the POST/input array from superglobals.
     *
     * For POST requests $_POST is already populated. For PUT/DELETE/PATCH
     * with an application/x-www-form-urlencoded body, PHP does not populate
     * $_POST, so we parse php://input manually (confirmed by the PHP docs:
     * $_POST is only populated for POST form submissions).
     *
     * @return array<string, mixed>
     */
    private static function parseInput(): array
    {
        $input = $_POST;

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['PUT', 'DELETE', 'PATCH'], true)) {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
                $raw = file_get_contents('php://input');
                if (is_string($raw) && $raw !== '') {
                    parse_str($raw, $parsed);
                    $input = array_merge($input, $parsed);
                }
            }
        }

        return $input;
    }

    /**
     * Get the request URI path.
     *
     * @return string e.g. '/users/42'
     */
    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * Get the HTTP method.
     *
     * @return string Uppercased method name
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Check if the request method is GET.
     *
     * @return bool
     */
    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    /**
     * Check if the request method is POST.
     *
     * @return bool
     */
    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * Check if the request method is PUT.
     *
     * @return bool
     */
    public function isPut(): bool
    {
        return $this->method === 'PUT';
    }

    /**
     * Check if the request method is DELETE.
     *
     * @return bool
     */
    public function isDelete(): bool
    {
        return $this->method === 'DELETE';
    }

    /**
     * Get a value from $_POST or $_GET (POST first, then GET fallback).
     *
     * @example $name = $request->input('name');
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->get[$key] ?? $default;
    }

    /**
     * Get all input data (merged POST + GET).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->get, $this->post);
    }

    /**
     * Check if an input key exists.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->post) || array_key_exists($key, $this->get);
    }

    /**
     * Get a value from $_GET.
     *
     * @example $page = $request->query('page', 1);
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    /**
     * Get a value from $_POST.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Get an uploaded file.
     *
     * @param string $key
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Get a cookie value.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Get an HTTP header value.
     *
     * The header name is normalized: 'Authorization' -> 'HTTP_AUTHORIZATION'.
     *
     * @example $auth = $request->header('Authorization');
     *
     * @param string $name    Header name (e.g. 'Authorization')
     * @param mixed  $default
     * @return mixed
     */
    public function header(string $name, mixed $default = null): mixed
    {
        $normalized = strtoupper(str_replace('-', '_', $name));

        // Per the CGI/1.1 spec, PHP stores Content-Type and Content-Length
        // without the HTTP_ prefix. See https://www.php.net/manual/en/reserved.variables.server.php
        $special = ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'];

        $key = in_array($normalized, $special, true) ? $normalized : 'HTTP_' . $normalized;

        return $this->server[$key] ?? $default;
    }

    /**
     * Decode and return the JSON request body.
     *
     * Reads from php://input and caches the result.
     *
     * @return array<string, mixed>|null Decoded JSON, or null if not valid JSON
     */
    public function json(): ?array
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        $this->jsonBody = is_array($data) ? $data : null;

        return $this->jsonBody;
    }

    /**
     * Set an attribute on the request.
     *
     * Used by middleware and the router to pass data to controllers.
     *
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get a request attribute.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Alias for get() — specifically for route parameters.
     *
     * @example $id = $request->route('id');
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     * @see get()
     */
    public function route(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Detect the HTTP method, respecting method override headers.
     *
     * Supports the X-HTTP-Method-Override header and the _method
     * POST field for clients that don't support PUT/DELETE.
     *
     * @return string
     */
    private function detectMethod(): string
    {
        $method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'POST') {
            $override = $this->server['HTTP_X_HTTP_METHOD_OVERRIDE']
                ?? $this->post['_method']
                ?? null;

            if ($override !== null) {
                $method = strtoupper((string) $override);
            }
        }

        return $method;
    }

    /**
     * Detect the request URI path.
     *
     * Strips the query string and normalizes the path.
     *
     * @return string
     */
    private function detectUri(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';

        // Strip query string
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }

        // Strip base path if SCRIPT_NAME is set
        $script = $this->server['SCRIPT_NAME'] ?? '';
        $base = dirname($script);
        if ($base !== '' && $base !== '/' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }

        return '/' . ltrim($uri, '/');
    }
}
