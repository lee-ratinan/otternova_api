<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the business_master table: a client's business entity, also the billing details on its invoices.
 * One entity has one country and one currency, both fixed once created; going international means another
 * entity. The contact details are the business's own, not those of the account that created it.
 * tax_id is encrypted (App\Libraries\FieldEncryption), so it is sized for the ciphertext.
 * Depends on business_type.
 */
class CreateBusinessMasterTable extends Migration
{
    /** Ciphertext of the longest tax ID the validation rules allow (255 characters), with the prefix */
    private const ENCRYPTED_LENGTH = 512;

    public function up()
    {
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $this->db->DBDriver === 'MySQLi' ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = fn (string $name): string => $this->db->DBDriver === 'MySQLi' ? $name : ''; // nor named foreign keys

        $this->forge->addField([
            'id'                       => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_type_id'         => ['type' => 'INT', 'unsigned' => true],
            'business_name'            => ['type' => 'VARCHAR', 'constraint' => 255],
            'business_slug'            => ['type' => 'VARCHAR', 'constraint' => 128],
            // contact details, also used for billing
            'contact_person_name'      => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true], // the name on the invoice/receipt, if any
            'contact_email_address'    => ['type' => 'VARCHAR', 'constraint' => 128],
            'contact_phone_number'     => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'contact_website'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'contact_address_line_1'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'contact_address_line_2'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'contact_city'             => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'contact_subdivision_code' => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true], // listSupportedSubdivisions(country_code)
            'contact_postal_code'      => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'country_code'             => ['type' => 'CHAR', 'constraint' => 2], // listSupportedCountries()
            'currency_code'            => ['type' => 'CHAR', 'constraint' => 3], // listSupportedCurrencies(country_code)
            'tax_id'                   => ['type' => 'VARCHAR', 'constraint' => self::ENCRYPTED_LENGTH, 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('business_slug');
        $this->forge->addUniqueKey(['business_name', 'country_code']); // one name per country; the same brand may open elsewhere
        $this->forge->addKey('business_type_id');
        $this->forge->addKey('contact_email_address');
        $this->forge->addKey('contact_phone_number');
        $this->forge->addKey('contact_website');
        $this->forge->addForeignKey('business_type_id', 'business_type', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_business_master_type'));
        $this->forge->createTable('business_master', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('business_master', true);
    }
}
