<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers;

use Illuminate\Http\JsonResponse;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Exceptions\InsufficientBalanceException;

class PaymentController
{
    public function charge(): JsonResponse
    {
        throw new InsufficientBalanceException;

        return response()->json(['status' => 'charged']);
    }
}
