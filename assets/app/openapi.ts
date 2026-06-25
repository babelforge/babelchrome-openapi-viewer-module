import SwaggerUI from 'swagger-ui-dist/swagger-ui-bundle.js';

type SwaggerUIFactory = (configuration: Record<string, unknown>) => unknown;

const sourceElement = document.querySelector<HTMLScriptElement>('#openapi-source');
const viewerElement = document.querySelector<HTMLElement>('[data-openapi-viewer]');
const rootElement = document.querySelector<HTMLElement>('#swagger-ui');

if (null !== sourceElement && null !== rootElement) {
  const specification = JSON.parse(sourceElement.textContent ?? '{}') as Record<string, unknown>;
  const swaggerUi = SwaggerUI as unknown as SwaggerUIFactory;

  swaggerUi({
    domNode: rootElement,
    spec: specification,
    deepLinking: true,
    docExpansion: 'list',
    displayRequestDuration: true,
    persistAuthorization: true,
    tryItOutEnabled: true,
    validatorUrl: null,
  });
}

const sourceId = viewerElement?.dataset.sourceId ?? '';
const autoRefreshEnabled = '1' === (viewerElement?.dataset.autoRefresh ?? '');
const initialLastModified = Number.parseInt(viewerElement?.dataset.lastModified ?? '', 10);

if (autoRefreshEnabled && '' !== sourceId && Number.isFinite(initialLastModified)) {
  let knownLastModified = initialLastModified;
  window.setInterval(() => {
    void (async () => {
      try {
        const query = new URLSearchParams(window.location.search);
        query.set('viewer', 'openapi');
        const response = await fetch(`/source-status/${encodeURIComponent(sourceId)}?${query.toString()}`, {
          cache: 'no-store',
        });
        if (!response.ok) {
          return;
        }

        const payload = (await response.json()) as { lastModified?: number };
        if ('number' === typeof payload.lastModified && payload.lastModified > knownLastModified) {
          knownLastModified = payload.lastModified;
          window.location.reload();
        }
      } catch {
        // Auto-refresh is best-effort and must never disturb reading.
      }
    })();
  }, 1600);
}
