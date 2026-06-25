# Development

Navigation: [Previous: Usage](01-usage.md) | [README](README.md)

The module is a Symfony-based PHP project. Source assets live in `assets/`, compiled runtime assets live in `public/assets/`, and Twig templates live in `templates/`.

It depends on `babelforge/babel-chrome-viewer-kit` for the shared viewer header and `Open with` controls.

Run quality checks with:

```bash
composer qa
```

Build the production zip from the modules workspace:

```bash
./tools/dev2prod.sh openapi-viewer
```

Navigation: [Previous: Usage](01-usage.md) | [README](README.md)
