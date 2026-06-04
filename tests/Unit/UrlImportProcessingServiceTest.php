<?php

namespace Tests\Unit;

use App\Services\GeoFlow\EntityExtractionService;
use App\Services\GeoFlow\EntityMaterialLinkService;
use App\Services\GeoFlow\UrlImportProcessingService;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Models\UrlImportJob;
use InvalidArgumentException;
use Tests\TestCase;

class UrlImportProcessingServiceTest extends TestCase
{
    private UrlImportProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new UrlImportProcessingService(
            new ApiKeyCrypto,
            $this->app->make(EntityExtractionService::class),
            $this->app->make(EntityMaterialLinkService::class)
        );
    }

    public function test_it_accepts_valid_public_url(): void
    {
        $result = $this->service->normalizeInputUrl('https://www.example.com');

        $this->assertSame('https://www.example.com', $result['url']);
        $this->assertSame('www.example.com', $result['host']);
    }

    public function test_it_rejects_localhost(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->normalizeInputUrl('http://localhost');
    }

    public function test_it_rejects_loopback_ip(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->normalizeInputUrl('http://127.0.0.1');
    }

    public function test_it_rejects_zero_ip(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->normalizeInputUrl('http://0.0.0.0');
    }

    public function test_it_rejects_local_hostname(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->normalizeInputUrl('http://mycomputer.local');
    }

    public function test_it_rejects_empty_url(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->normalizeInputUrl('');
    }

    public function test_it_accepts_url_without_scheme(): void
    {
        $result = $this->service->normalizeInputUrl('example.com/path');

        $this->assertSame('https://example.com/path', $result['url']);
        $this->assertSame('example.com', $result['host']);
    }

    public function test_it_accepts_valid_url_with_path(): void
    {
        $result = $this->service->normalizeInputUrl('https://www.example.com/some/path');

        $this->assertSame('https://www.example.com/some/path', $result['url']);
        $this->assertSame('www.example.com', $result['host']);
    }

    public function test_it_preserves_http_scheme(): void
    {
        $result = $this->service->normalizeInputUrl('http://www.example.com');

        $this->assertSame('http://www.example.com', $result['url']);
    }

    public function test_it_uses_business_entity_when_ai_returns_generic_library_name(): void
    {
        $job = new UrlImportJob([
            'source_domain' => 'example.test',
            'options_json' => '{}',
        ]);

        $name = $this->callPrivate('resolveImportBaseName', [[
            'library_name' => 'GEOFlow Knowledge Base',
            'cleaned' => ['title' => 'GEOFlow Title Library'],
        ], [
            'title' => 'Keyword Library',
        ], $job, [[
            'name' => 'SJ4060',
        ]], [
            'visual dispensing machine',
        ]]);

        $this->assertSame('SJ4060', $name);
        $this->assertSame('SJ4060 Knowledge Base', $this->callPrivate('materialLibraryName', [$name, ' Knowledge Base']));
    }

    /**
     * @param  list<mixed>  $parameters
     */
    private function callPrivate(string $method, array $parameters): mixed
    {
        $reflection = new \ReflectionMethod($this->service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->service, $parameters);
    }
}
