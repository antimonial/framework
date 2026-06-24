<?php

declare(strict_types=1);

namespace Antimonial\Core;

/**
 * PSR-4 autoloader.
 *
 * Registers an autoloader for the Antimonial\ namespace,
 * mapping it to the src/ directory.
 *
 * @example
 *   $autoloader = new Autoloader();
 *   $autoloader->register();
 */
class Autoloader
{
    /**
     * The base namespace.
     */
    private string $namespace;

    /**
     * The base directory for the namespace.
     */
    private string $basePath;

    /**
     * @param string $namespace The root namespace (e.g. 'Antimonial')
     * @param string $basePath  The root directory (e.g. __DIR__ . '/src')
     */
    public function __construct(string $namespace, string $basePath)
    {
        $this->namespace = rtrim($namespace, '\\') . '\\';
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * Register the autoloader with SPL.
     *
     * @return void
     */
    public function register(): void
    {
        spl_autoload_register([$this, 'loadClass']);
    }

    /**
     * Autoload a class by fully-qualified name.
     *
     * Converts a class like `Antimonial\Http\Request` to the file
     * path `src/Http/Request.php` and includes it if found.
     *
     * @param string $class Fully-qualified class name
     * @return void
     */
    public function loadClass(string $class): void
    {
        if (!str_starts_with($class, $this->namespace)) {
            return;
        }

        $relativeClass = substr($class, strlen($this->namespace));
        $file = $this->basePath . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    }
}
