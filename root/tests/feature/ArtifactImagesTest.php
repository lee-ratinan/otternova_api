<?php

use App\Libraries\ImageProcessor;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\ImageUpload;
use Config\PlanLimits;
use Config\Services;

/**
 * POST /client/artifact/image-upload, /client/artifact/image-delete and /client/artifact/image-reorder, and the
 * artifact-image files served by GET image/artifact-image/{public_id}.webp
 *
 * @internal
 */
final class ArtifactImagesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private int $businessId;
    private string $ownerToken;
    private string $artifact;
    private int $artifactId;
    private string $variant;
    private int $seq = 0;

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
        // room for the tests that are not about the cap
        config(PlanLimits::class)->plans['free'][PlanLimits::IMAGE_PER_ARTIFACT] = 10;
        $this->uploads                        = sys_get_temp_dir() . '/otternova-artifact-images-' . bin2hex(random_bytes(4)) . '/';
        config(ImageUpload::class)->uploadPath = $this->uploads;

        $this->db->table('business_type')->insert(['public_id' => 'typ1', 'type_code' => 'shop']);
        $typeId           = (int) $this->db->insertID();
        $this->businessId = $this->business($typeId, 'otter-shop');
        $this->ownerToken = $this->createClient('owner@example.com', $this->businessId, 'O');

        [$this->artifactId, $this->artifact] = $this->artifact($this->businessId);
        $this->variant                       = $this->variant($this->businessId, $this->artifactId);
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

    public function testOwnerUploadsAnImageThatIsServedToAnyone(): void
    {
        $response = $this->upload(['artifact_public_id' => $this->artifact], $this->image(1200, 1500));
        $response->assertStatus(200);
        $data = $this->body($response)['data'];

        $this->assertNull($data['variant_public_id']);
        $this->assertSame(0, $data['sort_order']);
        $this->assertSame(base_url('image/artifact-image/' . $data['public_id'] . '.webp'), $data['url']);
        $this->seeInDatabase('artifact_image', ['public_id' => $data['public_id'], 'artifact_id' => $this->artifactId, 'image_path' => $data['url']]);
        $this->seeInDatabase('log_activity', ['table_involved' => 'artifact_image']);

        $path = $this->uploads . 'artifact-image/' . $data['public_id'] . '.webp';
        $this->assertSame([1000, 1000], array_slice(getimagesize($path), 0, 2));
        $this->assertSame([], glob($this->uploads . 'artifact-image/.*.webp')); // no temporary file left

        $served = $this->withHeaders([])->get('image/artifact-image/' . $data['public_id'] . '.webp');
        $served->assertStatus(200);
        $this->assertSame('image/webp', $served->response()->getHeaderLine('Content-Type'));
    }

    public function testAnImageOfAVariantOfTheArtifact(): void
    {
        $response = $this->upload(['artifact_public_id' => $this->artifact, 'variant_public_id' => $this->variant], $this->image(1000, 1000));
        $response->assertStatus(200);
        $this->assertSame($this->variant, $this->body($response)['data']['variant_public_id']);

        // a variant of another artifact is refused, and nothing is written
        [$otherId] = $this->artifact($this->businessId);
        $response  = $this->upload(['artifact_public_id' => $this->artifact, 'variant_public_id' => $this->variant($this->businessId, $otherId)], $this->image(1000, 1000));
        $response->assertStatus(422);
        $this->assertArrayHasKey('variant_public_id', $this->body($response)['errors']);
        $this->upload(['artifact_public_id' => $this->artifact, 'variant_public_id' => 'nope'], $this->image(1000, 1000))->assertStatus(422);

        $this->seeNumRecords(1, 'artifact_image', ['artifact_id' => $this->artifactId]);
    }

    public function testTheFileIsChecked(): void
    {
        $this->upload(['artifact_public_id' => $this->artifact], null)->assertStatus(422);
        $this->upload(['artifact_public_id' => $this->artifact], $this->image(1000, 1000, 'gif'))->assertStatus(415);
        $this->upload(['artifact_public_id' => $this->artifact], $this->image(999, 1200))->assertStatus(422);

        $this->seeNumRecords(0, 'artifact_image', []);
        $this->assertSame([], glob($this->uploads . 'artifact-image/{,.}*.webp', GLOB_BRACE));
    }

    public function testAnArtifactHasAtMostTheMaximumOfImages(): void
    {
        config(PlanLimits::class)->plans['free'][PlanLimits::IMAGE_PER_ARTIFACT] = 2;
        $this->upload(['artifact_public_id' => $this->artifact], $this->image(1000, 1000))->assertStatus(200);
        $second = $this->body($this->upload(['artifact_public_id' => $this->artifact], $this->image(1000, 1000)))['data'];
        $this->assertSame(1, $second['sort_order']);

        $response = $this->upload(['artifact_public_id' => $this->artifact], $this->image(1000, 1000));
        $response->assertStatus(409);
        $this->assertSame(lang('ArtifactImage.limitReached', [2, 'Free']), $this->body($response)['message']);
        $this->seeNumRecords(2, 'artifact_image', ['artifact_id' => $this->artifactId]);
        $this->assertCount(2, glob($this->uploads . 'artifact-image/{,.}*.webp', GLOB_BRACE)); // the refused one's file is gone too

        // deleting one makes room again, after the last
        $this->send('v1/client/artifact/image-delete', ['public_id' => $second['public_id']])->assertStatus(200);
        $third = $this->body($this->upload(['artifact_public_id' => $this->artifact], $this->image(1000, 1000)))['data'];
        $this->assertSame(1, $third['sort_order']);
    }

    public function testDeleteRemovesTheRowAndTheFile(): void
    {
        $image = $this->body($this->upload(['artifact_public_id' => $this->artifact], $this->image(1000, 1000)))['data'];
        $path  = $this->uploads . 'artifact-image/' . $image['public_id'] . '.webp';
        $this->assertFileExists($path);

        $this->send('v1/client/artifact/image-delete', ['public_id' => $image['public_id']])->assertStatus(200);

        $this->dontSeeInDatabase('artifact_image', ['public_id' => $image['public_id']]);
        $this->assertFileDoesNotExist($path);
        $this->seeInDatabase('log_activity', ['table_involved' => 'artifact_image', 'activity_summary' => 'Deleted artifact image ' . $image['public_id']]);

        $this->send('v1/client/artifact/image-delete', ['public_id' => $image['public_id']])->assertStatus(404);
        $this->send('v1/client/artifact/image-delete', [])->assertStatus(400);
    }

    public function testReorderTakesEveryImageOnce(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->body($this->upload(['artifact_public_id' => $this->artifact], $this->image(1000, 1000)))['data']['public_id'];
        }

        foreach ([[$ids[0], $ids[1]], [$ids[0], $ids[1], $ids[1]], [$ids[0], $ids[1], 'nope'], 'x', [1, 2, 3]] as $order) {
            $response = $this->send('v1/client/artifact/image-reorder', ['artifact_public_id' => $this->artifact, 'order' => $order]);
            $response->assertStatus(422);
            $this->assertArrayHasKey('order', $this->body($response)['errors']);
        }

        $response = $this->send('v1/client/artifact/image-reorder', ['artifact_public_id' => $this->artifact, 'order' => [$ids[2], $ids[0], $ids[1]]]);
        $response->assertStatus(200);
        $images = $this->body($response)['data']['images'];
        $this->assertSame([$ids[2], $ids[0], $ids[1]], array_column($images, 'public_id'));
        $this->assertSame([0, 1, 2], array_column($images, 'sort_order'));
    }

    public function testOnlyActiveOwnersAndManagersOfTheArtifactsBusinessGetIn(): void
    {
        $image = $this->body($this->upload(['artifact_public_id' => $this->artifact], $this->image(1000, 1000)))['data']['public_id'];

        $manager = $this->createClient('manager@example.com', $this->businessId, 'M');
        $this->upload(['artifact_public_id' => $this->artifact], $this->image(1000, 1000), $manager)->assertStatus(200);

        $employee = $this->createClient('employee@example.com', $this->businessId, 'E');
        $this->upload(['artifact_public_id' => $this->artifact], $this->image(1000, 1000), $employee)->assertStatus(403);
        $this->send('v1/client/artifact/image-delete', ['public_id' => $image], $employee)->assertStatus(403);
        $this->send('v1/client/artifact/image-reorder', ['artifact_public_id' => $this->artifact, 'order' => [$image]], $employee)->assertStatus(403);

        // the owner of another business, a former owner and a stranger do not even see it
        $otherOwner = $this->createClient('other@example.com', $this->business((int) $this->db->table('business_type')->get()->getRow()->id, 'beaver-shop'), 'O');
        $former     = $this->createClient('former@example.com', $this->businessId, 'O', 'T');
        $stranger   = $this->createClient('stranger@example.com', null, null);
        foreach ([$otherOwner, $former, $stranger] as $outsider) {
            $this->upload(['artifact_public_id' => $this->artifact], $this->image(1000, 1000), $outsider)->assertStatus(404);
            $this->send('v1/client/artifact/image-delete', ['public_id' => $image], $outsider)->assertStatus(404);
            $this->send('v1/client/artifact/image-reorder', ['artifact_public_id' => $this->artifact, 'order' => [$image]], $outsider)->assertStatus(404);
        }

        $this->upload(['artifact_public_id' => 'nope'], $this->image(1000, 1000))->assertStatus(404);
        $this->upload([], $this->image(1000, 1000))->assertStatus(400);
        $this->seeNumRecords(2, 'artifact_image', ['artifact_id' => $this->artifactId]);
    }

    public function testTheGenericImageEndpointsRefuseArtifactImages(): void
    {
        $image = $this->body($this->upload(['artifact_public_id' => $this->artifact], $this->image(1000, 1000)))['data'];
        $row   = $this->db->table('artifact_image')->where('public_id', $image['public_id'])->get()->getRow();

        service('superglobals')->setFilesArray(['image' => $this->fileEntry($this->image(1000, 1000))]);
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->ownerToken])
            ->post('v1/client/image-upload', ['used-for' => 'artifact-image', 'image-name' => 'hijack', 'id' => $row->id])
            ->assertStatus(403);
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->ownerToken])->withBodyFormat('json')
            ->post('v1/client/image-delete', ['used-for' => 'artifact-image', 'id' => $row->id])
            ->assertStatus(403);

        $this->seeInDatabase('artifact_image', ['id' => $row->id, 'image_path' => $image['url']]);
    }

    private function upload(array $fields, ?string $source, ?string $token = null): TestResponse
    {
        service('superglobals')->setFilesArray($source === null ? [] : ['image' => $this->fileEntry($source)]);

        return $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->ownerToken)])
            ->post('v1/client/artifact/image-upload', $fields);
    }

    private function send(string $path, array $body, ?string $token = null): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->ownerToken)])
            ->withBodyFormat('json')
            ->post($path, $body);
    }

    private function fileEntry(string $source): array
    {
        return ['name' => 'photo.jpg', 'type' => 'image/jpeg', 'tmp_name' => $source, 'error' => UPLOAD_ERR_OK, 'size' => filesize($source)];
    }

    private function image(int $width, int $height, string $type = 'jpeg'): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 0, 128, 255));
        $path = tempnam(sys_get_temp_dir(), 'img');
        $type === 'gif' ? imagegif($image, $path) : imagejpeg($image, $path, 90);
        $this->sources[] = $path;

        return $path;
    }

    private function body(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
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

    /** @return array{int, string} [id, public_id] */
    private function artifact(int $businessId): array
    {
        $publicId = $this->publicId();
        $this->db->table('artifact_master')->insert([
            'public_id'       => $publicId,
            'business_id'     => $businessId,
            'artifact_name'   => 'T-Shirt',
            'artifact_type'   => 'P',
            'tax_calculation' => 'X',
        ]);

        return [(int) $this->db->insertID(), $publicId];
    }

    private function variant(int $businessId, int $artifactId): string
    {
        $publicId = $this->publicId();
        $this->db->table('artifact_variant')->insert(['public_id' => $publicId, 'business_id' => $businessId, 'artifact_id' => $artifactId, 'price_active' => '299.00']);

        return $publicId;
    }

    /**
     * A client account, linked to the business with $role and $status when given
     * @return string Its access token
     */
    private function createClient(string $email, ?int $businessId, ?string $role, string $status = 'A'): string
    {
        $account = [
            'uid'             => preg_replace('/[^a-z0-9]/', '', $email),
            'email_address'   => $email,
            'password_hash'   => 'x',
            'password_expiry' => '2027-01-01',
            'full_name'       => 'Otter Tester',
            'otp_mode'        => 'X',
            'account_status'  => 'A',
            'account_type'    => 'C',
        ];
        $this->db->table('auth_master')->insert($account + ['public_id' => bin2hex(random_bytes(6))]);
        $account['id'] = (int) $this->db->insertID();
        $this->db->table('client_master')->insert(['auth_id' => $account['id']]);
        $clientId = (int) $this->db->insertID();
        if ($businessId !== null && $role !== null) {
            $this->db->table('business_client_link')->insert(['business_id' => $businessId, 'client_id' => $clientId, 'client_role' => $role, 'client_status' => $status,
                'start_date' => '2026-01-01', 'termination_date' => $status === 'T' ? '2026-02-01' : null]);
        }

        return service('jwt')->issue($account)['access_token'];
    }
}
