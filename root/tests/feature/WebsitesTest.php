<?php

use App\Libraries\ImageProcessor;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\ImageUpload;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * /client/website*: the active owners and managers of a business set how its web pages look: colours, languages
 * with their fonts, meta tags and main-page text, featured artifacts, and the logo
 *
 * @internal
 */
final class WebsitesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ArtifactFixturesTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private string $ownerToken;
    private int $businessId;

    /** Stands in for writable/uploads/ */
    private string $uploads;

    /** Source images made for the test, removed in tearDown */
    private array $sources = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        // the test files are not real uploads, so is_uploaded_file() would refuse them
        Services::injectMock('imageProcessor', new class () extends ImageProcessor {
            public function isGenuineUpload(UploadedFile $file): bool
            {
                return $file->getError() === UPLOAD_ERR_OK && is_file($file->getTempName());
            }
        });
        $this->uploads                         = sys_get_temp_dir() . '/otternova-logos-' . bin2hex(random_bytes(4)) . '/';
        config(ImageUpload::class)->uploadPath = $this->uploads;

        $this->businessId = $this->business('otter-cafe');
        $this->ownerToken = $this->createClient('owner@example.com', $this->businessId, 'O');
    }

    protected function tearDown(): void
    {
        service('superglobals')->setFilesArray([]);
        foreach ($this->sources as $source) {
            @unlink($source);
        }
        foreach (glob($this->uploads . '*/{,.}*.webp', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->uploads . '*', GLOB_ONLYDIR) ?: [] as $folder) {
            rmdir($folder);
        }
        @rmdir($this->uploads);

        parent::tearDown();
    }

    public function testOnlyActiveOwnersAndManagersGetIn(): void
    {
        $manager  = $this->createClient('manager@example.com', $this->businessId, 'M');
        $employee = $this->createClient('employee@example.com', $this->businessId, 'E');
        $other    = $this->createClient('other@example.com', $this->business('other-shop'), 'O');
        $slug     = ['business_slug' => 'otter-cafe'];

        $this->send('v1/client/website', $slug, $manager)->assertStatus(200);
        $this->send('v1/client/website', $slug, $employee)->assertStatus(403);
        $this->send('v1/client/website/save', $slug + ['colors' => $this->colors()], $other)->assertStatus(404);
        $this->send('v1/client/website/logo-delete', $slug, $employee)->assertStatus(403);
        $this->send('v1/client/website', ['business_slug' => 'no-such-business'])->assertStatus(404);
        $this->send('v1/client/website', [])->assertStatus(400);
        $this->dontSeeInDatabase('business_website', []);
    }

    public function testABusinessStartsWithTheDefaultsAndItsChoices(): void
    {
        $this->artifactRow($this->businessId);
        $this->artifactRow($this->businessId, 'S', 'I'); // inactive: not one to feature

        $data = $this->settings();
        $this->assertSame('#ff7533', $data['colors']['accent_color']);
        $this->assertCount(6, $data['colors']);
        $this->assertNull($data['logo']);
        $this->assertSame([], $data['languages']);
        $this->assertSame(['best_sellers' => [], 'trending' => []], $data['featured']);
        $this->assertSame(listLanguages(), $data['options']['languages']);
        $this->assertArrayHasKey('Prompt', $data['options']['typefaces']['th']);
        $this->assertArrayHasKey('Montserrat', $data['options']['typefaces']['en-US']);
        $this->assertCount(1, $data['options']['artifacts']);
        $this->assertSame(3, $data['options']['max_featured']);
        $this->dontSeeInDatabase('business_website', []); // reading makes nothing
    }

    public function testColoursAreSaved(): void
    {
        $colors = ['accent_color' => '#AA3300'] + $this->colors();
        $this->save(['colors' => $colors])->assertStatus(200);
        $this->assertSame('#aa3300', $this->settings()['colors']['accent_color']);
        $this->seeInDatabase('log_activity', ['table_involved' => 'business_website']);

        $errors = $this->body($this->save(['colors' => ['accent_color' => 'red'] + $this->colors()]))['errors'];
        $this->assertSame(['colors.accent_color'], array_keys($errors));
        $errors = $this->body($this->save(['colors' => array_diff_key($this->colors(), ['surface_color' => true])]))['errors'];
        $this->assertSame(['colors.surface_color'], array_keys($errors));
        $this->save([])->assertStatus(422);
    }

    public function testLanguagesAreReplacedTheDefaultFirst(): void
    {
        $this->save(['languages' => [
            $this->language('th', ['hero_badge' => '  คัดสรรพิเศษ ', 'meta_keywords' => '']),
            $this->language('en-US', ['heading_font' => 'Montserrat', 'nav_font' => 'Ubuntu', 'default_font' => 'Helvetica']),
        ]])->assertStatus(422); // Helvetica is not one of the typefaces

        $this->save(['languages' => [
            $this->language('th', ['hero_badge' => '  คัดสรรพิเศษ ', 'meta_keywords' => '']),
            $this->language('en-US', ['heading_font' => 'Montserrat', 'nav_font' => 'Ubuntu', 'default_font' => 'Lato', 'hero_headline' => 'Best in Town']),
        ]])->assertStatus(200);
        $languages = $this->settings()['languages'];
        $this->assertSame(['th', 'en-US'], array_column($languages, 'language_code'));
        $this->assertSame('คัดสรรพิเศษ', $languages[0]['hero_badge']);
        $this->assertNull($languages[0]['meta_keywords']);
        $this->assertSame(['Montserrat', 'Ubuntu', 'Lato'], [$languages[1]['heading_font'], $languages[1]['nav_font'], $languages[1]['default_font']]);

        // English becomes the default, Thai goes, Malay comes in with the Latin typefaces
        $this->save(['languages' => [$this->language('en-US', ['heading_font' => 'Oswald']), $this->language('ms')]])->assertStatus(200);
        $languages = $this->settings()['languages'];
        $this->assertSame(['en-US', 'ms'], array_column($languages, 'language_code'));
        $this->assertSame('Oswald', $languages[0]['heading_font']);
        $this->assertNull($languages[0]['hero_headline']);
        $this->seeInDatabase('business_website_language', ['language_code' => 'en-US', 'sort_order' => 0]);
        $this->dontSeeInDatabase('business_website_language', ['language_code' => 'th']);
    }

    public function testLanguagesAreChecked(): void
    {
        $errors = fn (array $languages): array => array_keys($this->body($this->save(['languages' => $languages]))['errors'] ?? []);

        $this->assertSame(['languages'], $errors([]));
        $this->assertSame(['languages'], $errors(['th' => $this->language('th')]));
        $this->assertSame(['languages.0.language_code'], $errors([$this->language('xx')]));
        $this->assertSame(['languages.1.language_code'], $errors([$this->language('th'), $this->language('th')]));
        // a Latin typeface is not one of Thai's
        $this->assertSame(['languages.0.heading_font', 'languages.0.default_font'], $errors([$this->language('th', ['heading_font' => 'Montserrat', 'default_font' => ''])]));
        $this->assertSame(['languages.0.meta_description', 'languages.0.hero_badge'],
            $errors([$this->language('th', ['meta_description' => str_repeat('ก', 161), 'hero_badge' => ['x']])]));
        $this->dontSeeInDatabase('business_website_language', []);
    }

    public function testFeaturedArtifactsAreTheBusinessesActiveOnesThreeAtMost(): void
    {
        $ids = [];
        foreach (range(1, 4) as $n) {
            $ids[] = $this->artifactRow($this->businessId)[1];
        }
        [, $inactive] = $this->artifactRow($this->businessId, 'P', 'I');
        [, $foreign]  = $this->artifactRow($this->business('other-shop'));

        $this->save(['featured' => ['best_sellers' => [$ids[0], $ids[1], $ids[2]], 'trending' => [$ids[0]]]])->assertStatus(200);
        // reordered, one swapped, trending emptied
        $this->save(['featured' => ['best_sellers' => [$ids[2], $ids[0], $ids[3]]]])->assertStatus(200);
        $featured = $this->settings()['featured'];
        $this->assertSame([$ids[2], $ids[0], $ids[3]], array_column($featured['best_sellers'], 'public_id'));
        $this->assertSame([], $featured['trending']);

        $error = fn (array $featured): ?string => $this->body($this->save(['featured' => $featured]))['errors']['featured.best_sellers'] ?? null;
        $this->assertSame(lang('Website.tooManyFeatured', [3]), $error(['best_sellers' => $ids]));
        $this->assertSame(lang('Website.duplicateFeatured'), $error(['best_sellers' => [$ids[0], $ids[0]]]));
        $this->assertSame(lang('Website.featuredNotFound'), $error(['best_sellers' => [$inactive]]));
        $this->assertSame(lang('Website.featuredNotFound'), $error(['best_sellers' => [$foreign]]));
        $this->assertSame(3, $this->db->table('business_website_featured')->countAllResults());
    }

    public function testAFeaturedArtifactThatStopsBeingActiveIsStillShownToTheClient(): void
    {
        [$id, $publicId] = $this->artifactRow($this->businessId);
        $this->save(['featured' => ['trending' => [$publicId]]])->assertStatus(200);
        $this->db->table('artifact_master')->where('id', $id)->update(['artifact_status' => 'D']);

        $this->assertSame('D', $this->settings()['featured']['trending'][0]['artifact_status']);
        $this->assertSame([], $this->settings()['options']['artifacts']);
    }

    public function testALogoIsSquareOrWideByItsShape(): void
    {
        $square = $this->body($this->uploadLogo($this->image(800, 600)))['data'];
        $this->assertSame('S', $square['shape']);
        $path = $this->uploads . 'business-logo/' . basename($square['url']);
        $this->assertSame([1000, 1000], array_slice(getimagesize($path), 0, 2));
        $this->seeInDatabase('business_website', ['business_id' => $this->businessId, 'logo_path' => $square['url'], 'logo_shape' => 'S']);

        $wide = $this->body($this->uploadLogo($this->image(1500, 500)))['data'];
        $this->assertSame('W', $wide['shape']);
        $this->assertSame([2000, 1000], array_slice(getimagesize($this->uploads . 'business-logo/' . basename($wide['url'])), 0, 2));
        $this->assertFileDoesNotExist($path); // the old one is gone
        $this->assertSame(['url' => $wide['url'], 'shape' => 'W'], $this->settings()['logo']);
        $this->assertSame([], glob($this->uploads . 'business-logo/.*.webp')); // no temporary file left

        $served = $this->withHeaders([])->get('image/business-logo/' . basename($wide['url']));
        $served->assertStatus(200);
    }

    public function testALogoIsChecked(): void
    {
        $this->uploadLogo(null)->assertStatus(422);
        $this->uploadLogo($this->image(600, 150))->assertStatus(422); // too thin
        $this->uploadLogo($this->image(400, 400), $this->createClient('employee@example.com', $this->businessId, 'E'))->assertStatus(403);
        $this->dontSeeInDatabase('business_website', []);
        // nor through the generic upload, which cannot check the business
        service('superglobals')->setFilesArray(['image' => $this->fileEntry($this->image(400, 400))]);
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->ownerToken])
            ->post('v1/client/image-upload', ['used-for' => 'business-logo', 'image-name' => 'mine', 'id' => 1])
            ->assertStatus(403);
    }

    public function testALogoIsDeletedWithItsFile(): void
    {
        $this->send('v1/client/website/logo-delete', ['business_slug' => 'otter-cafe'])->assertStatus(404);
        $url = $this->body($this->uploadLogo($this->image(400, 400)))['data']['url'];

        $this->send('v1/client/website/logo-delete', ['business_slug' => 'otter-cafe'])->assertStatus(200);
        $this->assertNull($this->settings()['logo']);
        $this->assertFileDoesNotExist($this->uploads . 'business-logo/' . basename($url));
        $this->seeInDatabase('business_website', ['business_id' => $this->businessId, 'logo_path' => null]);
    }

    public function testEverythingGoesWithTheBusiness(): void
    {
        [, $publicId] = $this->artifactRow($this->businessId);
        $this->save(['colors' => $this->colors(), 'languages' => [$this->language('th')], 'featured' => ['best_sellers' => [$publicId]]])->assertStatus(200);
        $this->assertSame(1, $this->db->table('business_website_language')->countAllResults());

        $this->db->table('business_website')->where('business_id', $this->businessId)->delete();
        $this->dontSeeInDatabase('business_website_language', []);
        $this->dontSeeInDatabase('business_website_featured', []);
    }

    private function settings(): array
    {
        $response = $this->send('v1/client/website', ['business_slug' => 'otter-cafe']);
        $response->assertStatus(200);

        return $this->body($response)['data'];
    }

    private function save(array $body): TestResponse
    {
        return $this->send('v1/client/website/save', ['business_slug' => 'otter-cafe'] + $body);
    }

    private function colors(): array
    {
        return ['background_color' => '#ffffff', 'default_color' => '#333333', 'heading_color' => '#111111',
            'accent_color' => '#ff7533', 'surface_color' => '#fafafa', 'contrast_color' => '#ffffff'];
    }

    private function language(string $code, array $values = []): array
    {
        $fonts = $code === 'th'
            ? ['heading_font' => 'Prompt', 'nav_font' => 'Prompt', 'default_font' => 'Sarabun']
            : ['heading_font' => 'Montserrat', 'nav_font' => 'Ubuntu', 'default_font' => 'Lato'];

        return $values + ['language_code' => $code] + $fonts + ['meta_title' => 'Otter Café', 'meta_description' => 'Coffee by the river'];
    }

    private function uploadLogo(?string $source, ?string $token = null): TestResponse
    {
        service('superglobals')->setFilesArray($source === null ? [] : ['image' => $this->fileEntry($source)]);

        return $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->ownerToken)])
            ->post('v1/client/website/logo-upload', ['business_slug' => 'otter-cafe']);
    }

    private function fileEntry(string $source): array
    {
        return ['name' => 'logo.png', 'type' => 'image/png', 'tmp_name' => $source, 'error' => UPLOAD_ERR_OK, 'size' => filesize($source)];
    }

    private function image(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 255, 117, 51));
        $path = tempnam(sys_get_temp_dir(), 'logo');
        imagepng($image, $path);
        $this->sources[] = $path;

        return $path;
    }
}
