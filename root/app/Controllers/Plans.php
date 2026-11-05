<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\Money;
use App\Libraries\SubscriptionBilling;
use App\Models\BusinessMasterModel;
use App\Models\DiscountMasterModel;
use App\Models\PaymentMethodModel;
use App\Models\PlanMasterModel;
use App\Models\PlanVersionModel;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Model;
use Throwable;

/**
 * What the businesses can buy, for the Admin app, behind the jwt:S and department:EXE,FIN filters: the plans
 * (plan_master), their prices (plan_version), the payment methods (payment_method) and the discount codes
 * (discount_master). A plan comes in by its plan_code, a payment method by its method_code, a version and a discount
 * by their public_id; the ids stay inside.
 * Times in the body carry their offset ("2026-11-15T00:00:00+07:00"); every time sent back is UTC.
 */
class Plans extends BaseController
{
    use BillingRequestTrait;

    // ---- plans ----

    /**
     * POST /staff/plans/plans {}
     * Every plan, the free one (plan_tier 0) first
     * @return ResponseInterface
     */
    public function plans(): ResponseInterface
    {
        if ($this->jsonInput() === null) {
            return ApiResponse::error(lang('Plans.invalidRequest'), 422);
        }
        try {
            $rows = model(PlanMasterModel::class)->listPlans();
        } catch (Throwable $e) {
            return $this->failed('v1/staff/plans/plans', $e, 'Plans.loadFailed');
        }

        return ApiResponse::success(['rows' => $rows]);
    }

    /**
     * POST /staff/plans/plan/create { plan_code, plan_description, plan_tier }
     * plan_code is lower case (boost) and cannot be changed afterwards; plan_tier ranks the plans, 0 being free
     * @return ResponseInterface
     */
    public function createPlan(): ResponseInterface
    {
        return $this->savePlan(false);
    }

    /**
     * POST /staff/plans/plan/edit { plan_code, plan_description, plan_tier }
     * Updates the plan with that plan_code; a field left out keeps its value. The free plan stays at tier 0.
     * @return ResponseInterface
     */
    public function editPlan(): ResponseInterface
    {
        return $this->savePlan(true);
    }

    // ---- prices ----

    /**
     * POST /staff/plans/versions { plan_code, country_code, billing_cycle }
     * The prices, every filter optional, each with its state now: S scheduled, C current, E ended
     * @return ResponseInterface
     */
    public function versions(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Plans.invalidRequest'), 422);
        }
        try {
            $filters = [];
            $code    = $this->text($input, 'plan_code');
            if ($code !== '') {
                $plan               = model(PlanMasterModel::class)->findByCode($code);
                $filters['plan_id'] = $plan === null ? 0 : (int) $plan['id'];
            }
            foreach (['country_code', 'billing_cycle'] as $field) {
                $value = strtoupper($this->text($input, $field));
                if ($value !== '') {
                    $filters[$field] = $value;
                }
            }
            $rows = model(PlanVersionModel::class)->listVersions($filters, SubscriptionBilling::now());
        } catch (Throwable $e) {
            return $this->failed('v1/staff/plans/versions', $e, 'Plans.loadFailed');
        }

        return ApiResponse::success(['rows' => $rows]);
    }

    /**
     * POST /staff/plans/version/create { plan_code, country_code, currency_code, billing_cycle, price, effective_from }
     * Sets a new price of the series (plan, country_code, billing_cycle) from effective_from: the series' open row
     * ends at that very moment. country_code ZZ is the rest of the world. A series that already has a price keeps its
     * currency, and a change can only be set from now on, after the last one set.
     * @return ResponseInterface
     */
    public function createVersion(): ResponseInterface
    {
        $endpoint = 'v1/staff/plans/version/create';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Plans.invalidRequest'), 422);
        }
        helper('list');
        $errors = [];
        $row    = [
            'country_code'   => strtoupper($this->text($input, 'country_code')),
            'currency_code'  => strtoupper($this->text($input, 'currency_code')),
            'billing_cycle'  => strtoupper($this->text($input, 'billing_cycle')),
            'price'          => Money::normalize($input['price'] ?? null) ?? $this->text($input, 'price'),
            'effective_from' => SubscriptionBilling::parseTime($input['effective_from'] ?? null) ?? '',
        ];

        try {
            $plan = model(PlanMasterModel::class)->findByCode($this->text($input, 'plan_code'));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Plans.loadFailed');
        }
        if ($plan === null) {
            $errors['plan_code'] = lang('Plans.planNotFound');
        } elseif ((int) $plan['plan_tier'] === PlanMasterModel::FREE_TIER) {
            $errors['plan_code'] = lang('Plans.freePlanHasNoPrice');
        }
        $errors += $this->ruleErrors(model(PlanVersionModel::class), $row, null);
        if (! isset($errors['country_code']) && $row['country_code'] !== PlanVersionModel::REST_OF_WORLD && ! isset(listSupportedCountries()[$row['country_code']])) {
            $errors['country_code'] = lang('Plans.unknownCountry');
        }
        if (! isset($errors['currency_code']) && ! isset(listCurrencies()[$row['currency_code']])) {
            $errors['currency_code'] = lang('Plans.unknownCurrency');
        }
        if (! isset($errors['price']) && $row['price'] === '0.00') {
            $errors['price'] = lang('Plans.priceNotPositive');
        }
        if ($row['effective_from'] === '') {
            $errors['effective_from'] = lang('Plans.invalidTime');
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Plans.invalidFields'), 422, $errors);
        }

        $versions = model(PlanVersionModel::class);
        $row      = ['plan_id' => (int) $plan['id']] + $row;
        try {
            $open = $versions->openOfSeries((int) $plan['id'], $row['country_code'], $row['billing_cycle']);
            if ($open !== null) {
                if ($open['currency_code'] !== $row['currency_code']) {
                    $errors['currency_code'] = lang('Plans.currencyOfSeries', [$open['currency_code']]);
                }
                if ($row['effective_from'] <= $open['effective_from'] || $row['effective_from'] < SubscriptionBilling::now()) {
                    $errors['effective_from'] = lang('Plans.effectiveTooEarly');
                }
                if ($errors !== []) {
                    return ApiResponse::error(lang('Plans.invalidFields'), 422, $errors);
                }
            }
            $id = $versions->schedule($row, $this->actorId(), 'Priced plan ' . $plan['plan_code'] . ' ' . $row['country_code'] . ' ' . $row['billing_cycle'] . ' at ' . $row['currency_code'] . ' ' . $row['price']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Plans.saveFailed');
        }

        return ApiResponse::success(['public_id' => $versions->publicIdOf($id)], 200, lang('Plans.saved'));
    }

    /**
     * POST /staff/plans/version/cancel { public_id }
     * Takes back a price change that has not started: the series' previous price runs on with no end
     * @return ResponseInterface
     */
    public function cancelVersion(): ResponseInterface
    {
        $endpoint = 'v1/staff/plans/version/cancel';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Plans.invalidRequest'), 422);
        }
        $versions = model(PlanVersionModel::class);
        try {
            $stored = $versions->findByPublicId($input['public_id'] ?? null);
            if ($stored === null) {
                return ApiResponse::error(lang('Plans.versionNotFound'), 404);
            }
            if ($stored['effective_to'] !== null || $stored['effective_from'] <= SubscriptionBilling::now() || $versions->isInUse((int) $stored['id'])) {
                return ApiResponse::error(lang('Plans.versionStarted'), 409);
            }
            $versions->cancelScheduled($stored, $this->actorId(), 'Cancelled price change ' . $stored['public_id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Plans.saveFailed');
        }

        return ApiResponse::success(['public_id' => $stored['public_id']], 200, lang('Plans.versionCancelled'));
    }

    // ---- payment methods ----

    /**
     * POST /staff/plans/payment-methods {}
     * Every payment method, in display order
     * @return ResponseInterface
     */
    public function paymentMethods(): ResponseInterface
    {
        if ($this->jsonInput() === null) {
            return ApiResponse::error(lang('Plans.invalidRequest'), 422);
        }
        try {
            $rows = model(PaymentMethodModel::class)->listMethods();
        } catch (Throwable $e) {
            return $this->failed('v1/staff/plans/payment-methods', $e, 'Plans.loadFailed');
        }

        return ApiResponse::success(['rows' => $rows]);
    }

    /**
     * POST /staff/plans/payment-method/create { method_code, country_code, currency_code, confirmation_mode,
     * provider_code, is_active, sort_order }
     * country_code empty is every country. confirmation_mode A (by a provider) needs provider_code; M is checked by
     * the staff. method_code cannot be changed afterwards.
     * @return ResponseInterface
     */
    public function createPaymentMethod(): ResponseInterface
    {
        return $this->savePaymentMethod(false);
    }

    /**
     * POST /staff/plans/payment-method/edit { method_code, ... }
     * Updates the method with that method_code; a field left out keeps its value
     * @return ResponseInterface
     */
    public function editPaymentMethod(): ResponseInterface
    {
        return $this->savePaymentMethod(true);
    }

    // ---- discounts ----

    /**
     * POST /staff/plans/discounts { start, length, search }
     * One page of discount codes, newest first; search matches the code and the description
     * @return ResponseInterface
     */
    public function discounts(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Plans.invalidRequest'), 422);
        }
        [$start, $length] = $this->page($input);
        try {
            $data = model(DiscountMasterModel::class)->listDiscounts($start, $length, $this->text($input, 'search'));
        } catch (Throwable $e) {
            return $this->failed('v1/staff/plans/discounts', $e, 'Plans.loadFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /staff/plans/discount { public_id }
     * @return ResponseInterface
     */
    public function discount(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Plans.invalidRequest'), 422);
        }
        $discounts = model(DiscountMasterModel::class);
        try {
            $id       = $discounts->idOfPublicId($input['public_id'] ?? null);
            $discount = $id === null ? null : $discounts->findPayload($id);
        } catch (Throwable $e) {
            return $this->failed('v1/staff/plans/discount', $e, 'Plans.loadFailed');
        }
        if ($discount === null) {
            return ApiResponse::error(lang('Plans.discountNotFound'), 404);
        }

        return ApiResponse::success(['discount' => $discount]);
    }

    /**
     * POST /staff/plans/discount/update { public_id, discount_code, discount_description, discount_type,
     * discount_value, currency_code, country_code, business_slug, plan_code, billing_cycle, is_listed,
     * max_redemptions, max_per_business, valid_from, expires_at }
     * Creates the discount when there is no public_id, updates it otherwise; every field is sent, an empty one is
     * none (no limit). discount_type P is a percentage (up to 100), with no currency; F is a fixed amount, which needs
     * currency_code and country_code. country_code empty is every country, ZZ the rest of the world.
     * max_per_business left out of a create is 1. A business limits it to that business only.
     * @return ResponseInterface
     */
    public function saveDiscount(): ResponseInterface
    {
        $endpoint = 'v1/staff/plans/discount/update';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Plans.invalidRequest'), 422);
        }
        $discounts = model(DiscountMasterModel::class);
        $publicId  = $input['public_id'] ?? null;
        $stored    = null;
        $errors    = [];
        helper('list');
        try {
            if ($publicId !== null && $publicId !== '') {
                $stored = $discounts->findByPublicId($publicId);
                if ($stored === null) {
                    return ApiResponse::error(lang('Plans.discountNotFound'), 404);
                }
            }
            $businessId = null;
            $slug       = $this->text($input, 'business_slug');
            if ($slug !== '') {
                $business   = model(BusinessMasterModel::class)->select('id')->where('business_slug', $slug)->first();
                $businessId = $business === null ? null : (int) $business['id'];
                if ($businessId === null) {
                    $errors['business_slug'] = lang('Plans.businessNotFound');
                }
            }
            $planId = null;
            $code   = $this->text($input, 'plan_code');
            if ($code !== '') {
                $plan   = model(PlanMasterModel::class)->findByCode($code);
                $planId = $plan === null ? null : (int) $plan['id'];
                if ($plan === null) {
                    $errors['plan_code'] = lang('Plans.planNotFound');
                } elseif ((int) $plan['plan_tier'] === PlanMasterModel::FREE_TIER) {
                    $errors['plan_code'] = lang('Plans.freePlanHasNoPrice');
                }
            }
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Plans.loadFailed');
        }

        $row = [];
        foreach (DiscountMasterModel::FORM_FIELDS as $field) {
            $row[$field] = match ($field) {
                'discount_code', 'discount_type', 'currency_code', 'country_code', 'billing_cycle', 'is_listed' => strtoupper($this->text($input, $field)),
                'discount_value'          => Money::normalize($input[$field] ?? null) ?? $this->text($input, $field),
                'valid_from', 'expires_at' => SubscriptionBilling::parseTime($input[$field] ?? null) ?? '',
                'business_id'             => $businessId,
                'plan_id'                 => $planId,
                'max_per_business'        => $stored === null && ! array_key_exists($field, $input) ? '1' : $this->text($input, $field),
                default                   => $this->text($input, $field),
            };
            if ($row[$field] === '' && in_array($field, DiscountMasterModel::FORM_NULLABLE, true)) {
                $row[$field] = null;
            }
        }
        foreach (['valid_from', 'expires_at'] as $field) {
            if ($row[$field] === '' && $this->text($input, $field) !== '') {
                $errors[$field] = lang('Plans.invalidTime');
            }
        }
        $errors = $this->renameKeys($this->ruleErrors($discounts, $row, $stored), ['business_id' => 'business_slug', 'plan_id' => 'plan_code']) + $errors;
        $errors += $this->discountErrors($row, $stored, $errors);
        if ($errors !== []) {
            return ApiResponse::error(lang('Plans.invalidFields'), 422, $errors);
        }

        $summary = ($stored === null ? 'Created' : 'Updated') . ' discount ' . $row['discount_code'];
        try {
            if ($stored === null) {
                $id = $discounts->createLogged($row + ['created_by' => $this->actorId()], $this->actorId(), $summary);

                return ApiResponse::success(['public_id' => $discounts->publicIdOf($id)], 200, lang('Plans.saved'));
            }
            $discounts->updateLogged($stored, $row, $this->actorId(), $summary);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Plans.saveFailed');
        }

        return ApiResponse::success(['public_id' => $stored['public_id']], 200, lang('Plans.saved'));
    }

    // ---- inside ----

    /**
     * plan/create and plan/edit
     * @param bool $edit
     * @return ResponseInterface
     */
    private function savePlan(bool $edit): ResponseInterface
    {
        $endpoint = $edit ? 'v1/staff/plans/plan/edit' : 'v1/staff/plans/plan/create';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Plans.invalidRequest'), 422);
        }
        $plans  = model(PlanMasterModel::class);
        $stored = null;
        if ($edit) {
            try {
                $stored = $plans->findByCode($this->text($input, 'plan_code'));
            } catch (Throwable $e) {
                return $this->failed($endpoint, $e, 'Plans.loadFailed');
            }
            if ($stored === null) {
                return ApiResponse::error(lang('Plans.planNotFound'), 404);
            }
        }
        $row = $this->formRow($input, PlanMasterModel::FORM_FIELDS, PlanMasterModel::FORM_NULLABLE, $stored, ['plan_code']);
        if (! $edit) {
            $row['plan_code'] = strtolower((string) $row['plan_code']);
        }
        $errors = $this->ruleErrors($plans, $row, $stored);
        if ($stored !== null && (int) $stored['plan_tier'] === PlanMasterModel::FREE_TIER && (string) $row['plan_tier'] !== (string) PlanMasterModel::FREE_TIER) {
            $errors['plan_tier'] = lang('Plans.freePlanTier');
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Plans.invalidFields'), 422, $errors);
        }

        try {
            if ($stored === null) {
                $plans->createLogged($row, $this->actorId(), 'Created plan ' . $row['plan_code']);
            } else {
                $plans->updateLogged($stored, $row, $this->actorId(), 'Updated plan ' . $stored['plan_code']);
            }
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Plans.saveFailed');
        }

        return ApiResponse::success(['plan_code' => $stored['plan_code'] ?? $row['plan_code']], 200, lang('Plans.saved'));
    }

    /**
     * payment-method/create and payment-method/edit
     * @param bool $edit
     * @return ResponseInterface
     */
    private function savePaymentMethod(bool $edit): ResponseInterface
    {
        $endpoint = $edit ? 'v1/staff/plans/payment-method/edit' : 'v1/staff/plans/payment-method/create';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Plans.invalidRequest'), 422);
        }
        $methods = model(PaymentMethodModel::class);
        $stored  = null;
        if ($edit) {
            try {
                $stored = $methods->findByCode($this->text($input, 'method_code'));
            } catch (Throwable $e) {
                return $this->failed($endpoint, $e, 'Plans.loadFailed');
            }
            if ($stored === null) {
                return ApiResponse::error(lang('Plans.methodNotFound'), 404);
            }
        }
        $row = $this->formRow($input, PaymentMethodModel::FORM_FIELDS, PaymentMethodModel::FORM_NULLABLE, $stored, ['method_code'],
            ['country_code', 'currency_code', 'confirmation_mode', 'is_active']);
        if (! $edit) {
            $row['method_code'] = strtolower((string) $row['method_code']);
            $row['is_active'] ??= 'Y';
            $row['sort_order'] ??= '0';
        }
        helper('list');
        $errors = $this->ruleErrors($methods, $row, $stored);
        if (! isset($errors['country_code']) && $row['country_code'] !== null && ! isset(listSupportedCountries()[$row['country_code']])) {
            $errors['country_code'] = lang('Plans.unknownCountry');
        }
        if (! isset($errors['currency_code']) && ! isset(listCurrencies()[$row['currency_code']])) {
            $errors['currency_code'] = lang('Plans.unknownCurrency');
        }
        if (! isset($errors['provider_code']) && $row['confirmation_mode'] === PaymentMethodModel::CONFIRMED_BY_PROVIDER && $row['provider_code'] === null) {
            $errors['provider_code'] = lang('Plans.providerRequired');
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Plans.invalidFields'), 422, $errors);
        }

        try {
            if ($stored === null) {
                $methods->createLogged($row, $this->actorId(), 'Created payment method ' . $row['method_code']);
            } else {
                $methods->updateLogged($stored, $row, $this->actorId(), 'Updated payment method ' . $stored['method_code']);
            }
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Plans.saveFailed');
        }

        return ApiResponse::success(['method_code' => $stored['method_code'] ?? $row['method_code']], 200, lang('Plans.saved'));
    }

    /**
     * The row of a create (every field from the body; one left out is null) or an edit (a field left out keeps its
     * stored value; $fixed fields always do)
     * @param array        $input
     * @param list<string> $fields
     * @param list<string> $nullable
     * @param array|null   $stored
     * @param list<string> $fixed     Fields an edit never changes
     * @param list<string> $uppercase
     * @return array
     */
    private function formRow(array $input, array $fields, array $nullable, ?array $stored, array $fixed, array $uppercase = []): array
    {
        $row = [];
        foreach ($fields as $field) {
            if ($stored !== null && (in_array($field, $fixed, true) || ! array_key_exists($field, $input))) {
                if (! in_array($field, $fixed, true)) {
                    $row[$field] = $stored[$field];
                }

                continue;
            }
            $value = $this->text($input, $field);
            if (in_array($field, $uppercase, true)) {
                $value = strtoupper($value);
            }
            $row[$field] = $value === '' && (in_array($field, $nullable, true) || ! array_key_exists($field, $input)) ? null : $value;
        }

        return $row;
    }

    /**
     * What the model's rules say about $row, keyed by column, labelled from Plans.fields
     * @param Model      $model
     * @param array      $row
     * @param array|null $stored For is_unique[...,id,{id}]
     * @return array<string, string>
     */
    private function ruleErrors(Model $model, array $row, ?array $stored): array
    {
        $rules = [];
        foreach ($model->getValidationRules(['only' => ['id', ...array_keys($row)]]) as $field => $fieldRules) {
            $rules[$field] = ['label' => $field === 'id' ? 'ID' : lang('Plans.fields.' . $field), 'rules' => $fieldRules];
        }
        $validation = single_service('validation')->setRules($rules);

        return $validation->run($row + ['id' => $stored === null ? '' : (string) $stored['id']]) ? [] : $validation->getErrors();
    }

    /**
     * What the rules cannot check about a discount
     * @param array      $row
     * @param array|null $stored
     * @param array      $errors Already found, so a field is not reported twice
     * @return array<string, string>
     */
    private function discountErrors(array $row, ?array $stored, array $errors): array
    {
        $more  = [];
        $fixed = $row['discount_type'] === DiscountMasterModel::TYPE_FIXED;
        if (! isset($errors['discount_value']) && ! isset($errors['discount_type'])) {
            $cents = Money::toCents($row['discount_value']);
            if ($cents === 0) {
                $more['discount_value'] = lang('Plans.discountNotPositive');
            } elseif (! $fixed && $cents > 10000) {
                $more['discount_value'] = lang('Plans.percentageTooHigh');
            }
        }
        if (! isset($errors['currency_code'])) {
            if ($fixed && $row['currency_code'] === null) {
                $more['currency_code'] = lang('Plans.currencyRequired');
            } elseif (! $fixed && $row['currency_code'] !== null) {
                $more['currency_code'] = lang('Plans.noCurrencyForPercentage');
            } elseif ($row['currency_code'] !== null && ! isset(listCurrencies()[$row['currency_code']])) {
                $more['currency_code'] = lang('Plans.unknownCurrency');
            }
        }
        if (! isset($errors['country_code'])) {
            if ($fixed && $row['country_code'] === null) {
                $more['country_code'] = lang('Plans.countryRequired');
            } elseif ($row['country_code'] !== null && $row['country_code'] !== PlanVersionModel::REST_OF_WORLD && ! isset(listSupportedCountries()[$row['country_code']])) {
                $more['country_code'] = lang('Plans.unknownCountry');
            }
        }
        if (! isset($errors['expires_at']) && ! isset($errors['valid_from']) && $row['expires_at'] <= $row['valid_from']) {
            $more['expires_at'] = lang('Plans.expiresBeforeValid');
        }
        if (! isset($errors['max_redemptions']) && $stored !== null && $row['max_redemptions'] !== null && (int) $row['max_redemptions'] < (int) $stored['redemption_count']) {
            $more['max_redemptions'] = lang('Plans.capBelowUses', [(int) $stored['redemption_count']]);
        }

        return $more;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $names column => the body's field
     * @return array<string, string>
     */
    private function renameKeys(array $errors, array $names): array
    {
        $renamed = [];
        foreach ($errors as $key => $error) {
            $renamed[$names[$key] ?? $key] = $error;
        }

        return $renamed;
    }
}
