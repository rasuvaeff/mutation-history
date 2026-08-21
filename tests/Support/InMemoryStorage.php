<?php

declare(strict_types=1);

namespace Rasuvaeff\MutationHistory\Tests\Support;

use Rasuvaeff\QualityLedger\StoragePort;

/**
 * A {@see StoragePort} that never touches disk — for tests that exercise
 * {@see \Rasuvaeff\QualityLedger\Ledger} without paying for the filesystem.
 */
final class InMemoryStorage implements StoragePort
{
    /** @var array<string, string> */
    private array $bytesByScope = [];

    #[\Override]
    public function read(string $scope): ?string
    {
        return $this->bytesByScope[$scope] ?? null;
    }

    #[\Override]
    public function write(string $scope, string $bytes): void
    {
        $this->bytesByScope[$scope] = $bytes;
    }
}
