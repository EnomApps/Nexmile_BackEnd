<?php

namespace Tests\Feature;

use Tests\TestCase;

class PostmanDownloadTest extends TestCase
{
    public function test_each_collection_downloads_as_valid_json(): void
    {
        foreach (['customer', 'rider', 'merchant'] as $app) {
            $response = $this->get("/docs/postman/{$app}")->assertOk();

            $body = $response->streamedContent();
            $this->assertNotNull(json_decode($body, true), "{$app} collection is not valid JSON");
            $response->assertDownload();
        }
    }

    public function test_the_index_lists_all_three(): void
    {
        $this->get('/docs/postman')
            ->assertOk()
            ->assertSee('Customer app')
            ->assertSee('Rider app')
            ->assertSee('Merchant');
    }

    public function test_nothing_outside_the_allowlist_is_reachable(): void
    {
        // Serving the docs directory would also serve DEPLOYMENT.md and
        // MAPS.md, which describe the infrastructure.
        foreach (['admin', 'README', '..%2FDEPLOYMENT', 'DEPLOYMENT.md'] as $attempt) {
            $this->get("/docs/postman/{$attempt}")->assertNotFound();
        }
    }

    public function test_the_switch_that_hides_the_api_docs_hides_these_too(): void
    {
        config(['scramble.enabled' => false]);

        // 404 rather than 403, so their existence is not advertised.
        $this->get('/docs/postman')->assertNotFound();
        $this->get('/docs/postman/customer')->assertNotFound();
    }
}
