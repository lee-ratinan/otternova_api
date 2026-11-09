<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * GET /health: 200 for the container's health check while / stays 403
 *
 * @internal
 */
final class HealthEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testHealthIsOkWithoutSigningIn(): void
    {
        $result = $this->get('health');
        $result->assertStatus(200);
        $result->assertJSONExact(['status' => 'ok']);
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
    }

    public function testTheRootIsStillForbidden(): void
    {
        $this->get('/')->assertStatus(403);
    }
}
