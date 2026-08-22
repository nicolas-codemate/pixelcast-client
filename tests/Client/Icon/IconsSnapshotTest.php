<?php

declare(strict_types=1);

namespace App\Tests\Client\Icon;

use App\Client\Icon\IconsSnapshot;
use PHPUnit\Framework\TestCase;

final class IconsSnapshotTest extends TestCase
{
    public function testTheDeviceListingIsReadWithItsIconsAndItsFilesystemOccupancy(): void
    {
        $iconsSnapshot = IconsSnapshot::fromResponseBody([
            'icons' => [
                ['name' => 'bitcoin', 'filename' => 'bitcoin.png', 'size' => 171],
                ['name' => 'claude', 'filename' => 'claude.png', 'size' => 107],
            ],
            'count' => 2,
            'storage' => ['used' => 81_920, 'total' => 196_608],
        ]);

        self::assertSame(2, $iconsSnapshot->count);
        self::assertSame(['bitcoin', 'claude'], $iconsSnapshot->iconNames());
        self::assertSame('bitcoin.png', $iconsSnapshot->icons[0]->fileName);
        self::assertSame(171, $iconsSnapshot->icons[0]->sizeInBytes);
        self::assertNotNull($iconsSnapshot->storage);
        self::assertSame(81_920, $iconsSnapshot->storage->usedBytes);
        self::assertSame(196_608, $iconsSnapshot->storage->totalBytes);
        self::assertSame(114_688, $iconsSnapshot->storage->availableBytes());
    }

    public function testAnEmptyBodyLeavesNoIconAndNoStorage(): void
    {
        $iconsSnapshot = IconsSnapshot::fromResponseBody([]);

        self::assertSame([], $iconsSnapshot->icons);
        self::assertSame(0, $iconsSnapshot->count);
        self::assertNull($iconsSnapshot->storage);
    }

    public function testEntriesWithoutAUsableNameAreDroppedAndTheCountFallsBackOnWhatWasRead(): void
    {
        $iconsSnapshot = IconsSnapshot::fromResponseBody([
            'icons' => [
                ['name' => 'github', 'filename' => 'github.png', 'size' => 149],
                ['filename' => 'orphan.png', 'size' => 12],
                'not-an-object',
                ['name' => '', 'filename' => '.png', 'size' => 0],
            ],
        ]);

        self::assertSame(['github'], $iconsSnapshot->iconNames());
        self::assertSame(1, $iconsSnapshot->count);
    }

    public function testAnIncompleteStorageNodeIsIgnoredRatherThanReportedAsZero(): void
    {
        $iconsSnapshot = IconsSnapshot::fromResponseBody([
            'icons' => [],
            'storage' => ['used' => 81_920],
        ]);

        self::assertNull($iconsSnapshot->storage);
    }

    public function testMissingFieldsOfAnIconFallBackOnEmptyValues(): void
    {
        $iconsSnapshot = IconsSnapshot::fromResponseBody([
            'icons' => [['name' => 'etf']],
        ]);

        self::assertSame('', $iconsSnapshot->icons[0]->fileName);
        self::assertSame(0, $iconsSnapshot->icons[0]->sizeInBytes);
    }

    public function testAnIconIsFoundByItsName(): void
    {
        $iconsSnapshot = IconsSnapshot::fromResponseBody([
            'icons' => [['name' => 'claude', 'filename' => 'claude.png', 'size' => 107]],
        ]);

        self::assertTrue($iconsSnapshot->hasIcon('claude'));
        self::assertFalse($iconsSnapshot->hasIcon('bitcoin'));
    }

    public function testAFilesystemReportedFullerThanItsSizeLeavesNoRoomRatherThanANegativeOne(): void
    {
        $iconsSnapshot = IconsSnapshot::fromResponseBody([
            'storage' => ['used' => 200_000, 'total' => 196_608],
        ]);

        self::assertNotNull($iconsSnapshot->storage);
        self::assertSame(0, $iconsSnapshot->storage->availableBytes());
    }
}
