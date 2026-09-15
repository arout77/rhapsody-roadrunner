<?php
namespace Rhapsody\RoadRunner;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Rhapsody\Core\Debug;
use Rhapsody\Core\ErrorHandler;
use Rhapsody\Core\Kernel;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;
use Rhapsody\Core\Session;

/**
 * Bridges one RoadRunner PSR-7 request/response cycle to Rhapsody's
 * Request/Response/Kernel. Everything here runs ONCE PER REQUEST inside a
 * worker process that otherwise stays booted.
 */
class RoadRunnerKernel
{
    public function __construct(
        private Kernel $kernel,
        private Psr17Factory $psr17Factory,
        private string $basePath,
        private array $config
    ) {
    }

    public function handle(ServerRequestInterface $psrRequest): PsrResponseInterface
    {
        // Mirrors index.php's placement of this call: as early as possible,
        // so execution_time in the debug toolbar covers this whole
        // iteration, not just the part after bootstrap/session setup.
        Debug::getInstance()->start();

        // Mirrors index.php's maintenance-mode check, but returns a
        // Response instead of calling exit() — exit() here would kill the
        // whole worker process, not just this one request.
        if (file_exists($this->basePath . '/storage/framework/down')) {
            return $this->toPsrResponse(
                (new Response())
                    ->setStatusCode(503)
                    ->setHeader('Content-Type', 'text/html')
                    ->setContent('<h1>Be right back.</h1><p>We are currently performing scheduled maintenance. Please check back soon.</p>')
            );
        }

        $this->hydrateSuperglobals($psrRequest);

        // $_COOKIE is populated above, BEFORE Session::start(), so PHP's
        // native session mechanism picks up the correct session ID from
        // the incoming cookie — same as it would under a fresh per-request
        // PHP process.
        Session::start();

        $request = new Request((string) $psrRequest->getBody());

        try {
            $response = $this->kernel->handle($request);
            $response = $this->kernel->terminate($request, $response);
        } catch (\Throwable $e) {
            // Kernel::handle() intentionally lets HttpException (and
            // anything else) propagate uncaught, relying on classic
            // index.php's registered global exception handler to render
            // it — but that handler calls exit(1), which would kill this
            // worker on every 404. Catch here instead, and render via the
            // non-exiting counterpart to that same rendering logic.
            try {
                [$statusCode, $content] = ErrorHandler::renderErrorContent($e, $this->config);
            } catch (\Throwable $renderFailure) {
                // Double fault (e.g. Twig itself is broken) — fall back to
                // plain text rather than let this escape uncaught.
                error_log((string) $renderFailure);
                $statusCode = 500;
                $content    = 'Server error.';
            }

            $response = (new Response())
                ->setStatusCode($statusCode)
                ->setHeader('Content-Type', 'text/html')
                ->setContent($content);
        } finally {
            // session_write_close() releases the file-based session lock
            // session_start() holds. Skipping this on an exception path
            // would leave the lock held, and the next request carrying
            // the same session cookie would hang waiting for it.
            Session::close();
        }

        return $this->toPsrResponse($response);
    }

    /**
     * Populate the superglobals Rhapsody's Request/Session/Debug classes
     * read from directly. Classic per-request PHP gets these for free from
     * the SAPI; a persistent worker has to rebuild them from the PSR-7
     * request on every iteration.
     */
    private function hydrateSuperglobals(ServerRequestInterface $psrRequest): void
    {
        $uri = $psrRequest->getUri();

        $_GET    = $psrRequest->getQueryParams();
        $_COOKIE = $psrRequest->getCookieParams();
        $_FILES  = $this->mapUploadedFiles($psrRequest->getUploadedFiles());

        $parsedBody = $psrRequest->getParsedBody();
        $_POST      = is_array($parsedBody) ? $parsedBody : [];

        $server = [
            'REQUEST_METHOD' => $psrRequest->getMethod(),
            'REQUEST_URI'    => $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : ''),
            'HTTP_HOST'      => $uri->getHost(),
            'HTTPS'          => $uri->getScheme() === 'https' ? 'on' : 'off',
            'QUERY_STRING'   => $uri->getQuery(),
        ];

        foreach ($psrRequest->getHeaders() as $name => $values) {
            if (strcasecmp($name, 'Content-Type') === 0) {
                $server['CONTENT_TYPE'] = implode(', ', $values);
                continue;
            }
            if (strcasecmp($name, 'Content-Length') === 0) {
                $server['CONTENT_LENGTH'] = implode(', ', $values);
                continue;
            }
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = implode(', ', $values);
        }

        $_SERVER = array_merge($_SERVER, $server);
    }

    /**
     * @param array<string, UploadedFileInterface|array> $uploadedFiles
     */
    private function mapUploadedFiles(array $uploadedFiles): array
    {
        $mapped = [];
        foreach ($uploadedFiles as $key => $file) {
            if (! $file instanceof UploadedFileInterface) {
                continue;
            }
            $tmpPath = tempnam(sys_get_temp_dir(), 'rr_upload_');
            $file->moveTo($tmpPath);
            $mapped[$key] = [
                'name'     => $file->getClientFilename(),
                'type'     => $file->getClientMediaType(),
                'tmp_name' => $tmpPath,
                'error'    => $file->getError(),
                'size'     => $file->getSize(),
            ];
        }
        return $mapped;
    }

    private function toPsrResponse(Response $response): PsrResponseInterface
    {
        $psrResponse = $this->psr17Factory
            ->createResponse($response->getStatusCode())
            ->withBody($this->psr17Factory->createStream($response->getContent()));

        foreach ($response->getHeaders() as $name => $value) {
            $psrResponse = $psrResponse->withHeader($name, $value);
        }

        return $psrResponse;
    }
}
