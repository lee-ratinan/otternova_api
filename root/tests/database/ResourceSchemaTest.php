<?php

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The resource tables: a resource only at a store and of a type of its own business, a service only needing a type
 * of its own business, and one row per name, per link
 *
 * @internal
 */
final class ResourceSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('business_type')->insert(['type_code' => 'spa', 'public_id' => 'ty01']);
        $type = (int) $this->db->insertID();
        foreach (['spa', 'other'] as $i => $slug) {
            $business = $i + 1;
            $this->db->table('business_master')->insert(['business_type_id' => $type, 'business_name' => $slug, 'business_slug' => $slug,
                'contact_email_address' => $slug . '@example.com', 'country_code' => 'TH', 'currency_code' => 'THB']);
            $this->db->table('business_store')->insert(['public_id' => 'store000000' . $business, 'business_id' => $business, 'store_name' => 'Bangkok', 'store_timezone' => 'Asia/Bangkok']);
            $this->db->table('resource_type')->insert(['public_id' => 'rtype000000' . $business, 'business_id' => $business, 'type_name' => 'Massage room']);
            $this->db->table('artifact_master')->insert(['public_id' => 'artif000000' . $business, 'business_id' => $business, 'artifact_name' => 'Aromatherapy',
                'artifact_type' => 'S', 'tax_calculation' => 'X']);
        }
    }

    public function testAResourceIsAtAStoreAndOfATypeOfItsBusiness(): void
    {
        $resource = ['public_id' => 'resrc0000001', 'business_id' => 1, 'store_id' => 1, 'resource_type_id' => 1, 'resource_name' => '101'];
        $this->db->table('resource_master')->insert($resource);
        $row = $this->db->table('resource_master')->get()->getRow();
        $this->assertSame('A', $row->resource_status);
        $this->assertSame(0, (int) $row->sort_order);

        foreach ([
            ['public_id' => 'resrc0000002', 'resource_name' => '101'], // the same name, type and store
            ['public_id' => 'resrc0000003', 'resource_name' => '102', 'store_id' => 2], // the other business's store
            ['public_id' => 'resrc0000004', 'resource_name' => '102', 'resource_type_id' => 2], // the other business's type
        ] as $override) {
            try {
                $this->db->table('resource_master')->insert($override + $resource);
                $this->fail('Inserted ' . json_encode($override));
            } catch (DatabaseException) {
                // refused
            }
        }
        $this->seeNumRecords(1, 'resource_master', []);
    }

    public function testAServiceNeedsTypesOfItsBusinessOnce(): void
    {
        $link = ['business_id' => 1, 'artifact_id' => 1, 'resource_type_id' => 1];
        $this->db->table('business_service_resource_link')->insert($link);
        $this->assertSame(1, (int) $this->db->table('business_service_resource_link')->get()->getRow()->quantity);

        foreach ([$link, ['resource_type_id' => 2] + $link, ['artifact_id' => 2] + $link] as $row) {
            try {
                $this->db->table('business_service_resource_link')->insert($row);
                $this->fail('Inserted ' . json_encode($row));
            } catch (DatabaseException) {
                // refused
            }
        }
        $this->seeNumRecords(1, 'business_service_resource_link', []);
    }
}
