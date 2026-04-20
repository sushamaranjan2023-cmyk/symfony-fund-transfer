<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Converts all unhandled exceptions on /api/* routes to structured JSON.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class ApiExceptionListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $appEnv,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        // Only intercept API routes
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();

        if ($exception instanceof HttpExceptionInterface) {
            $status  = $exception->getStatusCode();
            $message = $exception->getMessage();
            $code    = 'HTTP_ERROR';
        } else {
            $status  = Response::HTTP_INTERNAL_SERVER_ERROR;
            $message = 'An internal server error occurred.';
            $code    = 'INTERNAL_ERROR';

            $this->logger->error('unhandled_exception', [
                'exception' => get_class($exception),
                'message'   => $exception->getMessage(),
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
            ]);
        }

        $body = ['code' => $code, 'message' => $message];

        // Include stack trace only in dev
        if ($this->appEnv === 'dev') {
            $body['trace'] = $exception->getTraceAsString();
        }

        $event->setResponse(new JsonResponse($body, $status));
    }
}
