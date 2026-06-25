# Usage

Navigation: [README](README.md) | [Next: Development](02-development.md)

The module handles `yaml`, `yml`, and `json` sources when the filename identifies an OpenAPI or Swagger document.

Stable routes include:

```text
babelchrome://openapi/file/<encoded-path>
babelchrome://openapi/url/<encoded-url>
```

The renderer validates the root document, resolves internal and relative `$ref` values, displays local errors clearly, and exposes the shared `Open with` header control for local files.

Navigation: [README](README.md) | [Next: Development](02-development.md)
