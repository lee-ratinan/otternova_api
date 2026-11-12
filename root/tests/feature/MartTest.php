<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * /mart/*: the public, cached answers the marketplace renders a business's pages from
 *
 * @internal
 */
final class MartTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ArtifactFixturesTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private string $ownerToken;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext', 'martCache'] as $service) {
            Services::resetSingle($service);
        }
        Services::injectMock('cache', new MockCache());

        $this->businessId = $this->business('otter-cafe');
        $this->ownerToken = $this->createClient('owner@example.com', $this->businessId, 'O');
    }

    public function testTheBusinessMustBeNamedAndExist(): void
    {
        $this->get('v1/mart/site')->assertStatus(400);
        $this->get('v1/mart/site?business_slug=no-such-shop')->assertStatus(404);
        $this->get('v1/mart/artifacts?business_slug=no-such-shop')->assertStatus(404);
        $this->get('v1/mart/artifact?business_slug=otter-cafe&public_id=nope')->assertStatus(404);
    }

    public function testASiteWithoutSettingsReadsAsTheDefaults(): void
    {
        $data = $this->data($this->get('v1/mart/site?business_slug=otter-cafe&lang=th'));

        $this->assertSame('th', $data['language']); // any language of listLanguages() until the business picks some
        $this->assertSame('otter-cafe', $data['business']['name']);
        $this->assertSame('THB', $data['business']['currency_code']);
        $this->assertSame(['policy' => 'D', 'value' => '0.00', 'unit' => 'P', 'kind' => 'S'], $data['business']['charges']['tip']);
        $this->assertSame(['value' => '0.00', 'unit' => 'P'], $data['business']['charges']['service_charge']);
        $this->assertSame('#ff7533', $data['theme']['colors']['accent_color']);
        $this->assertNull($data['theme']['logo']);
        $this->assertNull($data['theme']['google_fonts_url']);
        $this->assertSame('otter-cafe', $data['meta']['title']); // the business name stands in
        $this->assertSame(['best_sellers' => [], 'trending' => [], 'highest_rated' => []], $data['featured']);

        $this->assertSame('en-US', $this->data($this->get('v1/mart/site?business_slug=otter-cafe&lang=xx'))['language']);
    }

    public function testTheSiteIsInTheAskedLanguageOrTheDefault(): void
    {
        $this->db->table('business_master_translation')->insert(['business_id' => $this->businessId, 'language_code' => 'th', 'business_name' => 'ออตเตอร์คาเฟ่']);
        [$shirt]      = $this->artifactRow($this->businessId);
        [$mug, $mugP] = $this->artifactRow($this->businessId);
        [$gone]       = $this->artifactRow($this->businessId);
        $this->db->table('artifact_master')->where('id', $mug)->update(['rating_count' => 2, 'rating_average' => '4.50']);
        $this->db->table('artifact_master_translation')->insert(['artifact_id' => $mug, 'language_code' => 'th', 'artifact_name' => 'แก้ว']);
        [$category, $categoryP] = $this->category('Drinks');
        $this->db->table('artifact_master')->where('id', $shirt)->update(['category_id' => $category]);
        $this->store($this->businessId, 'Riverside');
        // a physical store with its name and part of its address in Thai: the rest falls back to business_store's
        $this->db->table('business_store')->insert(['public_id' => 'Sukhumvit001', 'business_id' => $this->businessId, 'store_name' => 'Sukhumvit',
            'store_type' => 'P', 'store_timezone' => 'Asia/Bangkok', 'store_address_line_1' => '1 Sukhumvit Rd', 'store_address_line_2' => 'Floor 2', 'store_city' => 'Bangkok']);
        $this->db->table('business_store_translation')->insert(['store_id' => (int) $this->db->insertID(), 'language_code' => 'th', 'store_name' => 'สุขุมวิท',
            'store_address_line_1' => '1 ถนนสุขุมวิท', 'store_city' => 'กรุงเทพมหานคร']);

        $this->send('v1/client/website/save', ['business_slug' => 'otter-cafe', 'languages' => [
            $this->language('en-US', ['hero_headline' => 'Best coffee', 'heading_font' => 'Montserrat']),
            $this->language('th', ['hero_headline' => 'กาแฟที่ดีที่สุด', 'heading_font' => 'Prompt', 'nav_font' => 'Kanit', 'default_font' => 'Kanit', 'meta_title' => 'ร้านกาแฟ']),
        ], 'featured' => ['best_sellers' => [$mugP, $this->publicIdOf($gone)]]])->assertStatus(200);
        $this->db->table('artifact_master')->where('id', $gone)->update(['artifact_status' => 'I']); // after it was featured

        $th = $this->data($this->get('v1/mart/site?business_slug=otter-cafe&lang=th'));
        $this->assertSame('th', $th['language']);
        $this->assertSame(['en-US', 'th'], array_column($th['languages'], 'code'));
        $this->assertSame('ออตเตอร์คาเฟ่', $th['business']['name']);
        $this->assertSame('กาแฟที่ดีที่สุด', $th['hero']['headline']);
        $this->assertSame('ร้านกาแฟ', $th['meta']['title']);
        $this->assertSame('Prompt', $th['theme']['fonts']['heading']['name']);
        $this->assertStringContainsString('family=Prompt', $th['theme']['google_fonts_url']);
        $this->assertSame('Kanit', $th['theme']['fonts']['default']['name']);
        $this->assertSame(1, substr_count($th['theme']['google_fonts_url'], 'family=Kanit')); // once for both
        // the inactive one is left out of the list
        $this->assertSame([$mugP], array_column($th['featured']['best_sellers'], 'public_id'));
        $this->assertSame('แก้ว', $th['featured']['best_sellers'][0]['name']);
        $this->assertSame([$mugP], array_column($th['featured']['highest_rated'], 'public_id'));
        $this->assertSame([['public_id' => $categoryP, 'name' => 'Drinks', 'artifact_count' => 1]], $th['categories']);
        $this->assertSame('Riverside', $th['stores'][0]['name']);
        $this->assertSame(['สุขุมวิท', '1 ถนนสุขุมวิท', 'Floor 2', 'กรุงเทพมหานคร'],
            [$th['stores'][1]['name'], $th['stores'][1]['address_line_1'], $th['stores'][1]['address_line_2'], $th['stores'][1]['city']]);

        // a language the business does not have: its default, the first
        $ja = $this->data($this->get('v1/mart/site?business_slug=otter-cafe&lang=ja'));
        $this->assertSame('en-US', $ja['language']);
        $this->assertSame('otter-cafe', $ja['business']['name']);
        $this->assertSame('T-Shirt', $ja['featured']['best_sellers'][0]['name']);
    }

    public function testArtifactsAreListedAsCards(): void
    {
        [$shirt, $shirtP] = $this->artifactRow($this->businessId);
        [, $serviceP]     = $this->artifactRow($this->businessId, 'S');
        $this->artifactRow($this->businessId, 'P', 'D');
        $this->variant($shirt, '199.00', '249.00');
        $this->variant($shirt, '299.00');
        $this->variant($shirt, '99.00', null, 'I'); // inactive: not a price on offer
        $this->db->table('artifact_master')->where('id', $shirt)->update(['price_active_lowest' => '199.00', 'price_compare_lowest' => '249.00']);
        $this->db->table('artifact_image')->insertBatch([
            ['public_id' => $this->fixturePublicId(), 'artifact_id' => $shirt, 'image_path' => 'https://img.test/b.webp', 'sort_order' => 1],
            ['public_id' => $this->fixturePublicId(), 'artifact_id' => $shirt, 'image_path' => 'https://img.test/a.webp', 'sort_order' => 0],
        ]);

        $data = $this->data($this->get('v1/mart/artifacts?business_slug=otter-cafe'));
        $this->assertSame(2, $data['total']);
        $card = $this->byId($data['artifacts'])[$shirtP];
        $this->assertSame(['active' => '199.00', 'compare' => '249.00', 'varies' => true], $card['price']);
        $this->assertSame('https://img.test/a.webp', $card['cover_url']);
        $this->assertNull($card['category']);

        $this->assertSame([$serviceP], array_column($this->data($this->get('v1/mart/artifacts?business_slug=otter-cafe&type=S'))['artifacts'], 'public_id'));
        $this->assertSame([$serviceP, $shirtP], array_column($this->data($this->get('v1/mart/artifacts?business_slug=otter-cafe&sort=price_asc'))['artifacts'], 'public_id'));

        $page = $this->data($this->get('v1/mart/artifacts?business_slug=otter-cafe&per_page=1&page=2&sort=price_desc'));
        $this->assertSame([$serviceP], array_column($page['artifacts'], 'public_id'));
        $this->assertSame(2, $page['pages']);

        // by public_id, in the order asked; unknown ones are left out
        $ids = $this->data($this->get('v1/mart/artifacts?business_slug=otter-cafe&ids=' . $serviceP . ',nosuchid,' . $shirtP));
        $this->assertSame([$serviceP, $shirtP], array_column($ids['artifacts'], 'public_id'));
    }

    public function testListFiltersAreChecked(): void
    {
        [, $categoryP] = $this->category('Hidden', 'I');

        $errors = $this->body($this->get('v1/mart/artifacts?business_slug=otter-cafe&type=X&sort=cheap&page=0&per_page=500'))['errors'];
        $this->assertEqualsCanonicalizing(['type', 'sort', 'page', 'per_page'], array_keys($errors));
        $this->get('v1/mart/artifacts?business_slug=otter-cafe&ids=' . implode(',', array_map(static fn ($i) => 'id' . $i, range(1, 49))))->assertStatus(422);
        $this->get('v1/mart/artifacts?business_slug=otter-cafe&category=' . $categoryP)->assertStatus(404); // inactive
        $this->get('v1/mart/artifacts?business_slug=otter-cafe&category=nosuchcat')->assertStatus(404);
    }

    public function testOneArtifactWithItsVariantsImagesAndReviews(): void
    {
        [$shirt, $shirtP] = $this->artifactRow($this->businessId);
        $this->db->table('artifact_master_translation')->insert(['artifact_id' => $shirt, 'language_code' => 'th', 'artifact_name' => 'เสื้อ', 'artifact_description' => 'ผ้าฝ้าย']);
        $sizes  = $this->attribute($this->businessId, 'Size', ['S', 'M', 'L']);
        $small  = $this->variant($shirt, '199.00', null, 'A', [$sizes['S']]);
        $large  = $this->variant($shirt, '249.00', null, 'A', [$sizes['L']]);
        $hidden = $this->variant($shirt, '299.00', null, 'I', [$sizes['M']]);
        [$storeId] = $this->store($this->businessId, 'Riverside');
        $this->db->table('artifact_variant_inventory')->insert(['variant_id' => $this->variantId($small), 'store_id' => $storeId, 'quantity' => 4]);
        $this->db->table('artifact_image')->insertBatch([
            ['public_id' => 'img000000001', 'artifact_id' => $shirt, 'variant_id' => null, 'image_path' => 'https://img.test/1.webp', 'sort_order' => 0],
            ['public_id' => 'img000000002', 'artifact_id' => $shirt, 'variant_id' => $this->variantId($hidden), 'image_path' => 'https://img.test/2.webp', 'sort_order' => 1],
        ]);
        $this->review($shirt, 'Somchai Jaidee', 'A');
        $this->review($shirt, 'Pending Person', 'P');

        $data = $this->data($this->get('v1/mart/artifact?business_slug=otter-cafe&lang=th&public_id=' . $shirtP));
        $this->assertSame('เสื้อ', $data['artifact']['name']);
        $this->assertSame('ผ้าฝ้าย', $data['artifact']['description']);
        $this->assertArrayHasKey('breakdown', $data['artifact']['rating']);
        $this->assertSame([$small, $large], array_column($data['variants'], 'public_id'));
        $this->assertSame(4, $data['variants'][0]['stock']);
        $this->assertNull($data['variants'][1]['stock']); // no store keeps count of it
        $this->assertSame('Size', $data['options'][0]['name']);
        $this->assertSame(['S', 'L'], array_column($data['options'][0]['values'], 'value')); // M is only on the inactive one
        $this->assertSame(['img000000001'], array_column($data['images'], 'public_id'));
        $this->assertCount(1, $data['reviews']);
        $this->assertSame('Somchai J.', $data['reviews'][0]['reviewer_name']);

        $this->db->table('artifact_master')->where('id', $shirt)->update(['artifact_status' => 'I']);
        $this->send('v1/client/website/save', ['business_slug' => 'otter-cafe', 'colors' => $this->colors()]); // clears the cache
        $this->get('v1/mart/artifact?business_slug=otter-cafe&public_id=' . $shirtP)->assertStatus(404);
    }

    public function testAnswersAreCachedUntilTheClientAppChangesTheBusiness(): void
    {
        $this->assertSame('#ff7533', $this->data($this->get('v1/mart/site?business_slug=otter-cafe'))['theme']['colors']['accent_color']);

        // a change behind the app's back is not seen until the answer expires
        $this->db->table('business_master')->where('id', $this->businessId)->update(['business_name' => 'Changed Directly']);
        $this->assertSame('otter-cafe', $this->data($this->get('v1/mart/site?business_slug=otter-cafe'))['business']['name']);

        // one through the Client app is, at once
        $this->send('v1/client/website/save', ['business_slug' => 'otter-cafe', 'colors' => ['accent_color' => '#123456'] + $this->colors()])->assertStatus(200);
        $data = $this->data($this->get('v1/mart/site?business_slug=otter-cafe'));
        $this->assertSame('#123456', $data['theme']['colors']['accent_color']);
        $this->assertSame('Changed Directly', $data['business']['name']);

        // a failed change clears nothing
        $this->db->table('business_master')->where('id', $this->businessId)->update(['business_name' => 'Again']);
        $this->send('v1/client/website/save', ['business_slug' => 'otter-cafe', 'colors' => ['accent_color' => 'red'] + $this->colors()])->assertStatus(422);
        $this->assertSame('Changed Directly', $this->data($this->get('v1/mart/site?business_slug=otter-cafe'))['business']['name']);
    }

    // ---- helpers ----

    private function data(TestResponse $response): array
    {
        $response->assertStatus(200);

        return $this->body($response)['data'];
    }

    /** @return array<string, array> */
    private function byId(array $cards): array
    {
        return array_column($cards, null, 'public_id');
    }

    private function colors(): array
    {
        return ['background_color' => '#ffffff', 'default_color' => '#5a6577', 'heading_color' => '#10243d',
            'accent_color' => '#ff7533', 'surface_color' => '#ffffff', 'contrast_color' => '#ffffff'];
    }

    private function language(string $code, array $values): array
    {
        return $values + ['language_code' => $code, 'heading_font' => 'Montserrat', 'nav_font' => 'Montserrat', 'default_font' => 'Montserrat'];
    }

    /** @return array{int, string} */
    private function category(string $name, string $status = 'A'): array
    {
        $publicId = $this->fixturePublicId();
        $this->db->table('artifact_category')->insert(['public_id' => $publicId, 'business_id' => $this->businessId, 'category_name' => $name, 'category_status' => $status]);

        return [(int) $this->db->insertID(), $publicId];
    }

    /**
     * @param list<string> $valuePublicIds artifact_attribute_value.public_id
     * @return string The variant's public_id
     */
    private function variant(int $artifactId, string $price, ?string $compare = null, string $status = 'A', array $valuePublicIds = []): string
    {
        $publicId = $this->fixturePublicId();
        $this->db->table('artifact_variant')->insert(['public_id' => $publicId, 'business_id' => $this->businessId, 'artifact_id' => $artifactId,
            'price_active' => $price, 'price_compare' => $compare, 'variant_status' => $status, 'sort_order' => $this->fixtureSeq]);
        $variantId = (int) $this->db->insertID();
        foreach ($valuePublicIds as $valuePublicId) {
            $value = $this->db->table('artifact_attribute_value')->where('public_id', $valuePublicId)->get()->getRowArray();
            $this->db->table('artifact_variant_attribute_value')->insert(['variant_id' => $variantId, 'attribute_id' => $value['attribute_id'], 'attribute_value_id' => $value['id']]);
        }

        return $publicId;
    }

    private function variantId(string $publicId): int
    {
        return (int) $this->db->table('artifact_variant')->where('public_id', $publicId)->get()->getRow()->id;
    }

    private function publicIdOf(int $artifactId): string
    {
        return $this->db->table('artifact_master')->where('id', $artifactId)->get()->getRow()->public_id;
    }

    private function review(int $artifactId, string $customerName, string $status): void
    {
        $this->db->table('customer_master')->insert(['public_id' => $this->fixturePublicId(), 'email_address' => $this->fixtureSeq . '@example.com', 'customer_name' => $customerName]);
        $this->db->table('artifact_review')->insert(['public_id' => $this->fixturePublicId(), 'artifact_id' => $artifactId, 'customer_id' => (int) $this->db->insertID(),
            'rating' => 5, 'review_title' => 'Lovely', 'review_message' => 'Soft and warm.', 'review_status' => $status]);
    }
}
