<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A stale CSRF token should be a retry, not a dead end.
 *
 * The default is a bare "419 PAGE EXPIRED" on a blank page with nowhere to go.
 * It happens for an entirely ordinary reason — a sign-in page left open past
 * the session lifetime, which is what a merchant's browser does overnight —
 * and the remedy is always the same: load the form again.
 *
 * The check itself is untouched. Only the refusal is made survivable.
 */
class ExpiredPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Routes that throw the exception rather than routes protected by the
         * middleware that raises it.
         *
         * Laravel's ValidateCsrfToken skips itself whenever runningUnitTests()
         * is true, so the real middleware can never fire in a test. What is
         * worth testing here is the handler — the middleware already works and
         * is not ours.
         */
        Route::middleware('web')->post('/test-csrf', fn () => throw new TokenMismatchException);
        Route::middleware('web')->post('/admin/test-csrf', fn () => throw new TokenMismatchException);
    }

    public function test_a_stale_token_sends_a_merchant_back_to_sign_in(): void
    {
        $response = $this->post('/test-csrf', ['_token' => 'a-token-from-yesterday']);

        $response->assertRedirect(route('merchants.login'));

        // Said in words a shopkeeper can act on, rather than a status code.
        $this->assertStringContainsString(
            'sign in again',
            (string) session('status'),
        );
    }

    public function test_an_admin_goes_to_the_admin_form_not_the_merchant_one(): void
    {
        // Two sign-in pages, and being bounced to the wrong one is its own
        // small dead end.
        $this->post('/admin/test-csrf', ['_token' => 'stale'])
            ->assertRedirect(route('admin.login'));
    }

    public function test_an_api_caller_gets_json_not_a_redirect(): void
    {
        // A phone following a redirect to an HTML sign-in page learns nothing.
        $this->postJson('/test-csrf', ['_token' => 'stale'])
            ->assertStatus(419)
            ->assertJsonPath('message', 'Your session expired. Sign in again.');
    }

    public function test_what_they_typed_survives_the_bounce(): void
    {
        $this->post('/test-csrf', [
            '_token' => 'stale',
            'email' => 'shop@example.in',
            'password' => 'hunter2',
        ])->assertRedirect();

        // The email comes back so they are not retyping it; the password does
        // not, because a flashed password is a password written to the session
        // store and then to a log the first time something goes wrong.
        $this->assertSame('shop@example.in', session('_old_input.email'));
        $this->assertArrayNotHasKey('password', (array) session('_old_input'));
    }

    public function test_the_sign_in_page_says_what_happened(): void
    {
        /*
         * A redirect to a form that explains nothing is the same dead end
         * wearing a friendlier URL. The message has to actually render.
         */
        $this->followingRedirects()
            ->post('/test-csrf', ['_token' => 'stale'])
            ->assertOk()
            ->assertSee('Please sign in again.', false);
    }

    public function test_the_request_is_still_refused(): void
    {
        /*
         * The point worth protecting. A friendlier refusal must still be a
         * refusal: the redirect goes to a sign-in page, never onward to
         * whatever was being posted to.
         */
        $response = $this->post('/test-csrf', ['_token' => 'stale']);

        $response->assertRedirect(route('merchants.login'));
        $this->assertStringNotContainsString('reached', $response->getContent());
    }
}
