<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The designs of the sections of a business's own web pages (webpage_section.design_code), and what each one takes.
 * A design is drawn by the storefront's view sections/{design_code}.php (otternova_mart), so a design_code is a
 * view's name: lowercase letters, digits and underscores only. Only the codes here are accepted, so nothing else
 * can name a view.
 * - section_type: what the section is (SECTION_TYPES); a hero is always the page's first section. Repeated items
 *   (cards, figures, steps) are numbered slots, {item}_{n}_{field}; the view shows item n when it has any text
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
    public const TYPE_HERO         = 'hero';
    public const TYPE_ABOUT        = 'about';
    public const TYPE_SERVICES     = 'services';
    public const TYPE_PORTFOLIO    = 'portfolio';
    public const TYPE_TESTIMONIALS = 'testimonials';
    public const TYPE_WHY_US       = 'why_us';

    /** The section types, a hero first; the others follow in the order they were added to the page */
    public const SECTION_TYPES = [self::TYPE_HERO, self::TYPE_ABOUT, self::TYPE_SERVICES, self::TYPE_PORTFOLIO, self::TYPE_TESTIMONIALS, self::TYPE_WHY_US];

    /**
     * The section types a page has at most one of: all of them for now, as the Client app's editor has one card per
     * section type
     */
    public const ONCE_PER_PAGE = self::SECTION_TYPES;

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
        // the headline and buttons beside a picture with up to three small figure cards floating over it
        'hero_01' => [
            'section_type' => self::TYPE_HERO,
            'texts'        => [
                'badge'          => ['max' => 48, 'required' => false],
                'headline'       => ['max' => 120, 'required' => true],
                'paragraph'      => ['max' => 500, 'required' => false, 'multiline' => true],
                'button_label'   => ['max' => 32, 'required' => true],
                'button_2_label' => ['max' => 32, 'required' => false],
                'card_1_value'   => ['max' => 16, 'required' => false],
                'card_1_label'   => ['max' => 32, 'required' => false],
                'card_2_value'   => ['max' => 16, 'required' => false],
                'card_2_label'   => ['max' => 32, 'required' => false],
                'card_3_value'   => ['max' => 16, 'required' => false],
                'card_3_label'   => ['max' => 32, 'required' => false],
            ],
            'images'       => [
                'picture' => ['width' => 1200, 'height' => 900, 'min' => 1, 'max' => 1],
            ],
            'sample'       => [
                'en-US' => [
                    'badge'          => 'Welcome',
                    'headline'       => 'Your headline goes here',
                    'paragraph'      => 'A sentence or two about what you offer and why people love it.',
                    'button_label'   => 'Shop now',
                    'button_2_label' => 'Contact us',
                    'card_1_value'   => '4.9★',
                    'card_1_label'   => 'Customer rating',
                    'card_2_value'   => '2,000+',
                    'card_2_label'   => 'Happy customers',
                    'card_3_value'   => '10 yrs',
                    'card_3_label'   => 'In business',
                ],
                'th'    => [
                    'badge'          => 'ยินดีต้อนรับ',
                    'headline'       => 'พาดหัวของคุณอยู่ตรงนี้',
                    'paragraph'      => 'ประโยคสั้น ๆ เกี่ยวกับสิ่งที่คุณมีให้ และเหตุผลที่ลูกค้าชื่นชอบ',
                    'button_label'   => 'ดูสินค้า',
                    'button_2_label' => 'ติดต่อเรา',
                    'card_1_value'   => '4.9★',
                    'card_1_label'   => 'คะแนนจากลูกค้า',
                    'card_2_value'   => '2,000+',
                    'card_2_label'   => 'ลูกค้าที่ประทับใจ',
                    'card_3_value'   => '10 ปี',
                    'card_3_label'   => 'ประสบการณ์',
                ],
            ],
        ],
        // who the business is, with figures, two strengths and two buttons, beside a picture with a smaller one over it
        'about_01' => [
            'section_type' => self::TYPE_ABOUT,
            'texts'        => [
                'badge'           => ['max' => 32, 'required' => false],
                'headline'        => ['max' => 120, 'required' => true],
                'paragraph'       => ['max' => 600, 'required' => false, 'multiline' => true],
                'stat_1_number'   => ['max' => 12, 'required' => false],
                'stat_1_label'    => ['max' => 40, 'required' => false],
                'stat_2_number'   => ['max' => 12, 'required' => false],
                'stat_2_label'    => ['max' => 40, 'required' => false],
                'stat_3_number'   => ['max' => 12, 'required' => false],
                'stat_3_label'    => ['max' => 40, 'required' => false],
                'stat_4_number'   => ['max' => 12, 'required' => false],
                'stat_4_label'    => ['max' => 40, 'required' => false],
                'feature_1_title' => ['max' => 60, 'required' => false],
                'feature_1_text'  => ['max' => 200, 'required' => false],
                'feature_2_title' => ['max' => 60, 'required' => false],
                'feature_2_text'  => ['max' => 200, 'required' => false],
                'button_label'    => ['max' => 32, 'required' => false],
                'button_2_label'  => ['max' => 32, 'required' => false],
                'award_title'     => ['max' => 40, 'required' => false],
                'award_subtitle'  => ['max' => 60, 'required' => false],
            ],
            'images'       => [
                'picture' => ['width' => 1200, 'height' => 900, 'min' => 0, 'max' => 1],
                'inset'   => ['width' => 800, 'height' => 800, 'min' => 0, 'max' => 1],
            ],
            'sample'       => [
                'en-US' => [
                    'badge'           => 'About us',
                    'headline'        => 'The story behind what we do',
                    'paragraph'       => 'Tell visitors who you are, how you started and what makes your shop different.',
                    'stat_1_number'   => '10+',
                    'stat_1_label'    => 'Years in business',
                    'stat_2_number'   => '2,000+',
                    'stat_2_label'    => 'Happy customers',
                    'stat_3_number'   => '150',
                    'stat_3_label'    => 'Products',
                    'stat_4_number'   => '98%',
                    'stat_4_label'    => 'Would come back',
                    'feature_1_title' => 'Quick service',
                    'feature_1_text'  => 'A line about how fast and easy it is to buy from you.',
                    'feature_2_title' => 'Quality you can trust',
                    'feature_2_text'  => 'A line about the care you put into everything you sell.',
                    'button_label'    => 'Shop now',
                    'button_2_label'  => 'Contact us',
                    'award_title'     => 'Customers\' choice',
                    'award_subtitle'  => 'Best local shop 2026',
                ],
                'th'    => [
                    'badge'           => 'เกี่ยวกับเรา',
                    'headline'        => 'เรื่องราวเบื้องหลังสิ่งที่เราทำ',
                    'paragraph'       => 'เล่าให้ผู้เยี่ยมชมรู้ว่าคุณคือใคร เริ่มต้นอย่างไร และร้านของคุณแตกต่างอย่างไร',
                    'stat_1_number'   => '10+',
                    'stat_1_label'    => 'ปีที่เปิดกิจการ',
                    'stat_2_number'   => '2,000+',
                    'stat_2_label'    => 'ลูกค้าที่ประทับใจ',
                    'stat_3_number'   => '150',
                    'stat_3_label'    => 'สินค้า',
                    'stat_4_number'   => '98%',
                    'stat_4_label'    => 'กลับมาซื้ออีก',
                    'feature_1_title' => 'บริการรวดเร็ว',
                    'feature_1_text'  => 'ประโยคสั้น ๆ ว่าการซื้อกับคุณนั้นง่ายและรวดเร็วแค่ไหน',
                    'feature_2_title' => 'คุณภาพที่ไว้ใจได้',
                    'feature_2_text'  => 'ประโยคสั้น ๆ ถึงความใส่ใจในทุกสิ่งที่คุณขาย',
                    'button_label'    => 'ดูสินค้า',
                    'button_2_label'  => 'ติดต่อเรา',
                    'award_title'     => 'ลูกค้าเลือก',
                    'award_subtitle'  => 'ร้านยอดนิยมประจำปี 2569',
                ],
            ],
        ],
        // an introduction, up to six service cards with their points, and a call-to-action banner; no pictures
        'services_01' => [
            'section_type' => self::TYPE_SERVICES,
            'texts'        => [
                'title'              => ['max' => 40, 'required' => false],
                'subtitle'           => ['max' => 40, 'required' => false],
                'subtitle_highlight' => ['max' => 40, 'required' => false],
                'intro_title'        => ['max' => 120, 'required' => true],
                'intro_text'         => ['max' => 400, 'required' => false, 'multiline' => true],
                'view_all_label'     => ['max' => 32, 'required' => false],
                'service_1_title'    => ['max' => 60, 'required' => true],
                'service_1_text'     => ['max' => 300, 'required' => false],
                'service_1_tag'      => ['max' => 24, 'required' => false],
                'service_1_points'   => ['max' => 300, 'required' => false, 'multiline' => true],
                'service_2_title'    => ['max' => 60, 'required' => false],
                'service_2_text'     => ['max' => 300, 'required' => false],
                'service_2_tag'      => ['max' => 24, 'required' => false],
                'service_2_points'   => ['max' => 300, 'required' => false, 'multiline' => true],
                'service_3_title'    => ['max' => 60, 'required' => false],
                'service_3_text'     => ['max' => 300, 'required' => false],
                'service_3_tag'      => ['max' => 24, 'required' => false],
                'service_3_points'   => ['max' => 300, 'required' => false, 'multiline' => true],
                'service_4_title'    => ['max' => 60, 'required' => false],
                'service_4_text'     => ['max' => 300, 'required' => false],
                'service_4_tag'      => ['max' => 24, 'required' => false],
                'service_4_points'   => ['max' => 300, 'required' => false, 'multiline' => true],
                'service_5_title'    => ['max' => 60, 'required' => false],
                'service_5_text'     => ['max' => 300, 'required' => false],
                'service_5_tag'      => ['max' => 24, 'required' => false],
                'service_5_points'   => ['max' => 300, 'required' => false, 'multiline' => true],
                'service_6_title'    => ['max' => 60, 'required' => false],
                'service_6_text'     => ['max' => 300, 'required' => false],
                'service_6_tag'      => ['max' => 24, 'required' => false],
                'service_6_points'   => ['max' => 300, 'required' => false, 'multiline' => true],
                'service_link_label' => ['max' => 32, 'required' => false],
                'cta_badge'          => ['max' => 32, 'required' => false],
                'cta_title'          => ['max' => 120, 'required' => false],
                'cta_text'           => ['max' => 400, 'required' => false, 'multiline' => true],
                'cta_button_label'   => ['max' => 32, 'required' => false],
                'cta_button_2_label' => ['max' => 32, 'required' => false],
            ],
            'images'       => [],
            'sample'       => [
                'en-US' => [
                    'title'              => 'Services',
                    'subtitle'           => 'What we',
                    'subtitle_highlight' => 'offer',
                    'intro_title'        => 'Everything you need, in one place',
                    'intro_text'         => 'A short introduction to what you offer and who it is for.',
                    'view_all_label'     => 'See everything',
                    'service_1_title'    => 'Your first service',
                    'service_1_text'     => 'A sentence or two about this service and why customers choose it.',
                    'service_1_tag'      => 'Popular',
                    'service_1_points'   => "What is included\nAnother thing included\nOne more",
                    'service_2_title'    => 'Your second service',
                    'service_2_text'     => 'A sentence or two about this service and why customers choose it.',
                    'service_2_points'   => "What is included\nAnother thing included\nOne more",
                    'service_3_title'    => 'Your third service',
                    'service_3_text'     => 'A sentence or two about this service and why customers choose it.',
                    'service_3_points'   => "What is included\nAnother thing included\nOne more",
                    'service_link_label' => 'Find out more',
                    'cta_badge'          => 'Ready?',
                    'cta_title'          => 'Let us help you today',
                    'cta_text'           => 'A line inviting visitors to buy or get in touch.',
                    'cta_button_label'   => 'Shop now',
                    'cta_button_2_label' => 'Contact us',
                ],
                'th'    => [
                    'title'              => 'บริการ',
                    'subtitle'           => 'สิ่งที่เรา',
                    'subtitle_highlight' => 'มีให้',
                    'intro_title'        => 'ครบทุกสิ่งที่คุณต้องการในที่เดียว',
                    'intro_text'         => 'แนะนำสั้น ๆ ว่าคุณมีอะไรให้ และเหมาะกับใคร',
                    'view_all_label'     => 'ดูทั้งหมด',
                    'service_1_title'    => 'บริการแรกของคุณ',
                    'service_1_text'     => 'ประโยคสั้น ๆ เกี่ยวกับบริการนี้ และเหตุผลที่ลูกค้าเลือก',
                    'service_1_tag'      => 'ยอดนิยม',
                    'service_1_points'   => "สิ่งที่รวมอยู่\nอีกสิ่งที่รวมอยู่\nอีกหนึ่งอย่าง",
                    'service_2_title'    => 'บริการที่สองของคุณ',
                    'service_2_text'     => 'ประโยคสั้น ๆ เกี่ยวกับบริการนี้ และเหตุผลที่ลูกค้าเลือก',
                    'service_2_points'   => "สิ่งที่รวมอยู่\nอีกสิ่งที่รวมอยู่\nอีกหนึ่งอย่าง",
                    'service_3_title'    => 'บริการที่สามของคุณ',
                    'service_3_text'     => 'ประโยคสั้น ๆ เกี่ยวกับบริการนี้ และเหตุผลที่ลูกค้าเลือก',
                    'service_3_points'   => "สิ่งที่รวมอยู่\nอีกสิ่งที่รวมอยู่\nอีกหนึ่งอย่าง",
                    'service_link_label' => 'ดูรายละเอียด',
                    'cta_badge'          => 'พร้อมหรือยัง?',
                    'cta_title'          => 'ให้เราช่วยคุณวันนี้',
                    'cta_text'           => 'ประโยคชวนผู้เยี่ยมชมให้ซื้อหรือติดต่อคุณ',
                    'cta_button_label'   => 'ดูสินค้า',
                    'cta_button_2_label' => 'ติดต่อเรา',
                ],
            ],
        ],
        // up to six work cards with pictures in a grid filtered by their categories, and a call-to-action box
        'portfolio_01' => [
            'section_type' => self::TYPE_PORTFOLIO,
            'texts'        => [
                'title'              => ['max' => 40, 'required' => false],
                'subtitle'           => ['max' => 40, 'required' => false],
                'subtitle_highlight' => ['max' => 40, 'required' => false],
                'item_1_title'       => ['max' => 60, 'required' => true],
                'item_1_text'        => ['max' => 200, 'required' => false],
                'item_1_category'    => ['max' => 32, 'required' => false],
                'item_1_tag'         => ['max' => 24, 'required' => false],
                'item_2_title'       => ['max' => 60, 'required' => false],
                'item_2_text'        => ['max' => 200, 'required' => false],
                'item_2_category'    => ['max' => 32, 'required' => false],
                'item_2_tag'         => ['max' => 24, 'required' => false],
                'item_3_title'       => ['max' => 60, 'required' => false],
                'item_3_text'        => ['max' => 200, 'required' => false],
                'item_3_category'    => ['max' => 32, 'required' => false],
                'item_3_tag'         => ['max' => 24, 'required' => false],
                'item_4_title'       => ['max' => 60, 'required' => false],
                'item_4_text'        => ['max' => 200, 'required' => false],
                'item_4_category'    => ['max' => 32, 'required' => false],
                'item_4_tag'         => ['max' => 24, 'required' => false],
                'item_5_title'       => ['max' => 60, 'required' => false],
                'item_5_text'        => ['max' => 200, 'required' => false],
                'item_5_category'    => ['max' => 32, 'required' => false],
                'item_5_tag'         => ['max' => 24, 'required' => false],
                'item_6_title'       => ['max' => 60, 'required' => false],
                'item_6_text'        => ['max' => 200, 'required' => false],
                'item_6_category'    => ['max' => 32, 'required' => false],
                'item_6_tag'         => ['max' => 24, 'required' => false],
                'cta_title'          => ['max' => 120, 'required' => false],
                'cta_text'           => ['max' => 400, 'required' => false, 'multiline' => true],
                'cta_button_label'   => ['max' => 32, 'required' => false],
            ],
            'images'       => [
                'items' => ['width' => 800, 'height' => 600, 'min' => 0, 'max' => 6],
            ],
            'sample'       => [
                'en-US' => [
                    'title'              => 'Our work',
                    'subtitle'           => 'See what we',
                    'subtitle_highlight' => 'have made',
                    'item_1_title'       => 'Your first piece of work',
                    'item_1_text'        => 'A line about this piece and what made it special.',
                    'item_1_category'    => 'Category one',
                    'item_1_tag'         => 'Featured',
                    'item_2_title'       => 'Your second piece of work',
                    'item_2_text'        => 'A line about this piece and what made it special.',
                    'item_2_category'    => 'Category two',
                    'item_3_title'       => 'Your third piece of work',
                    'item_3_text'        => 'A line about this piece and what made it special.',
                    'item_3_category'    => 'Category one',
                    'cta_title'          => 'Have something in mind?',
                    'cta_text'           => 'A line inviting visitors to get in touch about their own idea.',
                    'cta_button_label'   => 'Contact us',
                ],
                'th'    => [
                    'title'              => 'ผลงาน',
                    'subtitle'           => 'ชมสิ่งที่เรา',
                    'subtitle_highlight' => 'สร้างสรรค์',
                    'item_1_title'       => 'ผลงานชิ้นแรกของคุณ',
                    'item_1_text'        => 'ประโยคสั้น ๆ เกี่ยวกับผลงานนี้ และสิ่งที่ทำให้พิเศษ',
                    'item_1_category'    => 'หมวดที่หนึ่ง',
                    'item_1_tag'         => 'แนะนำ',
                    'item_2_title'       => 'ผลงานชิ้นที่สองของคุณ',
                    'item_2_text'        => 'ประโยคสั้น ๆ เกี่ยวกับผลงานนี้ และสิ่งที่ทำให้พิเศษ',
                    'item_2_category'    => 'หมวดที่สอง',
                    'item_3_title'       => 'ผลงานชิ้นที่สามของคุณ',
                    'item_3_text'        => 'ประโยคสั้น ๆ เกี่ยวกับผลงานนี้ และสิ่งที่ทำให้พิเศษ',
                    'item_3_category'    => 'หมวดที่หนึ่ง',
                    'cta_title'          => 'มีไอเดียในใจไหม?',
                    'cta_text'           => 'ประโยคชวนผู้เยี่ยมชมให้ติดต่อเรื่องไอเดียของพวกเขา',
                    'cta_button_label'   => 'ติดต่อเรา',
                ],
            ],
        ],
        // what customers say, up to four, one at a time in a slider, each with the person's picture
        'testimonials_01' => [
            'section_type' => self::TYPE_TESTIMONIALS,
            'texts'        => [
                'title'              => ['max' => 40, 'required' => false],
                'subtitle'           => ['max' => 40, 'required' => false],
                'subtitle_highlight' => ['max' => 40, 'required' => false],
                'item_1_title'       => ['max' => 80, 'required' => false],
                'item_1_quote'       => ['max' => 800, 'required' => true, 'multiline' => true],
                'item_1_name'        => ['max' => 60, 'required' => true],
                'item_1_role'        => ['max' => 60, 'required' => false],
                'item_2_title'       => ['max' => 80, 'required' => false],
                'item_2_quote'       => ['max' => 800, 'required' => false, 'multiline' => true],
                'item_2_name'        => ['max' => 60, 'required' => false],
                'item_2_role'        => ['max' => 60, 'required' => false],
                'item_3_title'       => ['max' => 80, 'required' => false],
                'item_3_quote'       => ['max' => 800, 'required' => false, 'multiline' => true],
                'item_3_name'        => ['max' => 60, 'required' => false],
                'item_3_role'        => ['max' => 60, 'required' => false],
                'item_4_title'       => ['max' => 80, 'required' => false],
                'item_4_quote'       => ['max' => 800, 'required' => false, 'multiline' => true],
                'item_4_name'        => ['max' => 60, 'required' => false],
                'item_4_role'        => ['max' => 60, 'required' => false],
            ],
            'images'       => [
                'people' => ['width' => 600, 'height' => 600, 'min' => 0, 'max' => 4],
            ],
            'sample'       => [
                'en-US' => [
                    'title'              => 'Reviews',
                    'subtitle'           => 'What our',
                    'subtitle_highlight' => 'customers say',
                    'item_1_title'       => 'Better than I expected',
                    'item_1_quote'       => "What a happy customer said about you, in their own words.\nA second line if they said more.",
                    'item_1_name'        => 'Customer name',
                    'item_1_role'        => 'Regular customer',
                    'item_2_title'       => 'I keep coming back',
                    'item_2_quote'       => 'What another happy customer said about you, in their own words.',
                    'item_2_name'        => 'Customer name',
                    'item_2_role'        => 'Customer since 2024',
                ],
                'th'    => [
                    'title'              => 'รีวิว',
                    'subtitle'           => 'เสียงจาก',
                    'subtitle_highlight' => 'ลูกค้าของเรา',
                    'item_1_title'       => 'ดีเกินคาด',
                    'item_1_quote'       => "สิ่งที่ลูกค้าที่ประทับใจพูดถึงคุณ ในคำพูดของเขาเอง\nบรรทัดที่สองถ้าเขาพูดมากกว่านั้น",
                    'item_1_name'        => 'ชื่อลูกค้า',
                    'item_1_role'        => 'ลูกค้าประจำ',
                    'item_2_title'       => 'กลับมาซื้อทุกครั้ง',
                    'item_2_quote'       => 'สิ่งที่ลูกค้าอีกคนพูดถึงคุณ ในคำพูดของเขาเอง',
                    'item_2_name'        => 'ชื่อลูกค้า',
                    'item_2_role'        => 'ลูกค้าตั้งแต่ปี 2567',
                ],
            ],
        ],
        // why customers choose the business: an introduction with figures and a picture, value cards, and what it
        // does best beside numbered steps
        'why_us_01' => [
            'section_type' => self::TYPE_WHY_US,
            'texts'        => [
                'title'              => ['max' => 40, 'required' => false],
                'subtitle'           => ['max' => 40, 'required' => false],
                'subtitle_highlight' => ['max' => 40, 'required' => false],
                'badge'              => ['max' => 32, 'required' => false],
                'headline'           => ['max' => 120, 'required' => true],
                'paragraph'          => ['max' => 600, 'required' => false, 'multiline' => true],
                'stat_1_number'      => ['max' => 12, 'required' => false],
                'stat_1_label'       => ['max' => 40, 'required' => false],
                'stat_2_number'      => ['max' => 12, 'required' => false],
                'stat_2_label'       => ['max' => 40, 'required' => false],
                'stat_3_number'      => ['max' => 12, 'required' => false],
                'stat_3_label'       => ['max' => 40, 'required' => false],
                'value_1_title'      => ['max' => 60, 'required' => false],
                'value_1_text'       => ['max' => 300, 'required' => false],
                'value_1_badge'      => ['max' => 24, 'required' => false],
                'value_2_title'      => ['max' => 60, 'required' => false],
                'value_2_text'       => ['max' => 300, 'required' => false],
                'value_2_badge'      => ['max' => 24, 'required' => false],
                'value_3_title'      => ['max' => 60, 'required' => false],
                'value_3_text'       => ['max' => 300, 'required' => false],
                'value_3_badge'      => ['max' => 24, 'required' => false],
                'capabilities_title' => ['max' => 120, 'required' => false],
                'capabilities_text'  => ['max' => 400, 'required' => false, 'multiline' => true],
                'capability_1_title' => ['max' => 60, 'required' => false],
                'capability_1_text'  => ['max' => 300, 'required' => false],
                'capability_2_title' => ['max' => 60, 'required' => false],
                'capability_2_text'  => ['max' => 300, 'required' => false],
                'capability_3_title' => ['max' => 60, 'required' => false],
                'capability_3_text'  => ['max' => 300, 'required' => false],
                'button_label'       => ['max' => 32, 'required' => false],
                'button_2_label'     => ['max' => 32, 'required' => false],
                'step_1_title'       => ['max' => 40, 'required' => false],
                'step_1_text'        => ['max' => 80, 'required' => false],
                'step_2_title'       => ['max' => 40, 'required' => false],
                'step_2_text'        => ['max' => 80, 'required' => false],
                'step_3_title'       => ['max' => 40, 'required' => false],
                'step_3_text'        => ['max' => 80, 'required' => false],
                'step_4_title'       => ['max' => 40, 'required' => false],
                'step_4_text'        => ['max' => 80, 'required' => false],
            ],
            'images'       => [
                'picture' => ['width' => 1200, 'height' => 900, 'min' => 0, 'max' => 1],
            ],
            'sample'       => [
                'en-US' => [
                    'title'              => 'Why us',
                    'subtitle'           => 'Why',
                    'subtitle_highlight' => 'choose us',
                    'badge'              => 'Why choose us',
                    'headline'           => 'The reasons customers pick us',
                    'paragraph'          => 'A few sentences about what you do better than anyone else.',
                    'stat_1_number'      => '10+',
                    'stat_1_label'       => 'Years of experience',
                    'stat_2_number'      => '98%',
                    'stat_2_label'       => 'Happy customers',
                    'stat_3_number'      => '24h',
                    'stat_3_label'       => 'To answer you',
                    'value_1_title'      => 'Fast',
                    'value_1_text'       => 'A line about how quickly you get things done.',
                    'value_2_title'      => 'Friendly',
                    'value_2_text'       => 'A line about how you look after every customer.',
                    'value_2_badge'      => 'Loved most',
                    'value_3_title'      => 'Reliable',
                    'value_3_text'       => 'A line about why people can count on you.',
                    'capabilities_title' => 'What we do best',
                    'capabilities_text'  => 'A short paragraph about your strengths.',
                    'capability_1_title' => 'Your first strength',
                    'capability_1_text'  => 'A line about it.',
                    'capability_2_title' => 'Your second strength',
                    'capability_2_text'  => 'A line about it.',
                    'capability_3_title' => 'Your third strength',
                    'capability_3_text'  => 'A line about it.',
                    'button_label'       => 'Shop now',
                    'button_2_label'     => 'Contact us',
                    'step_1_title'       => 'Choose',
                    'step_1_text'        => 'Pick what you need',
                    'step_2_title'       => 'Order',
                    'step_2_text'        => 'Order online in minutes',
                    'step_3_title'       => 'Receive',
                    'step_3_text'        => 'We get it to you',
                    'step_4_title'       => 'Enjoy',
                    'step_4_text'        => 'We are here if you need us',
                ],
                'th'    => [
                    'title'              => 'ทำไมต้องเรา',
                    'subtitle'           => 'ทำไมต้อง',
                    'subtitle_highlight' => 'เลือกเรา',
                    'badge'              => 'ทำไมต้องเลือกเรา',
                    'headline'           => 'เหตุผลที่ลูกค้าเลือกเรา',
                    'paragraph'          => 'ประโยคสั้น ๆ ว่าคุณทำสิ่งใดได้ดีกว่าใคร',
                    'stat_1_number'      => '10+',
                    'stat_1_label'       => 'ปีแห่งประสบการณ์',
                    'stat_2_number'      => '98%',
                    'stat_2_label'       => 'ลูกค้าพึงพอใจ',
                    'stat_3_number'      => '24 ชม.',
                    'stat_3_label'       => 'ตอบกลับภายใน',
                    'value_1_title'      => 'รวดเร็ว',
                    'value_1_text'       => 'ประโยคสั้น ๆ ว่าคุณทำงานได้รวดเร็วแค่ไหน',
                    'value_2_title'      => 'เป็นกันเอง',
                    'value_2_text'       => 'ประโยคสั้น ๆ ว่าคุณดูแลลูกค้าทุกคนอย่างไร',
                    'value_2_badge'      => 'ถูกใจที่สุด',
                    'value_3_title'      => 'ไว้ใจได้',
                    'value_3_text'       => 'ประโยคสั้น ๆ ว่าทำไมลูกค้าวางใจคุณได้',
                    'capabilities_title' => 'สิ่งที่เราทำได้ดีที่สุด',
                    'capabilities_text'  => 'ย่อหน้าสั้น ๆ เกี่ยวกับจุดแข็งของคุณ',
                    'capability_1_title' => 'จุดแข็งข้อแรก',
                    'capability_1_text'  => 'ประโยคสั้น ๆ เกี่ยวกับจุดแข็งนี้',
                    'capability_2_title' => 'จุดแข็งข้อที่สอง',
                    'capability_2_text'  => 'ประโยคสั้น ๆ เกี่ยวกับจุดแข็งนี้',
                    'capability_3_title' => 'จุดแข็งข้อที่สาม',
                    'capability_3_text'  => 'ประโยคสั้น ๆ เกี่ยวกับจุดแข็งนี้',
                    'button_label'       => 'ดูสินค้า',
                    'button_2_label'     => 'ติดต่อเรา',
                    'step_1_title'       => 'เลือก',
                    'step_1_text'        => 'เลือกสิ่งที่คุณต้องการ',
                    'step_2_title'       => 'สั่งซื้อ',
                    'step_2_text'        => 'สั่งออนไลน์ได้ในไม่กี่นาที',
                    'step_3_title'       => 'รับของ',
                    'step_3_text'        => 'เราส่งถึงมือคุณ',
                    'step_4_title'       => 'ใช้งาน',
                    'step_4_text'        => 'มีเราอยู่เสมอเมื่อคุณต้องการ',
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
