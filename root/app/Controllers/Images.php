<?php

namespace App\Controllers;

use App\Filters\JwtAuth;
use App\Libraries\ApiResponse;
use App\Libraries\ImageProcessor;
use App\Models\ImageTargetModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\ImageUpload as ImageUploadConfig;
use RuntimeException;
use Throwable;

/**
 * Image uploads for both apps, and the image/{used-for}/{image-name}.webp route that serves them back.
 * What each used-for allows is set in Config\ImageUpload.
 */
class Images extends BaseController
{
    /** An image-name: it becomes a file name, so no dots or slashes */
    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/';

    /** Seconds a browser may reuse an image before asking again (it gets a 304 when unchanged) */
    private const CACHE_SECONDS = 300;

    /**
     * POST /staff/image-upload and /client/image-upload, multipart/form-data { used-for, image-name, id, image }
     * Checks the used-for is one this account type may upload and the row is one it may change, turns the image
     * into a WebP of the used-for's size at {uploadPath}{used-for}/{image-name}.webp, and saves its URL
     * base_url('image/{used-for}/{image-name}.webp') to the used-for's target column of row id.
     * An image-name already used by another row is refused; re-using the row's own name replaces its image,
     * and a new name removes the row's previous file.
     * @return ResponseInterface
     */
    public function upload(): ResponseInterface
    {
        $config  = config(ImageUploadConfig::class);
        $usedFor = $this->request->getPost('used-for');
        $rule    = is_string($usedFor) ? ($config->usedFor[$usedFor] ?? null) : null;
        if ($rule === null) {
            return ApiResponse::error(lang('ImageUpload.invalidUsedFor'), 422, ['used-for' => lang('ImageUpload.invalidUsedFor')]);
        }
        $account     = service('authContext')->account();
        $accountType = (string) service('authContext')->claims()->typ;
        if (! in_array($accountType, $rule['uploaders'], true)) {
            return ApiResponse::error(lang('ImageUpload.usedForNotAllowed'), 403);
        }

        $name = $this->request->getPost('image-name');
        if (! is_string($name) || ! preg_match(self::NAME_PATTERN, $name)) {
            return ApiResponse::error(lang('ImageUpload.invalidImageName'), 422, ['image-name' => lang('ImageUpload.invalidImageName')]);
        }
        $id = filter_var($this->request->getPost('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return ApiResponse::error(lang('ImageUpload.invalidId'), 422, ['id' => lang('ImageUpload.invalidId')]);
        }

        $processor = service('imageProcessor');
        $file      = $this->request->getFile('image');
        if ($file === null || ! $processor->isGenuineUpload($file)) {
            return ApiResponse::error(lang('ImageUpload.imageRequired'), 422, ['image' => lang('ImageUpload.imageRequired')]);
        }
        if ($file->getSize() > $config->maxFileSize) {
            return ApiResponse::error(lang('ImageUpload.tooLarge', [intdiv($config->maxFileSize, 1024 * 1024)]), 413);
        }
        // detected from the content: the client's claimed type and extension are not trusted
        if (! in_array($file->getMimeType(), $config->allowedMimeTypes, true)) {
            return ApiResponse::error(lang('ImageUpload.invalidType'), 415);
        }

        [$table, $column] = explode('.', $rule['target'], 2);
        $targets          = model(ImageTargetModel::class);
        try {
            $row = $targets->findTarget($table, [$column, $rule['ownerColumn']], $id);
        } catch (DatabaseException $e) {
            log_message('error', 'v1/image-upload: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('ImageUpload.saveFailed'), 500);
        }
        if ($row === null) {
            return ApiResponse::error(lang('ImageUpload.targetNotFound'), 404);
        }
        if (in_array($accountType, $rule['ownRowOnly'], true) && (int) $row[$rule['ownerColumn']] !== (int) $account['id']) {
            return ApiResponse::error(lang('ImageUpload.notOwnRecord'), 403);
        }

        $folder = rtrim($config->uploadPath, '/') . '/' . $usedFor . '/';
        $path   = $folder . $name . '.webp';
        $url    = base_url('image/' . $usedFor . '/' . $name . '.webp');
        $oldUrl = $row[$column];
        if (is_file($path) && $oldUrl !== $url) {
            return ApiResponse::error(lang('ImageUpload.nameTaken'), 409, ['image-name' => lang('ImageUpload.nameTaken')]);
        }
        if (! is_dir($folder) && ! @mkdir($folder, 0755, true) && ! is_dir($folder)) {
            log_message('error', 'v1/image-upload: cannot create {folder}', ['folder' => $folder]);

            return ApiResponse::error(lang('ImageUpload.saveFailed'), 500);
        }

        // converted beside the final file first; the leading dot keeps it out of NAME_PATTERN and so never served
        $temp = $folder . '.' . $name . '.' . bin2hex(random_bytes(6)) . '.webp';
        try {
            $processor->convert($file->getTempName(), $temp, $rule['width'], $rule['height'], $config->quality);
            $targets->setImageUrl($table, $column, $row, $url, (int) $account['id'], 'Uploaded ' . $usedFor . ' ' . $name, static function () use ($temp, $path) {
                if (! @rename($temp, $path)) {
                    throw new RuntimeException(ImageProcessor::ERROR_SAVE);
                }
            });
        } catch (Throwable $e) {
            @unlink($temp);

            return match ($e->getMessage()) {
                ImageProcessor::ERROR_TOO_SMALL  => ApiResponse::error(lang('ImageUpload.tooSmall', [$rule['width'], $rule['height']]), 422),
                ImageProcessor::ERROR_UNREADABLE => ApiResponse::error(lang('ImageUpload.unreadable'), 422),
                default                          => $this->saveFailed($e),
            };
        }

        $this->removeReplacedFile($oldUrl, $url, $usedFor, $folder);

        return ApiResponse::success(['used_for' => $usedFor, 'url' => $url], 200, lang('ImageUpload.uploaded'));
    }

    /**
     * GET image/{used-for}/{image-name}.webp
     * The image, when its used-for's access lets the caller see it: ACCESS_PUBLIC to anyone, otherwise to a
     * signed-in account only (checked as the jwt filter does). Unknown used-fors and names are 404, after the
     * access check, so a caller who may not see a used-for cannot learn which images it has.
     * @param string $usedFor
     * @param string $file
     * @return ResponseInterface
     */
    public function show(string $usedFor, string $file): ResponseInterface
    {
        $config = config(ImageUploadConfig::class);
        $rule   = $config->usedFor[$usedFor] ?? null;
        if ($rule === null) {
            return ApiResponse::error(lang('ImageUpload.notFound'), 404);
        }
        $public = $rule['access'] === ImageUploadConfig::ACCESS_PUBLIC; // anything else is treated as signed-in
        if (! $public) {
            $refused = (new JwtAuth())->before($this->request);
            if ($refused !== null) {
                return $refused;
            }
        }

        $name = substr($file, 0, -strlen('.webp'));
        $path = rtrim($config->uploadPath, '/') . '/' . $usedFor . '/' . $name . '.webp';
        if (! str_ends_with($file, '.webp') || ! preg_match(self::NAME_PATTERN, $name) || ! is_file($path)) {
            return ApiResponse::error(lang('ImageUpload.notFound'), 404);
        }

        $etag = '"' . dechex((int) filemtime($path)) . '-' . dechex((int) filesize($path)) . '"';
        // removed first: the response starts with a no-store Cache-Control that setHeader() would add to
        $this->response
            ->removeHeader('Cache-Control')
            ->setHeader('Cache-Control', ($public ? 'public' : 'private') . ', max-age=' . self::CACHE_SECONDS)
            ->setHeader('ETag', $etag)
            ->setHeader('X-Content-Type-Options', 'nosniff');
        if (trim($this->request->getHeaderLine('If-None-Match')) === $etag) {
            return $this->response->setStatusCode(304);
        }

        return $this->response->setContentType('image/webp', '')->setBody((string) file_get_contents($path));
    }

    /**
     * @param Throwable $e
     * @return ResponseInterface
     */
    private function saveFailed(Throwable $e): ResponseInterface
    {
        log_message('error', 'v1/image-upload: {message}', ['message' => $e->getMessage()]);

        return ApiResponse::error(lang('ImageUpload.saveFailed'), 500);
    }

    /**
     * Delete the file the row pointed at before, when it was another image of the same used-for.
     * Safe as an image-name belongs to one row only (see upload()).
     * @param string|null $oldUrl
     * @param string      $url
     * @param string      $usedFor
     * @param string      $folder
     * @return void
     */
    private function removeReplacedFile(?string $oldUrl, string $url, string $usedFor, string $folder): void
    {
        $prefix = base_url('image/' . $usedFor . '/');
        if ($oldUrl === null || $oldUrl === $url || ! str_starts_with($oldUrl, $prefix) || ! str_ends_with($oldUrl, '.webp')) {
            return;
        }
        $oldName = substr($oldUrl, strlen($prefix), -strlen('.webp'));
        if (preg_match(self::NAME_PATTERN, $oldName)) {
            @unlink($folder . $oldName . '.webp');
        }
    }
}
