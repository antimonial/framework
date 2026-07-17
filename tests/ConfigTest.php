<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Core\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private static string $configDir;

    public static function setUpBeforeClass(): void
    {
        self::$configDir = rtrim(ROOT_PATH, '/').'/app/Config';
        if (! is_dir(self::$configDir)) {
            mkdir(self::$configDir, 0777, true);
        }
    }

    protected function setUp(): void
    {
        // Remove stale config files from previous test runs
        foreach (glob(self::$configDir.'/*.php') ?: [] as $f) {
            unlink($f);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$configDir.'/*.php') ?: [] as $f) {
            unlink($f);
        }
        @rmdir(self::$configDir);
        @rmdir(dirname(self::$configDir));
    }

    public function test_load_and_get(): void
    {
        $this->writeConfig('test_app', ['name' => 'Antimonial', 'debug' => true]);
        Config::load('test_app');

        $this->assertSame('Antimonial', Config::get('test_app.name'));
        $this->assertTrue(Config::get('test_app.debug'));
    }

    public function test_get_returns_default_when_not_found(): void
    {
        $this->assertNull(Config::get('nonexistent_file.key'));
        $this->assertSame('fallback', Config::get('nonexistent_file.key', 'fallback'));
    }

    public function test_dot_notation_nested(): void
    {
        $this->writeConfig('test_db', [
            'default' => 'mysql',
            'connections' => [
                'mysql' => ['host' => 'localhost', 'port' => 3306],
            ],
        ]);
        Config::load('test_db');

        $this->assertSame('mysql', Config::get('test_db.default'));
        $this->assertSame('localhost', Config::get('test_db.connections.mysql.host'));
        $this->assertSame(3306, Config::get('test_db.connections.mysql.port'));
    }

    public function test_partial_key_returns_array(): void
    {
        $this->writeConfig('test_mail', ['driver' => 'smtp', 'host' => 'smtp.example.com']);
        Config::load('test_mail');

        $config = Config::get('test_mail');
        $this->assertIsArray($config);
        $this->assertSame('smtp', $config['driver']);
    }

    public function test_load_missing_file_does_not_crash(): void
    {
        Config::load('__does_not_exist__');
        $this->assertNull(Config::get('__does_not_exist__.key'));
    }

    private function writeConfig(string $name, array $data): void
    {
        file_put_contents(
            self::$configDir.'/'.$name.'.php',
            '<?php return '.var_export($data, true).';'
        );
    }
}
