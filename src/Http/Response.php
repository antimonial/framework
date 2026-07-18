<?php

declare(strict_types=1);

namespace Antimonial\Http;

use JsonException;

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
     * Security headers applied automatically unless already set.
     *
     * @var array<string, string>
     */
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
    ];

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
     * @var bool Whether the response has already been sent
     */
    private bool $sent = false;

    /**
     * Set the HTTP status code.
     *
     * @param  int  $code  HTTP status code
     */
    public function status(int $code): static
    {
        $this->statusCode = $code;

        return $this;
    }

    /**
     * Get the current status code.
     *
     * @return int HTTP status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Set a response header.
     *
     * @param  string  $name  Header name (e.g. 'Content-Type')
     * @param  string  $value  Header value
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
     * @param  string  $content  Body content
     */
    public function body(string $content): static
    {
        $this->body = $content;

        return $this;
    }

    /**
     * Get the current response body.
     *
     * @return string The response body
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Whether the response has already been sent.
     *
     * @return bool True if send() has run
     */
    public function wasSent(): bool
    {
        return $this->sent;
    }

    /**
     * Set the response body as JSON.
     *
     * Encodes the data, sets Content-Type to application/json,
     * and stores the encoded string as the body.
     *
     * @example return (new Response())->json(['id' => 1, 'name' => 'John']);
     *
     * @param  mixed  $data  Data to encode (arrays, objects, etc.)
     * @param  int  $status  Optional status code
     *
     * @throws JsonException If encoding fails
     */
    public function json(mixed $data, int $status = 200): static
    {
        if ($status !== 200) {
            $this->statusCode = $status;
        }
        $this->headers['Content-Type'] = 'application/json; charset=UTF-8';
        $this->body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this;
    }

    /**
     * Serve a file for download (Content-Disposition: attachment).
     *
     * @example return (new Response())->download('storage/report.pdf');
     *
     * @param  string  $path  Absolute or relative path to the file
     * @param  string|null  $name  Override the download filename
     *
     * @throws \RuntimeException If the file does not exist or is unreadable
     */
    public function download(string $path, ?string $name = null): static
    {
        $name ??= basename($path);

        return $this->serveFile($path, true, $name);
    }

    /**
     * Serve a file inline with its MIME type.
     *
     * @example return (new Response())->file('storage/image.png');
     *
     * @param  string  $path  Absolute or relative path to the file
     *
     * @throws \RuntimeException If the file does not exist or is unreadable
     */
    public function file(string $path): static
    {
        return $this->serveFile($path, false, null);
    }

    /**
     * Serve a file as a response body, optionally forcing download.
     *
     * @param  string  $path  Absolute or relative path to the file
     * @param  bool  $asAttachment  Add a Content-Disposition: attachment header
     * @param  string|null  $name  Download filename (only used when attaching)
     *
     * @throws \RuntimeException If the file does not exist or is unreadable
     */
    private function serveFile(string $path, bool $asAttachment, ?string $name): static
    {
        if (! file_exists($path) || ! is_readable($path)) {
            throw new \RuntimeException("File not found or unreadable: {$path}");
        }

        $this->headers['Content-Type'] = $this->detectMimeType($path);
        $this->headers['Content-Length'] = (string) filesize($path);

        if ($asAttachment) {
            /** @var string $name */
            $this->headers['Content-Disposition'] = 'attachment; filename="'.addcslashes($name, '"\\').'"';
        }

        $this->body = $this->loadFile($path);

        return $this;
    }

    /**
     * Read a file's contents into a string.
     *
     * @throws \RuntimeException If the read fails
     */
    private function loadFile(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException("Failed to read file: {$path}");
        }

        return $contents;
    }

    /**
     * Detect the MIME type of a file.
     *
     * @param  string  $path  Absolute path to the file
     * @return string Detected MIME type, or 'application/octet-stream' as fallback
     */
    private function detectMimeType(string $path): string
    {
        return mime_content_type($path) ?: 'application/octet-stream';
    }

    /**
     * Set a redirect response.
     *
     * @example return (new Response())->redirect('/login', 302);
     *
     * @param  string  $url  The URL to redirect to
     * @param  int  $status  HTTP status (301, 302, 303, 307, 308)
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
     * @param  string  $name  Cookie name
     * @param  string  $value  Cookie value
     * @param  int  $expires  Lifetime in seconds (0 = until browser closes)
     */
    public function setCookie(string $name, string $value, int $expires = 0): static
    {
        setcookie($name, $value, [
            'expires' => $expires > 0 ? time() + $expires : 0,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return $this;
    }

    /**
     * Send the response to the client.
     *
     * Sets the status code, sends all headers, and outputs the body.
     */
    public function send(): void
    {
        if ($this->sent) {
            return;
        }

        if (headers_sent()) {
            echo $this->body;
            $this->sent = true;

            return;
        }

        http_response_code($this->statusCode);

        foreach (self::SECURITY_HEADERS as $name => $value) {
            if (! isset($this->headers[$name])) {
                header("{$name}: {$value}");
            }
        }

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $this->body;
        $this->sent = true;
    }
}
