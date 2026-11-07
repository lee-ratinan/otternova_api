<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * GET /contact-form-token and POST /contact
 *
 * @internal
 */
final class ContactFormTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    protected function setUp(): void
    {
        parent::setUp();

        // a shared guard built in an earlier test would still hold that test's cache
        Services::resetSingle('contactFormGuard');
    }

    public function testTokenEndpointIssuesAToken(): void
    {
        $response = $this->get('v1/contact-form-token');

        $response->assertStatus(200);
        $data = json_decode($response->getJSON(), true)['data'];
        $this->assertMatchesRegularExpression('/^\d+\.[0-9a-f]{32}\.[0-9a-f]{64}$/', $data['form_token']);
        $this->assertSame(2 * HOUR, $data['expires_in']);
    }

    public function testStoresAValidMessage(): void
    {
        $response = $this->submit(['email' => ' Somchai@Example.COM ', 'phone' => '+66 81-234-5678', 'locale' => 'th']);

        $response->assertStatus(201);
        $response->assertJSONFragment(['status' => 'success']);
        $row = $this->db->table('contact_message')->get()->getRowArray();
        $this->assertSame('somchai@example.com', $row['email_address']);
        $this->assertSame('+66 81-234-5678', $row['phone_number']);
        $this->assertSame('th', $row['locale']);
        $this->assertSame('N', $row['status']);
        $this->assertNull($row['spam_reason']);
        $this->seeInDatabase('log_activity', ['table_involved' => 'contact_message', 'table_id_updated' => $row['id']]);
    }

    public function testPhoneIsOptional(): void
    {
        $this->submit(['phone' => null])->assertStatus(201);

        $this->assertNull($this->db->table('contact_message')->get()->getRowArray()['phone_number']);
    }

    public function testRejectsUnsupportedLocaleAndBadFields(): void
    {
        $response = $this->submit(['locale' => 'fr', 'name' => '', 'email' => 'not-an-email', 'phone' => 'call me', 'message' => '   ']);

        $response->assertStatus(422);
        $errors = json_decode($response->getJSON(), true)['errors'];
        $this->assertSame(['name', 'email', 'phone', 'message', 'locale'], array_keys($errors));
        $this->assertSame(0, $this->db->table('contact_message')->countAllResults());
    }

    public function testTokenStaysUsableAfterAValidationError(): void
    {
        $token = $this->token();
        $this->submit(['form_token' => $token, 'locale' => 'xx'])->assertStatus(422);

        $this->submit(['form_token' => $token])->assertStatus(201);
    }

    public function testRejectsMissingForgedTooNewAndUsedTokens(): void
    {
        $this->submit(['form_token' => null])->assertStatus(400);

        [$issuedAt, $nonce] = explode('.', $this->token());
        $this->submit(['form_token' => $issuedAt . '.' . $nonce . '.' . str_repeat('0', 64)])->assertStatus(400);

        $this->submit(['form_token' => service('contactFormGuard')->issueToken()])->assertStatus(400); // filled in too fast
        $this->submit(['form_token' => $this->token(time() - 3 * HOUR)])->assertStatus(400); // stale

        $token = $this->token();
        $this->submit(['form_token' => $token])->assertStatus(201);
        $this->submit(['form_token' => $token, 'message' => 'Another message'])->assertStatus(400);

        $this->assertSame(1, $this->db->table('contact_message')->countAllResults());
    }

    public function testHoneypotLooksAcceptedButStoresNothing(): void
    {
        $this->submit(['website' => 'http://spam.example'])->assertStatus(201);

        $this->assertSame(0, $this->db->table('contact_message')->countAllResults());
    }

    public function testSameMessageTwiceIsStoredOnce(): void
    {
        $this->submit(['message' => 'Hello   there'])->assertStatus(201);
        $this->submit(['message' => 'HELLO there'])->assertStatus(201);

        $this->assertSame(1, $this->db->table('contact_message')->countAllResults());
    }

    public function testLinkStuffedMessageIsKeptAsSpam(): void
    {
        $links = 'https://a.example https://b.example https://c.example https://d.example';
        $this->submit(['message' => $links])->assertStatus(201);

        $row = $this->db->table('contact_message')->get()->getRowArray();
        $this->assertSame('S', $row['status']);
        $this->assertSame('too_many_links', $row['spam_reason']);
    }

    public function testThrottlesPerEmailAddress(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->submit(['message' => 'Message ' . $i])->assertStatus(201);
        }

        $this->submit(['message' => 'Message 4'])->assertStatus(429);
        $this->assertSame(3, $this->db->table('contact_message')->countAllResults());
    }

    public function testThrottlesPerIpAddress(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->submit(['email' => "person{$i}@example.com"])->assertStatus(201);
        }

        $this->submit(['email' => 'person6@example.com'])->assertStatus(429);
    }

    /**
     * A token old enough to be accepted
     * @param int|null $issuedAt
     * @return string
     */
    private function token(?int $issuedAt = null): string
    {
        return service('contactFormGuard')->issueToken($issuedAt ?? time() - 10);
    }

    /**
     * @param array $overrides A null value leaves the field out
     * @return TestResponse
     */
    private function submit(array $overrides = []): TestResponse
    {
        $body = array_filter($overrides + [
            'name'       => 'Somchai Jaidee',
            'email'      => 'somchai@example.com',
            'phone'      => '',
            'subject'    => 'Question about plans',
            'message'    => 'Do you offer a yearly plan?',
            'locale'     => 'en-US',
            'form_token' => array_key_exists('form_token', $overrides) ? null : $this->token(),
            'website'    => '',
        ], static fn ($value) => $value !== null);

        return $this->withBodyFormat('json')->post('v1/contact', $body);
    }
}
