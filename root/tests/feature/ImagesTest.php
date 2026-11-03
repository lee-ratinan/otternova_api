<?php

use App\Libraries\ImageProcessor;
use App\Libraries\InitialsAvatar;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\ImageUpload;
use Config\Services;

/**
 * POST /staff/image-upload, /client/image-upload, /staff/image-delete and /client/image-delete,
 * and GET image/{used-for}/{image-name}.webp
 *
 * @internal
 */
final class ImagesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    private array $staff;
    private string $staffToken;
    private array $client;
    private string $clientToken;

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

        $this->uploads = sys_get_temp_dir() . '/otternova-images-' . bin2hex(random_bytes(4)) . '/';
        $config                        = config(ImageUpload::class);
        $config->uploadPath            = $this->uploads;
        $config->usedFor['staff-badge'] = [
            'uploaders'   => ['S'],
            'width'       => 200,
            'height'      => 100,
            'target'      => 'auth_master.avatar_path',
            'ownerColumn' => 'id',
            'ownRowOnly'  => [],
            'access'      => ImageUpload::ACCESS_SIGNED_IN,
        ];
        $config->usedFor['client-card'] = ['uploaders' => ['C']] + $config->usedFor['staff-badge'];

        $this->staff       = $this->createStaff('admin@otternova.com');
        $this->staffToken  = service('jwt')->issue($this->staff)['access_token'];
        $this->client      = $this->createClient('client@example.com');
        $this->clientToken = service('jwt')->issue($this->client)['access_token'];
    }

    protected function tearDown(): void
    {
        service('superglobals')->setFilesArray([]);
        foreach ($this->sources as $source) {
            @unlink($source);
        }
        foreach (glob($this->uploads . '{,.}*/{,.}*.{webp,svg}', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->uploads . '*', GLOB_ONLYDIR) ?: [] as $folder) {
            rmdir($folder);
        }
        @rmdir($this->uploads);

        parent::tearDown();
    }

    // ---- request checks ----

    public function testUsedForMissingOrUnknownIsRejected(): void
    {
        foreach ([null, '', 'banner', ['profile-picture']] as $usedFor) {
            $fields = ['image-name' => 'me', 'id' => $this->client['id']];
            if ($usedFor !== null) {
                $fields['used-for'] = $usedFor;
            }
            $response = $this->upload($fields, $this->image('jpeg', 600, 600));

            $response->assertStatus(422);
            $this->assertArrayHasKey('used-for', $this->body($response)['errors']);
        }
    }

    public function testUsedForOfTheOtherAccountTypeIsForbidden(): void
    {
        $this->upload(['used-for' => 'staff-badge', 'image-name' => 'x', 'id' => $this->client['id']], $this->image('jpeg', 600, 600))
            ->assertStatus(403);
        $this->upload(['used-for' => 'client-card', 'image-name' => 'x', 'id' => $this->staff['id']], $this->image('jpeg', 600, 600), $this->staffToken, 'staff')
            ->assertStatus(403);
    }

    public function testImageNameMustBeSafe(): void
    {
        foreach (['', '../evil', 'a.webp', '.hidden', 'a/b', str_repeat('a', 101)] as $name) {
            $response = $this->upload(['used-for' => 'profile-picture', 'image-name' => $name, 'id' => $this->client['id']], $this->image('jpeg', 600, 600));

            $response->assertStatus(422);
            $this->assertArrayHasKey('image-name', $this->body($response)['errors']);
        }
    }

    public function testIdMustBeAPositiveInteger(): void
    {
        foreach (['', '0', '-1', 'abc', '1.5'] as $id) {
            $this->upload(['used-for' => 'profile-picture', 'image-name' => 'me', 'id' => $id], $this->image('jpeg', 600, 600))
                ->assertStatus(422);
        }
    }

    public function testImageIsRequired(): void
    {
        $this->upload(['used-for' => 'profile-picture', 'image-name' => 'me', 'id' => $this->client['id']], null)
            ->assertStatus(422);
    }

    public function testOnlyJpegPngAndWebpAreAccepted(): void
    {
        // a GIF named .jpg: the type is read from the content
        $this->upload(['used-for' => 'profile-picture', 'image-name' => 'me', 'id' => $this->client['id']], $this->image('gif', 600, 600), null, 'client', 'me.jpg')
            ->assertStatus(415);
        $this->assertFileDoesNotExist($this->uploads . 'profile-picture/me.webp');
    }

    public function testTooSmallIsRejected(): void
    {
        $response = $this->upload(['used-for' => 'profile-picture', 'image-name' => 'me', 'id' => $this->client['id']], $this->image('jpeg', 600, 511));

        $response->assertStatus(422);
        $this->assertSame(lang('ImageUpload.tooSmall', [512, 512]), $this->body($response)['message']);
        $this->assertSame([], glob($this->uploads . 'profile-picture/{,.}*.webp', GLOB_BRACE));
        $this->assertNull($this->avatarOf($this->client['id']));
    }

    public function testUnreadableImageIsRejected(): void
    {
        $source = $this->image('jpeg', 600, 600);
        file_put_contents($source, substr((string) file_get_contents($source), 0, 200)); // still detected as JPEG

        $this->upload(['used-for' => 'profile-picture', 'image-name' => 'me', 'id' => $this->client['id']], $source)
            ->assertStatus(422);
    }

    // ---- saving ----

    public function testClientUploadsOwnProfilePicture(): void
    {
        $response = $this->upload(['used-for' => 'profile-picture', 'image-name' => 'client-1', 'id' => $this->client['id']], $this->image('jpeg', 1000, 700));

        $response->assertStatus(200);
        $url = base_url('image/profile-picture/client-1.webp');
        $this->assertSame(['used_for' => 'profile-picture', 'url' => $url], $this->body($response)['data']);
        $this->assertSame($url, $this->avatarOf($this->client['id']));
        $saved = getimagesize($this->uploads . 'profile-picture/client-1.webp');
        $this->assertSame([512, 512, IMAGETYPE_WEBP], [$saved[0], $saved[1], $saved[2]]);
        $this->seeInDatabase('log_activity', ['table_involved' => 'auth_master', 'table_id_updated' => $this->client['id'], 'changed_by_acc' => $this->client['id']]);
    }

    public function testPngAndWebpAreResizedToo(): void
    {
        foreach (['png' => 'staff-badge', 'webp' => 'staff-badge'] as $type => $usedFor) {
            $this->upload(['used-for' => $usedFor, 'image-name' => 'badge-' . $type, 'id' => $this->staff['id']], $this->image($type, 300, 300), $this->staffToken, 'staff')
                ->assertStatus(200);

            $saved = getimagesize($this->uploads . $usedFor . '/badge-' . $type . '.webp');
            $this->assertSame([200, 100, IMAGETYPE_WEBP], [$saved[0], $saved[1], $saved[2]]);
        }
    }

    public function testJpegIsTurnedUprightByItsExifOrientation(): void
    {
        // left half red, right half blue, stored sideways: orientation 6 turns it a quarter clockwise, red on top
        $this->upload(['used-for' => 'profile-picture', 'image-name' => 'upright', 'id' => $this->client['id']], $this->image('jpeg', 1200, 800, 6))
            ->assertStatus(200);

        $saved = imagecreatefromwebp($this->uploads . 'profile-picture/upright.webp');
        $this->assertGreaterThan(200, imagecolorat($saved, 256, 20) >> 16 & 0xFF, 'red on top');
        $this->assertGreaterThan(200, imagecolorat($saved, 256, 490) & 0xFF, 'blue at the bottom');
    }

    public function testClientCannotChangeAnotherAccount(): void
    {
        $other = $this->createClient('other@example.com');

        $this->upload(['used-for' => 'profile-picture', 'image-name' => 'me', 'id' => $other['id']], $this->image('jpeg', 600, 600))
            ->assertStatus(403);
        $this->assertNull($this->avatarOf($other['id']));
    }

    public function testStaffCanChangeAnyAccount(): void
    {
        $this->upload(['used-for' => 'profile-picture', 'image-name' => 'for-client', 'id' => $this->client['id']], $this->image('jpeg', 600, 600), $this->staffToken, 'staff')
            ->assertStatus(200);

        $this->assertSame(base_url('image/profile-picture/for-client.webp'), $this->avatarOf($this->client['id']));
        $this->seeInDatabase('log_activity', ['table_id_updated' => $this->client['id'], 'changed_by_acc' => $this->staff['id']]);
    }

    public function testUnknownRowIsNotFound(): void
    {
        $this->upload(['used-for' => 'profile-picture', 'image-name' => 'ghost', 'id' => 99999], $this->image('jpeg', 600, 600), $this->staffToken, 'staff')
            ->assertStatus(404);
    }

    public function testNameOfAnotherRowIsRefusedButOwnNameIsReplaced(): void
    {
        $fields = ['used-for' => 'profile-picture', 'image-name' => 'shared'];
        $this->upload($fields + ['id' => $this->staff['id']], $this->image('jpeg', 600, 600), $this->staffToken, 'staff')->assertStatus(200);
        $before = (string) file_get_contents($this->uploads . 'profile-picture/shared.webp');

        $this->upload($fields + ['id' => $this->client['id']], $this->image('png', 700, 700))->assertStatus(409);
        $this->assertSame($before, file_get_contents($this->uploads . 'profile-picture/shared.webp'));

        $this->upload($fields + ['id' => $this->staff['id']], $this->image('png', 700, 700), $this->staffToken, 'staff')->assertStatus(200);
        $this->assertNotSame($before, file_get_contents($this->uploads . 'profile-picture/shared.webp'));
    }

    public function testNewNameRemovesThePreviousFile(): void
    {
        $fields = ['used-for' => 'profile-picture', 'id' => $this->client['id']];
        $this->upload($fields + ['image-name' => 'first'], $this->image('jpeg', 600, 600))->assertStatus(200);
        $this->upload($fields + ['image-name' => 'second'], $this->image('jpeg', 600, 600))->assertStatus(200);

        $this->assertFileDoesNotExist($this->uploads . 'profile-picture/first.webp');
        $this->assertFileExists($this->uploads . 'profile-picture/second.webp');
        $this->assertSame(base_url('image/profile-picture/second.webp'), $this->avatarOf($this->client['id']));
    }

    // ---- deleting ----

    public function testClientDeletesOwnImage(): void
    {
        $this->upload(['used-for' => 'profile-picture', 'image-name' => 'me', 'id' => $this->client['id']], $this->image('jpeg', 600, 600))->assertStatus(200);

        $response = $this->deleteImage(['used-for' => 'profile-picture', 'id' => $this->client['id']]);

        $response->assertStatus(200);
        // the app shows the initials avatar of "Otter Tester" in place of the deleted picture
        $this->assertSame(['used_for' => 'profile-picture', 'url' => base_url('image/initials-avatar/OT.svg')], $this->body($response)['data']);
        $this->assertFileExists($this->uploads . 'initials-avatar/OT.svg');
        $this->assertFileDoesNotExist($this->uploads . 'profile-picture/me.webp');
        $this->assertNull($this->avatarOf($this->client['id']));
        $this->seeInDatabase('log_activity', ['activity_summary' => 'Deleted profile-picture me', 'table_id_updated' => $this->client['id'], 'changed_by_acc' => $this->client['id']]);
        $this->withHeaders([])->get('image/profile-picture/me.webp')->assertStatus(404);
    }

    public function testDeleteTakesFormFieldsToo(): void
    {
        $this->upload(['used-for' => 'profile-picture', 'image-name' => 'me', 'id' => $this->client['id']], $this->image('jpeg', 600, 600))->assertStatus(200);

        $this->deleteImage(['used-for' => 'profile-picture', 'id' => (string) $this->client['id']], null, 'client', false)->assertStatus(200);
        $this->assertNull($this->avatarOf($this->client['id']));
    }

    public function testDeletedNameIsFreeAgain(): void
    {
        $other = $this->createClient('other@example.com');
        $this->upload(['used-for' => 'profile-picture', 'image-name' => 'taken', 'id' => $other['id']], $this->image('jpeg', 600, 600), $this->staffToken, 'staff')->assertStatus(200);
        $this->deleteImage(['used-for' => 'profile-picture', 'id' => $other['id']], $this->staffToken, 'staff')->assertStatus(200);

        $this->upload(['used-for' => 'profile-picture', 'image-name' => 'taken', 'id' => $this->client['id']], $this->image('jpeg', 600, 600))->assertStatus(200);
    }

    public function testStaffDeletesAnyAccountsImage(): void
    {
        $this->upload(['used-for' => 'profile-picture', 'image-name' => 'me', 'id' => $this->client['id']], $this->image('jpeg', 600, 600))->assertStatus(200);

        $this->deleteImage(['used-for' => 'profile-picture', 'id' => $this->client['id']], $this->staffToken, 'staff')->assertStatus(200);
        $this->assertFileDoesNotExist($this->uploads . 'profile-picture/me.webp');
        $this->seeInDatabase('log_activity', ['activity_summary' => 'Deleted profile-picture me', 'changed_by_acc' => $this->staff['id']]);
    }

    public function testClientCannotDeleteAnotherAccountsImage(): void
    {
        $other = $this->createClient('other@example.com');
        $this->upload(['used-for' => 'profile-picture', 'image-name' => 'theirs', 'id' => $other['id']], $this->image('jpeg', 600, 600), $this->staffToken, 'staff')->assertStatus(200);

        $this->deleteImage(['used-for' => 'profile-picture', 'id' => $other['id']])->assertStatus(403);
        $this->assertFileExists($this->uploads . 'profile-picture/theirs.webp');
        $this->assertSame(base_url('image/profile-picture/theirs.webp'), $this->avatarOf($other['id']));
    }

    public function testDeleteChecksUsedForAndId(): void
    {
        $this->deleteImage(['id' => $this->client['id']])->assertStatus(422);
        $this->deleteImage(['used-for' => 'banner', 'id' => $this->client['id']])->assertStatus(422);
        $this->deleteImage(['used-for' => 'staff-badge', 'id' => $this->client['id']])->assertStatus(403);
        $this->deleteImage(['used-for' => 'client-card', 'id' => $this->staff['id']], $this->staffToken, 'staff')->assertStatus(403);
        foreach ([null, 0, -1, 'abc', 1.5] as $id) {
            $this->deleteImage(['used-for' => 'profile-picture', 'id' => $id])->assertStatus(422);
        }
        $this->deleteImage(['used-for' => 'profile-picture', 'id' => 99999], $this->staffToken, 'staff')->assertStatus(404);
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->clientToken, 'Content-Type' => 'application/json'])
            ->withBody('{not json')->post('v1/client/image-delete')->assertStatus(422);
    }

    public function testFileThatWillNotGoLeavesTheRowAsItWas(): void
    {
        $this->upload(['used-for' => 'profile-picture', 'image-name' => 'stuck', 'id' => $this->client['id']], $this->image('jpeg', 600, 600))->assertStatus(200);
        chmod($this->uploads . 'profile-picture', 0555); // a file cannot be deleted from a read-only folder

        try {
            $this->deleteImage(['used-for' => 'profile-picture', 'id' => $this->client['id']])->assertStatus(500);
        } finally {
            chmod($this->uploads . 'profile-picture', 0755);
        }
        $this->assertFileExists($this->uploads . 'profile-picture/stuck.webp');
        $this->assertSame(base_url('image/profile-picture/stuck.webp'), $this->avatarOf($this->client['id']));
        $this->dontSeeInDatabase('log_activity', ['activity_summary' => 'Deleted profile-picture stuck']);
    }

    public function testNothingToDeleteIsNotFound(): void
    {
        $response = $this->deleteImage(['used-for' => 'profile-picture', 'id' => $this->client['id']]);

        $response->assertStatus(404);
        $this->assertSame(lang('ImageUpload.noImage'), $this->body($response)['message']);
    }

    public function testValueThatIsNotAnUploadIsClearedWithoutTouchingFiles(): void
    {
        $this->upload(['used-for' => 'profile-picture', 'image-name' => 'keep', 'id' => $this->staff['id']], $this->image('jpeg', 600, 600), $this->staffToken, 'staff')->assertStatus(200);
        $this->db->table('auth_master')->where('id', $this->client['id'])->update(['avatar_path' => 'https://elsewhere.example/keep.webp']);

        $this->deleteImage(['used-for' => 'profile-picture', 'id' => $this->client['id']])->assertStatus(200);
        $this->assertNull($this->avatarOf($this->client['id']));
        $this->assertFileExists($this->uploads . 'profile-picture/keep.webp');
    }

    // ---- serving ----

    public function testSignedInImageNeedsAToken(): void
    {
        $this->uploadBadge();

        $this->withHeaders([])->get('image/staff-badge/badge.webp')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer not-a-jwt'])->get('image/staff-badge/badge.webp')->assertStatus(401);
        // and the answer is the same whether the image exists or not
        $this->withHeaders([])->get('image/staff-badge/nobody.webp')->assertStatus(401);
    }

    public function testSignedInImageIsServedToStaffAndClients(): void
    {
        $this->uploadBadge();

        foreach ([$this->staffToken, $this->clientToken] as $token) {
            $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->get('image/staff-badge/badge.webp');

            $response->assertStatus(200);
            $response->assertHeader('Content-Type', 'image/webp');
            $this->assertSame('private, max-age=300', $response->response()->getHeaderLine('Cache-Control'));
            $this->assertSame(file_get_contents($this->uploads . 'staff-badge/badge.webp'), $response->response()->getBody());
        }
    }

    public function testProfilePictureIsPublicAndAnswersNotModified(): void
    {
        $this->upload(['used-for' => 'profile-picture', 'image-name' => 'me', 'id' => $this->client['id']], $this->image('jpeg', 600, 600))->assertStatus(200);

        $response = $this->withHeaders([])->get('image/profile-picture/me.webp');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/webp');
        $this->assertSame('public, max-age=300', $response->response()->getHeaderLine('Cache-Control'));
        $this->assertSame(file_get_contents($this->uploads . 'profile-picture/me.webp'), $response->response()->getBody());

        $etag = $response->response()->getHeaderLine('ETag');
        $this->withHeaders(['If-None-Match' => $etag])->get('image/profile-picture/me.webp')->assertStatus(304);
    }

    public function testUnknownUsedForOrNameIsNotFound(): void
    {
        $auth = ['Authorization' => 'Bearer ' . $this->clientToken];
        foreach (['image/banner/x.webp', 'image/profile-picture/nobody.webp', 'image/profile-picture/me.png', 'image/profile-picture/..webp'] as $uri) {
            $this->withHeaders($auth)->get($uri)->assertStatus(404);
        }
    }

    public function testInitialsAvatarIsPublicAndAnswersNotModified(): void
    {
        $url  = InitialsAvatar::urlFor('สมชาย ใจดี');
        $path = $this->uploads . 'initials-avatar/สจ.svg';
        $this->assertSame(base_url('image/initials-avatar/' . rawurlencode('สจ') . '.svg'), $url);

        $response = $this->withHeaders([])->get('image/initials-avatar/' . rawurlencode('สจ') . '.svg');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertSame('public, max-age=300', $response->response()->getHeaderLine('Cache-Control'));
        $this->assertSame(file_get_contents($path), $response->response()->getBody());

        $etag = $response->response()->getHeaderLine('ETag');
        $this->withHeaders(['If-None-Match' => $etag])->get('image/initials-avatar/' . rawurlencode('สจ') . '.svg')->assertStatus(304);
    }

    public function testInitialsAvatarIsOnlyServedWhenMade(): void
    {
        InitialsAvatar::urlFor('Otter Tester');
        foreach (['image/initials-avatar/AB.svg', 'image/initials-avatar/OT.webp', 'image/initials-avatar/..svg', 'image/initials-avatar/O.T.svg'] as $uri) {
            $this->withHeaders([])->get($uri)->assertStatus(404);
        }
        $this->assertSame(['OT.svg'], array_map('basename', glob($this->uploads . 'initials-avatar/*')));
    }

    // ---- helpers ----

    /**
     * @param bool $json JSON body; false sends form fields
     */
    private function deleteImage(array $body, ?string $token = null, string $app = 'client', bool $json = true): TestResponse
    {
        $request = $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->clientToken)]);
        if ($json) {
            $request = $request->withBodyFormat('json');
        }

        return $request->post('v1/' . $app . '/image-delete', $body);
    }

    /** A staff-badge (signed-in only) for the staff account */
    private function uploadBadge(): void
    {
        $this->upload(['used-for' => 'staff-badge', 'image-name' => 'badge', 'id' => $this->staff['id']], $this->image('png', 300, 300), $this->staffToken, 'staff')
            ->assertStatus(200);
    }

    /**
     * @param array       $fields   Form fields
     * @param string|null $source   The image file; null sends none
     * @param string      $app      staff or client, the endpoint to call
     */
    private function upload(array $fields, ?string $source, ?string $token = null, string $app = 'client', string $clientName = 'photo.jpg'): TestResponse
    {
        service('superglobals')->setFilesArray($source === null ? [] : ['image' => [
            'name'     => $clientName,
            'type'     => 'image/jpeg',
            'tmp_name' => $source,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($source),
        ]]);

        return $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->clientToken)])
            ->post('v1/' . $app . '/image-upload', $fields);
    }

    /**
     * A temporary image: left half red, right half blue
     * @param int|null $orientation EXIF orientation to add to a JPEG
     */
    private function image(string $type, int $width, int $height, ?int $orientation = null): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, imagecolorallocate($image, 0, 0, 255));

        $path = tempnam(sys_get_temp_dir(), 'img');
        match ($type) {
            'jpeg' => imagejpeg($image, $path, 95),
            'png'  => imagepng($image, $path),
            'webp' => imagewebp($image, $path),
            'gif'  => imagegif($image, $path),
        };
        if ($orientation !== null) {
            // an APP1 Exif segment with one IFD entry, Orientation (0x0112, SHORT), right after the SOI marker
            $tiff = "II*\x00" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, $orientation, 0) . pack('V', 0);
            $app1 = "\xFF\xE1" . pack('n', 2 + 6 + strlen($tiff)) . "Exif\x00\x00" . $tiff;
            $jpeg = (string) file_get_contents($path);
            file_put_contents($path, substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2));
        }
        $this->sources[] = $path;

        return $path;
    }

    private function avatarOf(int $id): ?string
    {
        return $this->db->table('auth_master')->select('avatar_path')->where('id', $id)->get()->getRow()->avatar_path;
    }

    private function body(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }

    private function createAccount(string $email, array $overrides = []): array
    {
        $account = $overrides + [
            'uid'              => preg_replace('/[^a-z0-9]/', '', $email),
            'email_address'    => $email,
            'password_hash'    => '$2y$12$dOj6FCfe3Bh1RnqoU9Mb1uV2cbYYofQOtT3A1bJE8H2HP2cXIiOV.',
            'password_expiry'  => '2027-01-01',
            'full_name'        => 'Otter Tester',
            'otp_mode'         => 'X',
            'account_status'   => 'A',
            'account_type'     => 'C',
        ];
        $this->db->table('auth_master')->insert($account);
        $account['id'] = (int) $this->db->insertID();

        return $account;
    }

    private function createStaff(string $email): array
    {
        $account = $this->createAccount($email, ['account_type' => 'S']);
        $this->db->table('otternova_department')->insert(['department_code' => 'ENG', 'department_name' => 'Engineering', 'country_code' => 'TH']);
        $this->db->table('staff_master')->insert([
            'auth_id'               => $account['id'],
            'department_id'         => (int) $this->db->insertID(),
            'employee_code'         => 'EMP-' . $account['id'],
            'job_title'             => 'Engineer',
            'employment_status'     => 'A',
            'employment_start_date' => '2024-01-01',
            'work_country_code'     => 'TH',
            'legal_first_name'      => 'Otter',
            'legal_last_name'       => 'Tester',
        ]);

        return $account;
    }

    private function createClient(string $email): array
    {
        $account = $this->createAccount($email);
        $this->db->table('client_master')->insert(['auth_id' => $account['id']]);

        return $account;
    }
}
