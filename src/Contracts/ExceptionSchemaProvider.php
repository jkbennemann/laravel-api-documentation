<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Contracts;

use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;

interface ExceptionSchemaProvider
{
    public function provides(string $exceptionClass): bool;

    public function getResponse(string $exceptionClass): ResponseResult;
}
