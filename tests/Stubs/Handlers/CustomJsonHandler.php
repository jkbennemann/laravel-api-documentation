<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Handlers;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Mirrors the product-catalogue handler pattern:
 * - Custom $exceptionStatusCode mapping (ValidationException → 400, AuthorizationException → 401)
 * - render() returns array_merge with base + conditional $error + debug-gated $trace
 * - 'errors' conditional key with i18n/message structure
 */
class CustomJsonHandler extends ExceptionHandler
{
    private array $exceptionStatusCode = [
        ValidationException::class => Response::HTTP_BAD_REQUEST,
        ModelNotFoundException::class => Response::HTTP_NOT_FOUND,
        AuthenticationException::class => Response::HTTP_UNAUTHORIZED,
        AuthorizationException::class => Response::HTTP_UNAUTHORIZED,
    ];

    public function render($request, Throwable $e): Response
    {
        $statusCode = $this->matchStatusCode($e);

        if (property_exists($e, 'validator')) {
            $error['errors'] = $this->addMessageContext($e->validator->getMessageBag()->toArray());
        } else {
            $error['errors'] = $this->addMessageContext(['exception' => [$e->getMessage()]]);
        }

        $trace = app()->hasDebugModeEnabled() ? ['detail' => $e->getTrace()] : [];

        return response()->json(array_merge([
            'timestamp' => (new Carbon)->now()->format(DateTimeInterface::ATOM),
            'message' => $e->getMessage(),
            'status' => $e->getCode(),
            'path' => $request->getPathInfo(),
        ], $error, $trace))->setStatusCode($statusCode);
    }

    private function matchStatusCode(Throwable $e): int
    {
        return $this->exceptionStatusCode[$e::class] ?? Response::HTTP_BAD_REQUEST;
    }

    private function addMessageContext(array $message): array
    {
        return $message;
    }
}
