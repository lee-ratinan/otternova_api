<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Settings for the image upload endpoints (/staff/image-upload, /client/image-upload) and the image/{used-for}/{name}.webp
 * route that serves the files back. Every upload names one of the $usedFor keys, which decides who may upload it,
 * its size, the table column that keeps its URL and who may see it.
 */
class ImageUpload extends BaseConfig
{
    /** Anyone may see the image, signed in or not */
    public const ACCESS_PUBLIC = 'public';

    /** Only a signed-in account (staff or client) may see the image: Authorization: Bearer <jwt> */
    public const ACCESS_SIGNED_IN = 'signed-in';

    /** Files are kept at {$uploadPath}{used-for}/{image-name}.webp */
    public string $uploadPath = WRITEPATH . 'uploads/';

    /** Detected (not claimed) MIME types accepted; everything is saved as WebP */
    public array $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];

    /** Largest upload accepted, in bytes */
    public int $maxFileSize = 10 * 1024 * 1024;

    /** WebP quality, 0-100 */
    public int $quality = 85;

    /**
     * used-for => [
     *     'uploaders'   => account types allowed to upload it (S staff, C client),
     *     'width'       => the saved width in px; a smaller original is rejected,
     *     'height'      => the saved height in px; a smaller original is rejected,
     *     'target'      => 'table.column' that keeps base_url('image/{used-for}/{image-name}.webp'), the request's id
     *                      being the table's id,
     *     'ownerColumn' => column of the target table holding the auth_master.id of the account the row belongs to,
     *     'ownRowOnly'  => account types that may only update a row they own; the others may update any row,
     *     'access'      => ACCESS_PUBLIC or ACCESS_SIGNED_IN, for the image/... route,
     * ]
     * A used-for key becomes a folder name and part of a URL: lowercase letters, digits and hyphens only.
     * @var array<string, array{uploaders: list<string>, width: int, height: int, target: string, ownerColumn: string, ownRowOnly: list<string>, access: string}>
     */
    public array $usedFor = [
        'profile-picture' => [
            'uploaders'   => ['S', 'C'],
            'width'       => 512,
            'height'      => 512,
            'target'      => 'auth_master.avatar_path',
            'ownerColumn' => 'id',
            'ownRowOnly'  => ['C'], // a client changes their own picture only; staff may change anyone's
            'access'      => self::ACCESS_PUBLIC, // shown with a plain <img src>, which cannot send a Bearer token
        ],
        // uploaded and deleted by App\Controllers\ArtifactImages, each as a row of its own named after its public_id;
        // no uploaders, so the generic image-upload and image-delete refuse it, as they cannot check the business
        'artifact-image' => [
            'uploaders'   => [],
            'width'       => 1000,
            'height'      => 1000,
            'target'      => 'artifact_image.image_path',
            'ownerColumn' => 'id',
            'ownRowOnly'  => [],
            'access'      => self::ACCESS_PUBLIC, // the shop shows them to anyone
        ],
    ];

    /** Most images an artifact may have (artifact-image) */
    public int $maxArtifactImages = 10;
}
