<?php

declare(strict_types=1);

namespace BabelForge\BabelChromeOpenApiViewerModule\Service;

use BabelForge\BabelChromeOpenApiViewerModule\Exception\OpenApiRenderException;
use BabelForge\BabelChromeOpenApiViewerModule\View\OpenApiView;
use BabelForge\BabelChromeViewerKit\OpenWithViewFactory;
use BabelForge\BabelChromeViewerKit\ViewerSource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Renders OpenAPI documents with Swagger UI.
 */
final readonly class OpenApiDocumentRenderer
{
    /**
     * Creates the OpenAPI renderer.
     *
     * @param ModuleAssetResolver $assetPathResolver resolves module asset paths
     */
    public function __construct(
        private ModuleAssetResolver $assetPathResolver,
    ) {
    }

    /**
     * Renders an OpenAPI source as a Swagger UI page model.
     *
     * @param ViewerSource $source  the document source
     * @param Request      $request the current request
     *
     * @return OpenApiView the rendered OpenAPI view data
     *
     * @throws OpenApiRenderException when the root OpenAPI document cannot be parsed or validated
     */
    public function render(ViewerSource $source, Request $request): OpenApiView
    {
        /** @var array<string, int> $referencedFiles */
        $referencedFiles = [];
        $specificationJson = $this->specificationJson($source, $referencedFiles);
        $lastModified = $this->combinedLastModified($source->lastModified, $referencedFiles);
        $sourceId = $this->sourceId($request);
        $openWithViewFactory = new OpenWithViewFactory();

        return new OpenApiView(
            $source->title,
            $openWithViewFactory->create($sourceId, $source->value, $source->local),
            $specificationJson,
            $this->importMapContent(),
            $this->stylesheetContent()."\n".$this->styleContent('babel-chrome-viewer-kit/viewer-shell.css'),
            $this->scriptContent('app/openapi.ts')."\n".$this->isolatedScriptContent('babel-chrome-viewer-kit/open-with.ts'),
            $sourceId,
            $source->local && null !== $lastModified && '' !== $sourceId,
            $lastModified,
        );
    }

    /**
     * Returns the last modification timestamp for an OpenAPI source and its local references.
     *
     * @param ViewerSource $source the document source
     *
     * @return int|null the latest known local modification timestamp
     */
    public function sourceLastModified(ViewerSource $source): ?int
    {
        /** @var array<string, int> $referencedFiles */
        $referencedFiles = [];
        $this->specification($source, $referencedFiles);

        return $this->combinedLastModified($source->lastModified, $referencedFiles);
    }

    /**
     * Returns the current registered source identifier.
     *
     * @param Request $request the current request
     *
     * @return string the source identifier
     */
    private function sourceId(Request $request): string
    {
        $sourceIdValue = $request->attributes->get('sourceId', '');

        return is_string($sourceIdValue) ? $sourceIdValue : '';
    }

    /**
     * Returns the source specification encoded as JSON for Swagger UI.
     *
     * @param ViewerSource       $source          the document source
     * @param array<string, int> $referencedFiles referenced local file timestamps
     *
     * @return string the encoded specification
     *
     * @throws OpenApiRenderException when the root OpenAPI document cannot be parsed or validated
     */
    private function specificationJson(ViewerSource $source, array &$referencedFiles): string
    {
        $specification = $this->specification($source, $referencedFiles);
        $encoded = json_encode(
            $specification,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );

        return false === $encoded ? '{}' : $encoded;
    }

    /**
     * Parses an OpenAPI source as JSON or YAML.
     *
     * @param ViewerSource       $source          the document source
     * @param array<string, int> $referencedFiles referenced local file timestamps
     *
     * @return array<string, mixed> the parsed specification
     *
     * @throws OpenApiRenderException when the root OpenAPI document cannot be parsed or validated
     */
    private function specification(ViewerSource $source, array &$referencedFiles): array
    {
        $trimmedContent = trim($source->content);
        if ('' === $trimmedContent) {
            throw new OpenApiRenderException('The OpenAPI document is empty.');
        }

        try {
            $decoded = json_decode($trimmedContent, true, 512, JSON_THROW_ON_ERROR);
            $decodedMap = $this->stringKeyedMap($decoded);
            if (null !== $decodedMap) {
                return $this->validatedSpecification($source, $decodedMap, $referencedFiles);
            }
        } catch (\JsonException) {
            // YAML parsing below covers non-JSON OpenAPI documents.
        }

        try {
            $parsed = Yaml::parse($trimmedContent);
            $parsedMap = $this->stringKeyedMap($parsed);
            if (null !== $parsedMap) {
                return $this->validatedSpecification($source, $parsedMap, $referencedFiles);
            }
        } catch (ParseException $exception) {
            throw new OpenApiRenderException($exception->getMessage(), previous: $exception);
        }

        throw new OpenApiRenderException('The OpenAPI document must contain a JSON or YAML object.');
    }

    /**
     * Returns a parsed OpenAPI document when it contains the required root fields.
     *
     * @param ViewerSource         $source          the document source
     * @param array<string, mixed> $specification   the parsed specification
     * @param array<string, int>   $referencedFiles referenced local file timestamps
     *
     * @return array<string, mixed> the valid specification or an error specification
     *
     * @throws OpenApiRenderException when the root OpenAPI document misses required fields
     */
    private function validatedSpecification(ViewerSource $source, array $specification, array &$referencedFiles): array
    {
        $hasVersion = (isset($specification['openapi']) && is_scalar($specification['openapi']))
            || (isset($specification['swagger']) && is_scalar($specification['swagger']));

        if ($hasVersion && isset($specification['info']) && is_array($specification['info']) && isset($specification['paths']) && is_array($specification['paths'])) {
            $resolvedSpecification = $this->resolveReferences($specification, $source->baseUri, $specification, [], $referencedFiles);
            $resolvedMap = $this->stringKeyedMap($resolvedSpecification);

            return null === $resolvedMap ? $specification : $resolvedMap;
        }

        throw new OpenApiRenderException('The OpenAPI document must define openapi/swagger, info, and paths.');
    }

    /**
     * Resolves OpenAPI JSON references recursively.
     *
     * @param mixed                $value           the current value
     * @param string               $baseUri         the base URI used for relative references
     * @param array<string, mixed> $root            the current document root
     * @param array<string, bool>  $visitedRefs     the visited reference keys
     * @param array<string, int>   $referencedFiles referenced local file timestamps
     *
     * @return mixed the resolved value
     */
    private function resolveReferences(mixed $value, string $baseUri, array $root, array $visitedRefs, array &$referencedFiles): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (isset($value['$ref']) && is_string($value['$ref'])) {
            return $this->resolvedReferenceValue($value, $baseUri, $root, $visitedRefs, $referencedFiles);
        }

        $resolved = [];
        foreach ($value as $key => $item) {
            $resolved[$key] = $this->resolveReferences($item, $baseUri, $root, $visitedRefs, $referencedFiles);
        }

        return $resolved;
    }

    /**
     * Resolves a single OpenAPI JSON reference value.
     *
     * @param array<mixed, mixed>  $value           the object containing a $ref key
     * @param string               $baseUri         the base URI used for relative references
     * @param array<string, mixed> $root            the current document root
     * @param array<string, bool>  $visitedRefs     the visited reference keys
     * @param array<string, int>   $referencedFiles referenced local file timestamps
     *
     * @return mixed the resolved reference value
     */
    private function resolvedReferenceValue(
        array $value,
        string $baseUri,
        array $root,
        array $visitedRefs,
        array &$referencedFiles,
    ): mixed {
        $reference = $value['$ref'];
        if (!is_string($reference)) {
            return $value;
        }

        [$referenceUri, $fragment] = $this->splitFragment($reference);
        $resolvedUri = '' === $referenceUri ? '' : $this->joinUri($baseUri, $referenceUri);
        $referenceKey = ('' === $resolvedUri ? $baseUri : $resolvedUri).$fragment;
        if (isset($visitedRefs[$referenceKey])) {
            return $this->referenceError($reference, 'Circular OpenAPI reference detected.');
        }

        $nextVisitedRefs = $visitedRefs;
        $nextVisitedRefs[$referenceKey] = true;
        $documentRoot = $root;
        $documentBaseUri = $baseUri;
        if ('' !== $resolvedUri) {
            $loadedDocument = $this->loadReferencedDocument($resolvedUri, $referencedFiles);
            if (null === $loadedDocument) {
                return $this->referenceError($reference, 'Referenced OpenAPI document could not be loaded.');
            }

            $documentRoot = $loadedDocument['document'];
            $documentBaseUri = $loadedDocument['baseUri'];
        }

        $target = '' === $fragment ? $documentRoot : $this->valueAtJsonPointer($documentRoot, $fragment);
        if (null === $target) {
            return $this->referenceError($reference, 'Referenced OpenAPI pointer could not be resolved.');
        }

        $resolvedTarget = $this->resolveReferences($target, $documentBaseUri, $documentRoot, $nextVisitedRefs, $referencedFiles);
        $siblings = $value;
        unset($siblings['$ref']);
        if ([] === $siblings || !is_array($resolvedTarget)) {
            return $resolvedTarget;
        }

        return array_replace_recursive($resolvedTarget, $siblings);
    }

    /**
     * Returns a visible reference error object.
     *
     * @param string $reference the unresolved reference
     * @param string $message   the error message
     *
     * @return array<string, string> the visible error object
     */
    private function referenceError(string $reference, string $message): array
    {
        return [
            'description' => $message,
            'x-babelchrome-ref' => $reference,
            'x-babelchrome-ref-error' => $message,
        ];
    }

    /**
     * Loads and parses a referenced OpenAPI document.
     *
     * @param string             $uri             the absolute referenced URI
     * @param array<string, int> $referencedFiles referenced local file timestamps
     *
     * @return array{document: array<string, mixed>, baseUri: string}|null the loaded document data
     */
    private function loadReferencedDocument(string $uri, array &$referencedFiles): ?array
    {
        $parts = parse_url($uri);
        $parts = is_array($parts) ? $parts : [];
        $scheme = strtolower($parts['scheme'] ?? 'file');
        if ('file' === $scheme) {
            $path = $this->filePathFromUri($uri);
            if (!is_file($path) || !is_readable($path)) {
                return null;
            }

            $content = file_get_contents($path);
            if (false === $content) {
                return null;
            }

            $lastModified = filemtime($path);
            if (false !== $lastModified) {
                $referencedFiles[$path] = $lastModified;
            }

            return [
                'document' => $this->parsedReferencedDocument($content) ?? [],
                'baseUri' => 'file://'.dirname($path).'/',
            ];
        }

        if ('http' !== $scheme && 'https' !== $scheme) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 8,
                'user_agent' => 'BabelChrome Local Viewer',
            ],
        ]);
        $content = file_get_contents($uri, false, $context);
        if (false === $content) {
            return null;
        }

        return [
            'document' => $this->parsedReferencedDocument($content) ?? [],
            'baseUri' => $this->remoteBaseUri($uri),
        ];
    }

    /**
     * Parses a referenced OpenAPI JSON or YAML document.
     *
     * @param string $content the referenced document content
     *
     * @return array<string, mixed>|null the parsed document
     */
    private function parsedReferencedDocument(string $content): ?array
    {
        $trimmedContent = trim($content);
        if ('' === $trimmedContent) {
            return null;
        }

        try {
            $decoded = json_decode($trimmedContent, true, 512, JSON_THROW_ON_ERROR);
            $decodedMap = $this->stringKeyedMap($decoded);
            if (null !== $decodedMap) {
                return $decodedMap;
            }
        } catch (\JsonException) {
            // YAML parsing below covers non-JSON referenced documents.
        }

        try {
            return $this->stringKeyedMap(Yaml::parse($trimmedContent));
        } catch (ParseException) {
            return null;
        }
    }

    /**
     * Returns the latest timestamp across the main source and referenced files.
     *
     * @param int|null           $sourceLastModified the main source timestamp
     * @param array<string, int> $referencedFiles    referenced local file timestamps
     *
     * @return int|null the latest known timestamp
     */
    private function combinedLastModified(?int $sourceLastModified, array $referencedFiles): ?int
    {
        $timestamps = array_values($referencedFiles);
        if (null !== $sourceLastModified) {
            $timestamps[] = $sourceLastModified;
        }

        if ([] === $timestamps) {
            return null;
        }

        return max($timestamps);
    }

    /**
     * Resolves a JSON pointer against a document root.
     *
     * @param array<string, mixed> $root     the document root
     * @param string               $fragment the URI fragment
     *
     * @return mixed the resolved value or null when missing
     */
    private function valueAtJsonPointer(array $root, string $fragment): mixed
    {
        if ('#' === $fragment || '' === $fragment) {
            return $root;
        }

        if (!str_starts_with($fragment, '#/')) {
            return null;
        }

        $value = $root;
        foreach (explode('/', substr($fragment, 2)) as $token) {
            $key = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($token));
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Converts a decoded value to a string-keyed map when possible.
     *
     * @param mixed $value the decoded JSON or YAML value
     *
     * @return array<string, mixed>|null the string-keyed map, or null when unsupported
     */
    private function stringKeyedMap(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $map = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return null;
            }

            $map[$key] = $item;
        }

        return $map;
    }

    /**
     * Resolves a relative URI against a base URI.
     *
     * @param string $baseUri     the source base URI
     * @param string $relativeUri the relative URI
     *
     * @return string the resolved URI
     */
    private function joinUri(string $baseUri, string $relativeUri): string
    {
        [$relativePath, $query] = $this->splitQuery($relativeUri);
        if (1 === preg_match('/^[a-z][a-z0-9+.-]*:/i', $relativePath)) {
            return $relativeUri;
        }

        if (str_starts_with($relativePath, '/')) {
            $parts = parse_url($baseUri);
            $parts = is_array($parts) ? $parts : [];
            $scheme = $parts['scheme'] ?? 'file';
            $host = $parts['host'] ?? '';
            $port = isset($parts['port']) ? ':'.(string) $parts['port'] : '';

            return ('file' === $scheme ? 'file://'.$relativePath : $scheme.'://'.$host.$port.$relativePath).$query;
        }

        $baseParts = parse_url($baseUri);
        $baseParts = is_array($baseParts) ? $baseParts : [];
        $scheme = $baseParts['scheme'] ?? 'file';
        $host = $baseParts['host'] ?? '';
        $port = isset($baseParts['port']) ? ':'.(string) $baseParts['port'] : '';
        $basePath = $baseParts['path'] ?? '/';
        $path = $this->normalizePath(rtrim($basePath, '/').'/'.$relativePath);

        if ('file' === $scheme) {
            return 'file://'.$path.$query;
        }

        return $scheme.'://'.$host.$port.$path.$query;
    }

    /**
     * Splits a URI fragment from the URI.
     *
     * @param string $uri the URI to split
     *
     * @return array{0: string, 1: string} the URI without fragment and the fragment
     */
    private function splitFragment(string $uri): array
    {
        $position = strpos($uri, '#');
        if (false === $position) {
            return [$uri, ''];
        }

        return [substr($uri, 0, $position), substr($uri, $position)];
    }

    /**
     * Splits a query string from a URI.
     *
     * @param string $uri the URI to split
     *
     * @return array{0: string, 1: string} the URI without query and the query
     */
    private function splitQuery(string $uri): array
    {
        $position = strpos($uri, '?');
        if (false === $position) {
            return [$uri, ''];
        }

        return [substr($uri, 0, $position), substr($uri, $position)];
    }

    /**
     * Extracts a decoded local file path from a file URI.
     *
     * @param string $uri the file URI
     *
     * @return string the local file path
     */
    private function filePathFromUri(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return '';
        }

        return rawurldecode($path);
    }

    /**
     * Returns the remote base URI used to resolve links.
     *
     * @param string $url the remote source URL
     *
     * @return string the base URI
     */
    private function remoteBaseUri(string $url): string
    {
        $parts = parse_url($url);
        $parts = is_array($parts) ? $parts : [];
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.(string) $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $scheme.'://'.$host.$port.('' === $directory ? '/' : $directory.'/');
    }

    /**
     * Normalizes a URI path.
     *
     * @param string $path the path to normalize
     *
     * @return string the normalized path
     */
    private function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }

            if ('..' === $part) {
                array_pop($parts);
                continue;
            }

            $parts[] = $part;
        }

        return '/'.implode('/', $parts);
    }

    /**
     * Returns inline import map content.
     *
     * @return string the safe inline import map content
     */
    private function importMapContent(): string
    {
        return str_replace('</script', '<\/script', $this->assetPathResolver->importMapContent());
    }

    /**
     * Returns the combined inline stylesheet content.
     *
     * @return string the safe inline stylesheet content
     */
    private function stylesheetContent(): string
    {
        return $this->styleContent('vendor/swagger-ui-dist/swagger-ui.css').
            "\n".$this->styleContent('styles/viewer.css');
    }

    /**
     * Returns inline stylesheet content.
     *
     * @param string $logicalPath the module asset logical path
     *
     * @return string the safe inline stylesheet content
     */
    private function styleContent(string $logicalPath): string
    {
        return str_replace('</style', '<\/style', $this->assetPathResolver->content($logicalPath));
    }

    /**
     * Returns inline script content.
     *
     * @param string $logicalPath the module asset logical path
     *
     * @return string the safe inline script content
     */
    private function scriptContent(string $logicalPath): string
    {
        return str_replace('</script', '<\/script', $this->assetPathResolver->content($logicalPath));
    }

    /**
     * Returns inline script content isolated from other compiled bundles.
     *
     * @param string $logicalPath the module asset logical path
     *
     * @return string the isolated safe inline script content
     */
    private function isolatedScriptContent(string $logicalPath): string
    {
        return "(function () {\n".$this->scriptContent($logicalPath)."\n})();";
    }
}
