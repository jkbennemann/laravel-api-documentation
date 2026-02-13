<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Exceptions;

use Illuminate\Http\JsonResponse;

class InsufficientBalanceException extends \RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'insufficient_balance',
            'message' => 'Your account balance is too low.',
        ], 402);
    }
}
