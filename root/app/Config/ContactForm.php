<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Settings for the public contact form (GET /v1/contact-form-token, POST /v1/contact); see App\Libraries\ContactFormGuard
 */
class ContactForm extends BaseConfig
{
    /** A form token must be at least this old when submitted: a person takes longer than this to fill in the form */
    public int $tokenMinAge = 3;

    /** ...and at most this old, so a page left open all day asks for a reload */
    public int $tokenMaxAge = 2 * HOUR;

    /** Messages accepted per IP address / per email address within $throttleWindow seconds */
    public int $maxPerIp    = 5;
    public int $maxPerEmail = 3;
    public int $throttleWindow = HOUR;

    /** The same message from the same address within this many seconds is answered but not stored again */
    public int $duplicateWindow = DAY;

    /** More links than this in the subject and message marks the message as spam */
    public int $maxLinks = 3;

    /** A subject or message containing any of these (ignoring case) is marked as spam; carried over from the website */
    public array $spamKeywords = [
        // SEO
        'seo', 'backlink', 'rank on google', 'search engine optimization', 'domain authority', 'guest post', 'drive traffic',
        // financial
        'crypto', 'bitcoin', 'forex', 'trading bot', 'passive income', 'make $$$', 'earn cash',
        // phishing
        'account suspended', 'payment declined', 'copyright infringement', 'legal action', 'urgent response',
        // adult and pharma
        'viagra', 'casino', 'betting', 'weight loss pill',
    ];

    /** The hidden field a person never sees, so never fills in */
    public string $honeypotField = 'website';

    /** Field lengths, in characters */
    public int $nameMaxLength    = 100;
    public int $emailMaxLength   = 128;
    public int $phoneMaxLength   = 32;
    public int $subjectMaxLength = 200;
    public int $messageMaxLength = 5000;
}
