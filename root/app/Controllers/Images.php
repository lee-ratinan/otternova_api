<?php

namespace App\Controllers;

use App\Filters\JwtAuth;
use App\Libraries\ApiResponse;
use App\Libraries\ImageProcessor;
use App\Libraries\InitialsAvatar;
use App\Models\ImageTargetModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\ImageUpload as ImageUploadConfig;
use RuntimeException;
use Throwable;

/**
 * Image uploads and deletes for both apps, and the image/{used-for}/{image-name}.webp route that serves them back.
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
        $rule    = $this->ruleFor($usedFor);
        if ($rule instanceof ResponseInterface) {
            return $rule;
        }

        $name = $this->request->getPost('image-name');
        if (! is_string($name) || ! preg_match(self::NAME_PATTERN, $name)) {
            return ApiResponse::error(lang('ImageUpload.invalidImageName'), 422, ['image-name' => lang('ImageUpload.invalidImageName')]);
        }
        $id = $this->validId($this->request->getPost('id'));
        if ($id === null) {
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
        $row              = $this->targetRow($rule, $id, 'v1/image-upload');
        if ($row instanceof ResponseInterface) {
            return $row;
        }

        $folder = $this->folderOf($usedFor);
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
            model(ImageTargetModel::class)->setImageUrl($table, $column, $row, $url, $this->actorId(), 'Uploaded ' . $usedFor . ' ' . $name, static function () use ($temp, $path) {
                if (! @rename($temp, $path)) {
                    throw new RuntimeException(ImageProcessor::ERROR_SAVE);
                }
            });
        } catch (Throwable $e) {
            @unlink($temp);

            return match ($e->getMessage()) {
                ImageProcessor::ERROR_TOO_SMALL  => ApiResponse::error(lang('ImageUpload.tooSmall', [$rule['width'], $rule['height']]), 422),
                ImageProcessor::ERROR_UNREADABLE => ApiResponse::error(lang('ImageUpload.unreadable'), 422),
                default                          => $this->failed('v1/image-upload', $e, 'ImageUpload.saveFailed'),
            };
        }

        // a new name leaves the previous file behind; safe to delete, as an image-name belongs to one row only
        $oldPath = $this->fileOf($oldUrl, $usedFor);
        if ($oldPath !== null && $oldPath !== $path) {
            @unlink($oldPath);
        }

        return ApiResponse::success(['used_for' => $usedFor, 'url' => $url], 200, lang('ImageUpload.uploaded'));
    }

    /**
     * POST /staff/image-delete and /client/image-delete { used-for, id }, as JSON or form fields
     * Upload in reverse: the same checks of used-for and row, then the target column of row id is set to null and
     * the file it pointed at is deleted. A column that points somewhere other than an uploaded file of this
     * used-for is set to null too, leaving the files alone.
     * A used-for kept in auth_master.avatar_path answers with the url of the account's initials avatar, the
     * avatar_path the app now shows in place of the deleted picture (null when full_name gives no initials).
     * @return ResponseInterface
     */
    public function delete(): ResponseInterface
    {
        try {
            $usedFor = $this->request->getVar('used-for');
            $id      = $this->validId($this->request->getVar('id'));
        } catch (HTTPException) {
            return ApiResponse::error(lang('ImageUpload.invalidUsedFor'), 422); // JSON that could not be read
        }
        $rule = $this->ruleFor($usedFor);
        if ($rule instanceof ResponseInterface) {
            return $rule;
        }
        if ($id === null) {
            return ApiResponse::error(lang('ImageUpload.invalidId'), 422, ['id' => lang('ImageUpload.invalidId')]);
        }

        [$table, $column] = explode('.', $rule['target'], 2);
        $row              = $this->targetRow($rule, $id, 'v1/image-delete');
        if ($row instanceof ResponseInterface) {
            return $row;
        }
        if ($row[$column] === null || $row[$column] === '') {
            return ApiResponse::error(lang('ImageUpload.noImage'), 404);
        }

        $path = $this->fileOf($row[$column], $usedFor);
        try {
            // deleted inside the transaction, so a file that will not go leaves the row as it was
            model(ImageTargetModel::class)->setImageUrl($table, $column, $row, null, $this->actorId(), 'Deleted ' . $usedFor . ' ' . basename((string) $path, '.webp'), static function () use ($path) {
                if ($path !== null && is_file($path) && ! @unlink($path)) {
                    throw new RuntimeException('Cannot delete ' . $path);
                }
            });
        } catch (Throwable $e) {
            return $this->failed('v1/image-delete', $e, 'ImageUpload.deleteFailed');
        }

        $data = ['used_for' => $usedFor];
        if ($rule['target'] === 'auth_master.avatar_path') {
            $account     = model(ImageTargetModel::class)->findTarget('auth_master', ['full_name'], $id);
            $data['url'] = InitialsAvatar::urlFor($account['full_name'] ?? null);
        }

        return ApiResponse::success($data, 200, lang('ImageUpload.deleted'));
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
        $path = $this->folderOf($usedFor) . $name . '.webp';
        if (! str_ends_with($file, '.webp') || ! preg_match(self::NAME_PATTERN, $name) || ! is_file($path)) {
            return ApiResponse::error(lang('ImageUpload.notFound'), 404);
        }

        return $this->sendFile($path, 'image/webp', $public);
    }

    /**
     * GET image/initials-avatar/{initials}.svg
     * An initials avatar made by InitialsAvatar, public like profile-picture. Only files already made are served:
     * they are made when a user object goes out, never here.
     * @param string $file
     * @return ResponseInterface
     */
    public function initials(string $file): ResponseInterface
    {
        $name = substr($file, 0, -strlen('.svg'));
        $path = InitialsAvatar::folder() . $name . '.svg';
        if (! str_ends_with($file, '.svg') || ! preg_match(InitialsAvatar::NAME_PATTERN, $name) || ! is_file($path)) {
            return ApiResponse::error(lang('ImageUpload.notFound'), 404);
        }

        return $this->sendFile($path, 'image/svg+xml', true);
    }

    /**
     * The file with its caching headers, or 304 when the caller already has it
     * @param string $path
     * @param string $contentType
     * @param bool   $public
     * @return ResponseInterface
     */
    private function sendFile(string $path, string $contentType, bool $public): ResponseInterface
    {
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

        return $this->response->setContentType($contentType, '')->setBody((string) file_get_contents($path));
    }

    /**
     * The config of a used-for the signed-in account type may upload (and so delete)
     * @param mixed $usedFor From the request
     * @return array|ResponseInterface The refusal when not
     */
    private function ruleFor(mixed $usedFor): array|ResponseInterface
    {
        $rule = is_string($usedFor) ? (config(ImageUploadConfig::class)->usedFor[$usedFor] ?? null) : null;
        if ($rule === null) {
            return ApiResponse::error(lang('ImageUpload.invalidUsedFor'), 422, ['used-for' => lang('ImageUpload.invalidUsedFor')]);
        }
        if (! in_array((string) service('authContext')->claims()->typ, $rule['uploaders'], true)) {
            return ApiResponse::error(lang('ImageUpload.usedForNotAllowed'), 403);
        }

        return $rule;
    }

    /**
     * @param mixed $id From the request
     * @return int|null Null unless a positive integer
     */
    private function validId(mixed $id): ?int
    {
        $id = is_int($id) || is_string($id) ? filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        return $id === false ? null : $id;
    }

    /**
     * Row id of the used-for's target table, when the signed-in account may change it: an account type in
     * ownRowOnly only the row it owns
     * @param array  $rule
     * @param int    $id
     * @param string $endpoint For the error log
     * @return array|ResponseInterface The row's id, target column and owner column, or the refusal
     */
    private function targetRow(array $rule, int $id, string $endpoint): array|ResponseInterface
    {
        [$table, $column] = explode('.', $rule['target'], 2);
        try {
            $row = model(ImageTargetModel::class)->findTarget($table, [$column, $rule['ownerColumn']], $id);
        } catch (DatabaseException $e) {
            return $this->failed($endpoint, $e, 'ImageUpload.loadFailed');
        }
        if ($row === null) {
            return ApiResponse::error(lang('ImageUpload.targetNotFound'), 404);
        }
        $accountType = (string) service('authContext')->claims()->typ;
        if (in_array($accountType, $rule['ownRowOnly'], true) && (int) $row[$rule['ownerColumn']] !== $this->actorId()) {
            return ApiResponse::error(lang('ImageUpload.notOwnRecord'), 403);
        }

        return $row;
    }

    /**
     * @param string $usedFor
     * @return string With a trailing slash
     */
    private function folderOf(string $usedFor): string
    {
        return rtrim(config(ImageUploadConfig::class)->uploadPath, '/') . '/' . $usedFor . '/';
    }

    /**
     * The file behind a URL saved by upload(): base_url('image/{used-for}/{image-name}.webp')
     * @param string|null $url
     * @param string      $usedFor
     * @return string|null Null for any other value
     */
    private function fileOf(?string $url, string $usedFor): ?string
    {
        $prefix = base_url('image/' . $usedFor . '/');
        if ($url === null || ! str_starts_with($url, $prefix) || ! str_ends_with($url, '.webp')) {
            return null;
        }
        $name = substr($url, strlen($prefix), -strlen('.webp'));

        return preg_match(self::NAME_PATTERN, $name) ? $this->folderOf($usedFor) . $name . '.webp' : null;
    }

    /**
     * auth_master.id of the signed-in account, for log_activity.changed_by_acc and the ownRowOnly check
     * @return int
     */
    private function actorId(): int
    {
        return (int) service('authContext')->account()['id'];
    }

    /**
     * @param string    $endpoint For the error log
     * @param Throwable $e
     * @param string    $message  Language key shown to the user
     * @return ResponseInterface
     */
    private function failed(string $endpoint, Throwable $e, string $message): ResponseInterface
    {
        log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

        return ApiResponse::error(lang($message), 500);
    }
}
