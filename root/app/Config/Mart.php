<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The public endpoints of a business's pages on the marketplace (otternova_mart): App\Controllers\Mart,
 * App\Models\MartCatalogModel and App\Libraries\MartCache
 */
class Mart extends BaseConfig
{
    /**
     * Seconds an answer stays in the cache. A change made in the Client app clears the business's answers at once
     * (App\Filters\MartCacheRefresh), so this only bounds what that cannot see, such as a change made straight in
     * the database.
     */
    public int $cacheTtl = 600;

    /** Seconds a business_slug => business id lookup stays in the cache */
    public int $slugTtl = 600;

    /** Artifacts a page of /mart/artifacts has when the request does not say */
    public int $defaultPerPage = 12;

    /** The most a page of /mart/artifacts may have, and the most public_ids it may ask for at once */
    public int $maxPerPage = 48;

    /** Artifacts of the site's "highest rated", by artifact_master.rating_average */
    public int $highestRatedCount = 3;

    /** Approved reviews /mart/artifact sends, the newest first */
    public int $reviewCount = 10;

    /**
     * Minutes between the starts /mart/slots offers, on the store's clock (30: 10:00, 10:30 ...); an opening at 10:15
     * offers 10:30 first
     */
    public int $slotStepMinutes = 30;

    /** Days /mart/slots looks through for the first with a free time, when no date is asked for */
    public int $firstDateSearchDays = 14;

    /** Lines a cart may send to /mart/quote and /mart/order, as many as the mart's cart may hold */
    public int $maxCartLines = 50;
}
