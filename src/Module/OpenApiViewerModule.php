<?php

declare(strict_types=1);

namespace BabelForge\BabelChromeOpenApiViewerModule\Module;

use BabelForge\BabelChrome\LocalViewer\Module\BabelChromeModuleInterface;
use BabelForge\BabelChrome\LocalViewer\Module\ModuleRequest;
use BabelForge\BabelChromeOpenApiViewerModule\Controller\OpenApiViewerController;
use BabelForge\BabelChromeOpenApiViewerModule\Service\ViewerModuleSupport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders OpenAPI documents as a BabelChrome viewer module.
 */
final class OpenApiViewerModule extends ViewerModuleSupport implements BabelChromeModuleInterface
{
    /**
     * Handles one OpenAPI viewer module request.
     *
     * @param ModuleRequest $request the module request context
     *
     * @return Response the rendered OpenAPI response
     */
    public function handle(ModuleRequest $request): Response
    {
        $sourceRegistry = $this->sourceRegistry();

        return new OpenApiViewerController(
            $this->twig(),
            $this->sourceLoader($sourceRegistry),
            $this->assetPathResolver($request->module, $request->context),
        )->render($request->request);
    }
}
