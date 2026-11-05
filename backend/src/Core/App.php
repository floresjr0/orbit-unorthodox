<?php

declare(strict_types=1);

namespace Orbit;

final class App
{
    /** @var array<string, object> */
    private array $services = [];

    public function __construct(
        public readonly array $config,
        private readonly \PDO $pdo
    ) {
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function get(string $class): object
    {
        return $this->services[$class] ??= new $class($this);
    }

    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (isset($this->services[NotificationService::class]) || class_exists(NotificationService::class)) {
                try {
                    $this->get(NotificationService::class)->clearPushes();
                } catch (\Throwable) {
                }
            }
            throw $e;
        }
    }
}
