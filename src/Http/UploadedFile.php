<?php

declare(strict_types=1);

namespace Antimonial\Http;

use RuntimeException;

/**
 * Thin wrapper around a single $_FILES entry.
 *
 * Unlike the raw $_FILES array, this object exposes a small, typed API
 * and never trusts client-supplied metadata: the MIME type is detected
 * from the temp file on disk (via mime_content_type()), not from the
 * client's "type" field.
 *
 * The caller decides the final filename explicitly — the framework does
 * not auto-generate names (consistent with the explicit $table / view
 * path rules). Pass the desired name to store().
 *
 * @see Request::file()
 */
final class UploadedFile
{
    /**
     * @param  array{name: string, type: string, tmp_name: string, error: int, size: int}  $file  A single $_FILES entry
     */
    public function __construct(
        /** @var array{name: string, type: string, tmp_name: string, error: int, size: int} $file */
        private readonly array $file,
    ) {}

    /**
     * Whether the upload succeeded and PHP recognizes it as a real upload.
     *
     * Both conditions must hold:
     *  - error code is UPLOAD_ERR_OK
     *  - is_uploaded_file() confirms it came through HTTP upload
     */
    public function isValid(): bool
    {
        return $this->error() === UPLOAD_ERR_OK
            && $this->file['tmp_name'] !== ''
            && is_uploaded_file($this->file['tmp_name']);
    }

    /**
     * The PHP upload error code.
     */
    public function error(): int
    {
        return (int) $this->file['error'];
    }

    /**
     * A human-readable description of the upload error.
     *
     * Based on PHP's upload error constants. For UPLOAD_ERR_OK an empty
     * string is returned (there is no error).
     */
    public function errorMessage(): string
    {
        return match ($this->error()) {
            UPLOAD_ERR_OK => '',
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive.',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the form.',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
            default => 'Unknown upload error.',
        };
    }

    /**
     * The size of the uploaded file in bytes.
     */
    public function size(): int
    {
        return (int) $this->file['size'];
    }

    /**
     * The original client-supplied filename.
     */
    public function clientName(): string
    {
        return $this->file['name'];
    }

    /**
     * The original client-supplied file extension (without the dot).
     */
    public function clientExtension(): string
    {
        $name = $this->clientName();
        if ($name === '') {
            return '';
        }

        $ext = pathinfo($name, PATHINFO_EXTENSION);

        return strtolower($ext);
    }

    /**
     * The MIME type detected from the temp file on disk.
     *
     * Never trusts the client-supplied "type" field. Returns an empty
     * string if the temp file is missing or undetectable.
     */
    public function mimeType(): string
    {
        $tmp = $this->file['tmp_name'];
        if ($tmp === '' || ! is_file($tmp)) {
            return '';
        }

        $mime = mime_content_type($tmp);

        return $mime === false ? '' : $mime;
    }

    /**
     * Persist the uploaded file to disk.
     *
     * Creates $directory if it does not exist, then moves the temp file
     * via move_uploaded_file() to "$directory/$name". The caller supplies
     * $name explicitly — no implicit naming convention is applied.
     *
     * @param  string  $directory  Destination directory (created if missing)
     * @param  string  $name  Final filename (caller-chosen)
     * @return string The final stored path ("$directory/$name")
     *
     * @throws RuntimeException If the directory cannot be created or the
     *                          move fails (e.g. not a valid upload).
     */
    public function store(string $directory, string $name): string
    {
        if (! is_dir($directory)) {
            if (! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
                throw new RuntimeException("Unable to create upload directory: {$directory}");
            }
        }

        $target = rtrim($directory, '/\\').'/'.$name;

        if (! move_uploaded_file($this->file['tmp_name'], $target)) {
            throw new RuntimeException("Failed to move uploaded file to: {$target}");
        }

        return $target;
    }
}
