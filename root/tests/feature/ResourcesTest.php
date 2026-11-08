<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * The resources of a business and what its services need: POST /client/resource-types, /client/resource-type/create
 * and /edit, /client/resources, /client/resource/create and /edit, /client/artifact/resources and its /save
 *
 * @internal
 */
final class ResourcesTest extends CIUnitTestCase
{
    use ArtifactFixturesTrait;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private int $businessId;
    private string $ownerToken;
    private string $storePublicId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        $this->businessId    = $this->business('otter-spa');
        $this->ownerToken    = $this->createClient('owner@example.com', $this->businessId, 'O');
        $this->storePublicId = $this->physicalStore($this->businessId, 'Bangkok');
    }

    public function testTypesAreCreatedListedAndEdited(): void
    {
        $response = $this->send('v1/client/resource-type/create', [
            'business_slug' => 'otter-spa',
            'resource_type' => ['type_name' => ' Massage room ', 'buffer_minutes' => 15, 'type_description' => ''],
            'translations'  => ['th' => ['type_name' => 'ห้องนวด']],
        ]);
        $response->assertStatus(200);
        $room = $this->body($response)['data']['public_id'];
        $this->send('v1/client/resource-type/create', ['business_slug' => 'otter-spa', 'resource_type' => ['type_name' => 'Hot-stone kit']])->assertStatus(200);

        $list = $this->body($this->send('v1/client/resource-types', ['business_slug' => 'otter-spa']))['data']['resource_types'];
        $this->assertSame(['Hot-stone kit', 'Massage room'], array_column($list, 'type_name'));
        $this->assertSame([
            'public_id'             => $room,
            'type_name'             => 'Massage room',
            'type_description'      => null,
            'buffer_minutes'        => 15,
            'type_status'           => 'A',
            'resource_count'        => 0,
            'active_resource_count' => 0,
            'service_count'         => 0,
            'translations'          => ['th' => ['type_name' => 'ห้องนวด', 'type_description' => null]],
        ], $list[1]);

        // a field left out keeps its value; translations left out stay
        $this->send('v1/client/resource-type/edit', ['public_id' => $room, 'resource_type' => ['type_status' => 'i']])->assertStatus(200);
        $this->seeInDatabase('resource_type', ['public_id' => $room, 'type_name' => 'Massage room', 'buffer_minutes' => 15, 'type_status' => 'I']);
        $this->seeInDatabase('resource_type_translation', ['language_code' => 'th', 'type_name' => 'ห้องนวด']);
        // translations given replace them
        $this->send('v1/client/resource-type/edit', ['public_id' => $room, 'translations' => []])->assertStatus(200);
        $this->dontSeeInDatabase('resource_type_translation', ['language_code' => 'th']);
        $this->seeInDatabase('log_activity', ['table_involved' => 'resource_type']);
    }

    public function testTypeFieldsAreChecked(): void
    {
        $this->send('v1/client/resource-type/create', ['business_slug' => 'otter-spa', 'resource_type' => ['type_name' => 'Massage room']])->assertStatus(200);

        foreach ([
            [['type_name' => 'Massage room'], [], 'type_name'],
            [['type_name' => ''], [], 'type_name'],
            [['type_name' => 'Bed', 'type_status' => 'X'], [], 'type_status'],
            [['type_name' => 'Bed', 'buffer_minutes' => -5], [], 'buffer_minutes'],
            [['type_name' => 'Bed', 'buffer_minutes' => 1441], [], 'buffer_minutes'],
            [['type_name' => 'Bed'], ['xx' => ['type_name' => 'Bed']], 'translations.xx'],
        ] as [$type, $translations, $field]) {
            $response = $this->send('v1/client/resource-type/create', ['business_slug' => 'otter-spa', 'resource_type' => $type, 'translations' => $translations]);
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $this->body($response)['errors'], $field);
        }
        $this->seeNumRecords(1, 'resource_type', []);
    }

    public function testResourcesAreCreatedAtAPhysicalStoreOfAnActiveType(): void
    {
        $room  = $this->type('Massage room');
        $kit   = $this->type('Hot-stone kit');
        $other = $this->physicalStore($this->businessId, 'Pattaya');
        foreach (['102', '101', '103'] as $sort => $name) {
            $this->send('v1/client/resource/create', ['business_slug' => 'otter-spa', 'resource' => [
                'store_public_id' => $this->storePublicId, 'type_public_id' => $room, 'resource_name' => $name, 'sort_order' => $sort,
            ]])->assertStatus(200);
        }
        // the same name at another store, or of another type, is fine
        $this->send('v1/client/resource/create', ['business_slug' => 'otter-spa', 'resource' => ['store_public_id' => $other, 'type_public_id' => $room, 'resource_name' => '101']])->assertStatus(200);
        $this->send('v1/client/resource/create', ['business_slug' => 'otter-spa', 'resource' => ['store_public_id' => $this->storePublicId, 'type_public_id' => $kit, 'resource_name' => '101']])->assertStatus(200);

        $list = $this->body($this->send('v1/client/resources', ['business_slug' => 'otter-spa', 'store_public_id' => $this->storePublicId, 'type_public_id' => $room]))['data']['resources'];
        $this->assertSame(['102', '101', '103'], array_column($list, 'resource_name')); // by sort_order
        $this->assertSame(['public_id' => $this->storePublicId, 'store_name' => 'Bangkok'], $list[0]['store']);
        $this->assertSame(['public_id' => $room, 'type_name' => 'Massage room', 'type_status' => 'A'], $list[0]['resource_type']);
        $this->assertCount(5, $this->body($this->send('v1/client/resources', ['business_slug' => 'otter-spa']))['data']['resources']);

        $types = $this->body($this->send('v1/client/resource-types', ['business_slug' => 'otter-spa']))['data']['resource_types'];
        $this->assertSame([1, 4], array_column($types, 'resource_count'));

        // edit: name, status and order only
        $publicId = $list[0]['public_id'];
        $this->send('v1/client/resource/edit', ['public_id' => $publicId, 'resource' => ['resource_status' => 'I', 'store_public_id' => $this->storePublicId]])->assertStatus(200);
        $this->seeInDatabase('resource_master', ['public_id' => $publicId, 'resource_name' => '102', 'resource_status' => 'I', 'sort_order' => 0]);
        foreach ([
            [['store_public_id' => $other], 'store_public_id'],
            [['type_public_id' => $kit], 'type_public_id'],
            [['resource_name' => '101'], 'resource_name'],
            [['sort_order' => 70000], 'sort_order'],
        ] as [$resource, $field]) {
            $response = $this->send('v1/client/resource/edit', ['public_id' => $publicId, 'resource' => $resource]);
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $this->body($response)['errors'], $field);
        }
        $this->seeInDatabase('log_activity', ['table_involved' => 'resource_master']);
    }

    public function testANewResourceNeedsAPhysicalStoreAndAnActiveTypeOfTheBusiness(): void
    {
        $room = $this->type('Massage room');
        $old  = $this->type('Sauna', 'I');
        [, $online] = $this->store($this->businessId, 'Online');
        $otherBusiness = $this->business('beaver-spa');
        $foreignStore  = $this->physicalStore($otherBusiness, 'Chiang Mai');

        foreach ([
            [['store_public_id' => $online, 'type_public_id' => $room], 'store_public_id'],
            [['store_public_id' => $foreignStore, 'type_public_id' => $room], 'store_public_id'],
            [['type_public_id' => $room], 'store_public_id'],
            [['store_public_id' => $this->storePublicId, 'type_public_id' => $old], 'type_public_id'],
            [['store_public_id' => $this->storePublicId, 'type_public_id' => 'nope'], 'type_public_id'],
            [['store_public_id' => $this->storePublicId, 'type_public_id' => $room, 'resource_status' => 'X'], 'resource_status'],
        ] as [$resource, $field]) {
            $response = $this->send('v1/client/resource/create', ['business_slug' => 'otter-spa', 'resource' => $resource + ['resource_name' => '101']]);
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $this->body($response)['errors'], $field);
        }
        $this->seeNumRecords(0, 'resource_master', []);
    }

    public function testAServiceNeedsResourceTypes(): void
    {
        $room = $this->type('Massage room');
        $kit  = $this->type('Hot-stone kit');
        $old  = $this->type('Sauna', 'I');
        [, $service] = $this->artifactRow($this->businessId, 'S');
        [, $product] = $this->artifactRow($this->businessId, 'P');

        $this->send('v1/client/artifact/resources/save', ['public_id' => $service, 'resources' => [
            ['public_id' => $room], ['public_id' => $kit, 'quantity' => 2],
        ]])->assertStatus(200);
        $needs = $this->body($this->send('v1/client/artifact/resources', ['public_id' => $service]))['data']['resources'];
        $this->assertSame([
            ['quantity' => 2, 'public_id' => $kit, 'type_name' => 'Hot-stone kit', 'type_status' => 'A'],
            ['quantity' => 1, 'public_id' => $room, 'type_name' => 'Massage room', 'type_status' => 'A'],
        ], $needs);

        // replaced: the kit is no longer needed, the room twice
        $this->send('v1/client/artifact/resources/save', ['public_id' => $service, 'resources' => [['public_id' => $room, 'quantity' => '2']]])->assertStatus(200);
        $this->seeNumRecords(1, 'business_service_resource_link', []);
        $this->seeInDatabase('business_service_resource_link', ['quantity' => 2]);

        // an inactive type may stay, but not be added
        $response = $this->send('v1/client/artifact/resources/save', ['public_id' => $service, 'resources' => [['public_id' => $old]]]);
        $this->assertArrayHasKey('resources.0.public_id', $this->body($response)['errors']);
        $this->send('v1/client/resource-type/edit', ['public_id' => $room, 'resource_type' => ['type_status' => 'I']])->assertStatus(200);
        $this->send('v1/client/artifact/resources/save', ['public_id' => $service, 'resources' => [['public_id' => $room, 'quantity' => 1]]])->assertStatus(200);

        foreach ([
            [[['public_id' => $kit], ['public_id' => $kit]], 'resources.1.public_id'],
            [[['public_id' => 'nope']], 'resources.0.public_id'],
            [[['public_id' => $kit, 'quantity' => 0]], 'resources.0.quantity'],
            [[['public_id' => $kit, 'quantity' => 256]], 'resources.0.quantity'],
            [[['public_id' => $kit, 'quantity' => 1.5]], 'resources.0.quantity'],
            [['public_id' => $kit], 'resources'],
        ] as [$resources, $field]) {
            $response = $this->send('v1/client/artifact/resources/save', ['public_id' => $service, 'resources' => $resources]);
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $this->body($response)['errors'], $field);
        }
        $this->send('v1/client/artifact/resources/save', ['public_id' => $service, 'resources' => []])->assertStatus(200);
        $this->seeNumRecords(0, 'business_service_resource_link', []);
        $this->seeInDatabase('log_activity', ['table_involved' => 'business_service_resource_link']);

        // a product needs none; a type of another business is unknown
        $this->send('v1/client/artifact/resources/save', ['public_id' => $product, 'resources' => []])->assertStatus(422);
        $this->send('v1/client/artifact/resources', ['public_id' => 'nope'])->assertStatus(404);
        $other      = $this->business('beaver-spa');
        $otherOwner = $this->createClient('other@example.com', $other, 'O');
        $foreign    = $this->body($this->send('v1/client/resource-type/create', ['business_slug' => 'beaver-spa', 'resource_type' => ['type_name' => 'Bed']], $otherOwner))['data']['public_id'];
        $response   = $this->send('v1/client/artifact/resources/save', ['public_id' => $service, 'resources' => [['public_id' => $foreign]]]);
        $this->assertArrayHasKey('resources.0.public_id', $this->body($response)['errors']);
    }

    public function testOnlyActiveOwnersAndManagersGetIn(): void
    {
        $room     = $this->type('Massage room');
        $resource = $this->body($this->send('v1/client/resource/create', ['business_slug' => 'otter-spa', 'resource' => [
            'store_public_id' => $this->storePublicId, 'type_public_id' => $room, 'resource_name' => '101',
        ]]))['data']['public_id'];
        [, $service] = $this->artifactRow($this->businessId, 'S');

        $manager = $this->createClient('manager@example.com', $this->businessId, 'M');
        $this->send('v1/client/resources', ['business_slug' => 'otter-spa'], $manager)->assertStatus(200);
        $this->send('v1/client/artifact/resources/save', ['public_id' => $service, 'resources' => [['public_id' => $room]]], $manager)->assertStatus(200);

        $employee = $this->createClient('employee@example.com', $this->businessId, 'E');
        $outsider = $this->createClient('outsider@example.com', null, null);
        foreach ([[$employee, 403], [$outsider, 404]] as [$token, $status]) {
            $this->send('v1/client/resource-types', ['business_slug' => 'otter-spa'], $token)->assertStatus($status);
            $this->send('v1/client/resource-type/create', ['business_slug' => 'otter-spa', 'resource_type' => ['type_name' => 'Bed']], $token)->assertStatus($status);
            $this->send('v1/client/resource-type/edit', ['public_id' => $room, 'resource_type' => ['type_name' => 'Bed']], $token)->assertStatus($status);
            $this->send('v1/client/resources', ['business_slug' => 'otter-spa'], $token)->assertStatus($status);
            $this->send('v1/client/resource/create', ['business_slug' => 'otter-spa', 'resource' => ['store_public_id' => $this->storePublicId, 'type_public_id' => $room, 'resource_name' => '102']], $token)->assertStatus($status);
            $this->send('v1/client/resource/edit', ['public_id' => $resource, 'resource' => ['resource_name' => '102']], $token)->assertStatus($status);
            $this->send('v1/client/artifact/resources', ['public_id' => $service], $token)->assertStatus($status);
            $this->send('v1/client/artifact/resources/save', ['public_id' => $service, 'resources' => []], $token)->assertStatus($status);
        }
        $this->send('v1/client/resource-type/edit', ['public_id' => 'nope'])->assertStatus(404);
        $this->send('v1/client/resource/edit', [])->assertStatus(400);
        $this->seeNumRecords(1, 'resource_type', []);
        $this->seeNumRecords(1, 'resource_master', []);
        $this->seeNumRecords(1, 'business_service_resource_link', []);
    }

    /** @return string The store's public_id */
    private function physicalStore(int $businessId, string $name): string
    {
        [$id, $publicId] = $this->store($businessId, $name);
        $this->db->table('business_store')->where('id', $id)->update(['store_type' => 'P']);

        return $publicId;
    }

    /** @return string The type's public_id */
    private function type(string $name, string $status = 'A'): string
    {
        $publicId = $this->fixturePublicId();
        $this->db->table('resource_type')->insert(['public_id' => $publicId, 'business_id' => $this->businessId, 'type_name' => $name, 'type_status' => $status]);

        return $publicId;
    }
}
