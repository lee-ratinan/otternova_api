<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\UploadedImage;
use App\Models\ArtifactImageModel;
use App\Models\ArtifactMasterModel;
use App\Models\ArtifactVariantModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\ImageUpload as ImageUploadConfig;
use RuntimeException;
use Throwable;

/**
 * The pictures of an artifact (artifact_image): the Client app's, behind the jwt:C filter (clients only), and only
 * for the active owners and managers of the artifact's business. Unlike Images::upload, an upload adds a row rather
 * than filling a column of one, and the file is named after the new row's public_id, never by the client.
 * The files are the artifact-image used-for of Config\ImageUpload, served by Images::show; its empty uploaders keep
 * the generic image-upload and image-delete away from them.
 * An image is sent as { public_id, variant_public_id, url, sort_order }; variant_public_id is null for one of the
 * whole artifact, and the lowest sort_order is the cover.
 */
class ArtifactImages extends BaseController
{
    use ManagedBusinessTrait;

    /** The Config\ImageUpload::$usedFor key of these images */
    public const USED_FOR = 'artifact-image';

    /**
     * POST /client/artifact/image-upload, multipart/form-data { artifact_public_id, variant_public_id, image }
     * Adds the image after the artifact's others, of the whole artifact or, with variant_public_id, of that variant
     * of it. Refused with 409 once the artifact has Config\ImageUpload::$maxArtifactImages.
     * @return ResponseInterface The image
     */
    public function upload(): ResponseInterface
    {
        $endpoint = 'v1/client/artifact/image-upload';
        $artifact = $this->managedArtifact($this->request->getPost('artifact_public_id'), $endpoint);
        if ($artifact instanceof ResponseInterface) {
            return $artifact;
        }

        $variantPublicId = $this->request->getPost('variant_public_id');
        $variantId       = null;
        if ($variantPublicId !== null && $variantPublicId !== '') {
            try {
                $variant = model(ArtifactVariantModel::class)->findByPublicId($variantPublicId);
            } catch (Throwable $e) {
                return $this->failed($endpoint, $e, 'ArtifactImage.loadFailed');
            }
            if ($variant === null || (int) $variant['artifact_id'] !== (int) $artifact['id']) {
                return ApiResponse::error(lang('ArtifactImage.invalidFields'), 422, ['variant_public_id' => lang('ArtifactImage.variantNotFound')]);
            }
            $variantId = (int) $variant['id'];
        }

        $file    = $this->request->getFile('image');
        $refusal = UploadedImage::refusal($file);
        if ($refusal !== null) {
            return $refusal;
        }

        $config = config(ImageUploadConfig::class);
        $rule   = $config->usedFor[self::USED_FOR];
        $folder = UploadedImage::folderOf(self::USED_FOR);
        try {
            $temp = UploadedImage::convertToTemp($file, $rule, $folder, 'artifact-' . $artifact['public_id']);
        } catch (RuntimeException $e) {
            return UploadedImage::conversionRefusal($e, $rule) ?? $this->failed($endpoint, $e, 'ArtifactImage.saveFailed');
        }

        $placed = null;
        try {
            $row = model(ArtifactImageModel::class)->addImage(
                (int) $artifact['id'],
                $variantId,
                $config->maxArtifactImages,
                $this->actorId(),
                static fn (string $publicId): string => base_url('image/' . self::USED_FOR . '/' . $publicId . '.webp'),
                static function (string $publicId) use ($temp, $folder, &$placed): void {
                    $path = $folder . $publicId . '.webp';
                    if (! @rename($temp, $path)) {
                        throw new RuntimeException('Cannot move the image to ' . $path);
                    }
                    $placed = $path;
                },
            );
        } catch (Throwable $e) {
            // the rows are rolled back; so is the file, when it got as far as its place
            @unlink($temp);
            if ($placed !== null) {
                @unlink($placed);
            }

            return $this->failed($endpoint, $e, 'ArtifactImage.saveFailed');
        }
        if ($row === null) {
            @unlink($temp);

            return ApiResponse::error(lang('ArtifactImage.limitReached', [$config->maxArtifactImages]), 409);
        }

        return ApiResponse::success([
            'public_id'         => $row['public_id'],
            'variant_public_id' => $variantId === null ? null : $variantPublicId,
            'url'               => $row['image_path'],
            'sort_order'        => $row['sort_order'],
        ], 200, lang('ArtifactImage.uploaded'));
    }

    /**
     * POST /client/artifact/image-delete { public_id }
     * Deletes the image's row and its file together; a file that will not go keeps the row.
     * @return ResponseInterface
     */
    public function delete(): ResponseInterface
    {
        $endpoint = 'v1/client/artifact/image-delete';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('ArtifactImage.invalidRequest'), 422);
        }
        $publicId = $input['public_id'] ?? null;
        if (! is_string($publicId) || $publicId === '') {
            return ApiResponse::error(lang('ArtifactImage.imageIdRequired'), 400);
        }

        $images = model(ArtifactImageModel::class);
        try {
            $image    = $images->findByPublicId($publicId);
            $artifact = $image === null ? null : model(ArtifactMasterModel::class)->find((int) $image['artifact_id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'ArtifactImage.loadFailed');
        }
        // an image of a business the client does not manage is as unknown as one that is not there
        $error = $artifact === null
            ? ApiResponse::error(lang('ArtifactImage.notFound'), 404)
            : $this->roleError((int) $artifact['business_id'], $endpoint, lang('ArtifactImage.notFound'), 'ArtifactImage');
        if ($error !== null) {
            return $error;
        }

        $path = UploadedImage::folderOf(self::USED_FOR) . $image['public_id'] . '.webp';
        try {
            $images->deleteImage($image, $this->actorId(), static function () use ($path): void {
                if (is_file($path) && ! @unlink($path)) {
                    throw new RuntimeException('Cannot delete ' . $path);
                }
            });
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'ArtifactImage.deleteFailed');
        }

        return ApiResponse::success(['public_id' => $image['public_id']], 200, lang('ArtifactImage.deleted'));
    }

    /**
     * POST /client/artifact/image-reorder { artifact_public_id, order: [public_id, ...] }
     * Puts the artifact's images in the order given, the first being the cover; "order" lists every one of them,
     * each once.
     * @return ResponseInterface The artifact's images, in their new order
     */
    public function reorder(): ResponseInterface
    {
        $endpoint = 'v1/client/artifact/image-reorder';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('ArtifactImage.invalidRequest'), 422);
        }
        $artifact = $this->managedArtifact($input['artifact_public_id'] ?? null, $endpoint);
        if ($artifact instanceof ResponseInterface) {
            return $artifact;
        }
        $order = $input['order'] ?? null;
        if (! is_array($order) || ! array_is_list($order) || array_filter($order, 'is_string') !== $order) {
            return ApiResponse::error(lang('ArtifactImage.invalidFields'), 422, ['order' => lang('ArtifactImage.invalidOrder')]);
        }

        $images = model(ArtifactImageModel::class);
        try {
            if (! $images->reorder((int) $artifact['id'], $order, $this->actorId())) {
                return ApiResponse::error(lang('ArtifactImage.invalidFields'), 422, ['order' => lang('ArtifactImage.invalidOrder')]);
            }
            $list = $images->listOfArtifact((int) $artifact['id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'ArtifactImage.saveFailed');
        }

        return ApiResponse::success(['images' => $list], 200, lang('ArtifactImage.reordered'));
    }

    /**
     * The artifact of a public_id from the request, when the signed-in client is an active owner or manager of its
     * business
     * @param mixed  $publicId
     * @param string $endpoint for the error log
     * @return array|ResponseInterface The artifact as stored, or the refusal
     */
    private function managedArtifact(mixed $publicId, string $endpoint): array|ResponseInterface
    {
        if (! is_string($publicId) || $publicId === '') {
            return ApiResponse::error(lang('ArtifactImage.artifactIdRequired'), 400);
        }
        try {
            $artifact = model(ArtifactMasterModel::class)->findByPublicId($publicId);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'ArtifactImage.loadFailed');
        }
        if ($artifact === null) {
            return ApiResponse::error(lang('ArtifactImage.artifactNotFound'), 404);
        }

        return $this->roleError((int) $artifact['business_id'], $endpoint, lang('ArtifactImage.artifactNotFound'), 'ArtifactImage') ?? $artifact;
    }

    /**
     * auth_master.id of the signed-in account, for log_activity.changed_by_acc
     * @return int
     */
    private function actorId(): int
    {
        return (int) service('authContext')->account()['id'];
    }

    /**
     * The JSON body as an array: an empty body counts as {}, and anything that is not a JSON object is null
     * @return array|null
     */
    private function jsonInput(): ?array
    {
        if (trim((string) $this->request->getBody()) === '') {
            return [];
        }

        try {
            $input = $this->request->getJSON(true);
        } catch (HTTPException) {
            return null;
        }

        return is_array($input) && ($input === [] || ! array_is_list($input)) ? $input : null;
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
