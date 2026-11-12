<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\BookingWindow;
use App\Models\ArtifactAttributeModel;
use App\Models\ArtifactCategoryModel;
use App\Models\ArtifactMasterModel;
use App\Models\SubscriptionMasterModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\PlanLimits;
use Throwable;

/**
 * The products and services of a business (artifact_master) with their variants, the attribute values of those and
 * their stock per store: the Client app's, behind the jwt:C filter, and only for the active owners and managers of
 * the business. Artifacts and variants are never deleted: artifact_status I hides an artifact, D retires it for good,
 * variant_status I takes a variant off sale. The images are ArtifactImages'; show() includes them.
 *
 * An artifact is sent as { artifact, variants, images } (see ArtifactMasterModel::detail()): prices are strings with
 * two decimals in the business's currency; artifact.price_active_lowest and price_compare_lowest are the cheapest
 * active variant's, worked out on every save and never sent by the client.
 */
class Artifacts extends BaseController
{
    use ManagedBusinessTrait;
    use TranslationInputTrait;

    /** The most variants an artifact may have */
    private const MAX_VARIANTS = 100;

    private const NAME_MAX_LENGTH        = 255;
    private const DESCRIPTION_MAX_LENGTH = 10000;
    private const CODE_MAX_LENGTH        = 64; // sku, barcode

    /** A price: up to 15 digits and 2 decimals */
    private const MONEY_PATTERN = '/^\d{1,15}(\.\d{1,2})?$/';

    /** tax_percentage: above 0, at most 100, 2 decimals */
    private const PERCENT_PATTERN = '/^\d{1,3}(\.\d{1,2})?$/';

    private const STATUSES = [ArtifactMasterModel::STATUS_ACTIVE, ArtifactMasterModel::STATUS_INACTIVE, ArtifactMasterModel::STATUS_DISCONTINUED];
    private const TYPES    = [ArtifactMasterModel::TYPE_PRODUCT, ArtifactMasterModel::TYPE_SERVICE];
    private const LABELS   = ['N', 'P', 'R', 'L']; // new, popular, recommended, limited
    private const TAXES    = [ArtifactMasterModel::TAX_INCLUSIVE, ArtifactMasterModel::TAX_EXCLUSIVE, ArtifactMasterModel::TAX_NONE];

    /**
     * POST /client/artifacts { business_slug, filters: { artifact_type, artifact_status, category_public_id } }
     * The artifacts of that business by name, each with public_id, artifact_name, artifact_type, artifact_status,
     * artifact_label, category_public_id, price_active_lowest, price_compare_lowest, rating_count, rating_average,
     * rating_breakdown ({ "1": reviews, ..., "5": reviews }, the approved ones with each number of stars),
     * variant_count and cover_url (its first image, or null). Every filter is optional.
     * With the business's plan_code and caps, by artifact_type: { P: { max, count, can_add }, S: {...} }, where max is
     * the plan's cap (Config\PlanLimits::PRODUCTS or SERVICES), count the artifacts of the type that it counts (the
     * discontinued ones are not, whatever the filters) and can_add false once count reaches max, so create would be
     * refused; and per_artifact: { variants, images }, the plan's caps of each artifact (Config\PlanLimits::VARIANTS,
     * IMAGE_PER_ARTIFACT), for the form of a new one.
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/artifacts';
        $input    = $this->jsonInput();
        if ($input === null || ! is_array($input['filters'] ?? [])) {
            return ApiResponse::error(lang('Artifact.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Artifact');
        if ($error !== null) {
            return $error;
        }
        $filtersIn = $input['filters'] ?? [];
        $filters   = [];
        foreach (['artifact_type' => self::TYPES, 'artifact_status' => self::STATUSES] as $filter => $allowed) {
            $value = is_string($filtersIn[$filter] ?? null) ? strtoupper(trim($filtersIn[$filter])) : '';
            if (in_array($value, $allowed, true)) {
                $filters[$filter] = $value;
            }
        }
        try {
            if (is_string($filtersIn['category_public_id'] ?? null) && $filtersIn['category_public_id'] !== '') {
                $category                = model(ArtifactCategoryModel::class)->findByPublicId($filtersIn['category_public_id']);
                $filters['category_id'] = $category !== null && (int) $category['business_id'] === (int) $business['id'] ? (int) $category['id'] : 0; // 0: none
            }
            $artifacts = model(ArtifactMasterModel::class);
            $rows      = $artifacts->listOfBusiness((int) $business['id'], $filters);
            $counts    = $artifacts->countsByType((int) $business['id']);
            $plan      = model(SubscriptionMasterModel::class)->planOfBusiness((int) $business['id'])['plan_code'];
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Artifact.loadFailed');
        }
        $caps = [];
        foreach (PlanLimits::ARTIFACT_TYPE_CAPS as $type => $limit) {
            $max         = config(PlanLimits::class)->limitOf($plan, $limit);
            $caps[$type] = ['max' => $max, 'count' => $counts[$type], 'can_add' => $counts[$type] < $max];
        }

        $perArtifact = [
            'variants' => config(PlanLimits::class)->limitOf($plan, PlanLimits::VARIANTS),
            'images'   => config(PlanLimits::class)->limitOf($plan, PlanLimits::IMAGE_PER_ARTIFACT),
        ];

        return ApiResponse::success(['artifacts' => $rows, 'plan_code' => $plan, 'caps' => $caps, 'per_artifact' => $perArtifact]);
    }

    /**
     * POST /client/artifact { public_id }
     * The artifact with its translations, variants and images; with its business's plan_code and caps:
     * { images: { max, count, can_add }, variants: { max, count, can_add } }, where max is the plan's cap
     * (Config\PlanLimits::IMAGE_PER_ARTIFACT, VARIANTS), count what the artifact has and can_add false once count
     * reaches max, so an upload or a new variant would be refused
     * @return ResponseInterface
     */
    public function show(): ResponseInterface
    {
        $endpoint = 'v1/client/artifact';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Artifact.invalidRequest'), 422);
        }
        [$stored, $error] = $this->managedArtifact($input, $endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            $detail = model(ArtifactMasterModel::class)->detail((int) $stored['id']);
            $plan   = model(SubscriptionMasterModel::class)->planOfBusiness((int) $stored['business_id'])['plan_code'];
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Artifact.loadFailed');
        }
        $caps = [];
        foreach (['images' => PlanLimits::IMAGE_PER_ARTIFACT, 'variants' => PlanLimits::VARIANTS] as $what => $limit) {
            $max         = config(PlanLimits::class)->limitOf($plan, $limit);
            $count       = count($detail[$what]);
            $caps[$what] = ['max' => $max, 'count' => $count, 'can_add' => $count < $max];
        }

        return ApiResponse::success($detail + ['plan_code' => $plan, 'caps' => $caps]);
    }

    /**
     * POST /client/artifact/create { business_slug, artifact: {...}, translations: {...}, variants: [{...}] }
     * - artifact: artifact_name, artifact_description, artifact_type (P or S, fixed once created), artifact_status
     *   (A, I the default, or D), artifact_label (N, P, R, L or null), category_public_id (a category of the business,
     *   or null), tax_calculation (I, E or X) and tax_percentage (above 0 and at most 100 for I and E; NULL for X)
     * - translations: { language_code: { artifact_name, artifact_description } }, optional
     * - variants: at least one, each { sku, barcode, price_active, price_compare, variant_status, weight_grams,
     *   duration_minutes, booking_type, staff_required, capacity, sort_order, attribute_values: [value public_id, ...],
     *   inventory: [{ store_public_id, quantity }] }. barcode and weight_grams are kept for products only,
     *   duration_minutes, booking_type, staff_required and capacity for services only: booking_type A (appointment, the
     *   default) or G (group), staff_required 1-255 (1 by default), and capacity 1-65535, the most customers in one
     *   session, required for G and not kept for A; inventory, for products only, creates the variant's stock at the stores
     *   picked. Every variant uses the same attributes. A product's variants differ by the combination of their
     *   values; a service's by that combination together with duration_minutes (required for a service) and
     *   booking_type, so a service can have, say, a 60- and a 90-minute variant, each as a group or a private session.
     *   A service variant may have its own booking window, booking_horizon_days, booking_notice_unit,
     *   booking_notice_value and booking_notice_time, as the business has (see App\Libraries\BookingWindow): all of
     *   them, or all null for the business's.
     * Refused with 409 once the business has as many artifacts of the type as its plan allows
     * (Config\PlanLimits::PRODUCTS or SERVICES, discontinued ones not counted), or when there are more variants than
     * its plan allows per artifact (Config\PlanLimits::VARIANTS).
     * @return ResponseInterface The artifact, as show() gives it
     */
    public function create(): ResponseInterface
    {
        return $this->save(false);
    }

    /**
     * POST /client/artifact/edit { public_id, artifact: {...}, translations: {...}, variants: [{...}] }
     * A field left out of "artifact" keeps its value; artifact_type never changes, and a discontinued (D) artifact
     * stays discontinued. "translations", when given, replace the names; left out, they stay.
     * A variant with a public_id updates that variant of the artifact (a field left out keeps its value;
     * attribute_values, when given, replace its values); one without is added; one left out stays as it is.
     * A variant's inventory sets the quantity at each store given, adding the store when the variant has no stock
     * there yet (a stock count); the stores left out keep theirs.
     * Variants added are refused with 409 when the artifact would have more than its business's plan allows
     * (Config\PlanLimits::VARIANTS); one over it after moving to a smaller plan keeps its variants, and can still
     * change them.
     * @return ResponseInterface The artifact, as show() gives it
     */
    public function edit(): ResponseInterface
    {
        return $this->save(true);
    }

    /**
     * create() and edit()
     * @param bool $edit
     * @return ResponseInterface
     */
    private function save(bool $edit): ResponseInterface
    {
        $endpoint   = $edit ? 'v1/client/artifact/edit' : 'v1/client/artifact/create';
        $input      = $this->jsonInput();
        $in         = $input['artifact'] ?? [];
        $variantsIn = $input['variants'] ?? [];
        if ($input === null || ! is_array($in) || ! is_array($variantsIn) || ! array_is_list($variantsIn)) {
            return ApiResponse::error(lang('Artifact.invalidRequest'), 422);
        }

        $artifacts = model(ArtifactMasterModel::class);
        $stored    = null;
        if ($edit) {
            [$stored, $error] = $this->managedArtifact($input, $endpoint);
            $businessId       = $stored === null ? 0 : (int) $stored['business_id'];
        } else {
            [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Artifact');
            $businessId         = $business === null ? 0 : (int) $business['id'];
        }
        if ($error !== null) {
            return $error;
        }

        $errors = [];
        $type   = $stored['artifact_type'] ?? strtoupper(is_string($in['artifact_type'] ?? null) ? trim($in['artifact_type']) : '');
        if ($stored === null && ! in_array($type, self::TYPES, true)) {
            $errors['artifact_type'] = lang('Artifact.invalidType');
        } elseif ($stored !== null && array_key_exists('artifact_type', $in) && strtoupper((string) $in['artifact_type']) !== $type) {
            $errors['artifact_type'] = lang('Artifact.typeFixed');
        }

        try {
            [$artifact, $fieldErrors] = $this->artifactFields($in, $stored, $businessId);
            $storedVariants           = $stored === null ? [] : $artifacts->variantsOf((int) $stored['id']);
            [$variants, $variantErrors] = $this->variants($variantsIn, $storedVariants, $type, $businessId, $stored === null ? null : (int) $stored['id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Artifact.loadFailed');
        }
        $errors += $fieldErrors + $variantErrors;
        if ($stored === null && $variantsIn === []) {
            $errors['variants'] = lang('Artifact.variantRequired');
        }
        $names = null;
        if (array_key_exists('translations', $input) || $stored === null) {
            [$names, $translationErrors] = $this->translationsIn($input['translations'] ?? [], ['artifact_name' => self::NAME_MAX_LENGTH, 'artifact_description' => self::DESCRIPTION_MAX_LENGTH]);
            $errors += $translationErrors;
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Artifact.invalidFields'), 422, $errors);
        }

        $maxOfType = null;
        try {
            $plan = model(SubscriptionMasterModel::class)->planOfBusiness($businessId)['plan_code'];
            // the plan's cap of variants per artifact, checked only when variants are added
            $added       = count(array_filter($variants, static fn (array $variant): bool => $variant['stored'] === null));
            $maxVariants = config(PlanLimits::class)->limitOf($plan, PlanLimits::VARIANTS);
            if ($added > 0 && count($storedVariants) + $added > $maxVariants) {
                return ApiResponse::error(lang('Artifact.variantLimitReached', [$maxVariants, ucfirst($plan)]), 409);
            }
            if ($stored === null) {
                // the plan's cap of the type; a business over it after moving to a smaller plan keeps what it has
                $maxOfType = config(PlanLimits::class)->limitOf($plan, PlanLimits::ARTIFACT_TYPE_CAPS[$type]);
                if ($maxOfType === 0) {
                    return ApiResponse::error(lang('Artifact.notIncluded', [ucfirst($plan), lang('Artifact.typeNames.' . $type)]), 409);
                }
                $artifact += ['business_id' => $businessId, 'artifact_type' => $type];
            }
            $publicId = $artifacts->saveArtifact($stored, $artifact, $names, $variants, $maxOfType, $this->actorId());
            if ($publicId === null) {
                return ApiResponse::error(lang('Artifact.limitReached', [$maxOfType, ucfirst($plan), lang('Artifact.typeNames.' . $type)]), 409);
            }
            $detail = $artifacts->detail($artifacts->idOfPublicId($publicId));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Artifact.saveFailed');
        }

        return ApiResponse::success($detail, 200, lang($edit ? 'Artifact.updated' : 'Artifact.created'));
    }

    /**
     * The artifact_master columns of "artifact", checked; a field left out keeps its stored value
     * @param array      $in
     * @param array|null $stored
     * @param int        $businessId
     * @return array{0: array, 1: array<string, string>} [the columns of ArtifactMasterModel::FORM_FIELDS, errors]
     * @throws Throwable When the category cannot be looked up
     */
    private function artifactFields(array $in, ?array $stored, int $businessId): array
    {
        $errors = [];
        $value  = static fn (string $field, mixed $default = null): mixed => array_key_exists($field, $in) ? $in[$field] : ($stored[$field] ?? $default);
        $text   = static fn (mixed $value): ?string => is_string($value) || is_int($value) || is_float($value) ? (trim((string) $value) === '' ? null : trim((string) $value)) : null;

        $artifact = [
            'artifact_name'        => $text($value('artifact_name')),
            'artifact_description' => $text($value('artifact_description')),
            'artifact_status'      => strtoupper((string) $text($value('artifact_status', ArtifactMasterModel::STATUS_INACTIVE))),
            'artifact_label'       => $text($value('artifact_label')),
            'tax_calculation'      => strtoupper((string) $text($value('tax_calculation'))),
        ];
        if ($artifact['artifact_name'] === null) {
            $errors['artifact_name'] = lang('Artifact.nameRequired');
        } elseif (mb_strlen($artifact['artifact_name']) > self::NAME_MAX_LENGTH) {
            $errors['artifact_name'] = lang('Artifact.tooLong', [self::NAME_MAX_LENGTH]);
        }
        if ($artifact['artifact_description'] !== null && mb_strlen($artifact['artifact_description']) > self::DESCRIPTION_MAX_LENGTH) {
            $errors['artifact_description'] = lang('Artifact.tooLong', [self::DESCRIPTION_MAX_LENGTH]);
        }
        if (! in_array($artifact['artifact_status'], self::STATUSES, true)) {
            $errors['artifact_status'] = lang('Artifact.invalidStatus');
        } elseif (($stored['artifact_status'] ?? null) === ArtifactMasterModel::STATUS_DISCONTINUED && $artifact['artifact_status'] !== ArtifactMasterModel::STATUS_DISCONTINUED) {
            $errors['artifact_status'] = lang('Artifact.discontinued');
        }
        if ($artifact['artifact_label'] !== null) {
            $artifact['artifact_label'] = strtoupper($artifact['artifact_label']);
            if (! in_array($artifact['artifact_label'], self::LABELS, true)) {
                $errors['artifact_label'] = lang('Artifact.invalidLabel');
            }
        }

        if (! in_array($artifact['tax_calculation'], self::TAXES, true)) {
            $errors['tax_calculation'] = lang('Artifact.invalidTax');
            $artifact['tax_percentage'] = null;
        } elseif ($artifact['tax_calculation'] === ArtifactMasterModel::TAX_NONE) {
            $artifact['tax_percentage'] = null;
        } else {
            $percent = $text($value('tax_percentage'));
            if ($percent === null || ! preg_match(self::PERCENT_PATTERN, $percent) || (float) $percent <= 0 || (float) $percent > 100) {
                $errors['tax_percentage'] = lang('Artifact.invalidTaxPercentage');
                $artifact['tax_percentage'] = null;
            } else {
                $artifact['tax_percentage'] = number_format((float) $percent, 2, '.', '');
            }
        }

        // category_public_id in, category_id stored
        if (array_key_exists('category_public_id', $in)) {
            $artifact['category_id'] = null;
            $categoryPublicId        = $in['category_public_id'];
            if ($categoryPublicId !== null && $categoryPublicId !== '') {
                $category = model(ArtifactCategoryModel::class)->findByPublicId($categoryPublicId);
                if ($category === null || (int) $category['business_id'] !== $businessId) {
                    $errors['category_public_id'] = lang('Artifact.categoryNotFound');
                } else {
                    $artifact['category_id'] = (int) $category['id'];
                }
            }
        } else {
            $artifact['category_id'] = $stored === null || $stored['category_id'] === null ? null : (int) $stored['category_id'];
        }

        return [$artifact, $errors];
    }

    /**
     * The "variants" of the body, checked one by one and together with the artifact's other variants
     * @param list<mixed>          $variantsIn
     * @param array<string, array> $storedVariants public_id => variant, as ArtifactMasterModel::variantsOf() gives them
     * @param string               $type           artifact_type
     * @param int                  $businessId
     * @param int|null             $artifactId     The artifact being edited
     * @return array{0: list<array>, 1: array<string, string>} [variants as ArtifactMasterModel::saveArtifact() takes them, errors]
     * @throws Throwable When a lookup fails
     */
    private function variants(array $variantsIn, array $storedVariants, string $type, int $businessId, ?int $artifactId): array
    {
        // look up every attribute value and store named at once
        $valuePublicIds = [];
        $storePublicIds = [];
        foreach ($variantsIn as $in) {
            foreach ((is_array($in) && is_array($in['attribute_values'] ?? null) ? $in['attribute_values'] : []) as $valuePublicId) {
                if (is_string($valuePublicId)) {
                    $valuePublicIds[$valuePublicId] = true;
                }
            }
            foreach ((is_array($in) && is_array($in['inventory'] ?? null) ? $in['inventory'] : []) as $stock) {
                if (is_array($stock) && is_string($stock['store_public_id'] ?? null)) {
                    $storePublicIds[$stock['store_public_id']] = true;
                }
            }
        }
        $values = model(ArtifactAttributeModel::class)->valuesOfBusiness($businessId, array_keys($valuePublicIds));
        $stores = $storePublicIds === [] ? [] : array_column(
            db_connect()->table('business_store')->select('id, public_id')->where('business_id', $businessId)->whereIn('public_id', array_keys($storePublicIds))->get()->getResultArray(),
            'id',
            'public_id',
        );

        $variants = [];
        $errors   = [];
        $listed   = []; // public_id => true
        foreach ($variantsIn as $i => $in) {
            $key = 'variants.' . $i;
            if (! is_array($in) || ($in !== [] && array_is_list($in))) {
                $errors[$key] = lang('Artifact.invalidVariant');

                continue;
            }
            $stored = null;
            if (($in['public_id'] ?? null) !== null && $in['public_id'] !== '') {
                $stored = is_string($in['public_id']) ? ($storedVariants[$in['public_id']] ?? null) : null;
                if ($stored === null || isset($listed[$stored['public_id']])) {
                    $errors[$key . '.public_id'] = lang('Artifact.variantNotFound');

                    continue;
                }
                $listed[$stored['public_id']] = true;
            }
            [$row, $rowErrors] = $this->variantRow($in, $stored, $type, $i, $key);
            $errors += $rowErrors;

            $chosen = null;
            if (array_key_exists('attribute_values', $in)) {
                $chosen = [];
                $list   = $in['attribute_values'];
                if (! is_array($list) || ! array_is_list($list)) {
                    $errors[$key . '.attribute_values'] = lang('Artifact.invalidAttributeValues');
                } else {
                    foreach ($list as $valuePublicId) {
                        $found = is_string($valuePublicId) ? ($values[$valuePublicId] ?? null) : null;
                        if ($found === null) {
                            $errors[$key . '.attribute_values'] = lang('Artifact.attributeValueNotFound');
                        } elseif (isset($chosen[$found['attribute_id']])) {
                            $errors[$key . '.attribute_values'] = lang('Artifact.oneValuePerAttribute');
                        } else {
                            $chosen[$found['attribute_id']] = $found['id'];
                        }
                    }
                }
            } elseif ($stored === null) {
                $chosen = [];
            }

            $inventory = [];
            if (array_key_exists('inventory', $in) && $in['inventory'] !== null && $in['inventory'] !== []) {
                if ($type !== ArtifactMasterModel::TYPE_PRODUCT) {
                    $errors[$key . '.inventory'] = lang('Artifact.inventoryProductsOnly');
                } elseif (! is_array($in['inventory']) || ! array_is_list($in['inventory'])) {
                    $errors[$key . '.inventory'] = lang('Artifact.invalidInventory');
                } else {
                    foreach ($in['inventory'] as $j => $stock) {
                        $storeId  = is_array($stock) && is_string($stock['store_public_id'] ?? null) ? ($stores[$stock['store_public_id']] ?? null) : null;
                        $quantity = filter_var($stock['quantity'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => -2147483648, 'max_range' => 2147483647]]);
                        if ($storeId === null || array_key_exists((int) $storeId, $inventory)) {
                            $errors[$key . '.inventory.' . $j . '.store_public_id'] = lang('Artifact.storeNotFound');
                        } elseif ($quantity === false || is_bool($stock['quantity'] ?? 0)) {
                            $errors[$key . '.inventory.' . $j . '.quantity'] = lang('Artifact.invalidQuantity');
                        } else {
                            $inventory[(int) $storeId] = $quantity;
                        }
                    }
                }
            }

            $variants[$i] = ['stored' => $stored, 'row' => $row, 'values' => $chosen, 'inventory' => $inventory];
        }
        if ($errors !== []) {
            return [[], $errors];
        }

        // together: the variants once saved, the stored ones left out included
        $final = [];
        foreach ($storedVariants as $publicId => $variant) {
            if (! isset($listed[$publicId])) {
                $final[] = ['index' => null, 'values' => $variant['values'], 'sku' => $variant['sku'],
                    'duration' => $variant['duration_minutes'] === null ? null : (int) $variant['duration_minutes'], 'booking' => $variant['booking_type']];
            }
        }
        foreach ($variants as $i => $variant) {
            $final[] = ['index' => $i, 'values' => $variant['values'] ?? $variant['stored']['values'], 'sku' => $variant['row']['sku'],
                'duration' => $variant['row']['duration_minutes'], 'booking' => $variant['row']['booking_type']];
        }
        if (count($final) > self::MAX_VARIANTS) {
            return [[], ['variants' => lang('Artifact.tooManyVariants', [self::MAX_VARIANTS])]];
        }

        // the stored variants left out come first, so a clash is always reported on a variant of the request
        $attributeSets = [];
        $combinations  = [];
        $skus          = [];
        foreach ($final as $variant) {
            ksort($variant['values']);
            $attributeSets[implode(',', array_keys($variant['values']))] = true;
            // a service's variants also differ by their length and booking type
            $service     = $type === ArtifactMasterModel::TYPE_SERVICE;
            $combination = json_encode($service ? [$variant['values'], $variant['duration'], $variant['booking']] : $variant['values']);
            $key         = $variant['index'] === null ? null : 'variants.' . $variant['index'];
            if ($key !== null && isset($combinations[$combination])) {
                $errors[$key . ($service ? '.duration_minutes' : '.attribute_values')] = lang($service ? 'Artifact.serviceCombinationTaken' : 'Artifact.combinationTaken');
            }
            $combinations[$combination] = true;
            if ($variant['sku'] !== null) {
                if ($key !== null && isset($skus[$variant['sku']])) {
                    $errors[$key . '.sku'] = lang('Artifact.skuTaken');
                }
                $skus[$variant['sku']] = true;
            }
        }
        if (count($attributeSets) > 1) {
            $errors['variants'] = lang('Artifact.attributesDiffer');
        }
        $requested = [];
        foreach ($variants as $i => $variant) {
            if ($variant['row']['sku'] !== null) {
                $requested[$variant['row']['sku']] = $i;
            }
        }
        foreach (model(ArtifactMasterModel::class)->skusTaken($businessId, array_map('strval', array_keys($requested)), $artifactId) as $sku) {
            $errors['variants.' . $requested[$sku] . '.sku'] = lang('Artifact.skuTaken');
        }

        return [array_values($variants), $errors];
    }

    /**
     * The artifact_variant columns of one variant, checked; a field left out keeps its stored value
     * @param array      $in
     * @param array|null $stored
     * @param string     $type  artifact_type
     * @param int        $index Its place in the list, its sort_order by default
     * @param string     $key   The prefix of its errors
     * @return array{0: array, 1: array<string, string>}
     */
    private function variantRow(array $in, ?array $stored, string $type, int $index, string $key): array
    {
        $errors = [];
        $value  = static fn (string $field, mixed $default = null): mixed => array_key_exists($field, $in) ? $in[$field] : ($stored[$field] ?? $default);
        $text   = static fn (mixed $value): ?string => is_string($value) || is_int($value) || is_float($value) ? (trim((string) $value) === '' ? null : trim((string) $value)) : null;
        $whole  = static fn (mixed $value, int $min, int $max): int|false|null => $value === null || $value === '' ? null
            : (is_bool($value) ? false : filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]));

        $row = [];
        foreach (['sku', 'barcode'] as $field) {
            $row[$field] = $text($value($field));
            if ($row[$field] !== null && mb_strlen($row[$field]) > self::CODE_MAX_LENGTH) {
                $errors[$key . '.' . $field] = lang('Artifact.tooLong', [self::CODE_MAX_LENGTH]);
            }
        }

        $priceActive = $this->cents($value('price_active'));
        if ($priceActive === null || $priceActive === false) {
            $errors[$key . '.price_active'] = lang('Artifact.invalidPrice');
        }
        $priceCompare = $this->cents($value('price_compare'));
        if ($priceCompare === false) {
            $errors[$key . '.price_compare'] = lang('Artifact.invalidPrice');
        } elseif ($priceCompare !== null && is_int($priceActive) && $priceCompare <= $priceActive) {
            $errors[$key . '.price_compare'] = lang('Artifact.compareNotHigher');
        }
        $row['price_active']  = is_int($priceActive) ? self::money($priceActive) : null;
        $row['price_compare'] = is_int($priceCompare) ? self::money($priceCompare) : null;

        $row['variant_status'] = strtoupper((string) $text($value('variant_status', 'A')));
        if (! in_array($row['variant_status'], ['A', 'I'], true)) {
            $errors[$key . '.variant_status'] = lang('Artifact.invalidVariantStatus');
        }

        $row['booking_type'] = strtoupper((string) $text($value('booking_type', ArtifactMasterModel::BOOKING_APPOINTMENT)));
        if (! in_array($row['booking_type'], [ArtifactMasterModel::BOOKING_APPOINTMENT, ArtifactMasterModel::BOOKING_GROUP], true)) {
            $errors[$key . '.booking_type'] = lang('Artifact.invalidBookingType');
        }

        $defaults = ['sort_order' => $index, 'staff_required' => 1];
        foreach (['weight_grams' => [0, 4294967295], 'duration_minutes' => [1, 65535], 'staff_required' => [1, 255], 'capacity' => [1, 65535], 'sort_order' => [0, 65535]] as $field => [$min, $max]) {
            $number = $whole($value($field, $defaults[$field] ?? null), $min, $max);
            if ($number === false) {
                $errors[$key . '.' . $field] = lang('Artifact.invalidNumber', [$min, $max]);
            }
            $row[$field] = is_int($number) ? $number : null;
        }
        $row['sort_order'] ??= $index;
        $row['staff_required'] ??= 1;
        // a service's length is one of what tells its variants apart
        if ($type === ArtifactMasterModel::TYPE_SERVICE && $row['duration_minutes'] === null && ! isset($errors[$key . '.duration_minutes'])) {
            $errors[$key . '.duration_minutes'] = lang('Artifact.durationRequired');
        }

        // a group session takes up to capacity customers; an appointment is one customer's
        if ($row['booking_type'] === ArtifactMasterModel::BOOKING_GROUP) {
            if ($row['capacity'] === null && ! isset($errors[$key . '.capacity'])) {
                $errors[$key . '.capacity'] = lang('Artifact.capacityRequired');
            }
        } else {
            $row['capacity'] = null;
            unset($errors[$key . '.capacity']);
        }

        // its own booking window, all of it, or none for the business's
        $window = [];
        foreach (BookingWindow::FIELDS as $field) {
            $raw            = $value($field);
            $window[$field] = is_int($raw) ? $raw : $text($raw);
        }
        $window['booking_notice_unit'] = $window['booking_notice_unit'] === null ? null : strtoupper((string) $window['booking_notice_unit']);
        $window['booking_notice_time'] = BookingWindow::normalizeTime($window['booking_notice_time']);
        if (array_filter($window, static fn (mixed $v): bool => $v !== null) !== []) {
            foreach (BookingWindow::errors($window) as $field => $error) {
                $errors[$key . '.' . $field] = $error;
            }
        }
        $row += $window;

        // what the other type has no use for is not kept
        foreach ($type === ArtifactMasterModel::TYPE_PRODUCT ? ArtifactMasterModel::SERVICE_ONLY_FIELDS : ArtifactMasterModel::PRODUCT_ONLY_FIELDS as $field) {
            $row[$field] = null;
            unset($errors[$key . '.' . $field]);
        }

        return [$row, $errors];
    }

    /**
     * A price from the body in cents: null when empty, false when it is not a price
     * @param mixed $value
     * @return int|false|null
     */
    private function cents(mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_float($value)) {
            $value = number_format($value, 2, '.', '');
        }
        if (! (is_string($value) || is_int($value)) || ! preg_match(self::MONEY_PATTERN, trim((string) $value))) {
            return false;
        }
        [$whole, $fraction] = explode('.', trim((string) $value) . '.');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    /**
     * Cents as the DECIMAL(19,2) the database takes
     * @param int $cents
     * @return string
     */
    private static function money(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The artifact of the body's public_id, when the signed-in client is an active owner or manager of its business
     * @param array  $input
     * @param string $endpoint for the error log
     * @return array{0: array|null, 1: ResponseInterface|null} [artifact as stored, null] or [null, error response]
     */
    private function managedArtifact(array $input, string $endpoint): array
    {
        $publicId = $input['public_id'] ?? null;
        if (! is_string($publicId) || $publicId === '') {
            return [null, ApiResponse::error(lang('Artifact.artifactIdRequired'), 400)];
        }
        try {
            $artifact = model(ArtifactMasterModel::class)->findByPublicId($publicId);
        } catch (Throwable $e) {
            return [null, $this->failed($endpoint, $e, 'Artifact.loadFailed')];
        }
        $error = $artifact === null
            ? ApiResponse::error(lang('Artifact.notFound'), 404)
            : $this->roleError((int) $artifact['business_id'], $endpoint, lang('Artifact.notFound'), 'Artifact');

        return $error === null ? [$artifact, null] : [null, $error];
    }

    /**
     * auth_master.id of the signed-in account, for log_activity.changed_by_acc
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

    /**
     * @param string    $endpoint For the error log
     * @param Throwable $e
     * @param string    $message  Language key shown to the user
     * @return ResponseInterface
     */
    private function failed(string $endpoint, Throwable $e, string $message): ResponseInterface
    {
        log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

        return ApiResponse::error(lang($message), 500);
    }
}
