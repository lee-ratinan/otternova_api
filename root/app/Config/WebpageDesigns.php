<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The designs of the sections of a business's own web pages (webpage_section.design_code), and what each one takes.
 * A design is drawn by the storefront's view sections/{design_code}.php (otternova_mart), so a design_code is a
 * view's name: lowercase letters, digits and underscores only. Only the codes here are accepted, so nothing else
 * can name a view.
 * - section_type: what the section is; a hero is always the page's first section, once per page
 * - texts:  slot_code => [max: the longest it may be, required: needed in the default language, multiline: keeps
 *   its line breaks (all other whitespace is collapsed)]
 * - images: slot_code => [width, height: the size every upload is cropped to (smaller originals are refused), min,
 *   max: how many the slot has, max null being as many as the plan's PlanLimits::IMAGE_PER_WEBPAGE leaves]
 * - sample: language_code => slot_code => text, what the design preview (Mart::design) shows before the business
 *   has written anything; a language with none shows en-US's. The preview shows grey boxes of the image sizes.
 * A released design's image sizes never change: the images already uploaded were cut to them. A new shape is a new
 * design. Changing a section's design keeps its texts and images whose slot_code the new design has too, the images
 * only where the size is the same and as many as the new slot takes.
 */
class WebpageDesigns extends BaseConfig
{
    public const TYPE_HERO = 'hero';

    /** The section types, in the order a page has them: a hero first */
    public const SECTION_TYPES = [self::TYPE_HERO];

    /** The section types a page has at most one of */
    public const ONCE_PER_PAGE = [self::TYPE_HERO];

    /**
     * design_code => the design
     * @var array<string, array{section_type: string, texts: array<string, array{max: int, required: bool, multiline?: bool}>, images: array<string, array{width: int, height: int, min: int, max: int|null}>, sample?: array<string, array<string, string>>}>
     */
    public array $designs = [
        // a full-width picture behind the badge, headline, paragraph and the button to the system home
        'hero_design_1' => [
            'section_type' => self::TYPE_HERO,
            'texts'        => [
                'badge'        => ['max' => 48, 'required' => false],
                'headline'     => ['max' => 120, 'required' => true],
                'paragraph'    => ['max' => 500, 'required' => false, 'multiline' => true],
                'button_label' => ['max' => 32, 'required' => true],
            ],
            'images'       => [
                'background' => ['width' => 2000, 'height' => 1000, 'min' => 1, 'max' => 1],
            ],
            'sample'       => [
                'en-US' => [
                    'badge'        => 'New this season',
                    'headline'     => 'Your headline goes here',
                    'paragraph'    => "A sentence or two about what you offer and why people love it.\nKeep it short and friendly.",
                    'button_label' => 'Shop now',
                ],
                'th'    => [
                    'badge'        => 'ใหม่ประจำฤดูกาล',
                    'headline'     => 'พาดหัวของคุณอยู่ตรงนี้',
                    'paragraph'    => "ประโยคสั้น ๆ เกี่ยวกับสิ่งที่คุณมีให้ และเหตุผลที่ลูกค้าชื่นชอบ\nเขียนให้กระชับและเป็นกันเอง",
                    'button_label' => 'ดูสินค้า',
                ],
            ],
        ],
    ];

    /**
     * The sample texts of a design in a language: its own, else en-US's
     * @param array  $design
     * @param string $language
     * @return array<string, string> slot_code => text
     */
    public function sampleOf(array $design, string $language): array
    {
        return $design['sample'][$language] ?? $design['sample']['en-US'] ?? [];
    }

    /**
     * A design, or null for a code that is not one
     * @param mixed $code
     * @return array|null
     */
    public function design(mixed $code): ?array
    {
        return is_string($code) && isset($this->designs[$code]) ? $this->designs[$code] : null;
    }

    /**
     * The most images a design takes on a page whose plan allows $imageCap: its slots' max, a null max being the cap
     * @param array $design
     * @param int   $imageCap PlanLimits::IMAGE_PER_WEBPAGE of the plan
     * @return int
     */
    public function maxImagesOf(array $design, int $imageCap): int
    {
        return array_sum(array_map(static fn (array $slot): int => $slot['max'] ?? $imageCap, $design['images']));
    }

    /**
     * Whether a plan allowing $imageCap images per page can have the design: its slots' min fit in it
     * @param array $design
     * @param int   $imageCap
     * @return bool
     */
    public function fitsPlan(array $design, int $imageCap): bool
    {
        return array_sum(array_column($design['images'], 'min')) <= $imageCap;
    }
}
