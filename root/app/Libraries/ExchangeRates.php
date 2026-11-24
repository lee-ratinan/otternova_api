<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Config\ExchangeRate;
use DateTimeImmutable;
use Throwable;

/**
 * A day's exchange rate for a ledger entry whose rate was not typed in: from otternova_fx_rate when it was fetched
 * before, otherwise from the provider of Config\ExchangeRate, then kept there. The service 'exchangeRates', so the
 * tests can stand in for the provider.
 */
class ExchangeRates
{
    public function __construct(private readonly BaseConnection $db, private readonly ExchangeRate $config)
    {
    }

    /**
     * How much of $base one unit of $currency bought on $date
     * @param string $date     Y-m-d
     * @param string $currency ISO 4217
     * @param string $base     ISO 4217
     * @return string|null The rate as a decimal string, or null when there is none to be had
     */
    public function rate(string $date, string $currency, string $base): ?string
    {
        if ($currency === $base) {
            return '1';
        }
        $kept = $this->db->table('otternova_fx_rate')
            ->select('rate')
            ->where(['rate_date' => $date, 'currency_code' => $currency, 'base_currency_code' => $base])
            ->get()->getRow();
        if ($kept !== null) {
            return (string) $kept->rate;
        }

        $rate = $this->fetch($date, $currency, $base);
        if ($rate === null) {
            return null;
        }
        try {
            $this->db->table('otternova_fx_rate')->insert([
                'rate_date' => $date, 'currency_code' => $currency, 'base_currency_code' => $base, 'rate' => $rate, 'source' => $this->config->source,
            ]);
        } catch (Throwable $e) {
            // another request kept the same day's rate meanwhile; this one is as good
            log_message('info', 'otternova_fx_rate not kept: {message}', ['message' => $e->getMessage()]);
        }

        return $rate;
    }

    /**
     * @param string $date
     * @param string $currency
     * @param string $base
     * @return string|null
     */
    protected function fetch(string $date, string $currency, string $base): ?string
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($this->config->url === '' || $day === false || $day > new DateTimeImmutable('today')) {
            return null; // no rate is published for a day still to come
        }
        try {
            $response = service('curlrequest')->get($this->config->url . $day->format('Y-m-d'), [
                'query'       => ['from' => $currency, 'to' => $base],
                'headers'     => ['Accept' => 'application/json'],
                'timeout'     => $this->config->timeout,
                'http_errors' => false,
            ]);
            $body = json_decode($response->getBody(), true);
        } catch (Throwable $e) {
            log_message('error', 'Exchange rate {currency}/{base} of {date} failed: {message}', ['currency' => $currency, 'base' => $base, 'date' => $date, 'message' => $e->getMessage()]);

            return null;
        }
        $rate = $body['rates'][$base] ?? null;
        if ($response->getStatusCode() !== 200 || ! is_numeric($rate) || $rate <= 0) {
            log_message('error', 'Exchange rate {currency}/{base} of {date}: HTTP {status}', ['currency' => $currency, 'base' => $base, 'date' => $date, 'status' => $response->getStatusCode()]);

            return null;
        }

        return rtrim(rtrim(number_format((float) $rate, 10, '.', ''), '0'), '.');
    }
}
