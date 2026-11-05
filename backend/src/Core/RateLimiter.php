<?php

declare(strict_types=1);

namespace Orbit;

final class RateLimiter
{
    public function __construct(private readonly App $app)
    {
    }

    public function hit(string $key, int $max, int $windowSeconds): void
    {
        $pdo = $this->app->pdo();
        $now = time();
        $key = substr($key, 0, 190);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT window_start, hits FROM rate_limits WHERE rate_key = ? FOR UPDATE');
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            if (!$row || ($now - (int) $row['window_start']) >= $windowSeconds) {
                $up = $pdo->prepare(
                    'INSERT INTO rate_limits (rate_key, window_start, hits) VALUES (?, ?, 1)
                     ON DUPLICATE KEY UPDATE window_start = VALUES(window_start), hits = 1'
                );
                $up->execute([$key, $now]);
                $pdo->commit();
                return;
            }
            if ((int) $row['hits'] >= $max) {
                $pdo->commit();
                throw new HttpException(429, 'Too many attempts. Please wait and try again.');
            }
            $pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE rate_key = ?')->execute([$key]);
            $pdo->commit();
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
