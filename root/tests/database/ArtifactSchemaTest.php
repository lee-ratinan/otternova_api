<?php

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The guards the artifact tables keep themselves: a variant stays in its artifact's business and has a unique sku
 * there, a variant has one value per attribute and only values of that attribute, an image's variant is of its
 * artifact, and a customer reviews an artifact once
 *
 * @internal
 */
final class ArtifactSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private int $businessId;
    private int $otherBusinessId;
    private int $artifactId;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('business_type')->insert(['public_id' => 'typ1', 'type_code' => 'shop']);
        $typeId                = (int) $this->db->insertID();
        $this->businessId      = $this->business($typeId, 'otter-shop');
        $this->otherBusinessId = $this->business($typeId, 'beaver-shop');
        $this->artifactId      = $this->artifact($this->businessId);
    }

    public function testNewArtifactsAndReviewsStartWithTheirDefaults(): void
    {
        $row = $this->db->table('artifact_master')->where('id', $this->artifactId)->get()->getRowArray();
        $this->assertSame('I', $row['artifact_status']);
        $this->assertNull($row['artifact_label']);
        $this->assertSame(0, (int) $row['rating_sum']);
        $this->assertSame(0, (int) $row['rating_count']);
        $this->assertSame(0.0, (float) $row['rating_average']);

        $this->db->table('artifact_master')->where('id', $this->artifactId)->update(['artifact_label' => 'L']);
        $this->seeInDatabase('artifact_master', ['id' => $this->artifactId, 'artifact_label' => 'L']);

        $this->review($this->customer('a@example.com'));
        $this->seeInDatabase('artifact_review', ['artifact_id' => $this->artifactId, 'review_status' => 'P']);
    }

    public function testAVariantStaysInItsArtifactsBusiness(): void
    {
        $this->variant($this->businessId, $this->artifactId, 'TEE-BLUE-L');

        $this->assertRefused(fn () => $this->variant($this->otherBusinessId, $this->artifactId, 'TEE-RED-L'));
    }

    public function testASkuIsUniqueWithinItsBusinessOnly(): void
    {
        $this->variant($this->businessId, $this->artifactId, 'TEE-BLUE-L');
        $this->assertRefused(fn () => $this->variant($this->businessId, $this->artifactId, 'TEE-BLUE-L'));

        // another business may use the same sku, and variants without one do not clash
        $this->variant($this->otherBusinessId, $this->artifact($this->otherBusinessId), 'TEE-BLUE-L');
        $this->variant($this->businessId, $this->artifactId, null);
        $this->variant($this->businessId, $this->artifactId, null);

        $this->seeNumRecords(3, 'artifact_variant', ['business_id' => $this->businessId]);
    }

    public function testAVariantHasOneValueOfEachAttributeAndOnlyItsOwnValues(): void
    {
        $color = $this->attribute('Color');
        $size  = $this->attribute('Size');
        $blue  = $this->value($color, 'Blue');
        $red   = $this->value($color, 'Red');
        $large = $this->value($size, 'L');

        $variant = $this->variant($this->businessId, $this->artifactId, 'TEE-BLUE-L');
        $this->link($variant, $color, $blue);
        $this->link($variant, $size, $large);

        // not both Blue and Red
        $this->assertRefused(fn () => $this->link($variant, $color, $red));
        // not L given as a color
        $other = $this->variant($this->businessId, $this->artifactId, 'TEE-X');
        $this->assertRefused(fn () => $this->link($other, $color, $large));
    }

    public function testAnImagesVariantIsOfItsArtifact(): void
    {
        $variant = $this->variant($this->businessId, $this->artifactId, 'TEE-BLUE-L');
        $this->image($this->artifactId, null);
        $this->image($this->artifactId, $variant);

        $otherArtifact = $this->artifact($this->businessId);
        $this->assertRefused(fn () => $this->image($otherArtifact, $variant));
    }

    public function testACustomerReviewsAnArtifactOnce(): void
    {
        $customer = $this->customer('a@example.com');
        $this->review($customer);

        $this->assertRefused(fn () => $this->review($customer));

        $this->review($this->customer('b@example.com'));
        $this->seeNumRecords(2, 'artifact_review', ['artifact_id' => $this->artifactId]);
    }

    public function testStockIsOneRowPerVariantPerStore(): void
    {
        $variant = $this->variant($this->businessId, $this->artifactId, 'TEE-BLUE-L');
        $this->db->table('business_store')->insert(['public_id' => $this->publicId(), 'business_id' => $this->businessId, 'store_name' => 'Bangkok', 'store_timezone' => 'Asia/Bangkok']);
        $store = (int) $this->db->insertID();

        $this->db->table('artifact_variant_inventory')->insert(['variant_id' => $variant, 'store_id' => $store, 'quantity' => 5]);
        $this->assertRefused(fn () => $this->db->table('artifact_variant_inventory')->insert(['variant_id' => $variant, 'store_id' => $store, 'quantity' => 1]));

        // oversold stock can go below zero
        $this->db->table('artifact_variant_inventory')->set('quantity', 'quantity - 7', false)->where(['variant_id' => $variant, 'store_id' => $store])->update();
        $this->seeInDatabase('artifact_variant_inventory', ['variant_id' => $variant, 'quantity' => -2]);
    }

    private function assertRefused(callable $insert): void
    {
        try {
            $insert();
            $this->fail('The database accepted a row it should refuse');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }
    }

    private function publicId(): string
    {
        return sprintf('pid%09d', ++$this->seq);
    }

    private function business(int $typeId, string $slug): int
    {
        $this->db->table('business_master')->insert([
            'business_type_id'      => $typeId,
            'business_name'         => $slug,
            'business_slug'         => $slug,
            'contact_email_address' => $slug . '@example.com',
            'country_code'          => 'TH',
            'currency_code'         => 'THB',
        ]);

        return (int) $this->db->insertID();
    }

    private function artifact(int $businessId): int
    {
        $this->db->table('artifact_master')->insert([
            'public_id'       => $this->publicId(),
            'business_id'     => $businessId,
            'artifact_name'   => 'T-Shirt',
            'artifact_type'   => 'P',
            'tax_calculation' => 'I',
            'tax_percentage'  => '7.00',
        ]);

        return (int) $this->db->insertID();
    }

    private function variant(int $businessId, int $artifactId, ?string $sku): int
    {
        $this->db->table('artifact_variant')->insert([
            'public_id'    => $this->publicId(),
            'business_id'  => $businessId,
            'artifact_id'  => $artifactId,
            'sku'          => $sku,
            'price_active' => '299.00',
        ]);

        return (int) $this->db->insertID();
    }

    private function attribute(string $name): int
    {
        $this->db->table('artifact_attribute')->insert(['public_id' => $this->publicId(), 'business_id' => $this->businessId, 'attribute_name' => $name]);

        return (int) $this->db->insertID();
    }

    private function value(int $attributeId, string $value): int
    {
        $this->db->table('artifact_attribute_value')->insert(['public_id' => $this->publicId(), 'attribute_id' => $attributeId, 'attribute_value' => $value]);

        return (int) $this->db->insertID();
    }

    private function link(int $variantId, int $attributeId, int $valueId): void
    {
        $this->db->table('artifact_variant_attribute_value')->insert(['variant_id' => $variantId, 'attribute_id' => $attributeId, 'attribute_value_id' => $valueId]);
    }

    private function image(int $artifactId, ?int $variantId): void
    {
        $this->db->table('artifact_image')->insert([
            'public_id'   => $this->publicId(),
            'artifact_id' => $artifactId,
            'variant_id'  => $variantId,
            'image_path'  => 'https://example.com/image/artifact-image/' . $this->seq . '.webp',
        ]);
    }

    private function customer(string $email): int
    {
        $this->db->table('customer_master')->insert(['public_id' => $this->publicId(), 'email_address' => $email, 'customer_name' => 'Otter']);

        return (int) $this->db->insertID();
    }

    private function review(int $customerId): void
    {
        $this->db->table('artifact_review')->insert([
            'public_id'      => $this->publicId(),
            'artifact_id'    => $this->artifactId,
            'customer_id'    => $customerId,
            'rating'         => 5,
            'review_title'   => 'Great',
            'review_message' => 'Soft and fits well.',
        ]);
    }
}
