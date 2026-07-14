<?php

namespace Tests\Feature\Middleware;

use App\Http\Middleware\PreventApiCaching;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PreventApiCachingTest extends TestCase
{
    #[Test]
    public function it_injects_cache_control_no_store_header()
    {
        $response = $this->runMiddleware();

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
    }
    #[Test]
    public function it_injects_pragma_no_cache_for_http10_compat()
    {
        $response = $this->runMiddleware();

        $this->assertEquals('no-cache', $response->headers->get('Pragma'));
    }
    #[Test]
    public function it_injects_expires_zero()
    {
        $response = $this->runMiddleware();

        $this->assertEquals('0', $response->headers->get('Expires'));
    }
    #[Test]
    public function it_injects_x_accel_expires_zero_for_nginx()
    {
        // Critical for HestiaCP nginx with proxy_ignore_headers Cache-Control
        $response = $this->runMiddleware();

        $this->assertEquals('0', $response->headers->get('X-Accel-Expires'));
    }
    #[Test]
    public function it_injects_surrogate_control_no_store_for_fastly_akamai()
    {
        $response = $this->runMiddleware();

        $this->assertEquals('no-store', $response->headers->get('Surrogate-Control'));
    }
    #[Test]
    public function it_injects_cdn_cache_control_no_store()
    {
        $response = $this->runMiddleware();

        $this->assertEquals('no-store', $response->headers->get('CDN-Cache-Control'));
    }
    #[Test]
    public function it_injects_cache_control_dengan_max_age_nol_dan_string_eksak()
    {
        $response = $this->runMiddleware();

        // Symfony ResponseHeaderBag menormalisasi: token diurutkan alfabetis + 'private'
        // ditambahkan otomatis (karena no-cache). Verifikasi STRING penuh hasil normalisasi.
        $this->assertEquals(
            'max-age=0, must-revalidate, no-cache, no-store, private',
            $response->headers->get('Cache-Control')
        );
    }
    #[Test]
    public function it_tetap_set_header_pada_response_error_4xx_dan_5xx()
    {
        // No-cache harus tetap dipasang walau response error (cegah cache stale error).
        foreach ([404, 422, 500] as $status) {
            $middleware = new PreventApiCaching();
            $request = Request::create('/api/v1/test', 'GET');
            $response = $middleware->handle($request, fn () => new Response('err', $status));

            $this->assertEquals('no-store', $response->headers->get('Surrogate-Control'), "status {$status}");
            $this->assertEquals('0', $response->headers->get('Expires'), "status {$status}");
            $this->assertEquals($status, $response->getStatusCode());
        }
    }
    #[Test]
    public function it_menimpa_header_cache_control_yang_sudah_ada_dari_response()
    {
        // Jika controller terlanjur set Cache-Control: public, middleware HARUS menimpa.
        $middleware = new PreventApiCaching();
        $request = Request::create('/api/v1/test', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('body', 200, ['Cache-Control' => 'public, max-age=3600']);
        });

        $this->assertEquals(
            'max-age=0, must-revalidate, no-cache, no-store, private',
            $response->headers->get('Cache-Control')
        );
    }
    #[Test]
    public function it_returns_the_original_response_unchanged_otherwise()
    {
        $middleware = new PreventApiCaching();
        $request = Request::create('/api/v1/test', 'GET');

        $response = $middleware->handle($request, function () {
            return new Response('original body', 201, ['Content-Type' => 'application/json']);
        });

        $this->assertEquals('original body', $response->getContent());
        $this->assertEquals(201, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));
    }

    private function runMiddleware(): Response
    {
        $middleware = new PreventApiCaching();
        $request = Request::create('/api/v1/brands', 'GET');

        return $middleware->handle($request, function () {
            return new Response('{"data": []}', 200);
        });
    }
}
