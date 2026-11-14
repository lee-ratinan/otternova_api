<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\PlanLimits;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * The products and services of a business: POST /client/artifacts, /client/artifact, /client/artifact/create and
 * /client/artifact/edit
 *
 * @internal
 */
final class ArtifactsTest extends CIUnitTestCase
{
    use ArtifactFixturesTrait;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private int $businessId;
    private string $ownerToken;
    private string $storeA;
    private string $storeB;
    private array $color;
    private array $size;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        // room for the tests that are not about the cap
        config(PlanLimits::class)->plans['free'][PlanLimits::PRODUCTS] = 10;
        config(PlanLimits::class)->plans['free'][PlanLimits::SERVICES] = 10;
        config(PlanLimits::class)->plans['free'][PlanLimits::VARIANTS] = 100;

        $this->businessId = $this->business('otter-shop');
        $this->ownerToken = $this->createClient('owner@example.com', $this->businessId, 'O');
        [, $this->storeA] = $this->store($this->businessId, 'Siam');
        [, $this->storeB] = $this->store($this->businessId, 'Online');
        $this->color      = $this->attribute($this->businessId, 'Color', ['Blue', 'Red']);
        $this->size       = $this->attribute($this->businessId, 'Size', ['S', 'M', 'L']);
    }

    public function testAProductIsCreatedWithItsVariantsAndStock(): void
    {
        $response = $this->create([
            'artifact'     => ['artifact_name' => 'T-Shirt', 'artifact_type' => 'p', 'artifact_label' => 'n', 'tax_calculation' => 'I', 'tax_percentage' => 7],
            'translations' => ['th' => ['artifact_name' => 'เสื้อยืด', 'artifact_description' => 'ผ้าฝ้าย']],
            'variants'     => [
                ['sku' => 'TS-BLUE', 'barcode' => '885000', 'price_active' => '299.5', 'price_compare' => 399, 'weight_grams' => 200, 'duration_minutes' => 30,
                    'attribute_values' => [$this->color['Blue']], 'inventory' => [['store_public_id' => $this->storeA, 'quantity' => 12], ['store_public_id' => $this->storeB, 'quantity' => -1]]],
                ['sku' => 'TS-RED', 'price_active' => 279, 'attribute_values' => [$this->color['Red']]],
            ],
        ]);
        $response->assertStatus(200);
        $data     = $this->body($response)['data'];
        $artifact = $data['artifact'];

        $this->assertSame('P', $artifact['artifact_type']);
        $this->assertSame('I', $artifact['artifact_status']); // inactive until made active
        $this->assertSame('N', $artifact['artifact_label']);
        $this->assertSame('7.00', $artifact['tax_percentage']);
        $this->assertSame(['th' => ['artifact_name' => 'เสื้อยืด', 'artifact_description' => 'ผ้าฝ้าย']], $artifact['translations']);
        // the cheapest active variant's prices
        $this->assertSame('279.00', $artifact['price_active_lowest']);
        $this->assertNull($artifact['price_compare_lowest']);

        $this->assertCount(2, $data['variants']);
        $blue = $data['variants'][0];
        $this->assertSame(['TS-BLUE', '885000', '299.50', '399.00', 200, null, 0], [$blue['sku'], $blue['barcode'], $blue['price_active'], $blue['price_compare'], $blue['weight_grams'], $blue['duration_minutes'], $blue['sort_order']]);
        $this->assertSame([['attribute_public_id' => $this->attributeOf($this->color['Blue']), 'value_public_id' => $this->color['Blue']]], $blue['attribute_values']);
        $this->assertSame([['store_public_id' => $this->storeB, 'quantity' => -1], ['store_public_id' => $this->storeA, 'quantity' => 12]], $blue['inventory']); // by store name
        $this->assertSame([], $data['images']);

        $this->seeNumRecords(2, 'artifact_variant', ['business_id' => $this->businessId]);
        $this->seeNumRecords(2, 'artifact_variant_inventory', []);
        $this->seeInDatabase('log_activity', ['table_involved' => 'artifact_variant_inventory']);

        // show() and list() give it back
        $shown = $this->body($this->send('v1/client/artifact', ['public_id' => $artifact['public_id']]))['data'];
        $this->assertSame($data, array_diff_key($shown, ['plan_code' => true, 'caps' => true]));
        $list = $this->body($this->send('v1/client/artifacts', ['business_slug' => 'otter-shop']))['data']['artifacts'];
        $this->assertSame([[
            'public_id'            => $artifact['public_id'],
            'artifact_name'        => 'T-Shirt',
            'artifact_type'        => 'P',
            'artifact_status'      => 'I',
            'artifact_label'       => 'N',
            'category_public_id'   => null,
            'price_active_lowest'  => '279.00',
            'price_compare_lowest' => null,
            'rating_count'         => 0,
            'rating_average'       => '0.00',
            'variant_count'        => 2,
            'rating_breakdown'     => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
            'cover_url'            => null,
        ]], $list);
    }

    public function testAServiceKeepsOnlyWhatServicesUse(): void
    {
        $response = $this->create([
            'artifact' => ['artifact_name' => 'Massage', 'artifact_type' => 'S', 'artifact_status' => 'A', 'tax_calculation' => 'X', 'tax_percentage' => 7],
            'variants' => [['price_active' => 500, 'barcode' => '123', 'weight_grams' => 10, 'duration_minutes' => 60]],
        ]);
        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertNull($data['artifact']['tax_percentage']); // no tax, no rate
        $this->assertSame([null, null, 60], [$data['variants'][0]['barcode'], $data['variants'][0]['weight_grams'], $data['variants'][0]['duration_minutes']]);

        // a service has no stock
        $response = $this->create([
            'artifact' => ['artifact_name' => 'Facial', 'artifact_type' => 'S', 'tax_calculation' => 'X'],
            'variants' => [['price_active' => 500, 'inventory' => [['store_public_id' => $this->storeA, 'quantity' => 1]]]],
        ]);
        $response->assertStatus(422);
        $this->assertArrayHasKey('variants.0.inventory', $this->body($response)['errors']);
    }

    public function testAServiceVariantIsBookedAsAnAppointmentOrAGroup(): void
    {
        $service = ['artifact_name' => 'Muay Thai', 'artifact_type' => 'S', 'tax_calculation' => 'X'];
        $response = $this->create(['artifact' => $service, 'variants' => [
            ['price_active' => 1500, 'duration_minutes' => 60, 'capacity' => 2, 'attribute_values' => [$this->color['Blue']]], // an appointment by default, for 2 customers
            ['price_active' => 800, 'duration_minutes' => 60, 'booking_type' => 'g', 'staff_required' => 2, 'capacity' => 12, 'attribute_values' => [$this->color['Red']]],
        ]]);
        $response->assertStatus(200);
        $data     = $this->body($response)['data'];
        $variants = array_map(static fn (array $v): array => [$v['booking_type'], $v['staff_required'], $v['capacity']], $data['variants']);
        $this->assertSame([['A', 1, 2], ['G', 2, 12]], $variants);

        // turned into an appointment, a group variant's capacity is the customers of one booking
        $group = $data['variants'][1]['public_id'];
        $this->edit(['public_id' => $data['artifact']['public_id'], 'variants' => [
            ['public_id' => $data['variants'][0]['public_id']],
            ['public_id' => $group, 'booking_type' => 'A'],
        ]])->assertStatus(200);
        $this->seeInDatabase('artifact_variant', ['public_id' => $group, 'booking_type' => 'A', 'staff_required' => 2, 'capacity' => 12]);

        foreach ([
            [['price_active' => 1, 'duration_minutes' => 60, 'booking_type' => 'X'], 'variants.0.booking_type'],
            [['price_active' => 1, 'duration_minutes' => 60, 'booking_type' => 'G'], 'variants.0.capacity'],
            [['price_active' => 1, 'duration_minutes' => 60, 'booking_type' => 'G', 'capacity' => 0], 'variants.0.capacity'],
            [['price_active' => 1, 'duration_minutes' => 60, 'staff_required' => 0], 'variants.0.staff_required'],
            [['price_active' => 1, 'duration_minutes' => 60, 'staff_required' => 256], 'variants.0.staff_required'],
            [['price_active' => 1], 'variants.0.duration_minutes'], // a service's length is required
        ] as $n => [$variant, $field]) {
            $response = $this->create(['artifact' => $service, 'variants' => [$variant]]);
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $this->body($response)['errors'] ?? [], $n . ': ' . $field);
        }

        // a product has none of them
        $response = $this->create(['artifact' => ['artifact_type' => 'P'] + $service, 'variants' => [['price_active' => 1, 'booking_type' => 'G', 'staff_required' => 3]]]);
        $response->assertStatus(200);
        $variant = $this->body($response)['data']['variants'][0];
        $this->assertSame([null, null, null], [$variant['booking_type'], $variant['staff_required'], $variant['capacity']]);
    }

    public function testAServiceVariantMayHaveItsOwnBookingWindow(): void
    {
        $service = ['artifact_name' => 'Facial', 'artifact_type' => 'S', 'tax_calculation' => 'X'];
        $window  = static fn (array $v): array => [$v['booking_horizon_days'], $v['booking_notice_unit'], $v['booking_notice_value'], $v['booking_notice_time']];
        $response = $this->create(['artifact' => $service, 'variants' => [
            ['price_active' => 1500, 'duration_minutes' => 60, 'attribute_values' => [$this->color['Blue']]], // the business's
            ['price_active' => 2500, 'duration_minutes' => 60, 'attribute_values' => [$this->color['Red']],
                'booking_horizon_days' => 14, 'booking_notice_unit' => 'd', 'booking_notice_value' => '1', 'booking_notice_time' => '18:00'],
        ]]);
        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame([[null, null, null, null], [14, 'D', 1, '18:00']], array_map($window, $data['variants']));

        // left out keeps it; all null goes back to the business's
        $own = $data['variants'][1]['public_id'];
        $this->edit(['public_id' => $data['artifact']['public_id'], 'variants' => [['public_id' => $data['variants'][0]['public_id']], ['public_id' => $own]]])->assertStatus(200);
        $this->seeInDatabase('artifact_variant', ['public_id' => $own, 'booking_horizon_days' => 14, 'booking_notice_time' => '18:00:00']);
        $this->edit(['public_id' => $data['artifact']['public_id'], 'variants' => [['public_id' => $data['variants'][0]['public_id']],
            ['public_id' => $own, 'booking_horizon_days' => null, 'booking_notice_unit' => null, 'booking_notice_value' => null, 'booking_notice_time' => null]]])->assertStatus(200);
        $this->seeInDatabase('artifact_variant', ['public_id' => $own, 'booking_horizon_days' => null, 'booking_notice_unit' => null]);

        // all of it or none
        foreach ([
            [['booking_horizon_days' => 14], 'variants.0.booking_notice_unit'],
            [['booking_horizon_days' => 14, 'booking_notice_unit' => 'D', 'booking_notice_value' => 1], 'variants.0.booking_notice_time'],
            [['booking_horizon_days' => 2, 'booking_notice_unit' => 'M', 'booking_notice_value' => 3000], 'variants.0.booking_notice_value'],
        ] as $n => [$fields, $field]) {
            $response = $this->create(['artifact' => $service, 'variants' => [['price_active' => 1, 'duration_minutes' => 60] + $fields]]);
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $this->body($response)['errors'] ?? [], $n . ': ' . $field);
        }

        // a product has none
        $response = $this->create(['artifact' => ['artifact_type' => 'P'] + $service, 'variants' => [['price_active' => 1, 'booking_horizon_days' => 0]]]);
        $response->assertStatus(200);
        $this->assertSame([null, null, null, null], $window($this->body($response)['data']['variants'][0]));
    }

    public function testTheFieldsAreChecked(): void
    {
        $product = ['artifact_name' => 'T-Shirt', 'artifact_type' => 'P', 'tax_calculation' => 'X'];
        $other   = $this->business('beaver-shop');
        [, $otherStore] = $this->store($other, 'Elsewhere');
        $otherValue     = $this->attribute($other, 'Color', ['Green'])['Green'];

        foreach ([
            [['artifact_type' => 'Q'] + $product, [['price_active' => 1]], 'artifact_type'],
            [['artifact_name' => ''] + $product, [['price_active' => 1]], 'artifact_name'],
            [['artifact_status' => 'Z'] + $product, [['price_active' => 1]], 'artifact_status'],
            [['artifact_label' => 'Z'] + $product, [['price_active' => 1]], 'artifact_label'],
            [['tax_calculation' => 'E'] + $product, [['price_active' => 1]], 'tax_percentage'],
            [['tax_calculation' => 'E', 'tax_percentage' => 101] + $product, [['price_active' => 1]], 'tax_percentage'],
            [['category_public_id' => 'nope'] + $product, [['price_active' => 1]], 'category_public_id'],
            [$product, [], 'variants'],
            [$product, [['price_active' => '']], 'variants.0.price_active'],
            [$product, [['price_active' => '1.234']], 'variants.0.price_active'],
            [$product, [['price_active' => -1]], 'variants.0.price_active'],
            [$product, [['price_active' => 10, 'price_compare' => 10]], 'variants.0.price_compare'],
            [$product, [['price_active' => 10, 'variant_status' => 'X']], 'variants.0.variant_status'],
            [$product, [['price_active' => 10, 'weight_grams' => -5]], 'variants.0.weight_grams'],
            [$product, [['price_active' => 10, 'attribute_values' => [$otherValue]]], 'variants.0.attribute_values'],
            [$product, [['price_active' => 10, 'attribute_values' => [$this->color['Blue'], $this->color['Red']]]], 'variants.0.attribute_values'],
            [$product, [['price_active' => 10, 'inventory' => [['store_public_id' => $otherStore, 'quantity' => 1]]]], 'variants.0.inventory.0.store_public_id'],
            [$product, [['price_active' => 10, 'inventory' => [['store_public_id' => $this->storeA, 'quantity' => 1.5]]]], 'variants.0.inventory.0.quantity'],
            [$product, [['price_active' => 10, 'public_id' => 'nope']], 'variants.0.public_id'],
            // every variant uses the same attributes, with a different combination of values
            [$product, [['price_active' => 10, 'attribute_values' => [$this->color['Blue']]], ['price_active' => 10, 'attribute_values' => [$this->size['S']]]], 'variants'],
            [$product, [['price_active' => 10, 'attribute_values' => [$this->color['Blue']]], ['price_active' => 10, 'attribute_values' => [$this->color['Blue']]]], 'variants.1.attribute_values'],
            [$product, [['price_active' => 10, 'sku' => 'X1', 'attribute_values' => [$this->color['Blue']]], ['price_active' => 10, 'sku' => 'X1', 'attribute_values' => [$this->color['Red']]]], 'variants.1.sku'],
        ] as $n => [$artifact, $variants, $field]) {
            $response = $this->create(['artifact' => $artifact, 'variants' => $variants]);
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $this->body($response)['errors'] ?? [], $n . ': ' . $field);
        }
        $this->seeNumRecords(0, 'artifact_master', []);

        // a SKU of another artifact of the business is taken; of another business it is not
        $this->create(['artifact' => $product, 'variants' => [['price_active' => 10, 'sku' => 'X1']]])->assertStatus(200);
        $response = $this->create(['artifact' => $product, 'variants' => [['price_active' => 10, 'sku' => 'X1']]]);
        $this->assertArrayHasKey('variants.0.sku', $this->body($response)['errors']);
        $otherOwner = $this->createClient('other@example.com', $other, 'O');
        $this->send('v1/client/artifact/create', ['business_slug' => 'beaver-shop', 'artifact' => $product, 'variants' => [['price_active' => 10, 'sku' => 'X1']]], $otherOwner)->assertStatus(200);
    }

    public function testEditKeepsWhatIsLeftOut(): void
    {
        $category = $this->body($this->send('v1/client/artifact-category/create', ['business_slug' => 'otter-shop', 'category' => ['category_name' => 'Tops']]))['data']['public_id'];
        $data     = $this->body($this->create([
            'artifact'     => ['artifact_name' => 'T-Shirt', 'artifact_type' => 'P', 'tax_calculation' => 'X', 'category_public_id' => $category],
            'translations' => ['th' => ['artifact_name' => 'เสื้อยืด']],
            'variants'     => [
                ['price_active' => 300, 'price_compare' => 350, 'attribute_values' => [$this->color['Blue']], 'inventory' => [['store_public_id' => $this->storeA, 'quantity' => 5]]],
                ['price_active' => 200, 'attribute_values' => [$this->color['Red']]],
            ],
        ]))['data'];
        $publicId      = $data['artifact']['public_id'];
        [$blue, $red]  = array_column($data['variants'], 'public_id');
        $this->assertSame($category, $data['artifact']['category_public_id']);

        // the artifact's name only: the rest stays, the variants and the translations too
        $data = $this->body($this->edit(['public_id' => $publicId, 'artifact' => ['artifact_name' => 'Tee', 'artifact_status' => 'A']]))['data'];
        $this->assertSame(['Tee', 'A', $category], [$data['artifact']['artifact_name'], $data['artifact']['artifact_status'], $data['artifact']['category_public_id']]);
        $this->assertSame(['th' => ['artifact_name' => 'เสื้อยืด', 'artifact_description' => null]], $data['artifact']['translations']);
        $this->assertCount(2, $data['variants']);

        // taking the cheapest off sale moves the lowest prices to the next one
        $data = $this->body($this->edit(['public_id' => $publicId, 'variants' => [['public_id' => $red, 'variant_status' => 'I']]]))['data'];
        $this->assertSame(['300.00', '350.00'], [$data['artifact']['price_active_lowest'], $data['artifact']['price_compare_lowest']]);
        $this->assertSame('200.00', $data['variants'][1]['price_active']); // its price stays

        // a stock count at the store it has, and stock at a new store; a new variant
        $data = $this->body($this->edit(['public_id' => $publicId, 'variants' => [
            ['public_id' => $blue, 'inventory' => [['store_public_id' => $this->storeA, 'quantity' => 3], ['store_public_id' => $this->storeB, 'quantity' => 7]]],
            ['price_active' => 150, 'attribute_values' => [$this->color['Blue']]],
        ]]))['errors'] ?? null;
        $this->assertArrayHasKey('variants.1.attribute_values', $data); // Blue is taken by the stored one
        $this->seeNumRecords(1, 'artifact_variant_inventory', []); // nothing written

        $green = $this->attribute($this->businessId, 'Shade', ['Green'])['Green'];
        $response = $this->edit(['public_id' => $publicId, 'variants' => [
            ['public_id' => $blue, 'inventory' => [['store_public_id' => $this->storeA, 'quantity' => 3], ['store_public_id' => $this->storeB, 'quantity' => 7]]],
            ['price_active' => 150, 'attribute_values' => [$green]],
        ]]);
        $this->assertArrayHasKey('variants', $this->body($response)['errors']); // Shade is not the attribute the others use

        $data = $this->body($this->edit(['public_id' => $publicId, 'variants' => [
            ['public_id' => $blue, 'inventory' => [['store_public_id' => $this->storeA, 'quantity' => 3], ['store_public_id' => $this->storeB, 'quantity' => 7]]],
            ['price_active' => 150, 'attribute_values' => [$this->color['Red']]],
        ]]))['errors'] ?? null;
        $this->assertArrayHasKey('variants.1.attribute_values', $data); // Red is the inactive variant's, still taken

        // all at once: change the attribute of every variant, so the new one fits
        $data = $this->body($this->edit(['public_id' => $publicId, 'variants' => [
            ['public_id' => $blue, 'attribute_values' => [$this->size['S']], 'inventory' => [['store_public_id' => $this->storeA, 'quantity' => 3], ['store_public_id' => $this->storeB, 'quantity' => 7]]],
            ['public_id' => $red, 'attribute_values' => [$this->size['M']]],
            ['price_active' => 150, 'attribute_values' => [$this->size['M'], $this->color['Blue']]],
        ]]))['errors'] ?? null;
        $this->assertArrayHasKey('variants', $data);

        $response = $this->edit(['public_id' => $publicId, 'variants' => [
            ['public_id' => $blue, 'attribute_values' => [$this->size['S']], 'inventory' => [['store_public_id' => $this->storeA, 'quantity' => 3], ['store_public_id' => $this->storeB, 'quantity' => 7]]],
            ['public_id' => $red, 'attribute_values' => [$this->size['M']]],
        ]]);
        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame([['store_public_id' => $this->storeB, 'quantity' => 7], ['store_public_id' => $this->storeA, 'quantity' => 3]], $data['variants'][0]['inventory']);
        $this->assertSame($this->size['S'], $data['variants'][0]['attribute_values'][0]['value_public_id']);
        $this->seeNumRecords(2, 'artifact_variant_attribute_value', []);
        $this->seeInDatabase('log_activity', ['table_involved' => 'artifact_variant_attribute_value', 'activity_summary' => 'Took an attribute value from variant ' . $blue]);

        // another value of the same attribute is a change of the row
        $this->edit(['public_id' => $publicId, 'variants' => [['public_id' => $blue, 'attribute_values' => [$this->size['L']]]]])->assertStatus(200);
        $this->seeInDatabase('log_activity', ['table_involved' => 'artifact_variant_attribute_value', 'activity_summary' => 'Changed an attribute value of variant ' . $blue]);
        $this->seeNumRecords(2, 'artifact_variant_attribute_value', []);

        // the type never changes; a discontinued artifact stays so
        $this->assertArrayHasKey('artifact_type', $this->body($this->edit(['public_id' => $publicId, 'artifact' => ['artifact_type' => 'S']]))['errors']);
        $this->edit(['public_id' => $publicId, 'artifact' => ['artifact_status' => 'D']])->assertStatus(200);
        $this->assertArrayHasKey('artifact_status', $this->body($this->edit(['public_id' => $publicId, 'artifact' => ['artifact_status' => 'A']]))['errors']);
        $this->edit(['public_id' => $publicId, 'artifact' => ['category_public_id' => null]])->assertStatus(200);
        $this->seeInDatabase('artifact_master', ['public_id' => $publicId, 'category_id' => null, 'artifact_status' => 'D']);
    }

    public function testAServiceVariantDiffersByLengthAndBookingType(): void
    {
        $service = ['artifact_name' => 'Japanese Course', 'artifact_type' => 'S', 'tax_calculation' => 'X'];
        // no options needed: 60 and 90 minutes, each as an appointment and as a group
        $response = $this->create(['artifact' => $service, 'variants' => [
            ['price_active' => 900, 'duration_minutes' => 60],
            ['price_active' => 400, 'duration_minutes' => 60, 'booking_type' => 'G', 'capacity' => 8],
            ['price_active' => 1300, 'duration_minutes' => 90],
            ['price_active' => 600, 'duration_minutes' => 90, 'booking_type' => 'G', 'capacity' => 8],
        ]]);
        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertCount(4, $data['variants']);

        // the same length and booking type twice is refused, on the variant sent
        $response = $this->create(['artifact' => $service, 'variants' => [
            ['price_active' => 900, 'duration_minutes' => 60],
            ['price_active' => 950, 'duration_minutes' => 60],
        ]]);
        $response->assertStatus(422);
        $this->assertSame(lang('Artifact.serviceCombinationTaken'), $this->body($response)['errors']['variants.1.duration_minutes'] ?? null);

        // also against a stored variant left out of the edit
        $response = $this->edit(['public_id' => $data['artifact']['public_id'], 'variants' => [['price_active' => 1000, 'duration_minutes' => 90]]]);
        $response->assertStatus(422);
        $this->assertArrayHasKey('variants.0.duration_minutes', $this->body($response)['errors']);

        // with options, the same length and booking type is fine for different choices
        $this->create(['artifact' => $service, 'variants' => [
            ['price_active' => 900, 'duration_minutes' => 60, 'attribute_values' => [$this->color['Blue']]],
            ['price_active' => 900, 'duration_minutes' => 60, 'attribute_values' => [$this->color['Red']]],
        ]])->assertStatus(200);
    }

    public function testListFilters(): void
    {
        $this->create(['artifact' => ['artifact_name' => 'B Tee', 'artifact_type' => 'P', 'artifact_status' => 'A', 'tax_calculation' => 'X'], 'variants' => [['price_active' => 1]]])->assertStatus(200);
        $this->create(['artifact' => ['artifact_name' => 'A Massage', 'artifact_type' => 'S', 'tax_calculation' => 'X'], 'variants' => [['price_active' => 1, 'duration_minutes' => 60]]])->assertStatus(200);

        $names = fn (array $filters) => array_column($this->body($this->send('v1/client/artifacts', ['business_slug' => 'otter-shop', 'filters' => $filters]))['data']['artifacts'], 'artifact_name');
        $this->assertSame(['A Massage', 'B Tee'], $names([]));
        $this->assertSame(['B Tee'], $names(['artifact_type' => 'P']));
        $this->assertSame(['A Massage'], $names(['artifact_status' => 'I']));
        $this->assertSame([], $names(['category_public_id' => 'nope']));
    }

    public function testThePlanCapsEachType(): void
    {
        $product = ['artifact' => ['artifact_name' => 'Tee', 'artifact_type' => 'P', 'tax_calculation' => 'X'], 'variants' => [['price_active' => 1]]];
        config(PlanLimits::class)->plans['free'][PlanLimits::PRODUCTS] = 0;
        $response = $this->create($product);
        $response->assertStatus(409);
        $this->assertSame(lang('Artifact.notIncluded', ['Free', 'products']), $this->body($response)['message']);

        config(PlanLimits::class)->plans['free'][PlanLimits::PRODUCTS] = 1;
        $first = $this->body($this->create($product))['data']['artifact']['public_id'];
        $response = $this->create($product);
        $response->assertStatus(409);
        $this->assertSame(lang('Artifact.limitReached', [1, 'Free', 'products']), $this->body($response)['message']);
        // services are counted apart
        $this->create(['artifact' => ['artifact_name' => 'Massage', 'artifact_type' => 'S', 'tax_calculation' => 'X'], 'variants' => [['price_active' => 1, 'duration_minutes' => 60]]])->assertStatus(200);

        // a discontinued one makes room
        $this->edit(['public_id' => $first, 'artifact' => ['artifact_status' => 'D']])->assertStatus(200);
        $this->create($product)->assertStatus(200);
        $this->seeNumRecords(2, 'artifact_master', ['artifact_type' => 'P']);
    }

    public function testTheListAndTheArtifactTellTheCaps(): void
    {
        config(PlanLimits::class)->plans['free'][PlanLimits::PRODUCTS]           = 1;
        config(PlanLimits::class)->plans['free'][PlanLimits::SERVICES]           = 2;
        config(PlanLimits::class)->plans['free'][PlanLimits::VARIANTS]           = 2;
        config(PlanLimits::class)->plans['free'][PlanLimits::IMAGE_PER_ARTIFACT] = 3;
        $publicId = $this->body($this->create(['artifact' => ['artifact_name' => 'Tee', 'artifact_type' => 'P', 'tax_calculation' => 'X'], 'variants' => [['price_active' => 1]]]))['data']['artifact']['public_id'];

        $data = $this->body($this->send('v1/client/artifacts', ['business_slug' => 'otter-shop', 'filters' => ['artifact_type' => 'S']]))['data'];
        $this->assertSame('free', $data['plan_code']);
        // counted whatever the filters
        $this->assertSame(['P' => ['max' => 1, 'count' => 1, 'can_add' => false], 'S' => ['max' => 2, 'count' => 0, 'can_add' => true]], $data['caps']);
        $this->assertSame(['variants' => 2, 'images' => 3], $data['per_artifact']);

        $data = $this->body($this->send('v1/client/artifact', ['public_id' => $publicId]))['data'];
        $this->assertSame('free', $data['plan_code']);
        $this->assertSame(['images' => ['max' => 3, 'count' => 0, 'can_add' => true], 'variants' => ['max' => 2, 'count' => 1, 'can_add' => true]], $data['caps']);

        // a discontinued one is not counted
        $this->edit(['public_id' => $publicId, 'artifact' => ['artifact_status' => 'D']])->assertStatus(200);
        $data = $this->body($this->send('v1/client/artifacts', ['business_slug' => 'otter-shop']))['data'];
        $this->assertSame(['max' => 1, 'count' => 0, 'can_add' => true], $data['caps']['P']);
    }

    public function testThePlanCapsTheVariantsOfAnArtifact(): void
    {
        config(PlanLimits::class)->plans['free'][PlanLimits::VARIANTS] = 2;
        $response = $this->create(['artifact' => ['artifact_name' => 'Tee', 'artifact_type' => 'P', 'tax_calculation' => 'X'],
            'variants' => [['price_active' => 1, 'attribute_values' => [$this->size['S']]], ['price_active' => 1, 'attribute_values' => [$this->size['M']]], ['price_active' => 1, 'attribute_values' => [$this->size['L']]]]]);
        $response->assertStatus(409);
        $this->assertSame(lang('Artifact.variantLimitReached', [2, 'Free']), $this->body($response)['message']);
        $this->seeNumRecords(0, 'artifact_master', []);

        $detail = $this->body($this->create(['artifact' => ['artifact_name' => 'Tee', 'artifact_type' => 'P', 'tax_calculation' => 'X'],
            'variants' => [['price_active' => 1, 'attribute_values' => [$this->size['S']]], ['price_active' => 1, 'attribute_values' => [$this->size['M']]]]]))['data'];
        $publicId = $detail['artifact']['public_id'];
        $this->edit(['public_id' => $publicId, 'variants' => [['price_active' => 1, 'attribute_values' => [$this->size['L']]]]])->assertStatus(409);
        $this->seeNumRecords(2, 'artifact_variant', []);

        // one over the cap after moving to a smaller plan keeps its variants and can still change them
        config(PlanLimits::class)->plans['free'][PlanLimits::VARIANTS] = 1;
        $this->edit(['public_id' => $publicId, 'variants' => [['public_id' => $detail['variants'][0]['public_id'], 'price_active' => 5]]])->assertStatus(200);
        $this->edit(['public_id' => $publicId, 'variants' => [['price_active' => 1, 'attribute_values' => [$this->size['L']]]]])->assertStatus(409);
    }

    public function testOnlyActiveOwnersAndManagersGetIn(): void
    {
        $product  = ['artifact' => ['artifact_name' => 'Tee', 'artifact_type' => 'P', 'tax_calculation' => 'X'], 'variants' => [['price_active' => 1]]];
        $publicId = $this->body($this->create($product))['data']['artifact']['public_id'];

        $manager = $this->createClient('manager@example.com', $this->businessId, 'M');
        $this->send('v1/client/artifact/edit', ['public_id' => $publicId, 'artifact' => ['artifact_name' => 'Tee 2']], $manager)->assertStatus(200);

        $employee = $this->createClient('employee@example.com', $this->businessId, 'E');
        $former   = $this->createClient('former@example.com', $this->businessId, 'M', 'T');
        foreach ([[$employee, 403], [$former, 404]] as [$token, $status]) {
            $this->send('v1/client/artifacts', ['business_slug' => 'otter-shop'], $token)->assertStatus($status);
            $this->send('v1/client/artifact', ['public_id' => $publicId], $token)->assertStatus($status);
            $this->send('v1/client/artifact/create', ['business_slug' => 'otter-shop'] + $product, $token)->assertStatus($status);
            $this->send('v1/client/artifact/edit', ['public_id' => $publicId, 'artifact' => ['artifact_name' => 'X']], $token)->assertStatus($status);
        }
        $this->send('v1/client/artifact', ['public_id' => 'nope'])->assertStatus(404);
        $this->send('v1/client/artifact', [])->assertStatus(400);
        $this->seeInDatabase('artifact_master', ['public_id' => $publicId, 'artifact_name' => 'Tee 2']);
    }

    private function create(array $body)
    {
        return $this->send('v1/client/artifact/create', ['business_slug' => 'otter-shop'] + $body);
    }

    private function edit(array $body)
    {
        return $this->send('v1/client/artifact/edit', $body);
    }

    private function attributeOf(string $valuePublicId): string
    {
        return $this->db->table('artifact_attribute_value v')->select('a.public_id')->join('artifact_attribute a', 'a.id = v.attribute_id')
            ->where('v.public_id', $valuePublicId)->get()->getRow()->public_id;
    }
}
