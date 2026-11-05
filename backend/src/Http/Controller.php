<?php

declare(strict_types=1);

namespace Orbit;

abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    protected function user(Request $request): array
    {
        if (!$request->user) {
            throw new HttpException(401, 'Authentication is required.');
        }

        return $request->user;
    }

    protected function intParam(Request $request, string $name): int
    {
        $value = $request->params[$name] ?? '';
        if (!preg_match('/^\d+$/', (string) $value)) {
            throw new HttpException(404, 'The requested record was not found.');
        }

        return (int) $value;
    }
}
