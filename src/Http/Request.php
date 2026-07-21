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
 * @phpstan-consistent-constructor
 *
 * @see Response
 * @see Router::dispatch()
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
     * @var array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}> Uploaded files ($_FILES)
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
     * Protected constructor — use fromGlobals() factory.
     *
     * @param  array<string, mixed>  $get
     * @param  array<string, mixed>  $post
     * @param  array<string, mixed>  $server
     * @param  array<string, mixed>  $cookies
     * @param  array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}>  $files
     */
    protected function __construct(
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
     * @return static A new request instance
     */
    public static function fromGlobals(): static
    {
        /** @var array<string, mixed> $get */
        $get = $_GET;
        /** @var array<string, mixed> $post */
        $post = self::parseInput();
        /** @var array<string, mixed> $server */
        $server = $_SERVER;
        /** @var array<string, mixed> $cookies */
        $cookies = $_COOKIE;
        /** @var array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}> $files */
        $files = $_FILES;

        return new static($get, $post, $server, $cookies, $files);
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
        /** @var array<string, mixed> $input */
        $input = [];
        foreach ($_POST as $key => $value) {
            $input[(string) $key] = $value;
        }

        /** @var string $requestMethod */
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $method = strtoupper($requestMethod);
        if (in_array($method, ['PUT', 'DELETE', 'PATCH'], true)) {
            /** @var string $contentType */
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
                $raw = file_get_contents('php://input');
                if (is_string($raw) && $raw !== '') {
                    parse_str($raw, $parsed);
                    foreach ((array) $parsed as $pk => $pv) {
                        $input[(string) $pk] = $pv;
                    }
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
     * @return bool True if GET request
     */
    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    /**
     * Check if the request method is POST.
     *
     * @return bool True if POST request
     */
    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * Check if the request method is PUT.
     *
     * @return bool True if PUT request
     */
    public function isPut(): bool
    {
        return $this->method === 'PUT';
    }

    /**
     * Check if the request method is DELETE.
     *
     * @return bool True if DELETE request
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
     * @param  string  $key  Input key
     * @param  mixed  $default  Default if not found
     * @return mixed The value, or default
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
     * @param  string  $key  Input key
     * @return bool True if the key is present in POST or GET
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
     * @param  string  $key  Query parameter key
     * @param  mixed  $default  Default if not found
     * @return mixed The value, or default
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    /**
     * Get a value from $_POST.
     *
     * @param  string  $key  POST parameter key
     * @param  mixed  $default  Default if not found
     * @return mixed The value, or default
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Get an uploaded file.
     *
     * Returns an UploadedFile wrapper for the given key, or null if the
     * key is not present in $_FILES.
     */
    public function file(string $key): ?UploadedFile
    {
        if (! isset($this->files[$key])) {
            return null;
        }

        /** @var array{name: string, type: string, tmp_name: string, error: int, size: int} $entry */
        $entry = $this->files[$key];

        return new UploadedFile($entry);
    }

    /**
     * Get a cookie value.
     *
     * @param  string  $key  Cookie name
     * @param  mixed  $default  Default if not found
     * @return mixed The cookie value, or default
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
     * @param  string  $name  Header name (e.g. 'Authorization')
     * @param  mixed  $default  Default if not found
     * @return mixed The header value, or default
     */
    public function header(string $name, mixed $default = null): mixed
    {
        $normalized = strtoupper(str_replace('-', '_', $name));

        // Per the CGI/1.1 spec, PHP stores Content-Type and Content-Length
        // without the HTTP_ prefix. See https://www.php.net/manual/en/reserved.variables.server.php
        $special = ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'];

        $key = in_array($normalized, $special, true) ? $normalized : 'HTTP_'.$normalized;

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
        /** @var array<string, mixed>|null $data */
        $data = is_string($raw) ? json_decode($raw, true) : null;

        $this->jsonBody = is_array($data) ? $data : null;

        return $this->jsonBody;
    }

    /**
     * Set an attribute on the request.
     *
     * Used by middleware and the router to pass data to controllers.
     *
     * @param  string  $key  Attribute name
     * @param  mixed  $value  Attribute value
     */
    public function set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get a request attribute.
     *
     * @param  string  $key  Attribute name
     * @param  mixed  $default  Default if not found
     * @return mixed The attribute value, or default
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
     * @param  string  $key  Route parameter name
     * @param  mixed  $default  Default if not found
     * @return mixed The route parameter value, or default
     *
     * @see get()
     */
    public function route(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Whether the request expects a JSON response.
     *
     * Checks the Accept header and the X-Requested-With header.
     */
    public function wantsJson(): bool
    {
        /** @var mixed $acceptRaw */
        $acceptRaw = $this->header('Accept', '');
        /** @var mixed $xhrRaw */
        $xhrRaw = $this->header('X-Requested-With', '');

        $accept = is_string($acceptRaw) ? $acceptRaw : '';
        $xhr = is_string($xhrRaw) ? $xhrRaw : '';

        return stripos($accept, 'application/json') !== false
            || strtolower($xhr) === 'xmlhttprequest';
    }

    /**
     * Detect the HTTP method, respecting method override headers.
     *
     * Supports the X-HTTP-Method-Override header and the _method
     * POST field for clients that don't support PUT/DELETE.
     *
     * @return string Uppercased HTTP method
     */
    private function detectMethod(): string
    {
        /** @var string $requestMethod */
        $requestMethod = $this->server['REQUEST_METHOD'] ?? 'GET';
        $method = strtoupper($requestMethod);

        if ($method === 'POST') {
            $override = $this->server['HTTP_X_HTTP_METHOD_OVERRIDE']
                ?? $this->post['_method']
                ?? null;

            if ($override !== null) {
                /** @var string $overrideString */
                $overrideString = $override;
                $method = strtoupper($overrideString);
            }
        }

        return $method;
    }

    /**
     * Detect the request URI path.
     *
     * Strips the query string and normalizes the path.
     *
     * @return string The URI path (e.g. '/users/42')
     */
    private function detectUri(): string
    {
        /** @var string $uri */
        $uri = $this->server['REQUEST_URI'] ?? '/';

        // Strip query string (parse_url keeps only the path component)
        $uri = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Strip base path if SCRIPT_NAME is set
        /** @var string $script */
        $script = $this->server['SCRIPT_NAME'] ?? '';
        $base = dirname($script);
        if ($base !== '' && $base !== '/' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }

        return '/'.ltrim($uri, '/');
    }
}
