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
 * Mirrors the API gateway handler pattern:
 * - Uses 'details' instead of 'errors'
 * - Includes 'request_id' in base properties
 * - Has a getMessage() method with match expression for status-specific messages
 */
class DetailsKeyHandler extends ExceptionHandler
{
    protected array $exceptionStatusCode = [
        ValidationException::class => Response::HTTP_BAD_REQUEST,
        AuthenticationException::class => Response::HTTP_UNAUTHORIZED,
        AuthorizationException::class => Response::HTTP_FORBIDDEN,
        ModelNotFoundException::class => Response::HTTP_NOT_FOUND,
    ];

    public function render($request, Throwable $e): Response
    {
        $error = $this->getErrors($e);

        return response()->json(array_merge([
            'timestamp' => (new Carbon)->now()->format(DateTimeInterface::ATOM),
            'message' => $this->getMessage($e),
            'path' => $request->getPathInfo(),
            'request_id' => $request->header('x-request-id'),
        ], $error))->setStatusCode($this->matchStatusCode($e));
    }

    private function matchStatusCode(Throwable $e): int
    {
        return $this->exceptionStatusCode[$e::class] ?? Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    private function getErrors(Throwable $exception): array
    {
        if (property_exists($exception, 'validator')) {
            return ['details' => $this->addMessageContext($exception->validator->getMessageBag()->toArray())];
        }

        return [];
    }

    private function getMessage(Throwable $exception): string
    {
        return match ($this->matchStatusCode($exception)) {
            Response::HTTP_UNAUTHORIZED => 'Unauthenticated.',
            Response::HTTP_FORBIDDEN => 'No permissions.',
            Response::HTTP_INTERNAL_SERVER_ERROR => 'Request could not be processed.',
            Response::HTTP_NOT_FOUND => 'Request not found.',
            default => $exception->getMessage()
        };
    }

    private function addMessageContext(array $message): array
    {
        return $message;
    }
}
