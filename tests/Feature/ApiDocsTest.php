<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The per-app OpenAPI documents.
 *
 * A developer reading one 94-endpoint document has to work out which half
 * belongs to them, and the answer is not obvious — /auth and /profile are
 * shared, and /orders means something different to a customer and a rider.
 */
class ApiDocsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function document(string $api): array
    {
        $body = $this->get("/docs/{$api}.json")->assertOk()->getContent();

        $json = json_decode($body, true);
        $this->assertIsArray($json, "{$api} document is not valid JSON");

        return $json;
    }

    /** @return list<string> */
    private function paths(string $api): array
    {
        return array_keys($this->document($api)['paths'] ?? []);
    }

    public function test_each_document_renders_and_is_valid_openapi(): void
    {
        foreach (['customer', 'rider', 'merchant'] as $api) {
            $doc = $this->document($api);

            $this->assertArrayHasKey('openapi', $doc);
            $this->assertNotEmpty($doc['paths'] ?? [], "{$api} document has no paths");
            $this->assertStringContainsString('Nexmile', $doc['info']['title']);

            $this->get("/docs/{$api}")->assertOk();
        }
    }

    public function test_the_customer_document_holds_the_ordering_journey(): void
    {
        $paths = $this->paths('customer');

        foreach ([
            '/v1/auth/otp/request',
            '/v1/addresses',
            '/v1/restaurants',
            '/v1/restaurants/deals',
            '/v1/orders',
        ] as $expected) {
            $this->assertContains($expected, $paths, "customer document is missing {$expected}");
        }
    }

    public function test_a_customer_is_not_shown_rider_or_merchant_endpoints(): void
    {
        $paths = $this->paths('customer');

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('/rider/', $path);
            $this->assertStringNotContainsString('/merchant/', $path);
            $this->assertStringNotContainsString('/admin/', $path);
        }
    }

    public function test_the_rider_document_holds_the_shift_and_shares_auth(): void
    {
        $paths = $this->paths('rider');

        // Riders sign in the same way customers do, so auth belongs in both.
        $this->assertContains('/v1/auth/otp/request', $paths);

        foreach ([
            '/v1/rider/kyc',
            '/v1/rider/duty-status',
            '/v1/rider/location',
            '/v1/rider/orders/available',
        ] as $expected) {
            $this->assertContains($expected, $paths, "rider document is missing {$expected}");
        }

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('/merchant/', $path);
            $this->assertStringNotContainsString('/restaurants/', $path);
        }
    }

    public function test_the_merchant_document_is_merchant_only(): void
    {
        $paths = $this->paths('merchant');

        $this->assertContains('/v1/merchant/login', $paths);
        $this->assertContains('/v1/merchant/menu-items', $paths);
        $this->assertContains('/v1/merchant/orders', $paths);

        // Merchants sign in with a password, so the shared OTP auth is not
        // theirs and would only mislead.
        foreach ($paths as $path) {
            $this->assertStringStartsWith('/v1/merchant/', $path);
        }
    }

    public function test_the_combined_document_still_covers_everything(): void
    {
        $all = array_keys(json_decode($this->get('/docs/api.json')->getContent(), true)['paths']);

        $split = array_unique(array_merge(
            $this->paths('customer'),
            $this->paths('rider'),
            $this->paths('merchant'),
        ));

        // Admin endpoints are deliberately in neither split document.
        $missing = array_diff($all, $split);

        foreach ($missing as $path) {
            $this->assertStringContainsString('/admin/', $path, "{$path} is in no app document");
        }
    }

    public function test_the_switch_that_hides_the_api_docs_hides_these_too(): void
    {
        config(['scramble.enabled' => false]);

        foreach (['customer', 'rider', 'merchant'] as $api) {
            $this->get("/docs/{$api}")->assertNotFound();
            $this->get("/docs/{$api}.json")->assertNotFound();
        }
    }
}
