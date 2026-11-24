<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Where App\Libraries\ExchangeRates fetches a day's rate when a ledger entry needs one and none was typed in.
 * The default is Frankfurter (https://frankfurter.dev): the European Central Bank's reference rates, free and
 * without a key, which cover THB and the other major currencies; a weekend or holiday gets the last working day's.
 * An empty $url turns fetching off, so every such entry needs its rate typed in.
 */
class ExchangeRate extends BaseConfig
{
    /** GET {$url}{Y-m-d}?from=USD&to=THB answers {"rates": {"THB": 36.12}} */
    public string $url = 'https://api.frankfurter.dev/v1/';

    /** Stored with each rate in otternova_fx_rate.source */
    public string $source = 'frankfurter';

    /** Seconds before giving up on the provider */
    public int $timeout = 10;
}
