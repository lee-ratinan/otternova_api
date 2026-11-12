<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * /client/staff-attribute*, /client/staff/profile, /client/staff/languages/save, /client/staff/attributes/save and
 * /client/staff/restrictions/save: the active owners and managers of a business record what it wants about its
 * people, the languages they speak and the customers they do not serve
 *
 * @internal
 */
final class StaffProfilesTest extends CIUnitTestCase
{
    use ArtifactFixturesTrait;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private const PERSON = ['business_slug' => 'otter-spa', 'uid' => 'therapistexamplecom'];

    private int $businessId;
    private string $ownerToken;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        $this->businessId = $this->business('otter-spa');
        $this->ownerToken = $this->createClient('owner@example.com', $this->businessId, 'O');
        $this->createClient('therapist@example.com', $this->businessId, 'E');
    }

    public function testAttributesAreCreatedListedEditedAndDeleted(): void
    {
        $response = $this->send('v1/client/staff-attribute/create', [
            'business_slug' => 'otter-spa',
            'attribute'     => ['attribute_name' => ' Certification ', 'sort_order' => 1],
            'translations'  => ['th' => 'ใบรับรอง'],
        ]);
        $response->assertStatus(200);
        $certification = $this->body($response)['data']['attribute']['public_id'];
        $height        = $this->attribute('Height', false);

        $list = $this->body($this->send('v1/client/staff-attributes', ['business_slug' => 'otter-spa']))['data']['attributes'];
        $this->assertSame(['Height', 'Certification'], array_column($list, 'attribute_name'));
        $this->assertSame([
            'public_id'      => $certification,
            'attribute_name' => 'Certification',
            'is_public'      => true,
            'sort_order'     => 1,
            'translations'   => ['th' => 'ใบรับรอง'],
            'value_count'    => 0,
        ], $list[1]);

        // a field left out keeps its value; translations left out stay, given they replace
        $this->send('v1/client/staff-attribute/edit', ['public_id' => $certification, 'attribute' => ['is_public' => false]])->assertStatus(200);
        $this->seeInDatabase('business_client_attribute', ['public_id' => $certification, 'attribute_name' => 'Certification', 'is_public' => 0, 'sort_order' => 1]);
        $this->seeInDatabase('business_client_attribute_translation', ['language_code' => 'th']);
        $this->send('v1/client/staff-attribute/edit', ['public_id' => $certification, 'translations' => []])->assertStatus(200);
        $this->dontSeeInDatabase('business_client_attribute_translation', ['language_code' => 'th']);

        // a name is unique within the business
        $response = $this->send('v1/client/staff-attribute/edit', ['public_id' => $height, 'attribute' => ['attribute_name' => 'Certification']]);
        $response->assertStatus(422);
        $this->assertArrayHasKey('attribute_name', $this->body($response)['errors']);

        // deleted with everyone's values of it
        $this->send('v1/client/staff/attributes/save', self::PERSON + ['values' => [
            ['attribute_public_id' => $certification, 'attribute_value' => 'CIBTAC', 'translations' => ['th' => 'ซีไอบีแทค']],
            ['attribute_public_id' => $height, 'attribute_value' => '168 cm'],
        ]])->assertStatus(200);
        $response = $this->send('v1/client/staff-attribute/delete', ['public_id' => $certification]);
        $response->assertStatus(200);
        $this->assertSame(1, $this->body($response)['data']['deleted_values']);
        $this->dontSeeInDatabase('business_client_attribute', ['public_id' => $certification]);
        $this->seeNumRecords(1, 'business_client_attribute_value', []);
        $this->seeNumRecords(0, 'business_client_attribute_value_translation', []);
        $this->seeInDatabase('log_activity', ['table_involved' => 'business_client_attribute']);
    }

    public function testAttributeFieldsAreChecked(): void
    {
        foreach ([
            [['attribute_name' => ''], 'attribute_name'],
            [['attribute_name' => str_repeat('a', 65)], 'attribute_name'],
            [['attribute_name' => 'Height', 'is_public' => 'maybe'], 'is_public'],
            [['attribute_name' => 'Height', 'sort_order' => -1], 'sort_order'],
        ] as [$attribute, $field]) {
            $response = $this->send('v1/client/staff-attribute/create', ['business_slug' => 'otter-spa', 'attribute' => $attribute]);
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $this->body($response)['errors'], $field);
        }
        $response = $this->send('v1/client/staff-attribute/create', ['business_slug' => 'otter-spa', 'attribute' => ['attribute_name' => 'Height'], 'translations' => ['xx' => 'Nope']]);
        $this->assertArrayHasKey('translations.xx', $this->body($response)['errors']);
        $this->seeNumRecords(0, 'business_client_attribute', []);
    }

    public function testValuesAreReplaced(): void
    {
        $specialty = $this->attribute('Specialized in');
        $height    = $this->attribute('Height', false, 1);
        $save      = fn (array $values) => $this->send('v1/client/staff/attributes/save', self::PERSON + ['values' => $values]);

        $response = $save([
            ['attribute_public_id' => $specialty, 'attribute_value' => ' Sport massage ', 'translations' => ['th' => 'นวดกีฬา']],
            ['attribute_public_id' => $specialty, 'attribute_value' => 'Aromatherapy'],
            ['attribute_public_id' => $height, 'attribute_value' => 168],
        ]);
        $response->assertStatus(200);
        $attributes = $this->body($response)['data']['attributes'];
        $this->assertSame(['Specialized in', 'Height'], array_column($attributes, 'attribute_name'));
        $this->assertSame(['Sport massage', 'Aromatherapy'], array_column($attributes[0]['values'], 'attribute_value'));
        $this->assertSame(['th' => 'นวดกีฬา'], $attributes[0]['values'][0]['translations']);
        $this->assertSame('168', $attributes[1]['values'][0]['attribute_value']);
        $this->assertFalse($attributes[1]['is_public']);
        $sport = $attributes[0]['values'][0]['public_id'];

        // given: updated, translations kept when left out; left out: deleted
        $save([['public_id' => $sport, 'attribute_value' => 'Sports massage']])->assertStatus(200);
        $profile = $this->profile();
        $this->assertSame([['public_id' => $sport, 'attribute_value' => 'Sports massage', 'sort_order' => 0, 'translations' => ['th' => 'นวดกีฬา']]], $profile['attributes'][0]['values']);
        $this->assertSame([], $profile['attributes'][1]['values']);
        $this->seeNumRecords(1, 'business_client_attribute_value', []);

        $save([])->assertStatus(200);
        $this->seeNumRecords(0, 'business_client_attribute_value', []);
        $this->seeNumRecords(0, 'business_client_attribute_value_translation', []);
        $this->seeInDatabase('log_activity', ['table_involved' => 'business_client_attribute_value']);
    }

    public function testValuesAreChecked(): void
    {
        $specialty  = $this->attribute('Specialized in');
        $other      = $this->business('beaver-spa');
        $otherOwner = $this->createClient('other@example.com', $other, 'O');
        $foreign    = $this->body($this->send('v1/client/staff-attribute/create', ['business_slug' => 'beaver-spa', 'attribute' => ['attribute_name' => 'Height']], $otherOwner))['data']['attribute']['public_id'];

        $response = $this->send('v1/client/staff/attributes/save', self::PERSON + ['values' => [
            ['attribute_public_id' => $foreign, 'attribute_value' => '168 cm'],
            ['attribute_value' => 'No attribute'],
            ['attribute_public_id' => $specialty, 'attribute_value' => ''],
            ['attribute_public_id' => $specialty, 'attribute_value' => 'Thai massage'],
            ['attribute_public_id' => $specialty, 'attribute_value' => 'thai MASSAGE'],
            ['public_id' => 'nope', 'attribute_value' => 'x'],
            'text',
        ]]);
        $response->assertStatus(422);
        $this->assertSame([
            'values.0.attribute_public_id', 'values.1.attribute_public_id', 'values.2.attribute_value',
            'values.4.attribute_value', 'values.5.public_id', 'values.6',
        ], array_keys($this->body($response)['errors']));
        $this->send('v1/client/staff/attributes/save', self::PERSON + ['values' => 'x'])->assertStatus(422);
        $this->seeNumRecords(0, 'business_client_attribute_value', []);
    }

    public function testLanguagesAreReplaced(): void
    {
        $save = fn (array $languages) => $this->send('v1/client/staff/languages/save', self::PERSON + ['languages' => $languages]);

        $response = $save([
            ['language_code' => 'EN', 'proficiency' => 'c'],
            ['language_code' => 'th', 'proficiency' => 'N'],
            ['language_code' => 'nan-tw'],
        ]);
        $response->assertStatus(200);
        $this->assertSame([
            ['language_code' => 'th', 'proficiency' => 'N'],
            ['language_code' => 'nan-TW', 'proficiency' => 'F'],
            ['language_code' => 'en', 'proficiency' => 'C'],
        ], $this->body($response)['data']['languages']);

        $save([['language_code' => 'th', 'proficiency' => 'N'], ['language_code' => 'en', 'proficiency' => 'F']])->assertStatus(200);
        $this->assertSame([['language_code' => 'th', 'proficiency' => 'N'], ['language_code' => 'en', 'proficiency' => 'F']], $this->profile()['languages']);
        $this->seeNumRecords(2, 'business_client_language', []);

        $response = $save([['language_code' => 'english'], ['language_code' => 'th', 'proficiency' => 'X'], ['language_code' => 'TH']]);
        $response->assertStatus(422);
        $this->assertSame(['languages.0.language_code', 'languages.1.proficiency', 'languages.2.language_code'], array_keys($this->body($response)['errors']));

        $save([])->assertStatus(200);
        $this->seeNumRecords(0, 'business_client_language', []);
    }

    public function testRestrictionsAreReplacedAndToldToCustomers(): void
    {
        $save = fn (array $body) => $this->send('v1/client/staff/restrictions/save', self::PERSON + $body);

        $response = $save(['excluded_genders' => ['nb', 'M', 'M'], 'age_min' => 18]);
        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame(['excluded_genders' => ['M', 'NB'], 'age_min' => 18, 'age_max' => null], $data['restrictions']);
        $this->assertSame([
            'Otter Tester does not serve male or non-binary customers.',
            'Otter Tester serves customers aged 18 and over only.',
        ], $data['restriction_notices']);
        $this->seeNumRecords(3, 'business_client_restriction', []);

        $save(['excluded_genders' => ['M'], 'age_min' => 18, 'age_max' => 60])->assertStatus(200);
        $profile = $this->profile();
        $this->assertSame(['excluded_genders' => ['M'], 'age_min' => 18, 'age_max' => 60], $profile['restrictions']);
        $this->assertSame('Otter Tester serves customers aged 18 to 60 only.', $profile['restriction_notices'][1]);
        $this->seeNumRecords(2, 'business_client_restriction', []);

        $response = $save(['excluded_genders' => ['X'], 'age_min' => 30, 'age_max' => 20]);
        $response->assertStatus(422);
        $this->assertSame(['excluded_genders.0', 'age_max'], array_keys($this->body($response)['errors']));
        $response = $save(['age_min' => 121, 'age_max' => 'old']);
        $this->assertSame(['age_min', 'age_max'], array_keys($this->body($response)['errors']));

        $save([])->assertStatus(200);
        $this->seeNumRecords(0, 'business_client_restriction', []);
        $this->assertSame([], $this->profile()['restriction_notices']);
        $this->seeInDatabase('log_activity', ['table_involved' => 'business_client_restriction']);
    }

    public function testOnlyActiveOwnersAndManagersGetIn(): void
    {
        $attribute = $this->attribute('Height');
        $manager   = $this->createClient('manager@example.com', $this->businessId, 'M');
        $this->send('v1/client/staff/profile', self::PERSON, $manager)->assertStatus(200);
        $this->send('v1/client/staff/languages/save', self::PERSON + ['languages' => [['language_code' => 'th']]], $manager)->assertStatus(200);

        $employee = $this->createClient('employee@example.com', $this->businessId, 'E');
        $former   = $this->createClient('former@example.com', $this->businessId, 'M', 'T');
        $outsider = $this->createClient('outsider@example.com', null, null);
        foreach ([[$employee, 403], [$former, 404], [$outsider, 404]] as [$token, $status]) {
            $this->send('v1/client/staff-attributes', ['business_slug' => 'otter-spa'], $token)->assertStatus($status);
            $this->send('v1/client/staff-attribute/create', ['business_slug' => 'otter-spa', 'attribute' => ['attribute_name' => 'Weight']], $token)->assertStatus($status);
            $this->send('v1/client/staff-attribute/edit', ['public_id' => $attribute, 'attribute' => ['attribute_name' => 'Weight']], $token)->assertStatus($status);
            $this->send('v1/client/staff-attribute/delete', ['public_id' => $attribute], $token)->assertStatus($status);
            $this->send('v1/client/staff/profile', self::PERSON, $token)->assertStatus($status);
            $this->send('v1/client/staff/languages/save', self::PERSON + ['languages' => []], $token)->assertStatus($status);
            $this->send('v1/client/staff/attributes/save', self::PERSON + ['values' => []], $token)->assertStatus($status);
            $this->send('v1/client/staff/restrictions/save', self::PERSON + ['excluded_genders' => ['M']], $token)->assertStatus($status);
        }
        $this->send('v1/client/staff/profile', ['business_slug' => 'otter-spa', 'uid' => 'nobody'])->assertStatus(404);
        $this->send('v1/client/staff/profile', ['business_slug' => 'otter-spa'])->assertStatus(400);
        $this->send('v1/client/staff-attribute/delete', [])->assertStatus(400);
        $this->send('v1/client/staff-attribute/edit', ['public_id' => 'nope'])->assertStatus(404);
        $this->seeNumRecords(1, 'business_client_attribute', []);
        $this->seeNumRecords(1, 'business_client_language', []);
        $this->seeNumRecords(0, 'business_client_restriction', []);
    }

    /** @return array The data of the therapist's profile */
    private function profile(): array
    {
        $response = $this->send('v1/client/staff/profile', self::PERSON);
        $response->assertStatus(200);

        return $this->body($response)['data'];
    }

    /** @return string The attribute's public_id */
    private function attribute(string $name, bool $public = true, int $sortOrder = 0): string
    {
        $publicId = $this->fixturePublicId();
        $this->db->table('business_client_attribute')->insert([
            'public_id' => $publicId, 'business_id' => $this->businessId, 'attribute_name' => $name, 'is_public' => $public ? 1 : 0, 'sort_order' => $sortOrder,
        ]);

        return $publicId;
    }
}
