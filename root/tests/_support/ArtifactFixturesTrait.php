<?php

namespace Tests\Support;

use CodeIgniter\Test\TestResponse;

/**
 * Rows and requests shared by the feature tests of the artifact endpoints: for a CIUnitTestCase with
 * DatabaseTestTrait and FeatureTestTrait, which sets $ownerToken.
 */
trait ArtifactFixturesTrait
{
    private int $fixtureSeq = 0;

    /**
     * POST a JSON body as a client
     * @param string      $path
     * @param array       $body
     * @param string|null $token The owner's when null
     * @return TestResponse
     */
    private function send(string $path, array $body, ?string $token = null): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->ownerToken)])
            ->withBodyFormat('json')
            ->post($path, $body);
    }

    private function body(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }

    private function fixturePublicId(): string
    {
        return sprintf('fix%09d', ++$this->fixtureSeq);
    }

    private function businessType(): int
    {
        $row = $this->db->table('business_type')->get()->getRow();
        if ($row !== null) {
            return (int) $row->id;
        }
        $this->db->table('business_type')->insert(['public_id' => 'typ1', 'type_code' => 'shop']);

        return (int) $this->db->insertID();
    }

    private function business(string $slug): int
    {
        $this->db->table('business_master')->insert([
            'business_type_id'      => $this->businessType(),
            'business_name'         => $slug,
            'business_slug'         => $slug,
            'contact_email_address' => $slug . '@example.com',
            'country_code'          => 'TH',
            'currency_code'         => 'THB',
        ]);

        return (int) $this->db->insertID();
    }

    /** @return array{int, string} [id, public_id] */
    private function store(int $businessId, string $name): array
    {
        $publicId = $this->fixturePublicId();
        $this->db->table('business_store')->insert(['public_id' => $publicId, 'business_id' => $businessId, 'store_name' => $name, 'store_type' => 'O', 'store_timezone' => 'Asia/Bangkok']);

        return [(int) $this->db->insertID(), $publicId];
    }

    /**
     * An attribute with values, straight into the database
     * @param int          $businessId
     * @param string       $name
     * @param list<string> $values
     * @return array<string, string> value => its public_id
     */
    private function attribute(int $businessId, string $name, array $values): array
    {
        $this->db->table('artifact_attribute')->insert(['public_id' => $this->fixturePublicId(), 'business_id' => $businessId, 'attribute_name' => $name]);
        $attributeId = (int) $this->db->insertID();
        $out         = [];
        foreach ($values as $sort => $value) {
            $out[$value] = $this->fixturePublicId();
            $this->db->table('artifact_attribute_value')->insert(['public_id' => $out[$value], 'attribute_id' => $attributeId, 'attribute_value' => $value, 'sort_order' => $sort]);
        }

        return $out;
    }

    /** @return array{int, string} [id, public_id] */
    private function artifactRow(int $businessId, string $type = 'P', string $status = 'A'): array
    {
        $publicId = $this->fixturePublicId();
        $this->db->table('artifact_master')->insert([
            'public_id'       => $publicId,
            'business_id'     => $businessId,
            'artifact_name'   => 'T-Shirt',
            'artifact_type'   => $type,
            'artifact_status' => $status,
            'tax_calculation' => 'X',
        ]);

        return [(int) $this->db->insertID(), $publicId];
    }

    /**
     * A client account, linked to the business with $role and $status when given
     * @return string Its access token
     */
    private function createClient(string $email, ?int $businessId, ?string $role, string $status = 'A'): string
    {
        $account = [
            'uid'             => preg_replace('/[^a-z0-9]/', '', $email),
            'email_address'   => $email,
            'password_hash'   => 'x',
            'password_expiry' => '2027-01-01',
            'full_name'       => 'Otter Tester',
            'otp_mode'        => 'X',
            'account_status'  => 'A',
            'account_type'    => 'C',
        ];
        $this->db->table('auth_master')->insert($account);
        $account['id'] = (int) $this->db->insertID();
        $this->db->table('client_master')->insert(['auth_id' => $account['id']]);
        $clientId = (int) $this->db->insertID();
        if ($businessId !== null && $role !== null) {
            $this->db->table('business_client_link')->insert(['business_id' => $businessId, 'client_id' => $clientId, 'client_role' => $role, 'client_status' => $status,
                'start_date' => '2026-01-01', 'termination_date' => $status === 'T' ? '2026-02-01' : null]);
        }

        return service('jwt')->issue($account)['access_token'];
    }
}
