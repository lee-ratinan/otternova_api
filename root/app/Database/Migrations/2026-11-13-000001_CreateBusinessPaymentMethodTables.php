<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates business_payment_method: the ways the customers of a business can pay it. Not to be confused with
 * payment_method, the ways a business pays Otternova.
 * - store_id NULL: offered at every store of the business; else at that store only. Stores never share a row.
 * - method_code is what the customer pays with (cash, bank_transfer, promptpay ...); handler_code is how it is handled
 *   (instruction: show the text; promptpay_qr: a QR from the QR generator ...). Both lists, and which handler takes
 *   which method, are in Config\BusinessPayment, which the model checks, as the database cannot.
 * - method_name and instruction are in the business's default language; business_payment_method_translation has them
 *   in other languages of listLanguages(), optional, the parent's shown where a language has none.
 * - settings_json holds the handler's settings that may be shown, as {"promptpay_id": "0812345678"};
 *   secret_json those that may not (a gateway's secret key), encrypted (App\Libraries\FieldEncryption).
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on business_master and business_store.
 */
class CreateBusinessPaymentMethodTables extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($mysql
            ? ['public_id CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 12]]);
        $this->forge->addField([
            'business_id'   => ['type' => 'INT', 'unsigned' => true],
            'store_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true], // NULL = every store of the business
            'method_code'   => ['type' => 'VARCHAR', 'constraint' => 30], // Config\BusinessPayment::$methods
            'handler_code'  => ['type' => 'VARCHAR', 'constraint' => 30], // Config\BusinessPayment::$handlers
            'method_name'   => ['type' => 'VARCHAR', 'constraint' => 100],
            'instruction'   => ['type' => 'TEXT', 'null' => true],
            'settings_json' => ['type' => 'TEXT', 'null' => true],
            'secret_json'   => ['type' => 'TEXT', 'null' => true], // encrypted, see FieldEncryption
            'is_active'     => ['type' => 'ENUM', 'constraint' => ['Y', 'N'], 'default' => 'Y'],
            'sort_order'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_business_payment_method_public_id'); // named after the column, see PublicIdTrait
        // which names may repeat depends on store_id being NULL, which a unique key cannot say; the model checks it
        $this->forge->addKey(['business_id', 'store_id', 'sort_order']);
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_business_payment_method_business'));
        $this->forge->addForeignKey('store_id', 'business_store', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_business_payment_method_store'));
        $this->forge->createTable('business_payment_method', true);

        $this->forge->addField([
            'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'payment_method_id' => ['type' => 'INT', 'unsigned' => true],
            'language_code'     => ['type' => 'VARCHAR', 'constraint' => 16], // BCP 47: en-US, th
            'method_name'       => ['type' => 'VARCHAR', 'constraint' => 100],
            'instruction'       => ['type' => 'TEXT', 'null' => true],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['payment_method_id', 'language_code']);
        $this->forge->addForeignKey('payment_method_id', 'business_payment_method', 'id', 'CASCADE', 'CASCADE', $fkName('fk_business_payment_method_translation_method'));
        $this->forge->createTable('business_payment_method_translation', true);
    }

    public function down()
    {
        $this->forge->dropTable('business_payment_method_translation', true);
        $this->forge->dropTable('business_payment_method', true);
    }
}
