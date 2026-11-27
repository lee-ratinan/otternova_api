<?php

namespace Config;

/**
 * Settings for the report form of otternova_mart, where a visitor reports a business or one of its products or
 * services to Otternova (moderation_report); see App\Libraries\MartReportGuard. The token ages, honeypot field,
 * duplicate window, link limit and spam keywords are the contact form's (Config\ContactForm); the counts below are
 * per business unless they say otherwise.
 */
class MartReportForm extends BusinessMessageForm
{
    /** Reports accepted per IP address / email address, about one business, within $throttleWindow seconds */
    public int $maxPerIp    = 3;
    public int $maxPerEmail = 3;

    /** Reports accepted per IP address about all businesses together within $throttleWindow seconds */
    public int $maxPerIpAllBusinesses = 10;

    /** Reports one business takes in a day; more get a "try later", so no one can flood the staff's queue */
    public int $maxPerBusinessPerDay = 50;

    public int $detailsMaxLength = 2000;

    /**
     * Words that, in a report of a business that declared an audience, make the report about that audience: it is
     * filtered as spam (ModerationReportModel::FILTER_AUDIENCE), as being LGBT-friendly, naturist or adults-only is
     * what the business said it is, not a reason to report it. Child-safety reports are never filtered.
     *
     * By audience: lgbt for business_master.lgbt_audience other than GEN, naturist for naturist_audience other than
     * GEN, adult for age_restricted Y. In the languages of the mart; a word in a script written with spaces between
     * words (Latin) matches as a whole word, with an s or es after it; one in Thai, Chinese or Japanese matches
     * anywhere. Lower case.
     *
     * @var array<string, list<string>>
     */
    public array $audienceKeywords = [
        'lgbt' => [
            // en
            'gay', 'lesbian', 'lgbt', 'lgbtq', 'lgbtq+', 'lgbtqia', 'lgbtqia+', 'queer', 'homosexual', 'homosexuality',
            'bisexual', 'transgender', 'trans', 'transsexual', 'tranny', 'fag', 'faggot', 'dyke', 'sodomy', 'sodomite',
            'same-sex', 'same sex', 'pride', 'rainbow',
            // ms, id
            'homoseksual', 'lesbi', 'pondan', 'mak nyah', 'banci', 'bencong', 'waria', 'songsang', 'transgender',
            // th
            'เกย์', 'เลสเบี้ยน', 'กะเทย', 'ตุ๊ด', 'รักร่วมเพศ', 'คนข้ามเพศ', 'สาวประเภทสอง', 'ทอมดี้', 'ความหลากหลายทางเพศ',
            // ja
            'ゲイ', 'レズ', 'レズビアン', 'ホモ', 'オカマ', '同性愛', 'トランスジェンダー', '性的少数者',
            // zh-SG, zh-TW
            '同性恋', '同性戀', '同志', '基佬', '跨性别', '跨性別', '变性', '變性', '女同', '拉拉', '拉子',
        ],
        'naturist' => [
            // en
            'nude', 'nudity', 'naked', 'naturist', 'naturism', 'nudist', 'nudism', 'topless', 'clothing optional',
            'clothing-optional', 'unclothed', 'undressed',
            // ms, id
            'bogel', 'telanjang', 'naturis', 'nudis',
            // th
            'เปลือย', 'โป๊', 'แก้ผ้า', 'ไม่ใส่เสื้อผ้า', 'ชีเปลือย',
            // ja
            'ヌード', '裸', 'ヌーディスト', 'ナチュリスト',
            // zh-SG, zh-TW
            '裸体', '裸體', '裸露', '全裸', '天体', '天體',
        ],
        'adult' => [
            // en
            'adult', 'adults only', 'adults-only', '18+', 'r18', 'r-18', 'erotic', 'erotica',
            // ms, id
            'dewasa',
            // th
            'ผู้ใหญ่', 'เฉพาะผู้ใหญ่',
            // ja
            'アダルト', '成人向け', '18禁',
            // zh-SG, zh-TW
            '成人', '限制级', '限制級',
        ],
    ];
}
