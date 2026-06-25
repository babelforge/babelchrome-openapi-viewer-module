<?php

declare(strict_types=1);

namespace BabelForge\BabelChromeOpenApiViewerModule\Tests;

use BabelForge\BabelChrome\LocalViewer\Module\ModuleManifest;
use BabelForge\BabelChrome\LocalViewer\Module\ModuleRuntimeContext;
use BabelForge\BabelChromeOpenApiViewerModule\Service\ModuleAssetResolver;
use BabelForge\BabelChromeOpenApiViewerModule\Service\OpenApiDocumentRenderer;
use BabelForge\BabelChromeViewerKit\ViewerSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the OpenAPI viewer module renderer.
 */
final class OpenApiDocumentRendererTest extends TestCase
{
    /**
     * Verifies that OpenAPI rendering uses module-local runtime assets.
     */
    public function testRenderUsesModuleAssets(): void
    {
        $renderer = new OpenApiDocumentRenderer($this->assetResolver());
        $view = $renderer->render(
            new ViewerSource('openapi.yaml', "openapi: 3.1.0\ninfo:\n  title: Test API\n  version: 1.0.0\npaths: {}\n", '', false, 'file', '/tmp/openapi.yaml', 'application/yaml', null),
            Request::create('/openapi'),
        );

        self::assertSame('openapi.yaml', $view->title);
        self::assertStringContainsString('Test API', $view->specJson);
        self::assertStringContainsString('/module/babelforge.openapi-viewer/assets/assets/', $view->importMapContent);
        self::assertStringContainsString('swagger-ui', $view->stylesheetContent);
        self::assertStringContainsString('openapi-source', $view->scriptContent);
    }

    /**
     * Creates the module asset resolver used by tests.
     *
     * @return ModuleAssetResolver the module asset resolver
     */
    private function assetResolver(): ModuleAssetResolver
    {
        return new ModuleAssetResolver(
            new ModuleManifest('babelforge.openapi-viewer', 'OpenAPI Viewer', '1.0.0'),
            new ModuleRuntimeContext('http://127.0.0.1:12345', 'test-token', 'babelchrome://openapi/test'),
        );
    }
}
