<?php

declare(strict_types=1);

namespace App\Tests\Client\Http;

use App\Client\Http\MultipartFormDataBody;
use PHPUnit\Framework\TestCase;

final class MultipartFormDataBodyTest extends TestCase
{
    private const string BINARY_CONTENTS = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDRpixels";

    public function testTheFilePartCarriesItsNameItsFilenameItsTypeAndItsBytesBetweenTheBoundaries(): void
    {
        $multipartBody = MultipartFormDataBody::forFile('file', 'bitcoin.png', 'image/png', self::BINARY_CONTENTS, 'fixedboundary');

        self::assertSame(
            "--fixedboundary\r\n"
            ."Content-Disposition: form-data; name=\"file\"; filename=\"bitcoin.png\"\r\n"
            ."Content-Type: image/png\r\n"
            ."\r\n"
            .self::BINARY_CONTENTS."\r\n"
            ."--fixedboundary--\r\n",
            $multipartBody->contents,
        );
    }

    public function testTheContentTypeHeaderNamesTheBoundaryUsedByTheBody(): void
    {
        $multipartBody = MultipartFormDataBody::forFile('file', 'bitcoin.png', 'image/png', self::BINARY_CONTENTS, 'fixedboundary');

        self::assertSame('multipart/form-data; boundary=fixedboundary', $multipartBody->contentTypeHeader());
    }

    public function testTwoBodiesBuiltWithoutAnExplicitBoundaryDoNotShareOne(): void
    {
        $firstBody = MultipartFormDataBody::forFile('file', 'bitcoin.png', 'image/png', self::BINARY_CONTENTS);
        $secondBody = MultipartFormDataBody::forFile('file', 'bitcoin.png', 'image/png', self::BINARY_CONTENTS);

        self::assertNotSame($firstBody->boundary, $secondBody->boundary);
        self::assertStringContainsString('--'.$firstBody->boundary."--\r\n", $firstBody->contents);
    }
}
