<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\BusinessDiscountModel;
use App\Models\BusinessMasterModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The discount codes a business gives its own customers on the mart (business_discount): the Client app's, behind
 * the jwt:C filter (clients only), and only for the active owners and managers of the business.
 * A business comes in by its business_slug, a discount by its public_id; the ids stay inside.
 * Nothing is deleted: a discount is made inactive instead. redemption_count is counted by the mart, never set here.
 */
class Discounts extends BaseController
{
    use ManagedBusinessTrait;

    /**
     * POST /client/discounts { business_slug }
     * The discounts of that business by code. For each: public_id, discount_code, description, discount_type (P percent,
     * F fixed amount), discount_value, applies_to (A all, P products, S services), min_subtotal (null: any),
     * max_redemptions (null: no limit), redemption_count, valid_from and expires_at (UTC, null: always, forever),
     * is_active, created_at, updated_at. Amounts are strings with 2 decimals, in the business's currency_code, which
     * is sent beside them.
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/discounts';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Discount.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Discount');
        if ($error !== null) {
            return $error;
        }
        try {
            $rows     = model(BusinessDiscountModel::class)->listOfBusiness((int) $business['id']);
            $currency = model(BusinessMasterModel::class)->select('currency_code')->find((int) $business['id'])['currency_code'] ?? null;
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Discount.loadFailed'), 500);
        }

        return ApiResponse::success(['discounts' => $rows, 'currency_code' => $currency]);
    }

    /**
     * POST /client/discount/create { business_slug, discount: {discount_code, description, discount_type,
     * discount_value, applies_to, min_subtotal, max_redemptions, valid_from, expires_at, is_active} }
     * Creates a discount of that business. discount_code is upper-cased: 3 to 30 letters, digits, dashes and
     * underscores, unique within the business. discount_type P (discount_value up to 100) or F; discount_value above 0.
     * applies_to A (the default), P or S. min_subtotal, max_redemptions, valid_from and expires_at may be null;
     * the datetimes are UTC ("Y-m-d H:i:s" or "Y-m-d\TH:i"), expires_at after valid_from. is_active Y (the default) or N.
     * @return ResponseInterface
     */
    public function create(): ResponseInterface
    {
        return $this->saveDiscount(false);
    }

    /**
     * POST /client/discount/edit { public_id, discount: {...} }
     * Updates the discount with that public_id. A field left out of "discount" keeps its value. max_redemptions may
     * not go below the redemptions already made.
     * @return ResponseInterface
     */
    public function edit(): ResponseInterface
    {
        return $this->saveDiscount(true);
    }

    /**
     * create() and edit()
     * @param bool $edit
     * @return ResponseInterface
     */
    private function saveDiscount(bool $edit): ResponseInterface
    {
        $endpoint = $edit ? 'v1/client/discount/edit' : 'v1/client/discount/create';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Discount.invalidRequest'), 422);
        }
        $in = $input['discount'] ?? [];
        if (! is_array($in) || ($in !== [] && array_is_list($in))) {
            return ApiResponse::error(lang('Discount.invalidRequest'), 422);
        }

        $discounts = model(BusinessDiscountModel::class);
        $stored    = null;
        if ($edit) {
            $publicId = $input['public_id'] ?? null;
            if (! is_string($publicId) || $publicId === '') {
                return ApiResponse::error(lang('Discount.idRequired'), 400);
            }
            try {
                $stored   = $discounts->findByPublicId($publicId);
                $business = $stored === null ? null : model(BusinessMasterModel::class)->select('id')->find((int) $stored['business_id']);
            } catch (Throwable $e) {
                log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

                return ApiResponse::error(lang('Discount.loadFailed'), 500);
            }
            $error = $business === null ? ApiResponse::error(lang('Discount.notFound'), 404) : $this->roleError((int) $business['id'], $endpoint, lang('Discount.notFound'), 'Discount');
        } else {
            [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Discount');
        }
        if ($error !== null) {
            return $error;
        }
        if ($stored !== null) {
            // as the driver gives them back (SQLite: 10 or 10.5), so that only real changes are written and logged
            foreach (['discount_value', 'min_subtotal'] as $field) {
                $stored[$field] = BusinessDiscountModel::decimal($stored[$field]);
            }
        }

        // a field left out of an edit keeps its value; left out of a create, it takes its default
        $value    = static fn (string $field, mixed $default) => array_key_exists($field, $in) ? $in[$field] : ($stored[$field] ?? $default);
        $discount = [
            'business_id'     => (int) $business['id'],
            'discount_code'   => strtoupper(self::text($value('discount_code', ''))),
            'description'     => self::text($value('description', null)),
            'discount_type'   => strtoupper(self::text($value('discount_type', ''))),
            'discount_value'  => BusinessDiscountModel::decimal($value('discount_value', null)),
            'applies_to'      => strtoupper(self::text($value('applies_to', 'A'))),
            'min_subtotal'    => BusinessDiscountModel::decimal($value('min_subtotal', null)),
            'max_redemptions' => self::text($value('max_redemptions', null)),
            'valid_from'      => BusinessDiscountModel::datetime($value('valid_from', null)),
            'expires_at'      => BusinessDiscountModel::datetime($value('expires_at', null)),
            'is_active'       => strtoupper(self::text($value('is_active', 'Y'))),
        ];
        $discount['description']     = $discount['description'] === '' ? null : $discount['description'];
        $discount['max_redemptions'] = $discount['max_redemptions'] === '' ? null : $discount['max_redemptions'];

        $errors = [];
        $rules  = [];
        foreach ($discounts->getValidationRules(['only' => array_keys($discount)]) as $field => $fieldRules) {
            $rules[$field] = ['label' => lang('BusinessDiscount.fields.' . $field), 'rules' => $fieldRules];
        }
        $validation = single_service('validation')->setRules($rules);
        if (! $validation->run($discount)) {
            $errors = $validation->getErrors() + $errors;
        }
        $errors += array_diff_key(BusinessDiscountModel::ruleErrors($discount + ['redemption_count' => $stored['redemption_count'] ?? 0]), $errors);
        if ($errors !== []) {
            return ApiResponse::error(lang('Discount.invalidFields'), 422, $errors);
        }
        $discount['max_redemptions'] = $discount['max_redemptions'] === null ? null : (int) $discount['max_redemptions'];

        try {
            $actorId = $this->actorId();
            if ($stored === null) {
                $publicId = $discounts->createDiscount($discount, $actorId);
                $saved    = $publicId !== null;
            } else {
                $saved    = $discounts->updateDiscount($stored, $discount, $actorId);
                $publicId = $stored['public_id'];
            }
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Discount.saveFailed'), 500);
        }
        if (! $saved) {
            return ApiResponse::error(lang('Discount.invalidFields'), 422, ['discount_code' => lang('Discount.codeTaken')]);
        }

        return ApiResponse::success(['public_id' => $publicId], 200, lang($edit ? 'Discount.updated' : 'Discount.created'));
    }

    /**
     * A value from the body as a trimmed string; anything but a string or a number becomes '', which the rules refuse
     * where a value is needed
     * @param mixed $value
     * @return string
     */
    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }

    /**
     * auth_master.id of the signed-in account, for log_activity.changed_by_acc and ManagedBusinessTrait
     * @return int
     */
    private function actorId(): int
    {
        return (int) service('authContext')->account()['id'];
    }

    /**
     * The JSON body as an array: an empty body counts as {}, and anything that is not a JSON object is null
     * @return array|null
     */
    private function jsonInput(): ?array
    {
        if (trim((string) $this->request->getBody()) === '') {
            return [];
        }

        try {
            $input = $this->request->getJSON(true);
        } catch (HTTPException) {
            return null;
        }

        return is_array($input) && ($input === [] || ! array_is_list($input)) ? $input : null;
    }
}
