<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 *
 * @return array<string, array{
 *     path: string,
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }|array{
 *     version: string,
 *     package_specifier?: string,
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }>
 */
return [
    'swagger-ui-dist' => ['version' => '5.32.6'],
    'swagger-ui-dist/swagger-ui-bundle.js' => ['version' => '5.32.6'],
    'swagger-ui-dist/swagger-ui.css' => ['version' => '5.32.6', 'type' => 'css'],
];
