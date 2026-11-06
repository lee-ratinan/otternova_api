<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * The reviews of a business's artifacts: POST /client/artifact-reviews, /client/artifact-review/approve and
 * /client/artifact-review/hide
 *
 * @internal
 */
final class ArtifactReviewsTest extends CIUnitTestCase
{
    use ArtifactFixturesTrait;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private int $businessId;
    private string $ownerToken;
    private string $employeeToken;
    private int $artifactId;
    private string $artifact;
    private int $customers = 0;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        $this->businessId                    = $this->business('otter-shop');
        $this->ownerToken                    = $this->createClient('owner@example.com', $this->businessId, 'O');
        $this->employeeToken                 = $this->createClient('employee@example.com', $this->businessId, 'E');
        [$this->artifactId, $this->artifact] = $this->artifactRow($this->businessId);
    }

    public function testAnyClientOfTheBusinessListsTheReviews(): void
    {
        $five = $this->review($this->artifactId, 5, '2026-11-01 10:00:00');
        $four = $this->review($this->artifactId, 4, '2026-11-02 10:00:00', 'A');
        [$otherArtifact] = $this->artifactRow($this->businessId);
        $three = $this->review($otherArtifact, 3, '2026-11-03 10:00:00');
        // another business's review is not listed
        [$elsewhere] = $this->artifactRow($this->business('beaver-shop'));
        $this->review($elsewhere, 1, '2026-11-04 10:00:00');

        $response = $this->send('v1/client/artifact-reviews', ['business_slug' => 'otter-shop'], $this->employeeToken);
        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame(3, $data['records_total']);
        $this->assertSame([$three, $four, $five], array_column($data['rows'], 'public_id')); // newest first
        $this->assertSame([
            'public_id'          => $four,
            'artifact_public_id' => $this->artifact,
            'artifact_name'      => 'T-Shirt',
            'customer_name'      => 'Customer 2',
            'rating'             => 4,
            'review_title'       => 'Rated 4',
            'review_message'     => 'Lovely.',
            'review_status'      => 'A',
            'reply_message'      => null,
            'replied_at'         => null,
            'created_at'         => '2026-11-02 10:00:00',
        ], $data['rows'][1]);

        $page = fn (array $body) => $this->body($this->send('v1/client/artifact-reviews', ['business_slug' => 'otter-shop'] + $body))['data'];
        $this->assertSame([$five], array_column($page(['filters' => ['review_status' => 'p', 'artifact_public_id' => $this->artifact]])['rows'], 'public_id'));
        $this->assertSame(2, $page(['filters' => ['review_status' => 'P']])['records_filtered']);
        $this->assertSame([$four], array_column($page(['start' => 1, 'length' => 1])['rows'], 'public_id'));
        $this->assertSame(0, $page(['filters' => ['artifact_public_id' => 'nope']])['records_filtered']);
    }

    public function testApprovingAndHidingWorkOutTheRatingAgain(): void
    {
        $five = $this->review($this->artifactId, 5, '2026-11-01 10:00:00');
        $four = $this->review($this->artifactId, 4, '2026-11-02 10:00:00');

        $response = $this->send('v1/client/artifact-review/approve', ['public_id' => $five], $this->employeeToken);
        $response->assertStatus(200);
        $this->assertSame('A', $this->body($response)['data']['review']['review_status']);
        $this->send('v1/client/artifact-review/approve', ['public_id' => $four])->assertStatus(200);
        $this->seeInDatabase('artifact_master', ['id' => $this->artifactId, 'rating_sum' => 9, 'rating_count' => 2]);
        $this->assertSame(4.5, (float) $this->db->table('artifact_master')->where('id', $this->artifactId)->get()->getRow()->rating_average);

        $this->send('v1/client/artifact-review/hide', ['public_id' => $four])->assertStatus(200);
        $this->seeInDatabase('artifact_master', ['id' => $this->artifactId, 'rating_sum' => 5, 'rating_count' => 1]);
        $this->seeInDatabase('artifact_review', ['public_id' => $four, 'review_status' => 'H']);
        $this->seeInDatabase('log_activity', ['table_involved' => 'artifact_review', 'activity_summary' => 'Hid artifact review ' . $four]);

        // again: answered the same, nothing written
        $logs = $this->db->table('log_activity')->countAllResults();
        $this->send('v1/client/artifact-review/hide', ['public_id' => $four])->assertStatus(200);
        $this->assertSame($logs, $this->db->table('log_activity')->countAllResults());

        $this->send('v1/client/artifact-review/hide', ['public_id' => $five])->assertStatus(200);
        $row = $this->db->table('artifact_master')->where('id', $this->artifactId)->get()->getRow();
        $this->assertSame([0, 0, 0.0], [(int) $row->rating_sum, (int) $row->rating_count, (float) $row->rating_average]);
    }

    public function testTheArtifactAndTheBusinessCountEachNumberOfStars(): void
    {
        [$otherArtifact, $other] = $this->artifactRow($this->businessId);
        $reviews = [
            $this->review($this->artifactId, 5, '2026-11-01 10:00:00'),
            $this->review($this->artifactId, 5, '2026-11-01 11:00:00'),
            $this->review($this->artifactId, 2, '2026-11-01 12:00:00'),
            $this->review($otherArtifact, 4, '2026-11-01 13:00:00'),
        ];
        // another business's approved review counts towards its own business only
        $beaver = $this->business('beaver-shop');
        [$elsewhere] = $this->artifactRow($beaver);
        $this->review($elsewhere, 1, '2026-11-01 14:00:00', 'A');
        foreach ($reviews as $review) {
            $this->send('v1/client/artifact-review/approve', ['public_id' => $review])->assertStatus(200);
        }

        $rating = fn (string $table, int $id) => $this->db->table($table)
            ->select('rating_sum, rating_count, rating_average, rating_1_count, rating_2_count, rating_3_count, rating_4_count, rating_5_count')
            ->where('id', $id)->get()->getRowArray();
        $this->assertEquals(['rating_sum' => 12, 'rating_count' => 3, 'rating_average' => 4.0, 'rating_1_count' => 0, 'rating_2_count' => 1, 'rating_3_count' => 0, 'rating_4_count' => 0, 'rating_5_count' => 2], $rating('artifact_master', $this->artifactId));
        $this->assertEquals(['rating_sum' => 16, 'rating_count' => 4, 'rating_average' => 4.0, 'rating_1_count' => 0, 'rating_2_count' => 1, 'rating_3_count' => 0, 'rating_4_count' => 1, 'rating_5_count' => 2], $rating('business_master', $this->businessId));
        $this->assertSame(0, (int) $rating('business_master', $beaver)['rating_count']); // approved before, not through setStatus()

        // hiding one takes it out of both
        $this->send('v1/client/artifact-review/hide', ['public_id' => $reviews[0]])->assertStatus(200);
        $this->assertEquals(['rating_sum' => 7, 'rating_count' => 2, 'rating_average' => 3.5, 'rating_1_count' => 0, 'rating_2_count' => 1, 'rating_3_count' => 0, 'rating_4_count' => 0, 'rating_5_count' => 1], $rating('artifact_master', $this->artifactId));
        $this->assertEquals(['rating_sum' => 11, 'rating_count' => 3, 'rating_average' => 3.67, 'rating_1_count' => 0, 'rating_2_count' => 1, 'rating_3_count' => 0, 'rating_4_count' => 1, 'rating_5_count' => 1], $rating('business_master', $this->businessId));
        $this->seeInDatabase('log_activity', ['table_involved' => 'business_master', 'activity_summary' => 'Worked out the rating of business ' . $this->businessId . ' again']);

        // the list tells the business's rating, whatever the filters; the artifacts tell theirs
        $data = $this->body($this->send('v1/client/artifact-reviews', ['business_slug' => 'otter-shop', 'filters' => ['review_status' => 'H']], $this->employeeToken))['data'];
        $this->assertSame(['rating_count' => 3, 'rating_average' => '3.67', 'rating_breakdown' => [1 => 0, 2 => 1, 3 => 0, 4 => 1, 5 => 1]], $data['business_rating']);
        $artifacts = array_column($this->body($this->send('v1/client/artifacts', ['business_slug' => 'otter-shop']))['data']['artifacts'], 'rating_breakdown', 'public_id');
        $this->assertSame([1 => 0, 2 => 1, 3 => 0, 4 => 0, 5 => 1], $artifacts[$this->artifact]);
        $this->assertSame([1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 0], $artifacts[$other]);
        $shown = $this->body($this->send('v1/client/artifact', ['public_id' => $this->artifact]))['data']['artifact'];
        $this->assertSame([1 => 0, 2 => 1, 3 => 0, 4 => 0, 5 => 1], $shown['rating_breakdown']);
    }

    public function testOutsidersDoNotSeeTheReviews(): void
    {
        $review = $this->review($this->artifactId, 5, '2026-11-01 10:00:00');
        $other  = $this->createClient('other@example.com', $this->business('beaver-shop'), 'O');
        $former = $this->createClient('former@example.com', $this->businessId, 'E', 'T');
        foreach ([$other, $former] as $token) {
            $this->send('v1/client/artifact-reviews', ['business_slug' => 'otter-shop'], $token)->assertStatus(404);
            $this->send('v1/client/artifact-review/approve', ['public_id' => $review], $token)->assertStatus(404);
            $this->send('v1/client/artifact-review/hide', ['public_id' => $review], $token)->assertStatus(404);
        }
        $this->send('v1/client/artifact-review/approve', ['public_id' => 'nope'])->assertStatus(404);
        $this->send('v1/client/artifact-review/approve', [])->assertStatus(400);
        $this->seeInDatabase('artifact_review', ['public_id' => $review, 'review_status' => 'P']);
    }

    /** @return string Its public_id */
    private function review(int $artifactId, int $rating, string $createdAt, string $status = 'P'): string
    {
        $n = ++$this->customers;
        $this->db->table('customer_master')->insert(['public_id' => sprintf('cus%09d', $n), 'email_address' => 'customer' . $n . '@example.com', 'customer_name' => 'Customer ' . $n]);
        $publicId = $this->fixturePublicId();
        $this->db->table('artifact_review')->insert([
            'public_id'      => $publicId,
            'artifact_id'    => $artifactId,
            'customer_id'    => (int) $this->db->insertID(),
            'rating'         => $rating,
            'review_title'   => 'Rated ' . $rating,
            'review_message' => 'Lovely.',
            'review_status'  => $status,
            'created_at'     => $createdAt,
        ]);

        return $publicId;
    }
}
