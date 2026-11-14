<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The receipt settings of a store (AddStoreReceipts): no printer until a format is picked, and a footer in the
 * store's language and in others
 *
 * @internal
 */
final class StoreReceiptsSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private int $storeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('business_type')->insert(['public_id' => 'typ1', 'type_code' => 'cafe']);
        $this->db->table('business_master')->insert([
            'business_type_id'      => (int) $this->db->insertID(),
            'business_name'         => 'Otter Café',
            'business_slug'         => 'otter-cafe',
            'contact_email_address' => 'otter@example.com',
            'country_code'          => 'TH',
            'currency_code'         => 'THB',
        ]);
        $this->db->table('business_store')->insert([
            'public_id'      => 'store0000001',
            'business_id'    => (int) $this->db->insertID(),
            'store_name'     => 'สาขาสยาม',
            'store_type'     => 'P',
            'store_timezone' => 'Asia/Bangkok',
        ]);
        $this->storeId = (int) $this->db->insertID();
    }

    public function testANewStoreHasNoPrinterAndNoFooter(): void
    {
        $store = $this->db->table('business_store')->where('id', $this->storeId)->get()->getRowArray();

        $this->assertNull($store['receipt_format']);
        $this->assertNull($store['receipt_footer']);
    }

    public function testAStoreKeepsItsFormatAndFooters(): void
    {
        foreach (['58mm', '80mm', 'A4'] as $format) {
            $this->db->table('business_store')->where('id', $this->storeId)->update(['receipt_format' => $format, 'receipt_footer' => "ขอบคุณค่ะ\nโทร 02-000-0000"]);
            $this->seeInDatabase('business_store', ['id' => $this->storeId, 'receipt_format' => $format]);
        }
        $this->db->table('business_store_translation')->insert([
            'store_id'       => $this->storeId,
            'language_code'  => 'en-US',
            'store_name'     => 'Siam Branch',
            'receipt_footer' => "Thank you\nTel 02-000-0000",
        ]);

        $this->seeInDatabase('business_store', ['id' => $this->storeId, 'receipt_footer' => "ขอบคุณค่ะ\nโทร 02-000-0000"]);
        $this->seeInDatabase('business_store_translation', ['store_id' => $this->storeId, 'language_code' => 'en-US', 'receipt_footer' => "Thank you\nTel 02-000-0000"]);
    }
}
