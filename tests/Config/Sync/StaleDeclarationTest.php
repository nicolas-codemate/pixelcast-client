<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Client\StaleBehavior;
use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\StaleDeclaration;
use App\Config\Sync\SyncInterval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StaleDeclarationTest extends TestCase
{
    private const string PARENT_PATH = 'syncs.boursorama';
    private const string ITEM_PATH = 'syncs.boursorama.items[0]';

    public function testWithoutAnOverrideTheSilenceToleratedIsThreeIntervals(): void
    {
        $staleDeclaration = StaleDeclaration::fromOptions([], self::PARENT_PATH, self::intervalOf('15 minutes'), StaleBehavior::cases());

        self::assertSame(2700, $staleDeclaration->staleAfterInSeconds);
        self::assertNull($staleDeclaration->staleBehavior);
    }

    public function testAnOverrideWinsOverTheDerivedValue(): void
    {
        $staleDeclaration = StaleDeclaration::fromOptions(
            ['staleAfter' => 0, 'staleBehavior' => 'hide'],
            self::PARENT_PATH,
            self::intervalOf('15 minutes'),
            StaleBehavior::cases(),
        );

        self::assertSame(0, $staleDeclaration->staleAfterInSeconds);
        self::assertSame(StaleBehavior::Hide, $staleDeclaration->staleBehavior);
    }

    public function testADerivedValueAboveSevenDaysIsClamped(): void
    {
        $staleDeclaration = StaleDeclaration::fromOptions([], self::PARENT_PATH, self::intervalOf('3 days'), StaleBehavior::cases());

        self::assertSame(604800, $staleDeclaration->staleAfterInSeconds);
    }

    public function testABehaviourTheGroupDoesNotAcceptNamesTheKeyAndTheAcceptedValues(): void
    {
        try {
            StaleDeclaration::fromOptions(
                ['staleBehavior' => 'dim'],
                'syncs.weather',
                self::intervalOf('30 minutes'),
                [StaleBehavior::Hide, StaleBehavior::None],
            );
        } catch (PixelCastConfigException $rejection) {
            self::assertStringContainsString('syncs.weather.staleBehavior', $rejection->getMessage());
            self::assertStringContainsString('expected one of: hide, none', $rejection->getMessage());

            return;
        }

        self::fail('The behaviour "dim" was accepted on the weather group.');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideRejectedStaleAfterValues(): iterable
    {
        yield 'negative' => [-1];
        yield 'above seven days' => [604801];
        yield 'not an integer' => ['5400'];
    }

    #[DataProvider('provideRejectedStaleAfterValues')]
    public function testARejectedStaleAfterNamesItsKey(mixed $rejectedValue): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage(self::PARENT_PATH.'.staleAfter');

        StaleDeclaration::fromOptions(['staleAfter' => $rejectedValue], self::PARENT_PATH, self::intervalOf('15 minutes'), StaleBehavior::cases());
    }

    public function testAnItemDeclaringNothingReusesBothValuesOfItsGroup(): void
    {
        $itemDeclaration = StaleDeclaration::inheritedFrom([], self::ITEM_PATH, self::groupDeclaration(), StaleBehavior::cases());

        self::assertSame(0, $itemDeclaration->staleAfterInSeconds);
        self::assertSame(StaleBehavior::Hide, $itemDeclaration->staleBehavior);
    }

    public function testAnItemOverridesEachValueOfItsGroupOnItsOwn(): void
    {
        $groupDeclaration = self::groupDeclaration();

        $overriddenSilence = StaleDeclaration::inheritedFrom(['staleAfter' => 5400], self::ITEM_PATH, $groupDeclaration, StaleBehavior::cases());
        self::assertSame(5400, $overriddenSilence->staleAfterInSeconds);
        self::assertSame(StaleBehavior::Hide, $overriddenSilence->staleBehavior);

        $overriddenBehaviour = StaleDeclaration::inheritedFrom(['staleBehavior' => 'dim'], self::ITEM_PATH, $groupDeclaration, StaleBehavior::cases());
        self::assertSame(0, $overriddenBehaviour->staleAfterInSeconds);
        self::assertSame(StaleBehavior::Dim, $overriddenBehaviour->staleBehavior);
    }

    #[DataProvider('provideRejectedStaleAfterValues')]
    public function testAStaleAfterRejectedOnAnItemNamesTheKeyOfThatItem(mixed $rejectedValue): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage(self::ITEM_PATH.'.staleAfter');

        StaleDeclaration::inheritedFrom(['staleAfter' => $rejectedValue], self::ITEM_PATH, self::groupDeclaration(), StaleBehavior::cases());
    }

    private static function groupDeclaration(): StaleDeclaration
    {
        return StaleDeclaration::fromOptions(
            ['staleAfter' => 0, 'staleBehavior' => 'hide'],
            self::PARENT_PATH,
            self::intervalOf('15 minutes'),
            StaleBehavior::cases(),
        );
    }

    private static function intervalOf(string $rawInterval): SyncInterval
    {
        return SyncInterval::fromOptions(['interval' => $rawInterval], self::PARENT_PATH);
    }
}
