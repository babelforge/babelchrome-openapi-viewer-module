<?php

declare(strict_types=1);

namespace BabelForge\BabelChromeOpenApiViewerModule\View;

use BabelForge\BabelChromeViewerKit\OpenWithView;

/**
 * Carries all data needed to render an OpenAPI viewer page.
 */
final readonly class OpenApiView
{
    /**
     * @param string       $title              the document title
     * @param OpenWithView $openWithView       the shared Open With control view model
     * @param string       $specJson           the JSON-encoded OpenAPI specification
     * @param string       $importMapContent   the inline import map content
     * @param string       $stylesheetContent  the inline stylesheet content
     * @param string       $scriptContent      the inline module script content
     * @param string       $sourceId           the registered source identifier
     * @param bool         $autoRefreshEnabled whether auto refresh is enabled
     * @param int|null     $lastModified       the source last modification timestamp
     */
    public function __construct(
        public string $title,
        public OpenWithView $openWithView,
        public string $specJson,
        public string $importMapContent,
        public string $stylesheetContent,
        public string $scriptContent,
        public string $sourceId,
        public bool $autoRefreshEnabled,
        public ?int $lastModified,
    ) {
    }
}
