<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Routing\Controller;
use JkBennemann\LaravelApiDocumentation\Attributes\DocumentationFile;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\SampleResource;

class SeveralDocsController extends Controller
{
    #[DocumentationFile('docOne')]
    public function docOne(): SampleResource
    {
        return new SampleResource([]);
    }

    #[DocumentationFile(['docTwo'])]
    public function docTwo(): SampleResource
    {
        return new SampleResource([]);
    }

    #[DocumentationFile('docOne,docTwo')]
    public function bothDocs(): SampleResource
    {
        return new SampleResource([]);
    }

    public function defaultDoc(): SampleResource
    {
        return new SampleResource([]);
    }
}
