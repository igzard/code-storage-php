<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Tests;

use Igzard\CodeStorage\Enum\DiffFileState;
use Igzard\CodeStorage\Enum\RefUpdateReason;
use Igzard\CodeStorage\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function test_package_identity(): void
    {
        self::assertSame('code-storage-php-sdk', Version::NAME);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Version::VERSION);
        self::assertSame(Version::NAME.'/'.Version::VERSION, Version::userAgent());
    }

    public static function diffStates(): iterable
    {
        yield ['A', DiffFileState::Added];
        yield ['M', DiffFileState::Modified];
        yield ['D', DiffFileState::Deleted];
        yield ['R100', DiffFileState::Renamed];
        yield ['C75', DiffFileState::Copied];
        yield ['T', DiffFileState::TypeChanged];
        yield ['U', DiffFileState::Unmerged];
        yield ['  a  ', DiffFileState::Added];
        yield ['', DiffFileState::Unknown];
        yield ['X', DiffFileState::Unknown];
    }

    #[DataProvider('diffStates')]
    public function test_diff_state_normalization(string $raw, DiffFileState $expected): void
    {
        self::assertSame($expected, DiffFileState::fromRaw($raw));
    }

    public static function refUpdateReasons(): iterable
    {
        yield ['precondition_failed', RefUpdateReason::PreconditionFailed];
        yield ['  CONFLICT  ', RefUpdateReason::Conflict];
        yield ['not_found', RefUpdateReason::NotFound];
        yield ['ok', RefUpdateReason::Unknown];
        yield ['', RefUpdateReason::Unknown];
        yield ['something-else', RefUpdateReason::Unknown];
    }

    #[DataProvider('refUpdateReasons')]
    public function test_ref_update_reason_inference(string $status, RefUpdateReason $expected): void
    {
        self::assertSame($expected, RefUpdateReason::fromStatus($status));
    }
}
