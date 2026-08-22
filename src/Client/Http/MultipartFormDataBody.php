<?php

declare(strict_types=1);

namespace App\Client\Http;

/**
 * A multipart/form-data body carrying a single file part, built by hand because the
 * project does not pull symfony/mime and its FormDataPart.
 */
final readonly class MultipartFormDataBody
{
    private const string LINE_BREAK = "\r\n";

    private function __construct(
        public string $boundary,
        public string $contents,
    ) {
    }

    public static function forFile(string $fieldName, string $fileName, string $mimeType, string $binaryContents, ?string $boundary = null): self
    {
        $boundary ??= bin2hex(random_bytes(16));

        $contents = '--'.$boundary.self::LINE_BREAK
            .\sprintf('Content-Disposition: form-data; name="%s"; filename="%s"', $fieldName, $fileName).self::LINE_BREAK
            .'Content-Type: '.$mimeType.self::LINE_BREAK
            .self::LINE_BREAK
            .$binaryContents.self::LINE_BREAK
            .'--'.$boundary.'--'.self::LINE_BREAK;

        return new self($boundary, $contents);
    }

    public function contentTypeHeader(): string
    {
        return 'multipart/form-data; boundary='.$this->boundary;
    }
}
