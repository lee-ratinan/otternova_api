<?php

namespace App\Models;

use CodeIgniter\Database\Exceptions\DatabaseException;
use RuntimeException;

/**
 * public_id for models whose table has one: a NanoID (generateNanoId() of id_helper) unique within the table,
 * the only ID the API sends and takes for these rows. The model sets $publicIdLength to the column's length and
 * lists 'fillPublicId' in $beforeInsert, so that Model::insert() gets one too; its own writes take one through
 * withPublicId(), which retries on a clash.
 */
trait PublicIdTrait
{
    /** Fresh IDs tried before giving up; each is checked against the table first */
    private const PUBLIC_ID_TRIES = 10;

    /** Times the write is run again when another one took the same ID meanwhile */
    private const PUBLIC_ID_RETRIES = 3;

    /**
     * A public_id no row of the table has yet
     * @return string
     * @throws RuntimeException When every try is taken, which only a nearly full table makes likely
     */
    public function newPublicId(): string
    {
        helper('id');
        for ($try = 0; $try < self::PUBLIC_ID_TRIES; $try++) {
            $publicId = generateNanoId($this->publicIdLength);
            if ($this->db->table($this->table)->where('public_id', $publicId)->countAllResults() === 0) {
                return $publicId;
            }
        }

        throw new RuntimeException('No free public_id found in ' . $this->table);
    }

    /**
     * The row with this public_id, as stored
     * @param mixed $publicId From the request; anything but a well-formed public_id finds nothing
     * @return array|null
     */
    public function findByPublicId(mixed $publicId): ?array
    {
        if (! is_string($publicId) || ! preg_match('/^[0-9A-Za-z_-]{' . $this->publicIdLength . '}$/', $publicId)) {
            return null;
        }

        return $this->db->table($this->table)->where('public_id', $publicId)->get()->getRowArray();
    }

    /**
     * The id of the row with this public_id, for the columns that point at it
     * @param mixed $publicId From the request
     * @return int|null
     */
    public function idOfPublicId(mixed $publicId): ?int
    {
        $row = $this->findByPublicId($publicId);

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * The public_id of the row with this id, for the response to a create
     * @param int $id
     * @return string|null
     */
    public function publicIdOf(int $id): ?string
    {
        $row = $this->db->table($this->table)->select('public_id')->where('id', $id)->get()->getRowArray();

        return $row['public_id'] ?? null;
    }

    /**
     * Run $insert with a new public_id, again with another one when the database refuses it as taken
     * (another request took it between the check and the insert). $insert must write nothing when it throws,
     * as inTransaction() does.
     * @param callable(string): mixed $insert
     * @return mixed What $insert returned
     * @throws DatabaseException When the write fails otherwise, or keeps clashing
     */
    protected function withPublicId(callable $insert): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $insert($this->newPublicId());
            } catch (DatabaseException $e) {
                // the unique key is named after the column (uq_<table>_public_id), so the message names it
                if ($attempt >= self::PUBLIC_ID_RETRIES || ! str_contains($e->getMessage(), 'public_id')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * beforeInsert callback: a public_id for a row inserted without one
     * @param array $data
     * @return array
     */
    protected function fillPublicId(array $data): array
    {
        if (($data['data']['public_id'] ?? '') === '') {
            $data['data']['public_id'] = $this->newPublicId();
        }

        return $data;
    }
}
