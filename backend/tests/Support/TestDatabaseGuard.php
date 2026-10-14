<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use RuntimeException;

final class TestDatabaseGuard
{
    public const DATABASE = 'sistem_project1_test';
    public static ?string $limiterScope = null;

    public static function settings(string $base): array
    {
        $settings = \Dotenv\Dotenv::createArrayBacked($base)->load();
        if (is_file($base.'/.env.testing')) {
            $settings = array_replace($settings, \Dotenv\Dotenv::createArrayBacked($base, '.env.testing')->load());
        }
        foreach (['DB_DATABASE' => self::DATABASE, 'DB_CONNECTION' => 'mysql'] as $key => $expected) {
            if (($settings[$key] ?? '') !== $expected) {
                throw new RuntimeException('Unsafe isolated database settings.');
            }
            foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $value) {
                if ($value !== false && $value !== null && $value !== '' && $value !== $expected) {
                    throw new RuntimeException('Conflicting database environment.');
                }
            }
        }
        foreach (['DB_URL', 'DATABASE_URL', 'APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_BASE_PATH'] as $key) {
            foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null, $settings[$key] ?? null] as $value) {
                if ($value !== false && $value !== null && $value !== '') {
                    throw new RuntimeException('Unsupported URL/cache/base-path override.');
                }
            }
        }
        if (is_file($base.'/bootstrap/cache/config.php')) {
            throw new RuntimeException('Cached configuration must be reviewed before testing.');
        }
        return $settings;
    }

    public static function install(Application $app): void
    {
        self::settings($app->basePath());
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $config = $app['config'];
            $connection = $config->get('database.connections.mysql');
            if ($config->get('database.default') !== 'mysql'
                || ($connection['driver'] ?? '') !== 'mysql'
                || ($connection['database'] ?? '') !== self::DATABASE
                || !empty($connection['url'])) {
                throw new RuntimeException('Effective connection rejected before providers boot.');
            }
            // Other named connections cannot silently reach a different database.
            $config->set('database.connections', ['mysql' => $connection]);
            $config->set('logging.default', 'null');
            $config->set('logging.channels.null', [
                'driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class,
            ]);
            $config->set('app.debug', false);
            if (self::$limiterScope !== null) {
                if (!preg_match('/^[a-f0-9]{32}$/', self::$limiterScope)) throw new RuntimeException('Invalid limiter test namespace.');
                $path = $app->storagePath('framework/cache/auth-limiter-tests/'.self::$limiterScope);
                $config->set('cache.stores.auth_limiter.path', $path);
                $config->set('cache.stores.auth_limiter.lock_path', $path);
            }
            // DatabaseServiceProvider is not registered yet: verify directly
            // through PDO before any application provider can query data.
            $dsn = !empty($connection['unix_socket'])
                ? 'mysql:unix_socket='.$connection['unix_socket']
                : 'mysql:host='.$connection['host'].';port='.$connection['port'];
            $pdo = new \PDO($dsn.';dbname='.self::DATABASE.';charset=utf8mb4',
                $connection['username'], $connection['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== self::DATABASE) {
                throw new RuntimeException('Actual database rejected before providers boot.');
            }
            $pdo = null;
        });
    }

    public static function assertActual($connection): void
    {
        if ($connection->selectOne('SELECT DATABASE() AS db')->db !== self::DATABASE) {
            throw new RuntimeException('Actual database rejected; operation cancelled.');
        }
    }
}
