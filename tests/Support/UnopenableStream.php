<?php

declare(strict_types=1);

namespace Rasuvaeff\MutationHistory\Tests\Support;

/**
 * A stream wrapper whose paths pass is_file() but can never be opened, so
 * file_get_contents() returns false regardless of the user running the tests
 * (a chmod 000 file is readable by root).
 */
final class UnopenableStream
{
    public const string SCHEME = 'mhunopenable';

    /** @var resource|null */
    public $context;

    public static function register(): void
    {
        if (!\in_array(self::SCHEME, stream_get_wrappers(), strict: true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public static function unregister(): void
    {
        if (\in_array(self::SCHEME, stream_get_wrappers(), strict: true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    /**
     * @return array<string, int>
     */
    public function url_stat(): array
    {
        return ['mode' => 0o100644, 'size' => 1];
    }

    public function stream_open(): bool
    {
        return false;
    }
}
