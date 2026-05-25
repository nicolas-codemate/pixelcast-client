<?php

declare(strict_types=1);

namespace App\Tests\Tui\Scenarios;

use App\Tui\Scenarios\ScenarioDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScenarioDefinitionTest extends TestCase
{
    public function testConstructorStoresEveryFieldVerbatim(): void
    {
        $scenario = new ScenarioDefinition(
            id: 'weather',
            label: 'Weather',
            description: 'current conditions + 3-day forecast',
            httpMethod: 'POST',
            path: '/weather',
            queryParams: ['name' => 'BTC'],
            body: ['temp' => 22],
        );

        self::assertSame('weather', $scenario->id);
        self::assertSame('Weather', $scenario->label);
        self::assertSame('current conditions + 3-day forecast', $scenario->description);
        self::assertSame('POST', $scenario->httpMethod);
        self::assertSame('/weather', $scenario->path);
        self::assertSame(['name' => 'BTC'], $scenario->queryParams);
        self::assertSame(['temp' => 22], $scenario->body);
    }

    public function testDefaultValuesAreAppliedWhenOptionalArgumentsOmitted(): void
    {
        $scenario = new ScenarioDefinition(
            id: 'brightness',
            label: 'Brightness',
            description: 'set device brightness',
            httpMethod: 'POST',
            path: '/brightness',
        );

        self::assertSame([], $scenario->queryParams);
        self::assertNull($scenario->body);
    }

    /**
     * @return iterable<string,array{string,string,string}>
     */
    public static function provideEmptyRequiredFieldCases(): iterable
    {
        yield 'empty id' => ['', 'Weather', '/weather'];
        yield 'empty label' => ['weather', '', '/weather'];
        yield 'empty path' => ['weather', 'Weather', ''];
    }

    #[DataProvider('provideEmptyRequiredFieldCases')]
    public function testConstructorRejectsEmptyRequiredField(string $id, string $label, string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScenarioDefinition(
            id: $id,
            label: $label,
            description: 'some description',
            httpMethod: 'POST',
            path: $path,
        );
    }

    public function testConstructorRejectsUnknownHttpMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WHATEVER');

        new ScenarioDefinition(
            id: 'weather',
            label: 'Weather',
            description: 'current conditions',
            httpMethod: 'WHATEVER',
            path: '/weather',
        );
    }
}
