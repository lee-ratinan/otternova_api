<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the otternova_fx_rate table (App\Libraries\ExchangeRates): the exchange rates fetched from the rate
 * provider (Config\ExchangeRate), kept so the same day's rate is fetched once. A rate typed in on a ledger entry is
 * kept on the entry (base_rate), not here.
 * rate is how much of base_currency_code one unit of currency_code buys on rate_date.
 */
class CreateOtternovaFxRateTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'rate_date'          => ['type' => 'DATE'],
            'currency_code'      => ['type' => 'CHAR', 'constraint' => 3],
            'base_currency_code' => ['type' => 'CHAR', 'constraint' => 3],
            'rate'               => ['type' => 'DECIMAL', 'constraint' => '20,10'],
            'source'             => ['type' => 'VARCHAR', 'constraint' => 32], // the provider, such as frankfurter
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['rate_date', 'currency_code', 'base_currency_code'], 'uq_otternova_fx_rate_day_pair');
        $this->forge->createTable('otternova_fx_rate', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('otternova_fx_rate', true);
    }
}
