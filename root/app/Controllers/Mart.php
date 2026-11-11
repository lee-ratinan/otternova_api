<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\MartCache;
use App\Models\ArtifactMasterModel;
use App\Models\BusinessMasterModel;
use App\Models\MartCatalogModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Mart as MartConfig;
use Throwable;

/**
 * The public pages of a business on the marketplace (otternova_mart): no sign-in needed, as the visitors are anyone.
 * GET only, every one with business_slug and lang in the query string; lang is a language_code, and the answer is in
 * the business's website language nearest to it (MartCatalogModel::languageOf()), named in the answer's "language".
 * Answers come from the cache (App\Libraries\MartCache) when they can; App\Filters\MartCacheRefresh clears the
 * business's when the Client app changes it.
 * - /mart/site:      the business and how its pages look
 * - /mart/artifacts: product cards, by public_ids, category or type
 * - /mart/artifact:  one product or service with its variants, options, images and reviews
 */
class Mart extends BaseController
{
    /** listLanguages() */
    protected $helpers = ['list'];

    /** A public_id: CHAR(12), see PublicIdTrait */
    private const PUBLIC_ID_PATTERN = '/^[0-9A-Za-z_-]{1,12}$/';

    /**
     * GET /mart/site?business_slug=&lang=
     * { language, languages: [{code, name}], business: {slug, name, description, country_code, currency_code,
     *   contact: {...}, audience: {...}, rating: {average, count, breakdown}}, theme: {colors: {...}, fonts: {heading,
     *   nav, default: {name, query} or null}, google_fonts_url, logo: {url, shape} or null}, meta: {title, description,
     *   keywords}, hero: {badge, headline, paragraph}, featured: {best_sellers, trending, highest_rated: [card]},
     *   categories: [{public_id, name, artifact_count}], stores: [{public_id, name, type, phone_number, address...}] }
     * A card is as in /mart/artifacts.
     * @return ResponseInterface
     */
    public function site(): ResponseInterface
    {
        return $this->answer('v1/mart/site', function (int $businessId, string $lang): ResponseInterface {
            $data = service('martCache')->remember($businessId, 'site', [$lang], function () use ($businessId, $lang): array {
                $catalog              = model(MartCatalogModel::class);
                [$language, $rows]    = $catalog->languageOf($businessId, $lang);
                $business             = model(BusinessMasterModel::class)->find($businessId);

                return $catalog->site($business, $language, $rows);
            });

            return ApiResponse::success($data);
        });
    }

    /**
     * GET /mart/artifacts?business_slug=&lang=&ids=&category=&type=&sort=&page=&per_page=
     * - ids:      public_ids, comma-separated, at most Config\Mart::$maxPerPage; the cards come in that order, all on
     *             one page, and the other filters are not used
     * - category: public_id of an active category of the business (404 when it is not one)
     * - type:     P (products) or S (services)
     * - sort:     name (the default), price_asc, price_desc, rating, newest
     * - page, per_page: from 1, and up to Config\Mart::$maxPerPage
     * { language, artifacts: [{public_id, type, name, label, category: {public_id, name} or null, price: {active,
     *   compare, varies}, rating: {average, count}, cover_url}], total, page, per_page, pages }
     * price.varies: the active variants have more than one price, so price.active is the lowest ("from").
     * @return ResponseInterface
     */
    public function artifacts(): ResponseInterface
    {
        return $this->answer('v1/mart/artifacts', function (int $businessId, string $lang): ResponseInterface {
            $config = config(MartConfig::class);
            [$query, $errors] = $this->listQuery($config);
            if ($errors !== []) {
                return ApiResponse::error(lang('Mart.invalidQuery'), 422, $errors);
            }

            $data = service('martCache')->remember($businessId, 'artifacts', [$lang, $query], function () use ($businessId, $lang, $query): ?array {
                $catalog       = model(MartCatalogModel::class);
                [$language]    = $catalog->languageOf($businessId, $lang);
                if (isset($query['ids'])) {
                    $cards = $catalog->cardsByPublicIds($businessId, $language, $query['ids']);

                    return ['language' => $language, 'artifacts' => $cards, 'total' => count($cards), 'page' => 1, 'per_page' => count($query['ids']), 'pages' => 1];
                }
                if (isset($query['category'])) {
                    $categoryId = $catalog->categoryIdOf($businessId, $query['category']);
                    if ($categoryId === null) {
                        return null;
                    }
                    $query['category_id'] = $categoryId;
                }

                return ['language' => $language] + $catalog->cards($businessId, $language, $query);
            });

            return $data === null ? ApiResponse::error(lang('Mart.categoryNotFound'), 404) : ApiResponse::success($data);
        });
    }

    /**
     * GET /mart/artifact?business_slug=&lang=&public_id=
     * { language, artifact: {card, with rating.breakdown, description, tax: {calculation, percentage}},
     *   options: [{public_id, name, values: [{public_id, value}]}] (the attributes a variant is chosen by),
     *   variants: [{public_id, sku, price: {active, compare}, attribute_values: [{attribute_public_id,
     *   value_public_id}], weight_grams, duration_minutes, booking_type, capacity, stock}],
     *   images: [{public_id, url, variant_public_id}] (the cover first), reviews: [{public_id, rating, title, message,
     *   reviewer_name, created_at, reply: {message, replied_at} or null}] (the newest approved ones) }
     * Only an active artifact, and only its active variants; 404 otherwise.
     * @return ResponseInterface
     */
    public function artifact(): ResponseInterface
    {
        return $this->answer('v1/mart/artifact', function (int $businessId, string $lang): ResponseInterface {
            $publicId = $this->request->getGet('public_id');
            if (! is_string($publicId) || ! preg_match(self::PUBLIC_ID_PATTERN, $publicId)) {
                return ApiResponse::error(lang('Mart.artifactNotFound'), 404);
            }

            $data = service('martCache')->remember($businessId, 'artifact', [$lang, $publicId], function () use ($businessId, $lang, $publicId): ?array {
                $catalog    = model(MartCatalogModel::class);
                [$language] = $catalog->languageOf($businessId, $lang);
                $artifact   = $catalog->artifact($businessId, $language, $publicId);

                return $artifact === null ? null : ['language' => $language] + $artifact;
            });

            return $data === null ? ApiResponse::error(lang('Mart.artifactNotFound'), 404) : ApiResponse::success($data);
        });
    }

    /**
     * Finds the business and the asked language, then answers with $answer; a failure is logged and answered 500
     * @param string                                     $endpoint for the error log
     * @param callable(int, string): ResponseInterface   $answer   given the business id and the asked lang ('' for none)
     * @return ResponseInterface
     */
    private function answer(string $endpoint, callable $answer): ResponseInterface
    {
        $slug = $this->request->getGet('business_slug');
        if (! is_string($slug) || trim($slug) === '') {
            return ApiResponse::error(lang('Mart.slugRequired'), 400);
        }
        $lang = $this->request->getGet('lang');
        $lang = is_string($lang) && array_key_exists($lang, listLanguages()) ? $lang : '';

        try {
            $businessId = service('martCache')->businessIdOf(trim($slug));
            if ($businessId === null) {
                return ApiResponse::error(lang('Mart.businessNotFound'), 404);
            }

            return $answer($businessId, $lang);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Mart.loadFailed'), 500);
        }
    }

    /**
     * The checked filters of /mart/artifacts, in a fixed shape so equal queries share a cache key
     * @param MartConfig $config
     * @return array{0: array, 1: array<string, string>} [query, errors by parameter]
     */
    private function listQuery(MartConfig $config): array
    {
        $get    = fn (string $name): string => is_string($value = $this->request->getGet($name)) ? trim($value) : '';
        $errors = [];

        $ids = $get('ids');
        if ($ids !== '') {
            $list = array_values(array_unique(array_filter(array_map('trim', explode(',', $ids)), static fn (string $id): bool => $id !== '')));
            if (count($list) > $config->maxPerPage) {
                $errors['ids'] = lang('Mart.tooManyIds', [$config->maxPerPage]);
            } elseif ($list === [] || array_filter($list, static fn (string $id): bool => ! preg_match(self::PUBLIC_ID_PATTERN, $id)) !== []) {
                $errors['ids'] = lang('Mart.invalidIds');
            }

            return [['ids' => $list], $errors];
        }

        $query = ['sort' => $get('sort') === '' ? 'name' : $get('sort')];
        if (! array_key_exists($query['sort'], MartCatalogModel::SORTS)) {
            $errors['sort'] = lang('Mart.invalidSort');
        }
        $type = $get('type');
        if ($type !== '') {
            if (! in_array($type, [ArtifactMasterModel::TYPE_PRODUCT, ArtifactMasterModel::TYPE_SERVICE], true)) {
                $errors['type'] = lang('Mart.invalidType');
            }
            $query['type'] = $type;
        }
        $category = $get('category');
        if ($category !== '') {
            if (! preg_match(self::PUBLIC_ID_PATTERN, $category)) {
                $errors['category'] = lang('Mart.categoryNotFound');
            }
            $query['category'] = $category;
        }
        foreach (['page' => [1, PHP_INT_MAX, 1], 'per_page' => [1, $config->maxPerPage, $config->defaultPerPage]] as $name => [$min, $max, $default]) {
            $value = $get($name);
            if ($value === '') {
                $query[$name] = $default;
            } elseif (ctype_digit($value) && strlen($value) <= 9 && (int) $value >= $min && (int) $value <= $max) {
                $query[$name] = (int) $value;
            } else {
                $errors[$name] = $name === 'page' ? lang('Mart.invalidPage') : lang('Mart.invalidPerPage', [$max]);
            }
        }
        ksort($query);

        return [$query, $errors];
    }
}
