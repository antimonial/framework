<?php

declare(strict_types=1);

namespace Antimonial\Http;

/**
 * HTTP response builder.
 *
 * A thin, fluent wrapper around PHP's header() and echo. Builds
 * the status code, headers, and body, then sends everything in
 * one call to send().
 *
 * Supports auto-formatting: call json() to set the body and
 * Content-Type in one step.
 *
 * @example
 *   (new Response())->json(['users' => $users]);
 *   (new Response())->redirect('/login', 302);
 *   (new Response())->status(404)->body('Not found');
 *
 * @see Request
 */
class Response
{
    /**
     * @var int HTTP status code
     */
    private int $statusCode = 200;

    /**
     * Response headers.
     *
     * @var array<string, string>
     */
    private array $headers = [];

    /**
     * @var string Response body
     */
    private string $body = '';

    /**
     * Set the HTTP status code.
     *
     * @param int $code
     * @return static
     */
    public function status(int $code): static
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Get the current status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Set a response header.
     *
     * @param string $name  Header name (e.g. 'Content-Type')
     * @param string $value Header value
     * @return static
     */
    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Get all response headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Set the response body.
     *
     * @param string $content
     * @return static
     */
    public function body(string $content): static
    {
        $this->body = $content;
        return $this;
    }

    /**
     * Get the current response body.
     *
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Set the response body as JSON.
     *
     * Encodes the data, sets Content-Type to application/json,
     * and stores the encoded string as the body.
     *
     * @example return (new Response())->json(['id' => 1, 'name' => 'John']);
     *
     * @param mixed $data   Data to encode (arrays, objects, etc.)
     * @param int   $status Optional status code
     * @return static
     * @throws \JsonException If encoding fails
     */
    public function json(mixed $data, int $status = 200): static
    {
        $this->statusCode = $status;
        $this->headers['Content-Type'] = 'application/json; charset=UTF-8';
        $this->body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $this;
    }

    /**
     * Set a redirect response.
     *
     * @example return (new Response())->redirect('/login', 302);
     *
     * @param string $url    The URL to redirect to
     * @param int    $status HTTP status (301, 302, 303, 307, 308)
     * @return static
     */
    public function redirect(string $url, int $status = 302): static
    {
        $this->statusCode = $status;
        $this->headers['Location'] = $url;
        return $this;
    }

    /**
     * Set a cookie.
     *
     * @param string $name    Cookie name
     * @param string $value   Cookie value
     * @param int    $expires Lifetime in seconds (0 = until browser closes)
     * @return static
     */
    public function setCookie(string $name, string $value, int $expires = 0): static
    {
        setcookie($name, $value, [
            'expires'  => $expires > 0 ? time() + $expires : 0,
            'path'     => '/',
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
        return $this;
    }

    /**
     * Send the response to the client.
     *
     * Sets the status code, sends all headers, and outputs the body.
     *
     * @return void
     */
    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $this->body;
    }
}
