<?php

declare(strict_types=1);

namespace App\Tests\Client\Icon;

use App\Client\Exception\InvalidPayloadException;
use App\Client\Icon\IconUpload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IconUploadTest extends TestCase
{
    private const string PNG_CONTENTS = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDRpixels";
    private const string GIF_CONTENTS = "GIF89a\x10\x00\x10\x00pixels";

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedNameProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'with a dot' => ['bit.coin'];
        yield 'with a slash' => ['icons/bitcoin'];
        yield 'with a space' => ['bit coin'];
        yield 'with an accent' => ['icône'];
    }

    public function testAPngIsRecognisedAndNamedAfterItsIconName(): void
    {
        $iconUpload = IconUpload::fromContents('bitcoin', self::PNG_CONTENTS);

        self::assertSame('bitcoin', $iconUpload->name);
        self::assertSame(IconUpload::PNG_MIME_TYPE, $iconUpload->mimeType);
        self::assertSame('bitcoin.png', $iconUpload->fileName());
        self::assertSame(self::PNG_CONTENTS, $iconUpload->binaryContents);
    }

    public function testAGifIsRecognisedAndCarriesTheMatchingExtension(): void
    {
        $iconUpload = IconUpload::fromContents('spinner', self::GIF_CONTENTS);

        self::assertSame(IconUpload::GIF_MIME_TYPE, $iconUpload->mimeType);
        self::assertSame('spinner.gif', $iconUpload->fileName());
    }

    public function testTheOlderGif87SignatureIsAlsoAccepted(): void
    {
        $iconUpload = IconUpload::fromContents('spinner', "GIF87a\x10\x00\x10\x00pixels");

        self::assertSame(IconUpload::GIF_MIME_TYPE, $iconUpload->mimeType);
    }

    public function testAnyOtherFormatIsRejectedBeforeReachingTheDevice(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessageMatches('/neither a PNG nor a GIF/');

        IconUpload::fromContents('bitcoin', "\xff\xd8\xffJFIF");
    }

    #[DataProvider('rejectedNameProvider')]
    public function testANameTheDeviceCannotUseAsAFilenameIsRejected(string $rejectedName): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessageMatches('/letters, digits, dashes and underscores/');

        IconUpload::fromContents($rejectedName, self::PNG_CONTENTS);
    }

    public function testAFileIsReadFromDiskAndNamedAfterItsBasenameByDefault(): void
    {
        $filePath = $this->writeTemporaryIcon('github.png', self::PNG_CONTENTS);

        $iconUpload = IconUpload::fromFile($filePath);

        self::assertSame('github', $iconUpload->name);
        self::assertSame(self::PNG_CONTENTS, $iconUpload->binaryContents);
    }

    public function testAnExplicitNameOverridesTheFileBasename(): void
    {
        $filePath = $this->writeTemporaryIcon('downloaded-2867.png', self::PNG_CONTENTS);

        $iconUpload = IconUpload::fromFile($filePath, 'bitcoin');

        self::assertSame('bitcoin', $iconUpload->name);
    }

    public function testAnUnreadableFileIsReportedAsARejectedPayload(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessageMatches('/cannot be read/');

        IconUpload::fromFile(sys_get_temp_dir().'/pixelcast-icon-that-does-not-exist.png');
    }

    private function writeTemporaryIcon(string $fileName, string $contents): string
    {
        $directory = sys_get_temp_dir().'/pixelcast-icon-upload-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $filePath = $directory.'/'.$fileName;
        file_put_contents($filePath, $contents);

        register_shutdown_function(static function () use ($filePath, $directory): void {
            @unlink($filePath);
            @rmdir($directory);
        });

        return $filePath;
    }
}
