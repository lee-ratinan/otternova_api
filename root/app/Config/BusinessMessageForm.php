<?php

namespace Config;

/**
 * Settings for the contact form of each business on otternova_mart (business_message); see
 * App\Libraries\BusinessMessageGuard. The token ages, honeypot field, duplicate window, link limit, spam keywords and
 * field lengths are the public contact form's (Config\ContactForm); the counts below are per business unless they
 * say otherwise.
 */
class BusinessMessageForm extends ContactForm
{
    /** Messages accepted per IP address / email address / telephone number, to one business, within $throttleWindow seconds */
    public int $maxPerIp    = 3;
    public int $maxPerEmail = 3;
    public int $maxPerPhone = 3;

    /** Messages accepted per IP address to all businesses together within $throttleWindow seconds */
    public int $maxPerIpAllBusinesses = 10;

    /** Messages one business takes in a day, so no one can flood its inbox; more get a "try later" */
    public int $maxPerBusinessPerDay = 100;
}
