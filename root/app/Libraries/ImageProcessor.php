<?php

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;
use RuntimeException;

/**
 * Turns an uploaded JPEG, PNG or WebP into a WebP of an exact size, with GD: turned upright by its EXIF orientation,
 * cropped to the target's aspect ratio around the centre, then scaled down. Never scales up.
 * GD is used directly rather than the CodeIgniter image handler, which keeps the pre-rotation size for fit().
 */
class ImageProcessor
{
    /** Why convert() failed, for the controller to pick a message */
    public const ERROR_UNREADABLE = 'unreadable';
    public const ERROR_TOO_SMALL  = 'too-small';
    public const ERROR_SAVE       = 'save';

    /**
     * A file that really came in with this request; the tests replace this to use files on disk
     * @param UploadedFile $file
     * @return bool
     */
    public function isGenuineUpload(UploadedFile $file): bool
    {
        return $file->isValid();
    }

    /**
     * @param string $source  An image file
     * @param string $target  Where the WebP goes; overwritten
     * @param int    $width
     * @param int    $height
     * @param int    $quality 0-100
     * @return void
     * @throws RuntimeException With one of the ERROR_ codes as its message
     */
    public function convert(string $source, string $target, int $width, int $height, int $quality): void
    {
        $image      = $this->upright($this->open($source), $source);
        $origWidth  = imagesx($image);
        $origHeight = imagesy($image);
        if ($origWidth < $width || $origHeight < $height) {
            throw new RuntimeException(self::ERROR_TOO_SMALL);
        }

        // the largest centred box with the target's aspect ratio
        $cropWidth  = min($origWidth, (int) round($origHeight * $width / $height));
        $cropHeight = min($origHeight, (int) round($origWidth * $height / $width));
        $x          = intdiv($origWidth - $cropWidth, 2);
        $y          = intdiv($origHeight - $cropHeight, 2);

        $resized = imagecreatetruecolor($width, $height);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
        imagecopyresampled($resized, $image, 0, 0, $x, $y, $width, $height, $cropWidth, $cropHeight);

        if (! @imagewebp($resized, $target, $quality)) {
            throw new RuntimeException(self::ERROR_SAVE);
        }
    }

    /**
     * @param string $source
     * @return \GdImage
     * @throws RuntimeException
     */
    private function open(string $source): \GdImage
    {
        $type  = @getimagesize($source)[2] ?? null;
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG  => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
            default        => false,
        };
        if ($image === false) {
            throw new RuntimeException(self::ERROR_UNREADABLE);
        }
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image); // a palette PNG would lose its transparency on the copy
        }

        return $image;
    }

    /**
     * Apply a JPEG's EXIF orientation, as phone cameras store the pixels sideways and say so there
     * @param \GdImage $image
     * @param string   $source
     * @return \GdImage
     */
    private function upright(\GdImage $image, string $source): \GdImage
    {
        if (! function_exists('exif_read_data') || (@getimagesize($source)[2] ?? null) !== IMAGETYPE_JPEG) {
            return $image;
        }
        $orientation = (int) (@exif_read_data($source)['Orientation'] ?? 1);

        // imagerotate turns anticlockwise
        $rotated = match ($orientation) {
            3, 4    => imagerotate($image, 180, 0),
            5, 6    => imagerotate($image, 270, 0),
            7, 8    => imagerotate($image, 90, 0),
            default => $image,
        };
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
        }

        return $rotated;
    }
}
