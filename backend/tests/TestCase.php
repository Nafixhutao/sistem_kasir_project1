<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use DatabaseTransactions { beginDatabaseTransaction as private startGuardedTransaction; }

    private const TEST_DATABASE = 'sistem_project1_test';

    // Existing regression fixtures now submit a fresh logical operation UUID.
    // Idempotency validation tests call json() directly to test missing IDs.
    public function postJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        if ($uri === '/api/penjualan' || $uri === '/api/retur-supplier' || preg_match('~^/api/penjualan/\d+/retur$~', $uri)) {
            if (!array_key_exists('request_id', $data)) $data['request_id'] = (string) \Illuminate\Support\Str::uuid();
        }
        return parent::postJson($uri, $data, $headers, $options);
    }

    protected function setUp(): void
    {
        $this->guardConfiguredDatabaseBeforeLaravelBoots();
        \Tests\Support\TestDatabaseGuard::$limiterScope = bin2hex(random_bytes(16));
        parent::setUp();

        $database = DB::selectOne('SELECT DATABASE() AS db')->db;
        if ($database !== self::TEST_DATABASE) {
            throw new RuntimeException("Automated test dibatalkan karena database aktif adalah {$database}, bukan ".self::TEST_DATABASE.'.');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '198.18.'.random_int(0, 255).'.'.random_int(1, 254)]);
    }

    protected function tearDown(): void
    {
        try {
            $scope = \Tests\Support\TestDatabaseGuard::$limiterScope;
            if ($this->app && $scope !== null) {
                $expected = $this->app->storagePath('framework/cache/auth-limiter-tests/'.$scope);
                if (config('cache.stores.auth_limiter.path') !== $expected) throw new RuntimeException('Refusing to clear non-fixture cache.');
                \Illuminate\Support\Facades\Cache::store('auth_limiter')->flush();
            }
        } finally {
            parent::tearDown();
            \Tests\Support\TestDatabaseGuard::$limiterScope = null;
        }
    }

    private function guardConfiguredDatabaseBeforeLaravelBoots(): void
    {
        \Tests\Support\TestDatabaseGuard::settings(dirname(__DIR__));
        $candidates = array_unique(array_filter([
            getenv('DB_DATABASE'),
            $_ENV['DB_DATABASE'] ?? null,
            $_SERVER['DB_DATABASE'] ?? null,
        ], fn ($value) => $value !== false && $value !== null && $value !== ''));

        foreach ($candidates as $database) {
            if ($database !== self::TEST_DATABASE) {
                throw new RuntimeException("Automated test dibatalkan sebelum Laravel boot karena DB_DATABASE adalah {$database}, bukan ".self::TEST_DATABASE.'.');
            }
        }

        $configCache = dirname(__DIR__).'/bootstrap/cache/config.php';
        if (is_file($configCache)) {
            $cachedConfig = require $configCache;
            $connection = $cachedConfig['database']['default'] ?? null;
            $cachedDatabase = $cachedConfig['database']['connections'][$connection]['database'] ?? null;
            if ($cachedDatabase !== self::TEST_DATABASE) {
                throw new RuntimeException('Automated test dibatalkan sebelum Laravel boot karena config cache tidak menunjuk ke database test.');
            }
        }
    }

    public function beginDatabaseTransaction(): void
    {
        if (DB::selectOne('SELECT DATABASE() AS db')->db !== self::TEST_DATABASE) {
            throw new RuntimeException('Test connection guard rejected database before transaction.');
        }
        // Compatibility fixture only, while migration one retains required email.
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'email')) {
            \App\Models\User::creating(function ($user) {
                if ($user->getAttribute('email') === null) {
                    $user->setAttribute('email', $user->username);
                }
            });
        }
        $this->startGuardedTransaction();
    }
}
