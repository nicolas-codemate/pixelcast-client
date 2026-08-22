<?php

declare(strict_types=1);

namespace App\Client\Icon;

use App\Client\Exception\InvalidPayloadException;

/**
 * A PNG or GIF ready to be posted to the device, checked before it leaves the client.
 * The device only accepts these two formats, and stores the icon under a name it also
 * uses as a filename, hence the restricted character set.
 */
final readonly class IconUpload
{
    public const string PNG_MIME_TYPE = 'image/png';
    public const string GIF_MIME_TYPE = 'image/gif';

    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";
    private const string GIF87_SIGNATURE = 'GIF87a';
    private const string GIF89_SIGNATURE = 'GIF89a';
    private const string ACCEPTED_NAME_PATTERN = '/^[a-zA-Z0-9_-]+$/';

    private function __construct(
        public string $name,
        public string $mimeType,
        public string $binaryContents,
    ) {
    }

    public static function fromFile(string $filePath, ?string $name = null): self
    {
        $binaryContents = @file_get_contents($filePath);

        if (false === $binaryContents) {
            throw InvalidPayloadException::fromLocalValidation('/icons', \sprintf('icon file "%s" cannot be read', $filePath));
        }

        return self::fromContents($name ?? pathinfo($filePath, \PATHINFO_FILENAME), $binaryContents);
    }

    public static function fromContents(string $name, string $binaryContents): self
    {
        if (1 !== preg_match(self::ACCEPTED_NAME_PATTERN, $name)) {
            throw InvalidPayloadException::fromLocalValidation('/icons', \sprintf('icon name "%s" must only carry letters, digits, dashes and underscores', $name));
        }

        return new self($name, self::detectMimeType($binaryContents), $binaryContents);
    }

    public function fileName(): string
    {
        return $this->name.(self::GIF_MIME_TYPE === $this->mimeType ? '.gif' : '.png');
    }

    private static function detectMimeType(string $binaryContents): string
    {
        if (str_starts_with($binaryContents, self::PNG_SIGNATURE)) {
            return self::PNG_MIME_TYPE;
        }

        if (str_starts_with($binaryContents, self::GIF87_SIGNATURE) || str_starts_with($binaryContents, self::GIF89_SIGNATURE)) {
            return self::GIF_MIME_TYPE;
        }

        throw InvalidPayloadException::fromLocalValidation('/icons', 'icon contents are neither a PNG nor a GIF');
    }
}
