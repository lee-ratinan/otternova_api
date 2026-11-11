<?php

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * business_website, business_website_language, business_website_featured: one website per business with the
 * storefront's colours by default, one row per language, featured artifacts of the same business only, at most one
 * per place and once per section, and everything going with the business
 *
 * @internal
 */
final class BusinessWebsiteSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('business_type')->insert(['type_code' => 'spa', 'public_id' => 'ty01']);
        foreach ([1, 2] as $n) {
            $this->db->table('business_master')->insert(['business_type_id' => 1, 'business_name' => "Spa {$n}", 'business_slug' => "spa-{$n}",
                'contact_email_address' => "spa{$n}@example.com", 'country_code' => 'TH', 'currency_code' => 'THB']);
            $this->db->table('artifact_master')->insert(['public_id' => "artifact000{$n}", 'business_id' => $n, 'artifact_name' => 'Massage',
                'artifact_type' => 'S', 'tax_calculation' => 'X']);
        }
        $this->db->table('business_website')->insert(['business_id' => 1]);
    }

    public function testAWebsiteStartsWithTheStorefrontColoursAndNoLogo(): void
    {
        $row = $this->db->table('business_website')->get()->getRow();
        $this->assertSame('#ffffff', $row->background_color);
        $this->assertSame('#5a6577', $row->default_color);
        $this->assertSame('#10243d', $row->heading_color);
        $this->assertSame('#ff7533', $row->accent_color);
        $this->assertSame('#ffffff', $row->surface_color);
        $this->assertSame('#ffffff', $row->contrast_color);
        $this->assertNull($row->logo_path);
        $this->assertNull($row->logo_shape);
        $this->assertNotNull($row->created_at);
    }

    public function testOneWebsitePerBusinessAndOneRowPerLanguage(): void
    {
        $language = ['business_id' => 1, 'language_code' => 'th', 'heading_font' => 'Prompt', 'nav_font' => 'Prompt', 'default_font' => 'Sarabun'];
        $this->db->table('business_website_language')->insert($language);
        $this->db->table('business_website_language')->insert(['language_code' => 'en-US', 'sort_order' => 1] + $language);

        foreach ([
            ['business_website', ['business_id' => 1]], // a second website
            ['business_website', ['business_id' => 99]], // no such business
            ['business_website_language', $language], // the same language again
            ['business_website_language', ['business_id' => 2] + $language], // a business with no website
        ] as [$table, $row]) {
            try {
                $this->db->table($table)->insert($row);
                $this->fail("Inserted into {$table}: " . json_encode($row));
            } catch (DatabaseException) {
                // refused
            }
        }
        $this->seeNumRecords(1, 'business_website', []);
        $this->seeNumRecords(2, 'business_website_language', []);
    }

    public function testFeaturedArtifactsAreTheBusinessesOwnOncePerPlace(): void
    {
        $featured = ['business_id' => 1, 'artifact_id' => 1, 'section' => 'B', 'sort_order' => 1];
        $this->db->table('business_website_featured')->insert($featured);
        $this->db->table('business_website_featured')->insert(['section' => 'T'] + $featured); // the same artifact in another section

        $this->db->table('business_website')->insert(['business_id' => 2]);
        foreach ([
            ['sort_order' => 2] + $featured, // the same artifact twice in a section
            ['artifact_id' => 2, 'sort_order' => 2] + $featured, // another business's artifact
            ['business_id' => 2] + $featured, // the same, from the other side
            ['artifact_id' => 99, 'sort_order' => 2] + $featured, // no such artifact
        ] as $row) {
            try {
                $this->db->table('business_website_featured')->insert($row);
                $this->fail('Inserted ' . json_encode($row));
            } catch (DatabaseException) {
                // refused
            }
        }
        $this->seeNumRecords(2, 'business_website_featured', []);

        // a second artifact of business 1 in the place already taken
        $this->db->table('artifact_master')->insert(['public_id' => 'artifact0003', 'business_id' => 1, 'artifact_name' => 'Facial',
            'artifact_type' => 'S', 'tax_calculation' => 'X']);
        $this->expectException(DatabaseException::class);
        $this->db->table('business_website_featured')->insert(['artifact_id' => 3] + $featured);
    }

    public function testTheWebsiteGoesWithItsBusiness(): void
    {
        $this->db->table('business_website_language')->insert(['business_id' => 1, 'language_code' => 'th',
            'heading_font' => 'Prompt', 'nav_font' => 'Prompt', 'default_font' => 'Sarabun']);
        $this->db->table('business_website_featured')->insert(['business_id' => 1, 'artifact_id' => 1, 'section' => 'B', 'sort_order' => 1]);

        $this->db->table('business_website')->where('business_id', 1)->delete();
        $this->seeNumRecords(0, 'business_website_language', []);
        $this->seeNumRecords(0, 'business_website_featured', []);
    }
}
