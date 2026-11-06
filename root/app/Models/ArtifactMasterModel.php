<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the artifact_master table: a product (P) or service (S) of a business. For now only what the artifact
 * image endpoints need, finding an artifact by its public_id; the artifact endpoints add the writes.
 */
class ArtifactMasterModel extends Model
{
    use PublicIdTrait;

    protected $table            = 'artifact_master';
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
