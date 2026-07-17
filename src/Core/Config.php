<?php

declare(strict_types=1);

namespace Antimonial\Core;

/**
 * Dot-notation configuration loader.
 *
 * Loads PHP config files from app/Config/ and provides access
 * via dot notation (e.g. 'app.timezone').
 *
 * @example
 *   Config::load('database');    // loads app/Config/database.php
 *   Config::get('database.host'); // returns the 'host' value from the database config
 */
class Config
{
    /**
     * Loaded configuration data, keyed by file name.
     *
     * @var array<string, mixed>
     */
    private static array $data = [];

    /**
     * Config files that were looked up but do not exist (cached misses).
     *
     * @var array<string, true>
     */
    private static array $misses = [];

    /**
     * Load a configuration file.
     *
     * The file should return an array. Example:
     *
     * @example
     *   // app/Config/database.php
     *   return ['host' => '127.0.0.1', 'port' => 3306];
     *
     * @param  string  $file  Config file name (without .php extension)
     */
    public static function load(string $file): void
    {
        $path = ROOT_PATH."/app/Config/{$file}.php";

        if (file_exists($path)) {
            self::$data[$file] = require $path;
            unset(self::$misses[$file]);
        } else {
            self::$misses[$file] = true;
        }
    }

    /**
     * Get a config value using dot notation.
     *
     * @example Config::get('database.host', 'localhost');
     *
     * @param  string  $key  Dot-notation key (e.g. 'database.host')
     * @param  mixed  $default  Default value if key is not found
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key, 2);
        $file = $parts[0];

        if (isset(self::$misses[$file])) {
            return $default;
        }

        if (! isset(self::$data[$file])) {
            self::load($file);
        }

        if (! isset(self::$data[$file])) {
            return $default;
        }

        if (count($parts) === 1) {
            return self::$data[$file];
        }

        return self::dotGet((array) self::$data[$file], $parts[1], $default);
    }

    /**
     * Retrieve a nested value from an array using dot notation.
     *
     * @param  array<array-key, mixed>  $array
     * @param  string  $key  Remaining dot-notation path
     */
    private static function dotGet(array $array, string $key, mixed $default): mixed
    {
        $keys = explode('.', $key);

        foreach ($keys as $k) {
            if (! array_key_exists($k, $array)) {
                return $default;
            }
            $array = (array) $array[$k];
        }

        return $array;
    }
}
