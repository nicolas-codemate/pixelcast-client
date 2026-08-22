<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Client\Exception\DeviceBusyException;
use App\Client\Icon\IconUpload;
use App\Command\IconsUploadCommand;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use App\Tests\Stub\RecordingPixelcastClientStub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IconsUploadCommandTest extends TestCase
{
    private const string GITHUB_ICON_RELATIVE_PATH = 'assets/icons/github.png';
    private const string PATH_OF_NO_FILE = '/tmp/pixelcast-icon-that-does-not-exist.png';

    private string|false $terminalWidthBeforeTheTest;

    protected function setUp(): void
    {
        parent::setUp();

        // Console blocks wrap at the terminal width, and a width of 80 would fold the messages these
        // assertions read.
        $this->terminalWidthBeforeTheTest = getenv('COLUMNS');
        putenv('COLUMNS=200');
    }

    protected function tearDown(): void
    {
        putenv(false === $this->terminalWidthBeforeTheTest ? 'COLUMNS' : 'COLUMNS='.$this->terminalWidthBeforeTheTest);

        parent::tearDown();
    }

    public function testALocalPngIsUploadedUnderTheGivenName(): void
    {
        $client = new RecordingPixelcastClientStub();
        $tester = new CommandTester(new IconsUploadCommand($client));

        $exitCode = $tester->execute(['name' => 'github', 'path' => self::githubIconPath()]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(1, $client->uploadedIcons);
        self::assertSame('github', $client->uploadedIcons[0]->name);
        self::assertSame(IconUpload::PNG_MIME_TYPE, $client->uploadedIcons[0]->mimeType);
        self::assertStringContainsString('github.png', $tester->getDisplay());
    }

    public function testAFileThatCannotBeReadStopsTheCommand(): void
    {
        $client = new RecordingPixelcastClientStub();
        $tester = new CommandTester(new IconsUploadCommand($client));

        $exitCode = $tester->execute(['name' => 'github', 'path' => self::PATH_OF_NO_FILE]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertSame([], $client->uploadedIcons);
    }

    public function testANameTheDeviceCannotStoreStopsTheCommand(): void
    {
        $client = new RecordingPixelcastClientStub();
        $tester = new CommandTester(new IconsUploadCommand($client));

        $exitCode = $tester->execute(['name' => 'bit coin', 'path' => self::githubIconPath()]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertSame([], $client->uploadedIcons);
    }

    public function testADeviceThatRefusesTheUploadFailsTheCommand(): void
    {
        $client = new RecordingPixelcastClientStub(DeviceBusyException::queueFull('/icons'));
        $tester = new CommandTester(new IconsUploadCommand($client));

        $exitCode = $tester->execute(['name' => 'github', 'path' => self::githubIconPath()]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame([], $client->uploadedIcons);
    }

    private static function githubIconPath(): string
    {
        return SyncsConfigLoaderFactory::projectFilePath(self::GITHUB_ICON_RELATIVE_PATH);
    }
}
