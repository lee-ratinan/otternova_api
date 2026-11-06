<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the artifact_variant table: what a customer buys of an artifact. For now only what the artifact image
 * endpoints need, finding a variant by its public_id; the artifact endpoints add the writes.
 */
class ArtifactVariantModel extends Model
{
    use PublicIdTrait;

    protected $table            = 'artifact_variant';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;
}
