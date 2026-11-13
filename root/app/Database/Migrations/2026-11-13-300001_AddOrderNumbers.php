<?php

namespace App\Database\Migrations;

use App\Libraries\OrderNumber;
use CodeIgniter\Database\Migration;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The order numbers of a business (App\Libraries\OrderNumber), the number customers see instead of the order's
 * public_id:
 * - business_master.order_prefix (0 to 4 letters A-Z), order_date_part and order_reset (N, Y, M, D), order_digits
 *   (3 to 6): how its numbers look and when they start again from 1
 * - business_order_sequence: the last number given, by business, prefix and reset period ('' never, 2026, 202611,
 *   20261113); a prefix keeps its own count, so going back to an old prefix carries on from where it stopped
 * - order_master.order_number, unique within the business; the orders placed before this are numbered by when
 *   they were placed, with the default settings
 * Depends on business_master and order_master.
 */
class AddOrderNumbers extends Migration
{
    public function up()
    {
        $mysql    = $this->db->DBDriver === 'MySQLi';
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : '';
        $defaults = OrderNumber::DEFAULTS;

        $this->forge->addColumn('business_master', [
            'order_prefix'    => ['type' => 'VARCHAR', 'constraint' => OrderNumber::PREFIX_MAX, 'default' => $defaults['order_prefix']],
            'order_date_part' => ['type' => 'ENUM', 'constraint' => OrderNumber::PARTS, 'default' => $defaults['order_date_part']],
            'order_reset'     => ['type' => 'ENUM', 'constraint' => OrderNumber::PARTS, 'default' => $defaults['order_reset']],
            'order_digits'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => $defaults['order_digits']],
        ]);

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id' => ['type' => 'INT', 'unsigned' => true],
            'prefix'      => ['type' => 'VARCHAR', 'constraint' => OrderNumber::PREFIX_MAX, 'default' => ''],
            'period'      => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => ''],
            'last_number' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['business_id', 'prefix', 'period']);
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'CASCADE', $fkName('fk_business_order_sequence_business'));
        $this->forge->createTable(OrderNumber::TABLE, true);

        $this->forge->addColumn('order_master', [
            'order_number' => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => true, 'after' => 'public_id'],
        ]);

        // the orders placed so far, numbered by when they were placed
        $businesses = [];
        foreach ($this->db->table('order_master')->select('id, business_id, created_at')->orderBy('created_at')->orderBy('id')->get()->getResultArray() as $order) {
            $businessId = (int) $order['business_id'];
            $businesses[$businessId] ??= $this->db->table('business_master')->where('id', $businessId)->get()->getRowArray();
            $number = OrderNumber::next($this->db, $businesses[$businessId], new DateTimeImmutable($order['created_at'], new DateTimeZone('UTC')));
            $this->db->table('order_master')->where('id', $order['id'])->update(['order_number' => $number]);
        }

        $this->forge->addUniqueKey(['business_id', 'order_number'], 'uq_order_master_order_number');
        $this->forge->processIndexes('order_master');
    }

    public function down()
    {
        $this->db->query('DROP INDEX ' . ($this->db->DBDriver === 'MySQLi' ? 'uq_order_master_order_number ON order_master' : 'uq_order_master_order_number'));
        $this->forge->dropColumn('order_master', 'order_number');
        $this->forge->dropTable(OrderNumber::TABLE, true);
        $this->forge->dropColumn('business_master', OrderNumber::FIELDS);
    }
}
