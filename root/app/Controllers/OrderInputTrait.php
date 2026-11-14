<?php

namespace App\Controllers;

use Config\Mart as MartConfig;

/**
 * The cart, the choices and the customer of an order request, checked for their shape: the mart's checkout (Mart) and
 * an order taken at the counter (SalesOrders). MartOrderModel checks the rest against what is stored.
 */
trait OrderInputTrait
{
    /** A public_id as the API sends them */
    private const ORDER_PUBLIC_ID_PATTERN = '/^[0-9A-Za-z_-]{1,12}$/';

    /**
     * The cart and the choices of a checkout request, checked for their shape only
     * @return array{0: list<array>, 1: array, 2: array<string, string>} [cart, choice, errors by field]
     */
    private function checkoutInput(array $input, bool $withPeople = false): array
    {
        $errors = [];
        $id     = static fn (mixed $value): bool => is_string($value) && preg_match(self::ORDER_PUBLIC_ID_PATTERN, $value) === 1;
        $lines  = $input['lines'] ?? null;
        $cart   = [];
        if (! is_array($lines) || ! array_is_list($lines) || count($lines) > config(MartConfig::class)->maxCartLines) {
            $errors['lines'] = 'invalid';
            $lines           = [];
        }
        $keys = [];
        foreach ($lines as $n => $line) {
            $key = is_array($line) ? ($line['key'] ?? null) : null;
            if (! is_array($line) || ! is_string($key) || ! preg_match('/^[A-Za-z0-9_-]{1,32}$/', $key) || isset($keys[$key])
                || ! in_array($line['kind'] ?? null, ['P', 'A', 'G'], true) || ! $id($line['variant'] ?? null)
                || ! is_int($line['quantity'] ?? null) || $line['quantity'] < 1 || $line['quantity'] > 99) {
                $errors['lines.' . $n] = 'invalid';

                continue;
            }
            $keys[$key] = true;
            $item       = ['key' => $key, 'kind' => $line['kind'], 'variant' => $line['variant'], 'quantity' => $line['quantity'], 'participants' => []];
            if ($line['kind'] === 'A') {
                $staff = $line['staff'] ?? null;
                if (! $id($line['store'] ?? null) || ! is_string($line['starts_at'] ?? null) || strlen($line['starts_at']) > 40
                    || ! is_array($staff) || ! array_is_list($staff) || count($staff) > 10 || array_filter($staff, static fn ($s): bool => ! $id($s)) !== []) {
                    $errors['lines.' . $n] = 'invalid';

                    continue;
                }
                $item += ['store' => $line['store'], 'starts_at' => $line['starts_at'], 'staff' => $staff];
            } elseif ($line['kind'] === 'G') {
                if (! $id($line['session'] ?? null)) {
                    $errors['lines.' . $n] = 'invalid';

                    continue;
                }
                $item['session'] = $line['session'];
            }
            if ($withPeople && $line['kind'] !== 'P') {
                $people = $line['participants'] ?? [];
                if (! is_array($people) || ! array_is_list($people) || count($people) > 99
                    || array_filter($people, static fn ($p): bool => ! is_string($p) || mb_strlen($p) > 100) !== []) {
                    $errors['lines.' . $n . '.participants'] = 'invalid';

                    continue;
                }
                $item['participants'] = array_map(static fn (string $p): string => trim(preg_replace('/\s+/u', ' ', $p)), $people);
            }
            $cart[] = $item;
        }

        $choice = [];
        foreach (['shipping', 'pickup_store', 'payment'] as $name) {
            $value = $input[$name] ?? '';
            if ($value !== '' && $value !== null && ! $id($value)) {
                $errors[$name] = 'invalid';
            }
            $choice[$name] = is_string($value) ? $value : '';
        }
        $discount = $input['discount'] ?? '';
        if (! is_string($discount) || mb_strlen($discount) > 30) {
            $errors['discount'] = 'invalid';
            $discount           = '';
        }
        $choice['discount'] = $discount;

        return [$cart, $choice, $errors];
    }

    /**
     * The customer and the delivery of an order, trimmed and checked
     * @return array{0: array, 1: array, 2: array<string, string>} [customer, delivery, errors by field]
     */
    private function customerInput(array $input, bool $counter = false): array
    {
        $errors = [];
        $text   = static function (array $from, string $name, int $max, bool $required, string $field) use (&$errors): string {
            $value = $from[$name] ?? '';
            $value = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value)) : null;
            if ($value === null || mb_strlen($value) > $max) {
                $errors[$field] = 'invalid';

                return '';
            }
            if ($required && $value === '') {
                $errors[$field] = 'required';
            }

            return $value;
        };
        $who      = is_array($input['customer'] ?? null) ? $input['customer'] : [];
        // the mart needs all four; at the counter a first name, and an email address or a telephone number
        $customer = [
            'first_name' => $text($who, 'first_name', 100, true, 'customer.first_name'),
            'last_name'  => $text($who, 'last_name', 100, ! $counter, 'customer.last_name'),
            'email'      => strtolower($text($who, 'email', 64, ! $counter, 'customer.email')),
            'telephone'  => $text($who, 'telephone', 24, ! $counter, 'customer.telephone'),
        ];
        if ($counter) {
            // kept compact, so a customer who gave only a number is found again by it
            $customer['telephone'] = preg_replace('/[\s()-]+/', '', $customer['telephone']);
            if ($customer['email'] === '' && $customer['telephone'] === '' && ! isset($errors['customer.email']) && ! isset($errors['customer.telephone'])) {
                $errors['customer.contact'] = 'required';
            }
        }
        if ($customer['email'] !== '' && filter_var($customer['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['customer.email'] = 'invalid';
        }
        if ($customer['telephone'] !== '' && ! preg_match('/^\+?[0-9 ()-]{6,24}$/', $customer['telephone'])) {
            $errors['customer.telephone'] = 'invalid';
        }
        $address  = is_array($input['delivery'] ?? null) ? $input['delivery'] : [];
        $delivery = [
            'line_1'           => $text($address, 'line_1', 255, false, 'address.line_1'),
            'line_2'           => $text($address, 'line_2', 255, false, 'address.line_2'),
            'city'             => $text($address, 'city', 128, false, 'address.city'),
            'subdivision_code' => $text($address, 'subdivision_code', 8, false, 'address.subdivision_code'),
            'postal_code'      => $text($address, 'postal_code', 16, false, 'address.postal_code'),
            'pickup_point'     => $text($input, 'pickup_point', 500, false, 'pickup_point'),
        ];

        return [$customer, $delivery, $errors];
    }
}
