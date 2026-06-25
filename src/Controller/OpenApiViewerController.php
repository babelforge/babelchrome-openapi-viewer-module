<?php

declare(strict_types=1);

namespace BabelForge\BabelChromeOpenApiViewerModule\Controller;

use BabelForge\BabelChrome\LocalViewer\DocumentSource;
use BabelForge\BabelChrome\LocalViewer\Service\SourceLoader;
use BabelForge\BabelChromeOpenApiViewerModule\Exception\OpenApiRenderException;
use BabelForge\BabelChromeOpenApiViewerModule\Service\ModuleAssetResolver;
use BabelForge\BabelChromeOpenApiViewerModule\Service\OpenApiDocumentRenderer;
use BabelForge\BabelChromeOpenApiViewerModule\View\OpenApiView;
use BabelForge\BabelChromeViewerKit\Controller\AbstractViewerController;
use BabelForge\BabelChromeViewerKit\ViewerSource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Handles Symfony HTTP rendering for OpenAPI viewer pages.
 */
final class OpenApiViewerController extends AbstractViewerController
{
    /**
     * @param Environment         $twig              renders viewer templates
     * @param SourceLoader        $sourceLoader      loads viewer sources
     * @param ModuleAssetResolver $assetPathResolver resolves module assets
     */
    public function __construct(
        Environment $twig,
        private readonly SourceLoader $sourceLoader,
        private readonly ModuleAssetResolver $assetPathResolver,
    ) {
        parent::__construct($twig);
    }

    /**
     * Renders an OpenAPI document.
     *
     * @param Request $request the current request
     *
     * @return Response the rendered OpenAPI response
     */
    #[Route('/render', name: 'babelforge_openapi_viewer_render', methods: ['GET'])]
    public function render(Request $request): Response
    {
        return parent::render($request);
    }

    /**
     * Loads the OpenAPI source for the current request.
     *
     * @param Request $request the current request
     *
     * @return ViewerSource|null the loaded viewer source
     */
    protected function loadSource(Request $request): ?ViewerSource
    {
        $source = $this->sourceLoader->load($request);

        return null === $source ? null : $this->viewerSource($source);
    }

    /**
     * Renders the OpenAPI-specific view model.
     *
     * @param ViewerSource $source  the loaded viewer source
     * @param Request      $request the current request
     *
     * @return OpenApiView the rendered OpenAPI view model
     *
     * @throws OpenApiRenderException when the OpenAPI document cannot be parsed
     */
    protected function renderView(ViewerSource $source, Request $request): OpenApiView
    {
        return new OpenApiDocumentRenderer($this->assetPathResolver)->render($source, $request);
    }

    /**
     * Returns the Twig template used by the OpenAPI viewer.
     *
     * @return string the template name
     */
    protected function templateName(): string
    {
        return 'openapi/show.html.twig';
    }

    /**
     * Converts a rendering failure into a response when the viewer supports it.
     *
     * @param \Throwable $exception the rendering failure
     *
     * @return Response|null the failure response, or null to rethrow
     */
    protected function renderingFailureResponse(\Throwable $exception): ?Response
    {
        if (!$exception instanceof OpenApiRenderException) {
            return null;
        }

        return $this->errorResponse(
            'Unable to Render OpenAPI',
            'OpenAPI document is invalid',
            'The OpenAPI document could not be parsed or does not define a valid root OpenAPI object.',
            $exception->getMessage(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->errorStylesheetContent(),
        );
    }

    /**
     * Returns the source-not-found page title.
     *
     * @return string the page title
     */
    protected function sourceNotFoundTitle(): string
    {
        return 'Unable to Load OpenAPI';
    }

    /**
     * Returns the source-not-found visible heading.
     *
     * @return string the visible heading
     */
    protected function sourceNotFoundHeading(): string
    {
        return 'OpenAPI source not found';
    }

    /**
     * Returns the source-not-found visible message.
     *
     * @return string the visible message
     */
    protected function sourceNotFoundMessage(): string
    {
        return 'The OpenAPI file or remote OpenAPI document could not be loaded.';
    }

    /**
     * Returns the stylesheet content used by shared error pages.
     *
     * @return string the safe inline stylesheet content
     */
    protected function errorStylesheetContent(): string
    {
        return str_replace('</style', '<\/style', $this->assetPathResolver->content('styles/viewer.css'));
    }

    /**
     * Converts a host document source into a kit viewer source.
     *
     * @param DocumentSource $source the host document source
     *
     * @return ViewerSource the kit viewer source
     */
    private function viewerSource(DocumentSource $source): ViewerSource
    {
        return new ViewerSource(
            $source->title,
            $source->content,
            $source->baseUri,
            $source->local,
            $source->type,
            $source->value,
            $source->mimeType,
            $source->lastModified,
        );
    }
}
