<?php

declare(strict_types=1);

namespace App\Service;

final class IdempotencyService
{
    private const PREFIX          = 'idempotency:';
    private const IN_PROGRESS_TTL = 30;
    private const COMPLETE_TTL    = 86_400;

    public function __construct(private readonly \Redis $redis) {}

    public function check(string $key): false|null|array
    {
        $raw = $this->redis->get($this->redisKey($key));

        if ($raw === false) {
            return false;
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if ($data['state'] === 'in_progress') {
            return null;
        }

        return $data['response'];
    }

    public function markInProgress(string $key): bool
    {
        return (bool) $this->redis->set(
            $this->redisKey($key),
            json_encode(['state' => 'in_progress'], JSON_THROW_ON_ERROR),
            ['NX', 'EX' => self::IN_PROGRESS_TTL]
        );
    }

    public function markComplete(string $key, array $response): void
    {
        $this->redis->setex(
            $this->redisKey($key),
            self::COMPLETE_TTL,
            json_encode(['state' => 'complete', 'response' => $response], JSON_THROW_ON_ERROR)
        );
    }

    public function markFailed(string $key): void
    {
        $this->redis->del($this->redisKey($key));
    }

    private function redisKey(string $key): string
    {
        return self::PREFIX . $key;
    }
}