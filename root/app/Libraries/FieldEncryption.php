<?php

namespace App\Libraries;

use CodeIgniter\Encryption\EncrypterInterface;
use CodeIgniter\Encryption\Exceptions\EncryptionException;

/**
 * Encrypts the columns that hold identity and tax numbers, so the database only ever holds ciphertext.
 * Uses the framework encrypter (Config\Encryption: AES-256-CTR with an HMAC-SHA512, keyed by encryption.key
 * in .env); a stored value is "enc:v1:" + base64(ciphertext). Values without the prefix are read as they are,
 * for rows written before encryption (the EncryptIdentityNumbers migration encrypts those).
 */
class FieldEncryption
{
    /** table => its encrypted columns */
    public const FIELDS = [
        'staff_master'    => ['national_id_number', 'tax_id_number'],
        'client_master'   => ['national_id_number'],
        'business_master' => ['tax_id'],
    ];

    public const PREFIX = 'enc:v1:';

    private ?EncrypterInterface $encrypter = null;

    /**
     * @param string $table
     * @return list<string>
     */
    public static function fieldsOf(string $table): array
    {
        return self::FIELDS[$table] ?? [];
    }

    /**
     * @param string|null $value
     * @return bool
     */
    public static function isEncrypted(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::PREFIX);
    }

    /**
     * The encrypted columns of $row encrypted; NULL stays NULL, and an already encrypted value is kept
     * @param string $table
     * @param array  $row
     * @return array
     * @throws EncryptionException When encryption.key is not set
     */
    public function encryptRow(string $table, array $row): array
    {
        foreach (self::fieldsOf($table) as $field) {
            if (isset($row[$field]) && ! self::isEncrypted((string) $row[$field])) {
                $row[$field] = self::PREFIX . base64_encode($this->encrypter()->encrypt((string) $row[$field]));
            }
        }

        return $row;
    }

    /**
     * The encrypted columns of $row decrypted
     * @param string $table
     * @param array  $row
     * @return array
     * @throws EncryptionException When encryption.key is not set, or a value was not encrypted with it
     */
    public function decryptRow(string $table, array $row): array
    {
        foreach (self::fieldsOf($table) as $field) {
            if (isset($row[$field]) && self::isEncrypted((string) $row[$field])) {
                $row[$field] = $this->encrypter()->decrypt(base64_decode(substr((string) $row[$field], strlen(self::PREFIX)), true) ?: '');
            }
        }

        return $row;
    }

    /**
     * @return EncrypterInterface
     * @throws EncryptionException
     */
    private function encrypter(): EncrypterInterface
    {
        if ($this->encrypter === null) {
            $config = config('Encryption');
            if ($config->key === '') {
                throw new EncryptionException('encryption.key is not set; run "php spark key:generate"');
            }
            $this->encrypter = service('encrypter', $config, false);
        }

        return $this->encrypter;
    }
}
