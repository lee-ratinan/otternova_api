<?php

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;
use Config\ImageUpload as ImageUploadConfig;
use RuntimeException;

/**
 * The steps every image upload shares (Images::upload, ArtifactImages::upload): checking the uploaded file, and
 * turning it into a WebP of the used-for's size beside where it is finally kept. The converted file's name starts with
 * a dot, which no image name may, so it is never served; the caller renames it into place, or deletes it.
 */
class UploadedImage
{
    /**
     * The refusal of the request's image, or null when it is one to convert: present, genuine, not too large, and
     * a type of $allowedMimeTypes as detected from its content (the client's claimed type and extension are not trusted)
     * @param UploadedFile|null $file
     * @return ResponseInterface|null
     */
    public static function refusal(?UploadedFile $file): ?ResponseInterface
    {
        $config = config(ImageUploadConfig::class);
        if ($file === null || ! service('imageProcessor')->isGenuineUpload($file)) {
            return ApiResponse::error(lang('ImageUpload.imageRequired'), 422, ['image' => lang('ImageUpload.imageRequired')]);
        }
        if ($file->getSize() > $config->maxFileSize) {
            return ApiResponse::error(lang('ImageUpload.tooLarge', [intdiv($config->maxFileSize, 1024 * 1024)]), 413);
        }
        if (! in_array($file->getMimeType(), $config->allowedMimeTypes, true)) {
            return ApiResponse::error(lang('ImageUpload.invalidType'), 415);
        }

        return null;
    }

    /**
     * Where the files of a used-for are kept
     * @param string $usedFor
     * @return string With a trailing slash
     */
    public static function folderOf(string $usedFor): string
    {
        return rtrim(config(ImageUploadConfig::class)->uploadPath, '/') . '/' . $usedFor . '/';
    }

    /**
     * Convert $file to a WebP of the rule's size in $folder (made when missing), under a temporary dot-name
     * @param UploadedFile $file
     * @param array        $rule   The used-for's Config\ImageUpload::$usedFor entry
     * @param string       $folder From folderOf()
     * @param string       $name   The image name the file will get, for the temporary name
     * @return string The temporary file
     * @throws RuntimeException With an ImageProcessor::ERROR_ code as its message, or another one when the folder
     *                          cannot be made; nothing is left behind
     */
    public static function convertToTemp(UploadedFile $file, array $rule, string $folder, string $name): string
    {
        if (! is_dir($folder) && ! @mkdir($folder, 0755, true) && ! is_dir($folder)) {
            throw new RuntimeException('Cannot create ' . $folder);
        }
        $temp = $folder . '.' . $name . '.' . bin2hex(random_bytes(6)) . '.webp';
        try {
            service('imageProcessor')->convert($file->getTempName(), $temp, $rule['width'], $rule['height'], config(ImageUploadConfig::class)->quality);
        } catch (RuntimeException $e) {
            @unlink($temp);

            throw $e;
        }

        return $temp;
    }

    /**
     * The 422 for an image convert() turned down, or null for any other failure (a 500 for the caller to log)
     * @param RuntimeException $e    From convertToTemp()
     * @param array            $rule The used-for's entry, for the size in the message
     * @return ResponseInterface|null
     */
    public static function conversionRefusal(RuntimeException $e, array $rule): ?ResponseInterface
    {
        return match ($e->getMessage()) {
            ImageProcessor::ERROR_TOO_SMALL  => ApiResponse::error(lang('ImageUpload.tooSmall', [$rule['width'], $rule['height']]), 422),
            ImageProcessor::ERROR_UNREADABLE => ApiResponse::error(lang('ImageUpload.unreadable'), 422),
            default                          => null,
        };
    }
}
