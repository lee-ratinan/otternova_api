<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\ImageProcessor;
use App\Libraries\UploadedImage;
use App\Models\BusinessWebsiteModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\ImageUpload as ImageUploadConfig;
use RuntimeException;
use Throwable;

/**
 * How a business's web pages look (BusinessWebsiteModel): the Client app's, behind the jwt:C filter (clients only),
 * and only for the active owners and managers of the business, which comes in by its business_slug.
 * - colours: the six theme colours, #rrggbb
 * - languages: the languages of listLanguages() the pages are in, the default first, each with its typefaces
 *   (listTypefacesOfLanguage()), meta tags and main-page text
 * - featured: the artifacts of the main page's "best sellers" and "trending now", at most MAX_FEATURED each, active
 *   artifacts of the business only; "highest rated" is by artifact_master.rating_average and needs no picking
 * - the logo: the business-logo used-for of Config\ImageUpload, square or wide by the original's shape
 */
class Websites extends BaseController
{
    use ManagedBusinessTrait;

    /** The Config\ImageUpload::$usedFor key of the logo */
    public const USED_FOR = 'business-logo';

    /** The featured sections as the requests and responses name them => business_website_featured.section */
    public const SECTIONS = [
        'best_sellers' => BusinessWebsiteModel::SECTION_BEST_SELLERS,
        'trending'     => BusinessWebsiteModel::SECTION_TRENDING,
    ];

    /** The longest each optional text of a language may be, as its column allows */
    public const TEXT_LIMITS = [
        'meta_title'       => 70,
        'meta_description' => 160,
        'meta_keywords'    => 255,
        'hero_badge'       => 48,
        'hero_headline'    => 120,
        'hero_paragraph'   => 500,
    ];

    /** The typeface fields of a language */
    public const FONT_FIELDS = ['heading_font', 'nav_font', 'default_font'];

    /** listLanguages(), listTypefacesOfLanguage() */
    protected $helpers = ['list'];

    /**
     * POST /client/website { business_slug }
     * { colors: {background_color, ...}, logo: {url, shape} or null, languages: [{language_code, heading_font, ...}]
     *   (the default first), featured: {best_sellers: [...], trending: [...]} (public_id, artifact_name,
     *   artifact_type, artifact_status), options: {languages: {code: name}, typefaces: {code: {name: Google Fonts
     *   query}}, artifacts: [{public_id, artifact_name, artifact_type}] (those that may be featured), max_featured,
     *   text_limits} }
     * @return ResponseInterface
     */
    public function show(): ResponseInterface
    {
        $endpoint = 'v1/client/website';
        [$business, , $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            return ApiResponse::success($this->settings((int) $business['id']));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Website.loadFailed');
        }
    }

    /**
     * POST /client/website/save { business_slug, colors?, languages?, featured? }
     * Saves the parts sent; a part left out stays as it is.
     * - colors: {background_color, default_color, heading_color, accent_color, surface_color, contrast_color}, each #rrggbb
     * - languages: [{language_code, heading_font, nav_font, default_font, meta_title, meta_description, meta_keywords,
     *   hero_badge, hero_headline, hero_paragraph}, ...], at least one, each language once, the default first;
     *   replaces the languages the pages had. The texts are optional, empty being none.
     * - featured: {best_sellers: [artifact public_id, ...], trending: [...]}, in order, at most MAX_FEATURED each;
     *   a section left out or [] has none
     * @return ResponseInterface The settings, as show() gives them
     */
    public function save(): ResponseInterface
    {
        $endpoint = 'v1/client/website/save';
        [$business, $input, $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        $businessId = (int) $business['id'];
        $errors     = [];
        $colors     = array_key_exists('colors', $input) ? $this->colors($input['colors'], $errors) : null;
        $languages  = array_key_exists('languages', $input) ? $this->languages($input['languages'], $errors) : null;
        try {
            $featured = array_key_exists('featured', $input) ? $this->featured($input['featured'], $businessId, $errors) : null;
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Website.loadFailed');
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Website.invalidFields'), 422, $errors);
        }
        if ($colors === null && $languages === null && $featured === null) {
            return ApiResponse::error(lang('Website.nothingToSave'), 422);
        }
        try {
            model(BusinessWebsiteModel::class)->saveSettings($businessId, $colors, $languages, $featured, $this->actorId(), $input['business_slug']);

            return ApiResponse::success($this->settings($businessId), 200, lang('Website.saved'));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Website.saveFailed');
        }
    }

    /**
     * POST /client/website/logo-upload, multipart/form-data { business_slug, image }
     * Makes the image the business's logo: square (1000x1000) when its width is under Config\ImageUpload::$logoWideRatio
     * times its height, else wide (2000x1000); fitted in whole, the rest transparent. Replaces the logo it had.
     * @return ResponseInterface { url, shape }
     */
    public function logoUpload(): ResponseInterface
    {
        $endpoint = 'v1/client/website/logo-upload';
        [$business, $input, $error] = $this->managed($endpoint, (array) $this->request->getPost());
        if ($error !== null) {
            return $error;
        }
        $file    = $this->request->getFile('image');
        $refusal = UploadedImage::refusal($file);
        if ($refusal !== null) {
            return $refusal;
        }

        $config    = config(ImageUploadConfig::class);
        $processor = service('imageProcessor');
        $folder    = UploadedImage::folderOf(self::USED_FOR);
        $name      = 'logo-' . bin2hex(random_bytes(8)); // a new name each time, so no browser keeps showing the old one
        $temp      = $folder . '.' . $name . '.webp';
        try {
            [$width, $height] = $processor->uprightSize($file->getTempName());
            $shape            = $width < $config->logoWideRatio * $height ? 'S' : 'W';
            $size             = $config->logoShapes[$shape];
            if (! is_dir($folder) && ! @mkdir($folder, 0755, true) && ! is_dir($folder)) {
                throw new RuntimeException('Cannot create ' . $folder);
            }
            $processor->fit($file->getTempName(), $temp, $size['width'], $size['height'], $config->logoMinSide, $config->quality);
        } catch (RuntimeException $e) {
            @unlink($temp);

            return match ($e->getMessage()) {
                ImageProcessor::ERROR_TOO_SMALL  => ApiResponse::error(lang('Website.logoTooSmall', [$config->logoMinSide]), 422),
                ImageProcessor::ERROR_UNREADABLE => ApiResponse::error(lang('ImageUpload.unreadable'), 422),
                default                          => $this->failed($endpoint, $e, 'Website.logoFailed'),
            };
        }

        $url    = base_url('image/' . self::USED_FOR . '/' . $name . '.webp');
        $path   = $folder . $name . '.webp';
        $placed = false;
        try {
            $oldUrl = model(BusinessWebsiteModel::class)->setLogo((int) $business['id'], $url, $shape, $this->actorId(), $input['business_slug'],
                static function () use ($temp, $path, &$placed): void {
                    if (! @rename($temp, $path)) {
                        throw new RuntimeException('Cannot move the logo to ' . $path);
                    }
                    $placed = true;
                });
        } catch (Throwable $e) {
            @unlink($temp);
            if ($placed) {
                @unlink($path);
            }

            return $this->failed($endpoint, $e, 'Website.logoFailed');
        }
        $oldPath = $this->fileOf($oldUrl);
        if ($oldPath !== null && $oldPath !== $path) {
            @unlink($oldPath);
        }

        return ApiResponse::success(['url' => $url, 'shape' => $shape], 200, lang('Website.logoUploaded'));
    }

    /**
     * POST /client/website/logo-delete { business_slug }
     * Takes the logo off and deletes its file; 404 when there is none
     * @return ResponseInterface
     */
    public function logoDelete(): ResponseInterface
    {
        $endpoint = 'v1/client/website/logo-delete';
        [$business, $input, $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            $deleted = model(BusinessWebsiteModel::class)->removeLogo((int) $business['id'], $this->actorId(), $input['business_slug'], function (?string $url): void {
                $path = $this->fileOf($url);
                if ($path !== null && is_file($path) && ! @unlink($path)) {
                    throw new RuntimeException('Cannot delete ' . $path);
                }
            });
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Website.logoFailed');
        }
        if (! $deleted) {
            return ApiResponse::error(lang('Website.noLogo'), 404);
        }

        return ApiResponse::success(['logo' => null], 200, lang('Website.logoDeleted'));
    }

    /**
     * The business's settings and the choices for them, as show() describes
     * @param int $businessId
     * @return array
     */
    private function settings(int $businessId): array
    {
        $websites = model(BusinessWebsiteModel::class);
        $website  = $websites->websiteOf($businessId);
        $featured = $websites->featuredOf($businessId);
        $codes    = array_keys(listLanguages());

        return [
            'colors'    => array_intersect_key($website, BusinessWebsiteModel::DEFAULT_COLORS),
            'logo'      => $website['logo_path'] === null ? null : ['url' => $website['logo_path'], 'shape' => $website['logo_shape']],
            'languages' => $websites->languagesOf($businessId),
            'featured'  => array_map(static fn (string $section): array => $featured[$section], self::SECTIONS),
            'options'   => [
                'languages'    => listLanguages(),
                'typefaces'    => array_combine($codes, array_map('listTypefacesOfLanguage', $codes)),
                'artifacts'    => array_map(static fn (array $a): array => array_diff_key($a, ['id' => true]), $websites->featurableOf($businessId)),
                'max_featured' => BusinessWebsiteModel::MAX_FEATURED,
                'text_limits'  => self::TEXT_LIMITS,
            ],
        ];
    }

    /**
     * @param mixed $value  From the request
     * @param array $errors Added to
     * @return array|null The colours, lowercased; null when refused
     */
    private function colors(mixed $value, array &$errors): ?array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors['colors'] = lang('Website.invalidColors');

            return null;
        }
        $colors = [];
        foreach (array_keys(BusinessWebsiteModel::DEFAULT_COLORS) as $field) {
            $color = $value[$field] ?? null;
            if (! is_string($color) || preg_match('/^#[0-9a-f]{6}$/i', trim($color)) !== 1) {
                $errors['colors.' . $field] = lang('Website.invalidColor');
                continue;
            }
            $colors[$field] = strtolower(trim($color));
        }

        return count($colors) === count(BusinessWebsiteModel::DEFAULT_COLORS) ? $colors : null;
    }

    /**
     * @param mixed $value  From the request
     * @param array $errors Added to
     * @return list<array>|null The languages, texts trimmed and empty ones null; null when refused
     */
    private function languages(mixed $value, array &$errors): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $errors['languages'] = lang('Website.invalidLanguages');

            return null;
        }
        if ($value === []) {
            $errors['languages'] = lang('Website.languageRequired');

            return null;
        }
        $known     = listLanguages();
        $languages = [];
        $seen      = [];
        $count     = count($errors);
        foreach ($value as $i => $item) {
            $item = is_array($item) ? $item : [];
            $key  = 'languages.' . $i . '.';
            $code = is_string($item['language_code'] ?? null) ? trim($item['language_code']) : '';
            if (! isset($known[$code])) {
                $errors[$key . 'language_code'] = lang('Website.invalidLanguage');
                continue;
            }
            if (isset($seen[$code])) {
                $errors[$key . 'language_code'] = lang('Website.duplicateLanguage', [$known[$code]]);
                continue;
            }
            $seen[$code] = true;
            $language    = ['language_code' => $code];
            $typefaces   = listTypefacesOfLanguage($code);
            foreach (self::FONT_FIELDS as $field) {
                $font = is_string($item[$field] ?? null) ? trim($item[$field]) : '';
                if (! isset($typefaces[$font])) {
                    $errors[$key . $field] = lang('Website.invalidFont');
                }
                $language[$field] = $font;
            }
            foreach (self::TEXT_LIMITS as $field => $limit) {
                $text = $item[$field] ?? null;
                if ($text !== null && ! is_string($text)) {
                    $errors[$key . $field] = lang('Website.invalidText');
                    continue;
                }
                $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
                if (mb_strlen($text) > $limit) {
                    $errors[$key . $field] = lang('Website.textTooLong', [$limit]);
                }
                $language[$field] = $text === '' ? null : $text;
            }
            $languages[] = $language;
        }

        return count($errors) === $count ? $languages : null;
    }

    /**
     * @param mixed $value      From the request
     * @param int   $businessId
     * @param array $errors     Added to
     * @return array<string, list<int>>|null artifact_master.id by section; null when refused
     */
    private function featured(mixed $value, int $businessId, array &$errors): ?array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors['featured'] = lang('Website.invalidFeatured');

            return null;
        }
        $ids      = array_column(model(BusinessWebsiteModel::class)->featurableOf($businessId), 'id', 'public_id');
        $featured = [];
        $count    = count($errors);
        foreach (self::SECTIONS as $name => $section) {
            $list = $value[$name] ?? [];
            if (! is_array($list) || ! array_is_list($list) || array_filter($list, 'is_string') !== $list) {
                $errors['featured.' . $name] = lang('Website.invalidFeatured');
                continue;
            }
            if (count($list) > BusinessWebsiteModel::MAX_FEATURED) {
                $errors['featured.' . $name] = lang('Website.tooManyFeatured', [BusinessWebsiteModel::MAX_FEATURED]);
                continue;
            }
            if (count(array_unique($list)) !== count($list)) {
                $errors['featured.' . $name] = lang('Website.duplicateFeatured');
                continue;
            }
            $featured[$section] = [];
            foreach ($list as $publicId) {
                if (! isset($ids[$publicId])) {
                    // not the business's, or not active: either way not one to feature
                    $errors['featured.' . $name] = lang('Website.featuredNotFound');
                    continue 2;
                }
                $featured[$section][] = $ids[$publicId];
            }
        }

        return count($errors) === $count ? $featured : null;
    }

    /**
     * The business of the request's business_slug, when the signed-in client is one of its active owners or managers
     * @param string     $endpoint
     * @param array|null $input The request's fields; the JSON body when null
     * @return array{0: array|null, 1: array, 2: ResponseInterface|null} [business, input (business_slug trimmed), null] or [null, [], error]
     */
    private function managed(string $endpoint, ?array $input = null): array
    {
        $input ??= $this->jsonInput();
        if ($input === null) {
            return [null, [], ApiResponse::error(lang('Website.invalidRequest'), 422)];
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Website');
        if ($error !== null) {
            return [null, [], $error];
        }
        $input['business_slug'] = trim($input['business_slug']);

        return [$business, $input, null];
    }

    /**
     * The file of a logo URL, or null for one that is not a business-logo
     * @param string|null $url
     * @return string|null
     */
    private function fileOf(?string $url): ?string
    {
        $prefix = base_url('image/' . self::USED_FOR . '/');
        if ($url === null || ! str_starts_with($url, $prefix) || ! str_ends_with($url, '.webp')) {
            return null;
        }
        $name = substr($url, strlen($prefix), -strlen('.webp'));

        return preg_match(Images::NAME_PATTERN, $name) ? UploadedImage::folderOf(self::USED_FOR) . $name . '.webp' : null;
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
