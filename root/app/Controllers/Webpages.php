<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\UploadedImage;
use App\Libraries\WebpagePreview;
use App\Models\BusinessWebsiteModel;
use App\Models\SubscriptionMasterModel;
use App\Models\WebpageImageModel;
use App\Models\WebpageMasterModel;
use App\Models\WebpageSectionModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\PlanLimits;
use Config\WebpageDesigns;
use RuntimeException;
use Throwable;

/**
 * The pages a business designs itself (WebpageMasterModel, WebpageSectionModel, WebpageImageModel): the Client app's,
 * behind the jwt:C filter (clients only), and only for the active owners and managers of the business, which comes
 * in by its business_slug in every request.
 * - A business has as many pages as its plan's PlanLimits::WEBPAGES, the home page included; the first one made is
 *   always the home page, which has no name of its own (Webpage.homeName) and is never deleted. The others are named
 *   per website language, uniquely within the business, and get a slug from the name in the default language.
 * - A page needs the business's website (Websites::save) with at least one language first: its colours, typefaces
 *   and languages are the website's.
 * - A page is a draft (D) until published (P); a draft is never shown on the storefront, except through a preview
 *   link (preview()). Only the business's oldest PlanLimits::WEBPAGES pages may be published: after a move to a
 *   smaller plan the newer ones are unpublished (SubscriptionBilling) and cannot be published again; each page says
 *   whether it may be (publishable).
 * - Its sections are designs of Config\WebpageDesigns, a hero first; their texts are per language and slot, their
 *   images per slot, cropped to the slot's size, at most PlanLimits::IMAGE_PER_WEBPAGE per page.
 * The image files are the webpage-image used-for of Config\ImageUpload, served by Images::show, named after the
 * image's public_id.
 * A page is sent as { public_id, page_slug, path, page_status, is_home, publishable, sort_order, names: {language_code:
 * {page_name, meta_title, meta_description}}, image_count, sections: [{public_id, section_type, design_code,
 * sort_order, texts: {language_code: {slot_code: text}}, images: [{public_id, slot_code, url, sort_order}]}] };
 * path is where the storefront shows it under the business's own: '' for the home page, else pages/{page_slug}.
 */
class Webpages extends BaseController
{
    use ManagedBusinessTrait;

    /** The Config\ImageUpload::$usedFor key of the images */
    public const USED_FOR = 'webpage-image';

    /** The longest each text of a page's name may be, as its column allows */
    public const NAME_LIMITS = [
        'page_name'        => 64,
        'meta_title'       => 70,
        'meta_description' => 160,
    ];

    /** A page_slug the client sets: lowercase letters and digits, single hyphens between them */
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** listLanguages() */
    protected $helpers = ['list'];

    /**
     * POST /client/webpages { business_slug }
     * { pages: [page without sections], options: {plan, max_pages, max_images_per_page, languages: [language_code]
     *   (the website's, the default first), home_names: {language_code: name}, designs: {design_code: {section_type,
     *   texts, images, fits_plan, max_images}}, name_limits, website_ready} }
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/webpages';
        [$business, , $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            $businessId = (int) $business['id'];
            $context    = $this->context($businessId);
            // a plan's caps may have changed without a subscription change (Config\PlanLimits); keep to them
            model(WebpageMasterModel::class)->enforceCap($businessId, $context['max_pages'], $this->actorId(), true);
            $allowed    = model(WebpageMasterModel::class)->allowedIds($businessId, $context['max_pages']);
            $pages      = model(WebpageMasterModel::class)->pagesOf($businessId);
            $names      = model(WebpageMasterModel::class)->namesOf(array_map('intval', array_column($pages, 'id')));
            $designs    = config(WebpageDesigns::class);
            $options    = [
                'plan'                => $context['plan'],
                'max_pages'           => $context['max_pages'],
                'max_images_per_page' => $context['max_images'],
                'languages'           => $context['languages'],
                'home_names'          => $this->homeNames($context['languages']),
                'designs'             => array_map(static fn (array $design): array => $design + [
                    'fits_plan'  => $designs->fitsPlan($design, $context['max_images']),
                    'max_images' => $designs->maxImagesOf($design, $context['max_images']),
                ], $designs->designs),
                'name_limits'   => self::NAME_LIMITS,
                'website_ready' => $context['languages'] !== [],
            ];

            return ApiResponse::success([
                'pages'   => array_map(fn (array $page): array => $this->pageOut($page, $names[(int) $page['id']] ?? [], null, $allowed), $pages),
                'options' => $options,
            ]);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.loadFailed');
        }
    }

    /**
     * POST /client/webpage { business_slug, page_public_id }
     * @return ResponseInterface The page, with its sections
     */
    public function show(): ResponseInterface
    {
        $endpoint = 'v1/client/webpage';
        [$business, $input, $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        $page = $this->page((int) $business['id'], $input['page_public_id'] ?? null, $endpoint);
        if ($page instanceof ResponseInterface) {
            return $page;
        }
        try {
            return ApiResponse::success($this->pageDetail($page));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.loadFailed');
        }
    }

    /**
     * POST /client/webpage/create { business_slug, names?, page_slug? }
     * Makes a draft page at the end of the menu. The business's first page is always its home page, which takes
     * neither names nor page_slug; after it, names is [{language_code, page_name, meta_title?, meta_description?}],
     * the default language's needed, each language of the website at most once, and page_slug optional (made from
     * the name in the default language when left out). Refused with 409 once the business has as many pages as its
     * plan allows, or before its website has a language.
     * @return ResponseInterface The page, with its sections
     */
    public function create(): ResponseInterface
    {
        $endpoint = 'v1/client/webpage/create';
        [$business, $input, $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        $businessId = (int) $business['id'];
        $pages      = model(WebpageMasterModel::class);
        try {
            $context = $this->context($businessId);
            $home    = $pages->homeOf($businessId);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.loadFailed');
        }
        if ($context['languages'] === []) {
            return ApiResponse::error(lang('Webpage.websiteRequired'), 409);
        }

        $names     = [];
        $slug      = WebpageMasterModel::HOME_SLUG;
        $exactSlug = true;
        if ($home !== null) {
            $errors = [];
            $names  = $this->names($input['names'] ?? null, $context['languages'], $errors);
            if (array_key_exists('page_slug', $input) && $input['page_slug'] !== null && $input['page_slug'] !== '') {
                $slug = $this->slug($input['page_slug'], $errors);
            } else {
                $slug      = $names === null ? '' : $this->slugOf($names[$context['languages'][0]]['page_name']);
                $exactSlug = false;
            }
            try {
                $this->checkTaken($businessId, $names, $exactSlug ? $slug : null, null, $errors);
            } catch (Throwable $e) {
                return $this->failed($endpoint, $e, 'Webpage.loadFailed');
            }
            if ($errors !== []) {
                return ApiResponse::error(lang('Webpage.invalidFields'), 422, $errors);
            }
        }

        try {
            $page = $pages->createPage($businessId, $slug, $exactSlug, $names, $context['max_pages'], $this->actorId(), $input['business_slug']);
            if ($page === null) {
                return ApiResponse::error(lang('Webpage.limitReached', [$context['max_pages'], ucfirst($context['plan'])]), 409);
            }

            return ApiResponse::success($this->pageDetail($page), 200, lang('Webpage.created'));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.saveFailed');
        }
    }

    /**
     * POST /client/webpage/edit { business_slug, page_public_id, names?, page_slug?, page_status? }
     * Saves the parts sent; a part left out stays as it is. names (as in create) replaces the names the page had;
     * page_slug moves the page to another address, after which links to the old one no longer work. The home page
     * has neither: only its page_status changes. page_status: D draft, P published; publishing a page beyond the
     * business's oldest PlanLimits::WEBPAGES is refused with 409.
     * @return ResponseInterface The page, with its sections
     */
    public function edit(): ResponseInterface
    {
        $endpoint = 'v1/client/webpage/edit';
        [$business, $input, $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        $businessId = (int) $business['id'];
        $page       = $this->page($businessId, $input['page_public_id'] ?? null, $endpoint);
        if ($page instanceof ResponseInterface) {
            return $page;
        }
        try {
            $context = $this->context($businessId);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.loadFailed');
        }

        $isHome = $page['page_slug'] === WebpageMasterModel::HOME_SLUG;
        $errors = [];
        $names  = null;
        $slug   = null;
        $status = null;
        if (array_key_exists('names', $input)) {
            if ($isHome) {
                $errors['names'] = lang('Webpage.homeFixed');
            } else {
                $names = $this->names($input['names'], $context['languages'], $errors);
            }
        }
        if (array_key_exists('page_slug', $input)) {
            if ($isHome) {
                $errors['page_slug'] = lang('Webpage.homeFixed');
            } else {
                $slug = $this->slug($input['page_slug'], $errors);
            }
        }
        if (array_key_exists('page_status', $input)) {
            if (! in_array($input['page_status'], WebpageMasterModel::STATUSES, true)) {
                $errors['page_status'] = lang('Webpage.invalidStatus');
            } else {
                $status = $input['page_status'];
                try {
                    $publishable = in_array((int) $page['id'], model(WebpageMasterModel::class)->allowedIds($businessId, $context['max_pages']), true);
                } catch (Throwable $e) {
                    return $this->failed($endpoint, $e, 'Webpage.loadFailed');
                }
                if ($status === WebpageMasterModel::STATUS_PUBLISHED && $page['page_status'] !== WebpageMasterModel::STATUS_PUBLISHED && ! $publishable) {
                    return ApiResponse::error(lang('Webpage.publishBeyondPlan', [$context['max_pages'], ucfirst($context['plan'])]), 409);
                }
            }
        }
        try {
            $this->checkTaken($businessId, $names, $slug, (int) $page['id'], $errors);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.loadFailed');
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Webpage.invalidFields'), 422, $errors);
        }
        if ($names === null && $slug === null && $status === null) {
            return ApiResponse::error(lang('Webpage.nothingToSave'), 422);
        }

        try {
            $page = model(WebpageMasterModel::class)->editPage($page, $names, $slug, $status, $this->actorId(), $input['business_slug']);

            return ApiResponse::success($this->pageDetail($page), 200, lang('Webpage.saved'));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.saveFailed');
        }
    }

    /**
     * POST /client/webpage/delete { business_slug, page_public_id }
     * Deletes the page with its sections, texts and images (and the images' files); never the home page
     * @return ResponseInterface
     */
    public function delete(): ResponseInterface
    {
        $endpoint = 'v1/client/webpage/delete';
        [$business, $input, $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        $page = $this->page((int) $business['id'], $input['page_public_id'] ?? null, $endpoint);
        if ($page instanceof ResponseInterface) {
            return $page;
        }
        if ($page['page_slug'] === WebpageMasterModel::HOME_SLUG) {
            return ApiResponse::error(lang('Webpage.cannotDeleteHome'), 409);
        }
        try {
            $images = model(WebpageMasterModel::class)->deletePage($page, $this->actorId(), $input['business_slug']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.deleteFailed');
        }
        $this->removeFiles($images);

        return ApiResponse::success(['public_id' => $page['public_id']], 200, lang('Webpage.deleted'));
    }

    /**
     * POST /client/webpage/preview { business_slug, page_public_id }
     * A link's token that shows the page on the storefront as it is saved, a draft too, for WebpagePreview::TTL
     * seconds: {LINK_MART}{business_slug}/{path}?preview={token}
     * @return ResponseInterface { token, path, expires_at (Unix time) }
     */
    public function preview(): ResponseInterface
    {
        $endpoint = 'v1/client/webpage/preview';
        [$business, $input, $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        $page = $this->page((int) $business['id'], $input['page_public_id'] ?? null, $endpoint);
        if ($page instanceof ResponseInterface) {
            return $page;
        }
        try {
            $token = WebpagePreview::issue((int) $business['id'], $page['public_id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.loadFailed');
        }

        return ApiResponse::success($token + ['path' => $page['page_slug'] === WebpageMasterModel::HOME_SLUG ? '' : 'pages/' . $page['page_slug']]);
    }

    /**
     * POST /client/webpage/reorder { business_slug, order: [page public_id, ...] }
     * Puts the pages in this menu order; "order" lists every page once, the home page first.
     * @return ResponseInterface The pages, as list() gives them
     */
    public function reorder(): ResponseInterface
    {
        $endpoint = 'v1/client/webpage/reorder';
        [$business, $input, $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        $order = $input['order'] ?? null;
        if (! is_array($order) || ! array_is_list($order) || array_filter($order, 'is_string') !== $order) {
            return ApiResponse::error(lang('Webpage.invalidFields'), 422, ['order' => lang('Webpage.invalidOrder')]);
        }
        $businessId = (int) $business['id'];
        $model      = model(WebpageMasterModel::class);
        try {
            if (! $model->reorder($businessId, $order, $this->actorId())) {
                return ApiResponse::error(lang('Webpage.invalidFields'), 422, ['order' => lang('Webpage.invalidOrder')]);
            }
            $pages   = $model->pagesOf($businessId);
            $names   = $model->namesOf(array_map('intval', array_column($pages, 'id')));
            $allowed = $model->allowedIds($businessId, $this->context($businessId)['max_pages']);

            return ApiResponse::success([
                'pages' => array_map(fn (array $page): array => $this->pageOut($page, $names[(int) $page['id']] ?? [], null, $allowed), $pages),
            ], 200, lang('Webpage.reordered'));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.saveFailed');
        }
    }

    /**
     * POST /client/webpage/section/save { business_slug, page_public_id, section_public_id?, design_code, texts }
     * Without section_public_id, adds a section of the design to the page (a hero first, and only one); with it,
     * changes that section to the design, which must be of the same section_type. texts is {language_code:
     * {slot_code: text}}, languages of the website and slots of the design only, each at most its max; a required
     * slot needs a text in the default language. It replaces the texts the section had. A new design keeps the
     * images that fit it (Config\WebpageDesigns) and deletes the others. The design must fit the plan: its slots'
     * min within PlanLimits::IMAGE_PER_WEBPAGE.
     * @return ResponseInterface The page, with its sections
     */
    public function saveSection(): ResponseInterface
    {
        $endpoint = 'v1/client/webpage/section/save';
        [$business, $input, $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        $businessId = (int) $business['id'];
        $page       = $this->page($businessId, $input['page_public_id'] ?? null, $endpoint);
        if ($page instanceof ResponseInterface) {
            return $page;
        }
        $sections = model(WebpageSectionModel::class);
        $section  = null;
        try {
            $context = $this->context($businessId);
            if (($input['section_public_id'] ?? null) !== null) {
                $section = $sections->sectionOf((int) $page['id'], $input['section_public_id']);
                if ($section === null) {
                    return ApiResponse::error(lang('Webpage.sectionNotFound'), 404);
                }
            }
            $existing = $sections->sectionsOf((int) $page['id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.loadFailed');
        }

        $designs = config(WebpageDesigns::class);
        $code    = $input['design_code'] ?? null;
        $design  = $designs->design($code);
        $errors  = [];
        if ($design === null) {
            $errors['design_code'] = lang('Webpage.designNotFound');
        } elseif (! $designs->fitsPlan($design, $context['max_images'])) {
            $errors['design_code'] = lang('Webpage.designNotOnPlan', [ucfirst($context['plan'])]);
        } elseif ($section !== null && $design['section_type'] !== $section['section_type']) {
            $errors['design_code'] = lang('Webpage.designOtherType');
        } elseif ($section === null && in_array($design['section_type'], WebpageDesigns::ONCE_PER_PAGE, true)
            && in_array($design['section_type'], array_column($existing, 'section_type'), true)) {
            return ApiResponse::error(lang('Webpage.sectionTaken'), 409);
        }
        $texts = $design === null ? null : $this->texts($input['texts'] ?? null, $design, $context['languages'], $errors);
        if ($errors !== []) {
            return ApiResponse::error(lang('Webpage.invalidFields'), 422, $errors);
        }

        try {
            if ($section === null) {
                $sections->addSection((int) $page['id'], $code, $texts, $this->actorId());
                $dropped = [];
            } else {
                $dropped = $sections->editSection($section, $code, $texts, $context['max_images'], $this->actorId());
            }
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.saveFailed');
        }
        $this->removeFiles($dropped);

        try {
            return ApiResponse::success($this->pageDetail($page), 200, lang('Webpage.sectionSaved'));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.loadFailed');
        }
    }

    /**
     * POST /client/webpage/section/delete { business_slug, page_public_id, section_public_id }
     * Deletes the section with its texts and images (and the images' files)
     * @return ResponseInterface The page, with its sections
     */
    public function deleteSection(): ResponseInterface
    {
        $endpoint = 'v1/client/webpage/section/delete';
        [$business, $input, $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        $page = $this->page((int) $business['id'], $input['page_public_id'] ?? null, $endpoint);
        if ($page instanceof ResponseInterface) {
            return $page;
        }
        $sections = model(WebpageSectionModel::class);
        try {
            $section = $sections->sectionOf((int) $page['id'], $input['section_public_id'] ?? null);
            if ($section === null) {
                return ApiResponse::error(lang('Webpage.sectionNotFound'), 404);
            }
            $images = $sections->deleteSection($section, $this->actorId());
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.deleteFailed');
        }
        $this->removeFiles($images);

        try {
            return ApiResponse::success($this->pageDetail($page), 200, lang('Webpage.sectionDeleted'));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.loadFailed');
        }
    }

    /**
     * POST /client/webpage/image-upload, multipart/form-data { business_slug, page_public_id, section_public_id,
     * slot_code, image }
     * Adds the image after the slot's others, cropped to the slot's size; a smaller original is refused. Refused with
     * 409 once the page has as many images as the plan allows (PlanLimits::IMAGE_PER_WEBPAGE), or the slot its max.
     * @return ResponseInterface The image { public_id, slot_code, url, sort_order }
     */
    public function uploadImage(): ResponseInterface
    {
        $endpoint = 'v1/client/webpage/image-upload';
        [$business, $input, $error] = $this->managed($endpoint, (array) $this->request->getPost());
        if ($error !== null) {
            return $error;
        }
        $businessId = (int) $business['id'];
        $page       = $this->page($businessId, $input['page_public_id'] ?? null, $endpoint);
        if ($page instanceof ResponseInterface) {
            return $page;
        }
        try {
            $context = $this->context($businessId);
            $section = model(WebpageSectionModel::class)->sectionOf((int) $page['id'], $input['section_public_id'] ?? null);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.loadFailed');
        }
        if ($section === null) {
            return ApiResponse::error(lang('Webpage.sectionNotFound'), 404);
        }
        $design   = config(WebpageDesigns::class)->design($section['design_code']);
        $slotCode = $input['slot_code'] ?? null;
        $slot     = $design !== null && is_string($slotCode) ? ($design['images'][$slotCode] ?? null) : null;
        if ($slot === null) {
            return ApiResponse::error(lang('Webpage.invalidFields'), 422, ['slot_code' => lang('Webpage.slotNotFound')]);
        }

        $file    = $this->request->getFile('image');
        $refusal = UploadedImage::refusal($file);
        if ($refusal !== null) {
            return $refusal;
        }
        $rule   = ['width' => $slot['width'], 'height' => $slot['height']];
        $folder = UploadedImage::folderOf(self::USED_FOR);
        try {
            $temp = UploadedImage::convertToTemp($file, $rule, $folder, 'webpage-' . $page['public_id']);
        } catch (RuntimeException $e) {
            return UploadedImage::conversionRefusal($e, $rule) ?? $this->failed($endpoint, $e, 'Webpage.saveFailed');
        }

        $placed = null;
        try {
            $row = model(WebpageImageModel::class)->addImage(
                $section,
                $slotCode,
                $context['max_images'],
                $slot['max'],
                $this->actorId(),
                static fn (string $publicId): string => base_url('image/' . self::USED_FOR . '/' . $publicId . '.webp'),
                static function (string $publicId) use ($temp, $folder, &$placed): void {
                    $path = $folder . $publicId . '.webp';
                    if (! @rename($temp, $path)) {
                        throw new RuntimeException('Cannot move the image to ' . $path);
                    }
                    $placed = $path;
                },
            );
        } catch (Throwable $e) {
            // the rows are rolled back; so is the file, when it got as far as its place
            @unlink($temp);
            if ($placed !== null) {
                @unlink($placed);
            }

            return $this->failed($endpoint, $e, 'Webpage.saveFailed');
        }
        if (is_string($row)) {
            @unlink($temp);

            return $row === WebpageImageModel::FULL_PAGE
                ? ApiResponse::error(lang('Webpage.imageLimitReached', [$context['max_images'], ucfirst($context['plan'])]), 409)
                : ApiResponse::error(lang('Webpage.slotFull', [$slot['max']]), 409);
        }

        return ApiResponse::success([
            'public_id'  => $row['public_id'],
            'slot_code'  => $row['slot_code'],
            'url'        => $row['image_path'],
            'sort_order' => $row['sort_order'],
        ], 200, lang('Webpage.uploaded'));
    }

    /**
     * POST /client/webpage/image-delete { business_slug, public_id }
     * Deletes the image's row and its file together; a file that will not go keeps the row.
     * @return ResponseInterface
     */
    public function deleteImage(): ResponseInterface
    {
        $endpoint = 'v1/client/webpage/image-delete';
        [$business, $input, $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        $images = model(WebpageImageModel::class);
        try {
            $pageIds = array_map('intval', array_column(model(WebpageMasterModel::class)->pagesOf((int) $business['id']), 'id'));
            $image   = $images->imageOf($pageIds, $input['public_id'] ?? null);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.loadFailed');
        }
        if ($image === null) {
            return ApiResponse::error(lang('Webpage.imageNotFound'), 404);
        }
        $path = UploadedImage::folderOf(self::USED_FOR) . $image['public_id'] . '.webp';
        try {
            $images->deleteImage($image, $this->actorId(), static function () use ($path): void {
                if (is_file($path) && ! @unlink($path)) {
                    throw new RuntimeException('Cannot delete ' . $path);
                }
            });
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.deleteFailed');
        }

        return ApiResponse::success(['public_id' => $image['public_id']], 200, lang('Webpage.imageDeleted'));
    }

    /**
     * What the business's pages depend on: its plan's caps and its website's languages
     * @param int $businessId
     * @return array{plan: string, max_pages: int, max_images: int, languages: list<string>}
     */
    private function context(int $businessId): array
    {
        $plan   = model(SubscriptionMasterModel::class)->planOfBusiness($businessId)['plan_code'];
        $limits = config(PlanLimits::class)->forPlan($plan);

        return [
            'plan'       => $plan,
            'max_pages'  => (int) $limits[PlanLimits::WEBPAGES],
            'max_images' => (int) $limits[PlanLimits::IMAGE_PER_WEBPAGE],
            'languages'  => array_column(model(BusinessWebsiteModel::class)->languagesOf($businessId), 'language_code'),
        ];
    }

    /**
     * The page of the request's page_public_id, when it is one of the business's
     * @param int    $businessId
     * @param mixed  $publicId
     * @param string $endpoint
     * @return array|ResponseInterface The stored row, or the refusal
     */
    private function page(int $businessId, mixed $publicId, string $endpoint): array|ResponseInterface
    {
        if (! is_string($publicId) || $publicId === '') {
            return ApiResponse::error(lang('Webpage.pageIdRequired'), 400);
        }
        try {
            $page = model(WebpageMasterModel::class)->pageOf($businessId, $publicId);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Webpage.loadFailed');
        }

        return $page ?? ApiResponse::error(lang('Webpage.pageNotFound'), 404);
    }

    /**
     * A page as the API sends it
     * @param array      $page     The stored row (image_count when pagesOf() gave it)
     * @param array      $names    Its names, by language
     * @param array|null $sections From WebpageSectionModel::sectionsOf(); null leaves them out
     * @param list<int>  $allowed  WebpageMasterModel::allowedIds(): the pages that may be published
     * @return array
     */
    private function pageOut(array $page, array $names, ?array $sections, array $allowed): array
    {
        $isHome = $page['page_slug'] === WebpageMasterModel::HOME_SLUG;
        $out    = [
            'public_id'   => $page['public_id'],
            'page_slug'   => $page['page_slug'],
            'path'        => $isHome ? '' : 'pages/' . $page['page_slug'],
            'page_status' => $page['page_status'],
            'is_home'     => $isHome,
            'publishable' => in_array((int) $page['id'], $allowed, true),
            'sort_order'  => (int) $page['sort_order'],
            'names'       => $names,
        ];
        if (array_key_exists('image_count', $page)) {
            $out['image_count'] = (int) $page['image_count'];
        }
        if ($sections !== null) {
            $out['image_count'] = array_sum(array_map(static fn (array $section): int => count($section['images']), $sections));
            $out['sections']    = $sections;
        }

        return $out;
    }

    /**
     * A page with its names and sections, as stored now
     * @param array $page The stored row
     * @return array
     */
    private function pageDetail(array $page): array
    {
        $id      = (int) $page['id'];
        $pages   = model(WebpageMasterModel::class);
        $allowed = $pages->allowedIds((int) $page['business_id'], $this->context((int) $page['business_id'])['max_pages']);

        return $this->pageOut($page, $pages->namesOf([$id])[$id] ?? [], model(WebpageSectionModel::class)->sectionsOf($id), $allowed);
    }

    /**
     * The home page's name in each language
     * @param list<string> $languages
     * @return array<string, string>
     */
    private function homeNames(array $languages): array
    {
        $names = [];
        foreach ($languages as $code) {
            $names[$code] = $this->homeNameIn($code);
        }

        return $names;
    }

    /**
     * The home page's name in a language: the language file's, else the default locale's
     * @param string $code
     * @return string
     */
    private function homeNameIn(string $code): string
    {
        $name = lang('Webpage.homeName', [], $code);

        return $name === 'Webpage.homeName' ? lang('Webpage.homeName') : $name;
    }

    /**
     * @param mixed        $value     From the request
     * @param list<string> $languages The website's, the default first
     * @param array        $errors    Added to
     * @return array<string, array>|null language_code => NAME_FIELDS, texts trimmed and empty metas null; null when refused
     */
    private function names(mixed $value, array $languages, array &$errors): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            $errors['names'] = lang('Webpage.invalidNames');

            return null;
        }
        $known = listLanguages();
        $names = [];
        $count = count($errors);
        foreach ($value as $i => $item) {
            $item = is_array($item) ? $item : [];
            $key  = 'names.' . $i . '.';
            $code = is_string($item['language_code'] ?? null) ? trim($item['language_code']) : '';
            if (! in_array($code, $languages, true)) {
                $errors[$key . 'language_code'] = lang('Webpage.invalidLanguage');
                continue;
            }
            if (isset($names[$code])) {
                $errors[$key . 'language_code'] = lang('Webpage.duplicateLanguage', [$known[$code] ?? $code]);
                continue;
            }
            $name = [];
            foreach (self::NAME_LIMITS as $field => $limit) {
                $text = $item[$field] ?? null;
                if ($text !== null && ! is_string($text)) {
                    $errors[$key . $field] = lang('Webpage.invalidText');
                    continue;
                }
                $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
                if (mb_strlen($text) > $limit) {
                    $errors[$key . $field] = lang('Webpage.textTooLong', [$limit]);
                }
                $name[$field] = $text === '' ? null : $text;
            }
            if ($name['page_name'] === null) {
                $errors[$key . 'page_name'] = lang('Webpage.nameRequired');
            } elseif (mb_strtolower($name['page_name']) === mb_strtolower($this->homeNameIn($code))) {
                $errors[$key . 'page_name'] = lang('Webpage.nameIsHome');
            }
            $names[$code] = $name;
        }
        if (count($errors) === $count && ! isset($names[$languages[0]])) {
            $errors['names'] = lang('Webpage.defaultNameRequired', [$known[$languages[0]] ?? $languages[0]]);
        }

        return count($errors) === $count ? $names : null;
    }

    /**
     * A page_slug the client sets
     * @param mixed $value
     * @param array $errors Added to
     * @return string|null Null when refused
     */
    private function slug(mixed $value, array &$errors): ?string
    {
        $slug = is_string($value) ? strtolower(trim($value)) : '';
        if ($slug === WebpageMasterModel::HOME_SLUG) {
            $errors['page_slug'] = lang('Webpage.slugReserved');

            return null;
        }
        if (strlen($slug) > WebpageMasterModel::MAX_SLUG || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            $errors['page_slug'] = lang('Webpage.invalidSlug', [WebpageMasterModel::MAX_SLUG]);

            return null;
        }

        return $slug;
    }

    /**
     * A slug made from a page's name: its Latin letters (accents dropped) and digits, hyphens between; "page" for a
     * name with none (Thai, Japanese). The model makes it free in the business with -2, -3...
     * @param string $name
     * @return string
     */
    private function slugOf(string $name): string
    {
        helper('text');
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(convert_accented_characters($name))) ?? '', '-');
        $slug = rtrim(substr($slug, 0, WebpageMasterModel::MAX_SLUG), '-');

        return $slug === '' || $slug === WebpageMasterModel::HOME_SLUG ? 'page' : $slug;
    }

    /**
     * Refuse names and a slug that another page of the business has
     * @param int        $businessId
     * @param array|null $names    From names()
     * @param string|null $slug    Checked as it is; null is not checked
     * @param int|null   $exceptId The page itself
     * @param array      $errors   Added to
     * @return void
     */
    private function checkTaken(int $businessId, ?array $names, ?string $slug, ?int $exceptId, array &$errors): void
    {
        $pages = model(WebpageMasterModel::class);
        if ($names !== null) {
            // names() keeps the request's order, so a language's place in $names is its index in the request
            $index = array_flip(array_keys($names));
            foreach ($pages->namesTaken($businessId, array_map(static fn (array $name): string => $name['page_name'], $names), $exceptId) as $code) {
                $errors['names.' . $index[$code] . '.page_name'] = lang('Webpage.nameTaken');
            }
        }
        if ($slug !== null && $pages->slugTaken($businessId, $slug, $exceptId)) {
            $errors['page_slug'] = lang('Webpage.slugTaken');
        }
    }

    /**
     * The texts of a section of $design
     * @param mixed        $value     From the request: {language_code: {slot_code: text}}
     * @param array        $design
     * @param list<string> $languages The website's, the default first
     * @param array        $errors    Added to
     * @return array<string, array<string, string>>|null The texts, the empty ones left out; null when refused
     */
    private function texts(mixed $value, array $design, array $languages, array &$errors): ?array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors['texts'] = lang('Webpage.invalidTexts');

            return null;
        }
        $texts = [];
        $count = count($errors);
        foreach ($value as $code => $slots) {
            $key = 'texts.' . $code;
            if (! in_array($code, $languages, true)) {
                $errors[$key] = lang('Webpage.invalidLanguage');
                continue;
            }
            if (! is_array($slots) || ($slots !== [] && array_is_list($slots))) {
                $errors[$key] = lang('Webpage.invalidTexts');
                continue;
            }
            foreach ($slots as $slotCode => $text) {
                $slot = $design['texts'][$slotCode] ?? null;
                if ($slot === null) {
                    $errors[$key . '.' . $slotCode] = lang('Webpage.invalidSlot');
                    continue;
                }
                if ($text !== null && ! is_string($text)) {
                    $errors[$key . '.' . $slotCode] = lang('Webpage.invalidText');
                    continue;
                }
                $text = $this->cleanText((string) $text, ! empty($slot['multiline']));
                if (mb_strlen($text) > $slot['max']) {
                    $errors[$key . '.' . $slotCode] = lang('Webpage.textTooLong', [$slot['max']]);
                    continue;
                }
                if ($text !== '') {
                    $texts[$code][$slotCode] = $text;
                }
            }
        }
        foreach ($design['texts'] as $slotCode => $slot) {
            if ($slot['required'] && ! isset($texts[$languages[0]][$slotCode]) && ! isset($errors['texts.' . $languages[0] . '.' . $slotCode])) {
                $errors['texts.' . $languages[0] . '.' . $slotCode] = lang('Webpage.textRequired');
            }
        }

        return count($errors) === $count ? $texts : null;
    }

    /**
     * A text trimmed, its whitespace collapsed; a multiline one keeps its line breaks, at most one empty line in a row
     * @param string $text
     * @param bool   $multiline
     * @return string
     */
    private function cleanText(string $text, bool $multiline): string
    {
        if (! $multiline) {
            return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        }
        $lines = array_map(static fn (string $line): string => trim(preg_replace('/[^\S\n]+/u', ' ', $line) ?? ''), explode("\n", str_replace(["\r\n", "\r"], "\n", $text)));

        return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)) ?? '');
    }

    /**
     * Delete the files of images whose rows are gone; one that will not go is only logged
     * @param list<string> $publicIds
     * @return void
     */
    private function removeFiles(array $publicIds): void
    {
        $folder = UploadedImage::folderOf(self::USED_FOR);
        foreach ($publicIds as $publicId) {
            $path = $folder . $publicId . '.webp';
            if (is_file($path) && ! @unlink($path)) {
                log_message('error', 'Cannot delete the web page image {path}', ['path' => $path]);
            }
        }
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
            return [null, [], ApiResponse::error(lang('Webpage.invalidRequest'), 422)];
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Webpage');
        if ($error !== null) {
            return [null, [], $error];
        }
        $input['business_slug'] = trim($input['business_slug']);

        return [$business, $input, null];
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
