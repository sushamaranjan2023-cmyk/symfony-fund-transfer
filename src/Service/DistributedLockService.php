<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Distributed lock using native php-redis extension.
 */
final class DistributedLockService
{
    private const PREFIX  = 'lock:';
    private const TTL_MS  = 10_000;

    public function __construct(private readonly \Redis $redis) {}

    public function acquire(string $resource): string|false
    {
        $token = bin2hex(random_bytes(16));

        // SET key value NX PX ttl — atomic
        $result = $this->redis->set(
            self::PREFIX . $resource,
            $token,
            ['NX', 'PX' => self::TTL_MS]
        );

        return $result ? $token : false;
    }

    public function release(string $resource, string $token): void
    {
        // Lua compare-and-delete — atomic
        $script = <<<'LUA'
        if redis.call("GET", KEYS[1]) == ARGV[1] then
            return redis.call("DEL", KEYS[1])
        else
            return 0
        end
        LUA;

        $this->redis->eval($script, [$token], 1);
    }

    public static function accountPairKey(string $idA, string $idB): string
    {
        $sorted = [$idA, $idB];
        sort($sorted);
        return 'account-pair:' . implode(':', $sorted);
    }
}