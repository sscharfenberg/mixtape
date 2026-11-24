<?php

namespace Tests\Feature;

use App\Http\Middleware\ContentSecurityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use RuntimeException;
use Tests\TestCase;

/**
 * The Content-Security-Policy header (App\Http\Middleware\ContentSecurityPolicy).
 *
 * The policy's CONTENTS are exercised for real by the Playwright suite, which runs with it enforced
 * and fails any test on a violation; a browser is the only thing that can say whether a page actually
 * loads under it. This pins what PHP decides: when the header is sent and under which name, that every
 * request gets a fresh nonce, that the page's scripts carry exactly that nonce, and the directives a
 * careless edit would quietly widen.
 *
 * THE RISK it guards is a policy that still "works" while protecting nothing — `'unsafe-inline'` back
 * in script-src, a nonce the page's scripts no longer carry (so the policy gets loosened to make the
 * page load again), or an enforcing header that silently fell back to report-only.
 */
class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT_ONLY = 'Content-Security-Policy-Report-Only';

    private const ENFORCED = 'Content-Security-Policy';

    /** Two probe routes: a plain page, and one that throws, for the debug-page rule. */
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/csp-probe', fn () => response('<!DOCTYPE html><title>probe</title>'));
        Route::get('/csp-probe/throws', fn () => throw new RuntimeException('probe'));
    }

    /** The default is report-only: a changed policy is tried on real traffic before it can break anything. */
    public function test_the_policy_is_report_only_until_it_is_enforced(): void
    {
        $response = $this->get('/csp-probe');

        $response->assertHeader(self::REPORT_ONLY, ContentSecurityPolicy::policy(Vite::cspNonce()));
        $response->assertHeaderMissing(self::ENFORCED);
    }

    /**
     * `mixtape.csp.enforce` switches the header's NAME, and with it whether browsers block. Asked
     * over HTTPS, as production is, so the policy is exactly what a reader's browser receives.
     */
    public function test_an_enforced_policy_uses_the_enforcing_header(): void
    {
        config(['mixtape.csp.enforce' => true]);

        $response = $this->get('https://localhost/csp-probe');

        $response->assertHeader(self::ENFORCED, ContentSecurityPolicy::policy(Vite::cspNonce(), upgradeInsecureRequests: true));
        $response->assertHeaderMissing(self::REPORT_ONLY);
    }

    /** A nonce is only worth anything while an attacker cannot know it in advance. */
    public function test_every_request_gets_a_new_script_nonce(): void
    {
        $first = $this->get('/csp-probe')->headers->get(self::REPORT_ONLY);
        $second = $this->get('/csp-probe')->headers->get(self::REPORT_ONLY);

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{40}'/", $first);
        $this->assertNotSame($first, $second);
    }

    /**
     * A nonce or hash in a directive makes browsers ignore its 'unsafe-inline'. Vue writes inline
     * styles at runtime, so a nonce in style-src would block them; inline scripts must never be
     * allowed. img-src carries no `data:` because nothing the app renders is a data: image.
     */
    public function test_only_scripts_carry_the_nonce_and_only_styles_may_be_inline(): void
    {
        $directives = collect(explode('; ', ContentSecurityPolicy::policy('n0nce')))
            ->mapWithKeys(fn (string $directive) => [strtok($directive, ' ') => $directive]);

        $this->assertSame("default-src 'self'", $directives['default-src']);
        $this->assertSame("script-src 'self' 'nonce-n0nce'", $directives['script-src']);
        $this->assertSame("style-src 'self' 'unsafe-inline'", $directives['style-src']);
        $this->assertSame("img-src 'self'", $directives['img-src']);
        $this->assertSame("media-src 'self'", $directives['media-src']);
        $this->assertSame("object-src 'none'", $directives['object-src']);
        $this->assertSame("frame-ancestors 'self'", $directives['frame-ancestors']);
    }

    /**
     * Browsers ignore upgrade-insecure-requests in a report-only header (and say so in the console),
     * and over plain HTTP it would rewrite the page's own requests to an https:// nobody answers — the
     * end-to-end server is plain HTTP. So it rides only on an enforced policy served over HTTPS.
     */
    public function test_upgrade_insecure_requests_rides_only_on_an_enforced_policy_over_https(): void
    {
        $this->assertStringNotContainsString(
            'upgrade-insecure-requests',
            $this->get('https://localhost/csp-probe')->headers->get(self::REPORT_ONLY),
        );

        config(['mixtape.csp.enforce' => true]);

        $this->assertStringNotContainsString(
            'upgrade-insecure-requests',
            $this->get('http://localhost/csp-probe')->headers->get(self::ENFORCED),
        );
        $this->assertStringEndsWith(
            '; upgrade-insecure-requests',
            $this->get('https://localhost/csp-probe')->headers->get(self::ENFORCED),
        );
    }

    /**
     * The half only a rendered page shows: the pre-paint theme snippet (the one inline script) and
     * Vite's module tags must carry THIS request's nonce, or an enforced policy blocks the whole app.
     * Inertia's page data is `type="application/json"` and never executes, so it needs none.
     */
    public function test_the_pages_scripts_carry_the_requests_nonce(): void
    {
        config(['mixtape.csp.enforce' => true]);

        $response = $this->get('/login')->assertOk();
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $response->headers->get(self::ENFORCED), $match);
        $nonce = $match[1] ?? $this->fail('The policy carries no nonce.');

        preg_match_all('/<script\b[^>]*>/i', $response->getContent(), $tags);
        $executable = array_filter($tags[0], fn (string $tag) => ! str_contains($tag, 'application/json'));

        $this->assertNotEmpty($executable, 'The page renders no script at all.');
        $this->assertStringContainsString("<script nonce=\"{$nonce}\">", $response->getContent(), 'The theme snippet lost its nonce.');
        $this->assertTrue(
            collect($executable)->some(fn (string $tag) => str_contains($tag, 'type="module"')),
            'No Vite module tag was rendered.',
        );
        foreach ($executable as $tag) {
            $this->assertStringContainsString("nonce=\"{$nonce}\"", $tag, "A script without this request's nonce: {$tag}");
        }
    }

    /** A 404 never reaches the web middleware group, and in debug mode it still renders the error view. */
    public function test_an_error_page_carries_the_policy_in_debug_mode_too(): void
    {
        foreach ([false, true] as $debug) {
            config(['app.debug' => $debug]);

            $this->get('/no-such-page')
                ->assertNotFound()
                ->assertHeader(self::REPORT_ONLY);
        }
    }

    /** Laravel's debug page runs inline scripts, so it is the one page sent without the policy. */
    public function test_the_exception_page_carries_the_policy_unless_it_is_the_debug_page(): void
    {
        config(['app.debug' => false]);
        $this->get('/csp-probe/throws')->assertServerError()->assertHeader(self::REPORT_ONLY);

        config(['app.debug' => true]);
        $this->get('/csp-probe/throws')->assertServerError()->assertHeaderMissing(self::REPORT_ONLY);
    }

    /**
     * With Vite's dev server running, scripts and the HMR websocket come from its origin; the policy
     * would block the very page it is meant to protect.
     */
    public function test_no_policy_while_the_vite_dev_server_runs(): void
    {
        $hotFile = tempnam(sys_get_temp_dir(), 'vite-hot');
        file_put_contents($hotFile, 'http://localhost:5173');

        try {
            $this->withVite();
            Vite::useHotFile($hotFile);

            $this->get('/csp-probe')
                ->assertHeaderMissing(self::REPORT_ONLY)
                ->assertHeaderMissing(self::ENFORCED);
        } finally {
            unlink($hotFile);
        }
    }
}
