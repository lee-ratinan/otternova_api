<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * The categories and attributes of a business's artifacts: POST /client/artifact-categories,
 * /client/artifact-category/create and /edit, /client/artifact-attributes, /client/artifact-attribute/create and /edit
 *
 * @internal
 */
final class ArtifactCatalogTest extends CIUnitTestCase
{
    use ArtifactFixturesTrait;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private int $businessId;
    private string $ownerToken;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        $this->businessId = $this->business('otter-shop');
        $this->ownerToken = $this->createClient('owner@example.com', $this->businessId, 'O');
    }

    public function testCategoriesAreCreatedListedAndEdited(): void
    {
        $response = $this->send('v1/client/artifact-category/create', [
            'business_slug' => 'otter-shop',
            'category'      => ['category_name' => ' Drinks ', 'sort_order' => 2],
            'translations'  => ['th' => 'เครื่องดื่ม', 'ja' => ''],
        ]);
        $response->assertStatus(200);
        $drinks = $this->body($response)['data']['public_id'];
        $this->send('v1/client/artifact-category/create', ['business_slug' => 'otter-shop', 'category' => ['category_name' => 'Bakery']])->assertStatus(200);

        $list = $this->body($this->send('v1/client/artifact-categories', ['business_slug' => 'otter-shop']))['data']['categories'];
        $this->assertSame(['Bakery', 'Drinks'], array_column($list, 'category_name')); // by sort_order: 0, 2
        $this->assertSame(['public_id' => $drinks, 'category_name' => 'Drinks', 'category_status' => 'A', 'sort_order' => 2, 'artifact_count' => 0, 'translations' => ['th' => 'เครื่องดื่ม']], $list[1]);

        // a field left out keeps its value; translations given replace the names
        $this->send('v1/client/artifact-category/edit', ['public_id' => $drinks, 'category' => ['category_status' => 'i'], 'translations' => ['ja' => '飲み物']])->assertStatus(200);
        $this->seeInDatabase('artifact_category', ['public_id' => $drinks, 'category_name' => 'Drinks', 'category_status' => 'I', 'sort_order' => 2]);
        $this->seeInDatabase('artifact_category_translation', ['language_code' => 'ja', 'category_name' => '飲み物']);
        $this->dontSeeInDatabase('artifact_category_translation', ['language_code' => 'th']);
        // left out altogether, they stay
        $this->send('v1/client/artifact-category/edit', ['public_id' => $drinks, 'category' => ['category_name' => 'Beverages']])->assertStatus(200);
        $this->seeInDatabase('artifact_category_translation', ['language_code' => 'ja', 'category_name' => '飲み物']);
        $this->seeInDatabase('log_activity', ['table_involved' => 'artifact_category']);
    }

    public function testCategoryFieldsAreChecked(): void
    {
        $this->send('v1/client/artifact-category/create', ['business_slug' => 'otter-shop', 'category' => ['category_name' => 'Drinks']])->assertStatus(200);

        foreach ([
            [['category_name' => 'Drinks'], [], 'category_name'],
            [['category_name' => ''], [], 'category_name'],
            [['category_name' => 'Tea', 'category_status' => 'X'], [], 'category_status'],
            [['category_name' => 'Tea', 'sort_order' => -1], [], 'sort_order'],
            [['category_name' => 'Tea'], ['xx' => 'Tea'], 'translations.xx'],
        ] as [$category, $translations, $field]) {
            $response = $this->send('v1/client/artifact-category/create', ['business_slug' => 'otter-shop', 'category' => $category, 'translations' => $translations]);
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $this->body($response)['errors'], $field);
        }
        $this->seeNumRecords(1, 'artifact_category', []);

        // the same name in another business is fine
        $other      = $this->business('beaver-shop');
        $otherOwner = $this->createClient('other@example.com', $other, 'O');
        $this->send('v1/client/artifact-category/create', ['business_slug' => 'beaver-shop', 'category' => ['category_name' => 'Drinks']], $otherOwner)->assertStatus(200);
    }

    public function testAttributesAreSavedWithTheirValues(): void
    {
        $response = $this->send('v1/client/artifact-attribute/create', [
            'business_slug' => 'otter-shop',
            'attribute'     => ['attribute_name' => 'Color'],
            'translations'  => ['th' => 'สี'],
            'values'        => [
                ['attribute_value' => 'Blue', 'translations' => ['th' => 'น้ำเงิน']],
                ['attribute_value' => 'Red'],
            ],
        ]);
        $response->assertStatus(200);
        $color = $this->body($response)['data']['attribute'];
        $this->assertSame('Color', $color['attribute_name']);
        $this->assertSame(['th' => 'สี'], $color['translations']);
        $this->assertSame(['Blue', 'Red'], array_column($color['values'], 'attribute_value'));
        $this->assertSame([0, 1], array_column($color['values'], 'sort_order'));
        $this->assertSame(['th' => 'น้ำเงิน'], $color['values'][0]['translations']);
        [$blue, $red] = array_column($color['values'], 'public_id');

        // update a value by its public_id, add one; the value left out stays
        $response = $this->send('v1/client/artifact-attribute/edit', [
            'public_id' => $color['public_id'],
            'values'    => [
                ['public_id' => $red, 'attribute_value' => 'Crimson', 'sort_order' => 5],
                ['attribute_value' => 'Green', 'sort_order' => 1],
            ],
        ]);
        $response->assertStatus(200);
        $values = $this->body($response)['data']['attribute']['values'];
        $this->assertSame(['Blue', 'Green', 'Crimson'], array_column($values, 'attribute_value'));
        $this->assertSame($red, $values[2]['public_id']);
        $this->assertSame(['th' => 'น้ำเงิน'], $values[0]['translations']); // untouched
        $this->assertSame(['th' => 'สี'], $this->body($response)['data']['attribute']['translations']);

        $list = $this->body($this->send('v1/client/artifact-attributes', ['business_slug' => 'otter-shop']))['data']['attributes'];
        $this->assertCount(1, $list);
        $this->assertCount(3, $list[0]['values']);

        // values are checked against each other and the stored ones
        foreach ([
            [['attribute_value' => 'blue'], 'values.0.attribute_value'],
            [['public_id' => 'nope', 'attribute_value' => 'Pink'], 'values.0.public_id'],
            [['attribute_value' => ''], 'values.0.attribute_value'],
            [['attribute_value' => 'Pink', 'sort_order' => 70000], 'values.0.sort_order'],
        ] as [$value, $field]) {
            $response = $this->send('v1/client/artifact-attribute/edit', ['public_id' => $color['public_id'], 'values' => [$value]]);
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $this->body($response)['errors'], $field);
        }
        $response = $this->send('v1/client/artifact-attribute/edit', ['public_id' => $color['public_id'], 'values' => [['public_id' => $blue], ['public_id' => $blue]]]);
        $this->assertArrayHasKey('values.1.public_id', $this->body($response)['errors']);
        $response = $this->send('v1/client/artifact-attribute/create', ['business_slug' => 'otter-shop', 'attribute' => ['attribute_name' => 'Color']]);
        $this->assertArrayHasKey('attribute_name', $this->body($response)['errors']);
        $this->seeNumRecords(3, 'artifact_attribute_value', []);
    }

    public function testOnlyActiveOwnersAndManagersGetIn(): void
    {
        $category  = $this->body($this->send('v1/client/artifact-category/create', ['business_slug' => 'otter-shop', 'category' => ['category_name' => 'Drinks']]))['data']['public_id'];
        $attribute = $this->body($this->send('v1/client/artifact-attribute/create', ['business_slug' => 'otter-shop', 'attribute' => ['attribute_name' => 'Size']]))['data']['attribute']['public_id'];

        $manager = $this->createClient('manager@example.com', $this->businessId, 'M');
        $this->send('v1/client/artifact-categories', ['business_slug' => 'otter-shop'], $manager)->assertStatus(200);
        $this->send('v1/client/artifact-attribute/edit', ['public_id' => $attribute, 'attribute' => ['attribute_name' => 'Sizes']], $manager)->assertStatus(200);

        $employee = $this->createClient('employee@example.com', $this->businessId, 'E');
        $outsider = $this->createClient('outsider@example.com', null, null);
        foreach ([[$employee, 403], [$outsider, 404]] as [$token, $status]) {
            $this->send('v1/client/artifact-categories', ['business_slug' => 'otter-shop'], $token)->assertStatus($status);
            $this->send('v1/client/artifact-category/create', ['business_slug' => 'otter-shop', 'category' => ['category_name' => 'Tea']], $token)->assertStatus($status);
            $this->send('v1/client/artifact-category/edit', ['public_id' => $category, 'category' => ['category_name' => 'Tea']], $token)->assertStatus($status);
            $this->send('v1/client/artifact-attributes', ['business_slug' => 'otter-shop'], $token)->assertStatus($status);
            $this->send('v1/client/artifact-attribute/create', ['business_slug' => 'otter-shop', 'attribute' => ['attribute_name' => 'Color']], $token)->assertStatus($status);
            $this->send('v1/client/artifact-attribute/edit', ['public_id' => $attribute, 'attribute' => ['attribute_name' => 'Color']], $token)->assertStatus($status);
        }
        $this->send('v1/client/artifact-category/edit', ['public_id' => 'nope'])->assertStatus(404);
        $this->send('v1/client/artifact-category/edit', [])->assertStatus(400);
        $this->seeNumRecords(1, 'artifact_category', []);
        $this->seeNumRecords(1, 'artifact_attribute', []);
    }
}
