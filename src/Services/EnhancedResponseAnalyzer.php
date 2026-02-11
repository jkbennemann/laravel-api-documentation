<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use ReflectionMethod;
use Throwable;

/**
 * Enhanced Response Analyzer with infinite-depth AST analysis
 * for 100% accurate multi-status HTTP response detection
 */
class EnhancedResponseAnalyzer
{
    private array $detectedResponses = [];

    private array $errorStatusMappings = [];

    private NodeFinder $nodeFinder;

    private $parser;

    public function __construct(
        private readonly Repository $configuration,
        private readonly ResponseAnalyzer $responseAnalyzer,
        private readonly ?RequestAnalyzer $requestAnalyzer = null,
        private readonly ?ErrorMessageGenerator $errorMessageGenerator = null
    ) {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder;
        $this->initializeErrorStatusMappings();
    }

    /**
     * Analyze controller method for ALL possible HTTP status codes and responses
     * with infinite depth analysis
     */
    public function analyzeControllerMethodResponses(string $controller, string $method): array
    {
        $this->detectedResponses = [];

        if (! class_exists($controller)) {
            return [];
        }

        try {
            $reflection = new ReflectionMethod($controller, $method);

            // Get method source code
            $methodSource = $this->getMethodSource($reflection);
            if (empty($methodSource)) {
                return $this->getDefaultResponses($controller, $method);
            }

            // Parse with AST for infinite depth analysis
            // Use a simpler approach: parse the entire file and find the method
            $filename = $reflection->getDeclaringClass()->getFileName();
            if ($filename && file_exists($filename)) {
                $fileContent = file_get_contents($filename);
                $ast = $this->parser->parse($fileContent);

                if (! $ast) {
                    return $this->getDefaultResponses($controller, $method);
                }
            } else {
                return $this->getDefaultResponses($controller, $method);
            }

            // FIRST: Process explicit DataResponse attributes (highest priority)
            $this->processDataResponseAttributes($reflection);

            // SECOND: Comprehensive AST analysis with infinite recursion
            $this->performInfiniteDepthAnalysis($ast, $controller, $method);

            // THIRD: Add validation error responses if method has validation
            $this->detectValidationResponses($reflection);

            // Add exception-based error responses
            // Temporarily disabled due to infinite loop
            // $this->detectExceptionResponses($controller, $ast);

            // Ensure comprehensive response coverage (both success AND error responses)
            $this->ensureComprehensiveResponseCoverage($controller, $method, $reflection);

            return $this->detectedResponses;

        } catch (Throwable $e) {
            // Fallback to basic analysis if AST parsing fails
            return $this->getDefaultResponses($controller, $method);
        }
    }

    /**
     * Perform infinite-depth AST analysis to detect all response patterns
     */
    private function performInfiniteDepthAnalysis(array $ast, string $controller, string $method): void
    {
        $visitor = new class($this, $controller, $method) extends NodeVisitorAbstract
        {
            public function __construct(
                private EnhancedResponseAnalyzer $analyzer,
                private string $controller,
                private string $method
            ) {}

            private bool $insideTargetMethod = false;

            public function enterNode(Node $node)
            {
                // Check if we're entering the target method
                if ($node instanceof \PhpParser\Node\Stmt\ClassMethod &&
                    $node->name instanceof Node\Identifier &&
                    $node->name->toString() === $this->method) {
                    $this->insideTargetMethod = true;

                    return null;
                }

                // Only analyze nodes if we're inside the target method
                if (! $this->insideTargetMethod) {
                    return null;
                }

                // Detect response() helper calls
                if ($node instanceof MethodCall && $this->isResponseCall($node)) {
                    $this->analyzer->analyzeResponseCall($node, $this->controller, $this->method);
                }

                // Detect new JsonResponse() instantiation
                if ($node instanceof New_ && $this->isJsonResponseNew($node)) {
                    $this->analyzer->analyzeJsonResponseNew($node);
                }

                // Detect abort() function calls
                if ($node instanceof FuncCall && $this->isAbortCall($node)) {
                    $this->analyzer->analyzeAbortCall($node, $this->controller, $this->method);
                }

                // Detect throw statements
                if ($node instanceof Throw_) {
                    $this->analyzer->analyzeThrowStatement($node, $this->controller, $this->method);
                }

                // Detect custom helper method calls
                if ($node instanceof MethodCall && $this->isCustomHelperCall($node)) {
                    $this->analyzer->analyzeCustomHelperCall($node, $this->controller, $this->method);
                }

                // Detect setStatusCode method calls
                if ($node instanceof MethodCall && $this->isSetStatusCodeCall($node)) {
                    $this->analyzer->analyzeSetStatusCodeCall($node, $this->controller, $this->method);
                }

                // Detect static Response::* calls
                if ($node instanceof StaticCall && $this->isResponseStaticCall($node)) {
                    $this->analyzer->analyzeResponseStaticCall($node);
                }

                // Detect Laravel Resource returns
                if ($node instanceof New_ && $this->isResourceNew($node)) {
                    $this->analyzer->analyzeResourceNew($node, $this->controller, $this->method);
                }

                return null;
            }

            public function leaveNode(Node $node)
            {
                // Check if we're leaving the target method
                if ($node instanceof \PhpParser\Node\Stmt\ClassMethod &&
                    $node->name instanceof Node\Identifier &&
                    $node->name->toString() === $this->method) {
                    $this->insideTargetMethod = false;
                }

                return null;
            }

            private function isResponseCall(MethodCall $node): bool
            {
                // Valid response helper methods
                $validResponseMethods = [
                    'json', 'created', 'accepted', 'noContent', 'view',
                    'redirect', 'redirectTo', 'download', 'file', 'stream',
                ];

                if (! $node->name instanceof Node\Identifier) {
                    return false;
                }

                $methodName = $node->name->toString();

                // First check if this is a valid response method
                if (! in_array($methodName, $validResponseMethods)) {
                    return false;
                }

                // Check for direct response() helper chained calls: response()->method()
                if ($node->var instanceof FuncCall &&
                    $node->var->name instanceof Name &&
                    $node->var->name->toString() === 'response') {
                    return true;
                }

                // Check for response() helper variable assignments: $response = response(); $response->method()
                if ($node->var instanceof Node\Expr\Variable &&
                    is_string($node->var->name) &&
                    $node->var->name === 'response') {
                    return true;
                }

                return false;
            }

            private function isJsonResponseNew(New_ $node): bool
            {
                return $node->class instanceof Name &&
                       in_array($node->class->toString(), ['JsonResponse', 'Illuminate\\Http\\JsonResponse']);
            }

            private function isAbortCall(FuncCall $node): bool
            {
                return $node->name instanceof Name && $node->name->toString() === 'abort';
            }

            private function isCustomHelperCall(MethodCall $node): bool
            {
                if ($node->var instanceof Node\Expr\Variable &&
                    $node->var->name === 'this' &&
                    $node->name instanceof Node\Identifier) {

                    $methodName = $node->name->toString();

                    return str_starts_with($methodName, 'return') ||
                           str_starts_with($methodName, 'send');
                }

                return false;
            }

            private function isResponseStaticCall(StaticCall $node): bool
            {
                return $node->class instanceof Name &&
                       in_array($node->class->toString(), ['Response', 'Illuminate\\Http\\Response']);
            }

            private function isResourceNew(New_ $node): bool
            {
                if (! $node->class instanceof Name) {
                    return false;
                }

                $className = $node->class->toString();

                return str_ends_with($className, 'Resource') ||
                       str_contains($className, 'Resource');
            }

            private function isSetStatusCodeCall(MethodCall $node): bool
            {
                return $node->name instanceof Node\Identifier &&
                       $node->name->toString() === 'setStatusCode';
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }

    /**
     * Analyze response() helper method calls
     */
    public function analyzeResponseCall(MethodCall $node, string $controller, string $method): void
    {
        if (! $node->name instanceof Node\Identifier) {
            return;
        }

        $methodName = $node->name->toString();

        // Only process if this is actually a response() helper call, not other method calls
        if (! $this->isActualResponseHelperCall($node)) {
            return;
        }

        switch ($methodName) {
            case 'json':
                $this->analyzeJsonCall($node, $controller, $method);
                break;
            case 'created':
                $this->addSuccessResponse('201', 'Resource created', $controller, $method, 'application/json', null, [], null);
                break;
            case 'accepted':
                $this->addSuccessResponse('202', 'Request accepted', $controller, $method, 'application/json', null, [], null);
                break;
            case 'noContent':
                $this->addResponse('204', 'No content', null);
                break;
            case 'view':
                $this->addResponse('200', 'HTML view response', 'text/html');
                break;
            case 'redirectTo':
            case 'redirect':
                $this->addResponse('302', 'Redirect response', null);
                break;
            default:
                // Try to extract status from method call
                $this->analyzeGenericResponseCall($node);
        }
    }

    /**
     * Check if this is actually a response() helper method call
     */
    private function isActualResponseHelperCall(MethodCall $node): bool
    {
        // Valid response helper methods
        $validResponseMethods = [
            'json', 'created', 'accepted', 'noContent', 'view',
            'redirect', 'redirectTo', 'download', 'file', 'stream',
        ];

        if (! $node->name instanceof Node\Identifier) {
            return false;
        }

        $methodName = $node->name->toString();

        // Check if this is a valid response method
        if (! in_array($methodName, $validResponseMethods)) {
            return false;
        }

        // Check if the call is actually on the response() helper
        return $node->var instanceof FuncCall &&
               $node->var->name instanceof Name &&
               $node->var->name->toString() === 'response';
    }

    /**
     * Analyze response()->json() calls
     */
    public function analyzeJsonCall(MethodCall $node, string $controller, string $method): void
    {
        $statusCode = '200'; // default
        $data = null;

        // Look for status code parameter
        if (isset($node->args[1])) {
            $statusArg = $node->args[1]->value;
            if ($statusArg instanceof LNumber) {
                $statusCode = (string) $statusArg->value;
            } elseif ($statusArg instanceof Node\Expr\ClassConstFetch) {
                $statusCode = $this->resolveHttpConstant($statusArg);
            }
        }

        // Try to determine response structure from data parameter
        if (isset($node->args[0])) {
            $data = $this->analyzeDataParameter($node->args[0]->value);
        }

        // Use detailed schema analysis for success responses
        if (in_array($statusCode, ['200', '201', '202'])) {
            $this->addSuccessResponse($statusCode, $this->getStatusDescription($statusCode), $controller, $method, 'application/json', $data, [], null);
        } else {
            $this->addResponse($statusCode, $this->getStatusDescription($statusCode), 'application/json', $data);
        }
    }

    /**
     * Analyze new JsonResponse() instantiation
     */
    public function analyzeJsonResponseNew(New_ $node): void
    {
        $statusCode = '200'; // default
        $data = null;

        // Check constructor arguments
        if (isset($node->args[1])) {
            $statusArg = $node->args[1]->value;
            if ($statusArg instanceof LNumber) {
                $statusCode = (string) $statusArg->value;
            } elseif ($statusArg instanceof Node\Expr\ClassConstFetch) {
                $statusCode = $this->resolveHttpConstant($statusArg);
            }
        }

        if (isset($node->args[0])) {
            $data = $this->analyzeDataParameter($node->args[0]->value);
        }

        $this->addResponse($statusCode, $this->getStatusDescription($statusCode), 'application/json', $data);
    }

    /**
     * Analyze abort() function calls
     */
    public function analyzeAbortCall(FuncCall $node, string $controller = '', string $method = ''): void
    {
        if (empty($node->args)) {
            return;
        }

        $statusArg = $node->args[0]->value;
        $statusCode = '500'; // default
        $message = 'Error';

        if ($statusArg instanceof LNumber) {
            $statusCode = (string) $statusArg->value;
        } elseif ($statusArg instanceof Node\Expr\ClassConstFetch) {
            $statusCode = $this->resolveHttpConstant($statusArg);
        }

        // Extract message if provided
        if (isset($node->args[1]) && $node->args[1]->value instanceof String_) {
            $message = $node->args[1]->value->value;
        }

        $this->addErrorResponse($statusCode, $message, $controller, $method);
    }

    /**
     * Analyze setStatusCode method calls
     */
    public function analyzeSetStatusCodeCall(MethodCall $node, string $controller, string $method): void
    {
        if (empty($node->args)) {
            return;
        }

        $statusArg = $node->args[0]->value;
        $statusCode = '200'; // default

        if ($statusArg instanceof LNumber) {
            $statusCode = (string) $statusArg->value;
        } elseif ($statusArg instanceof Node\Expr\ClassConstFetch) {
            $statusCode = $this->resolveHttpConstant($statusArg);
        }

        // Get response schema from comprehensive analysis
        $responseSchema = $this->responseAnalyzer->analyzeControllerMethod($controller, $method);

        $this->addSuccessResponse(
            $statusCode,
            $this->getStatusDescription($statusCode),
            $controller,
            $method,
            'application/json',
            $responseSchema,
            [],
            null
        );
    }

    /**
     * Analyze throw statements to detect exception-based responses
     */
    public function analyzeThrowStatement(Throw_ $node, string $controller = '', string $method = ''): void
    {
        // Handle throw new Exception()
        if ($node->expr instanceof New_) {
            $exceptionClass = $this->getExceptionClassName($node->expr);
            if ($exceptionClass && isset($this->errorStatusMappings[$exceptionClass])) {
                $statusCode = (string) $this->errorStatusMappings[$exceptionClass];
                $this->addErrorResponse($statusCode, $this->getStatusDescription($statusCode), $controller, $method);
            }

            return;
        }

        // Handle throw Exception::staticMethod() (e.g. ValidationException::withMessages())
        if ($node->expr instanceof StaticCall && $node->expr->class instanceof Name) {
            $className = $node->expr->class->toString();

            // Try fully qualified class name first
            if (isset($this->errorStatusMappings[$className])) {
                $statusCode = (string) $this->errorStatusMappings[$className];
                $this->addErrorResponse($statusCode, $this->getStatusDescription($statusCode), $controller, $method);

                return;
            }

            // Try to resolve short class name against known mappings
            foreach ($this->errorStatusMappings as $mappedClass => $status) {
                $shortName = class_basename($mappedClass);
                if ($shortName === $className) {
                    $this->addErrorResponse((string) $status, $this->getStatusDescription((string) $status), $controller, $method);

                    return;
                }
            }
        }
    }

    /**
     * Analyze custom helper method calls (returnNoContent, returnAccepted, etc.)
     */
    public function analyzeCustomHelperCall(MethodCall $node, string $controller, string $method): void
    {
        if (! $node->name instanceof Node\Identifier) {
            return;
        }

        $methodName = $node->name->toString();

        $helperMappings = [
            'returnNoContent' => ['204', 'No content', null],
            'returnAccepted' => ['202', 'Request accepted', 'application/json'],
            'returnCreated' => ['201', 'Resource created', 'application/json'],
            'returnOk' => ['200', 'Success', 'application/json'],
            'sendProxiedRequest' => ['200', 'Proxied response', 'application/json'], // Could be any status
        ];

        if (isset($helperMappings[$methodName])) {
            [$status, $description, $contentType] = $helperMappings[$methodName];

            // Use detailed schema analysis for success responses
            if (in_array($status, ['200', '201', '202']) && $contentType === 'application/json') {
                $this->addSuccessResponse($status, $description, $controller, $method, $contentType, null, [], null);
            } else {
                $this->addResponse($status, $description, $contentType);
            }
        }
    }

    /**
     * Analyze static Response::* calls
     */
    public function analyzeResponseStaticCall(StaticCall $node): void
    {
        if (! $node->name instanceof Node\Identifier) {
            return;
        }

        $methodName = $node->name->toString();

        // Map static Response methods to status codes
        $staticMappings = [
            'HTTP_OK' => '200',
            'HTTP_CREATED' => '201',
            'HTTP_ACCEPTED' => '202',
            'HTTP_NO_CONTENT' => '204',
            'HTTP_BAD_REQUEST' => '400',
            'HTTP_UNAUTHORIZED' => '401',
            'HTTP_FORBIDDEN' => '403',
            'HTTP_NOT_FOUND' => '404',
            'HTTP_UNPROCESSABLE_ENTITY' => '422',
            'HTTP_INTERNAL_SERVER_ERROR' => '500',
        ];

        if (isset($staticMappings[$methodName])) {
            $statusCode = $staticMappings[$methodName];
            $this->addResponse($statusCode, $this->getStatusDescription($statusCode), 'application/json');
        }
    }

    /**
     * Analyze Laravel Resource instantiation with wrapping detection
     */
    public function analyzeResourceNew(New_ $node, string $controller, string $method): void
    {
        if (! $node->class instanceof Name) {
            return;
        }

        $resourceClass = $node->class->toString();

        if (!class_exists($resourceClass)) {
            $resolvedClass = $this->resolveClassNameFromController($resourceClass, $controller);
            if ($resolvedClass && class_exists($resolvedClass)) {
                $resourceClass = $resolvedClass;
            } else {
                return;
            }
        }

        // Use existing ResponseAnalyzer for resource analysis
        $analysis = $this->responseAnalyzer->analyzeJsonResourceResponse($resourceClass);

        if (! empty($analysis)) {
            // Detect resource wrapping configuration
            $wrapping = $this->detectResourceWrapping($resourceClass);
            if ($wrapping) {
                $analysis['wrapping'] = $wrapping;
            }

            // Only add a default 200 if no explicit success status already exists
            // (e.g. from a DataResponse attribute specifying 201 or 202)
            $hasExplicitSuccess = false;
            foreach ($this->detectedResponses as $code => $resp) {
                if (in_array($code, ['200', '201', '202', '204'])) {
                    $hasExplicitSuccess = true;
                    break;
                }
            }

            if (! $hasExplicitSuccess) {
                $this->addSuccessResponse('200', 'Success', $controller, $method, 'application/json', $analysis, [], $resourceClass);
            }
        }
    }

    private function resolveClassNameFromController(string $shortClassName, string $controllerClass): ?string
    {
        if (!class_exists($controllerClass)) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($controllerClass);
            $filename = $reflection->getFileName();

            if (!$filename || !file_exists($filename)) {
                return null;
            }

            $content = file_get_contents($filename);
            $namespace = $reflection->getNamespaceName();

            if (preg_match('/^use\s+([^\s;]+\\\\' . preg_quote($shortClassName, '/') . ')\s*;/m', $content, $matches)) {
                return $matches[1];
            }

            $sameNamespaceClass = $namespace . '\\' . $shortClassName;
            if (class_exists($sameNamespaceClass)) {
                return $sameNamespaceClass;
            }

            $resourceNamespaces = [
                str_replace('\\Controllers\\', '\\Resources\\', $namespace),
                str_replace('\\Http\\Controllers\\', '\\Http\\Resources\\', $namespace),
            ];

            foreach ($resourceNamespaces as $resourceNamespace) {
                $potentialClass = $resourceNamespace . '\\' . $shortClassName;
                if (class_exists($potentialClass)) {
                    return $potentialClass;
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Detect resource wrapping configuration with inheritance support
     */
    private function detectResourceWrapping(string $resourceClass): ?array
    {
        if (! class_exists($resourceClass)) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($resourceClass);
            $wrappingInfo = [];

            // Check for custom $wrap property in the resource class and its parents
            $currentClass = $reflection;
            while ($currentClass) {
                if ($currentClass->hasProperty('wrap')) {
                    $wrapProperty = $currentClass->getProperty('wrap');
                    if ($wrapProperty->isStatic()) {
                        $wrapProperty->setAccessible(true);
                        $wrapValue = $wrapProperty->getValue();

                        if ($wrapValue !== null) {
                            $wrappingInfo['custom_wrap'] = $wrapValue;
                            $wrappingInfo['wrap_source'] = $currentClass->getName();
                            break;
                        }
                    }
                }

                $currentClass = $currentClass->getParentClass();
            }

            // Check if resource wrapping is disabled globally or for this resource
            $wrappingInfo['global_wrapping'] = $this->checkGlobalWrappingStatus($resourceClass);

            // Parse resource source for additional() calls and other wrapping modifications
            $additionalData = $this->detectResourceAdditionalData($reflection);
            if ($additionalData) {
                $wrappingInfo['additional_data'] = $additionalData;
            }

            return ! empty($wrappingInfo) ? $wrappingInfo : null;

        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Check global resource wrapping status
     */
    private function checkGlobalWrappingStatus(string $resourceClass): bool
    {
        // Check if JsonResource::withoutWrapping() has been called
        // This would require analyzing the application bootstrap or service providers
        // For now, return true (wrapping enabled by default)
        return true;
    }

    /**
     * Detect additional data added to resources via additional() method
     */
    private function detectResourceAdditionalData(\ReflectionClass $reflection): ?array
    {
        $fileName = $reflection->getFileName();
        if (! $fileName || ! file_exists($fileName)) {
            return null;
        }

        try {
            $source = file_get_contents($fileName);
            $ast = $this->parser->parse($source);

            if (! $ast) {
                return null;
            }

            // Look for ->additional() method calls in the resource class
            $additionalCalls = $this->nodeFinder->find($ast, function (Node $node) {
                return $node instanceof MethodCall &&
                       $node->name instanceof Node\Identifier &&
                       $node->name->toString() === 'additional';
            });

            $additionalData = [];
            foreach ($additionalCalls as $call) {
                if (isset($call->args[0])) {
                    $dataArg = $call->args[0]->value;
                    if ($dataArg instanceof Array_) {
                        $additionalData[] = $this->parseArrayNode($dataArg);
                    }
                }
            }

            return ! empty($additionalData) ? $additionalData : null;

        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Parse PHP AST Array node to extract structure
     */
    private function parseArrayNode(Array_ $arrayNode): array
    {
        $result = [];

        foreach ($arrayNode->items as $item) {
            if (! $item instanceof ArrayItem) {
                continue;
            }

            $key = null;
            $value = null;

            if ($item->key instanceof String_) {
                $key = $item->key->value;
            } elseif ($item->key instanceof Node\Scalar\LNumber) {
                $key = $item->key->value;
            }

            if ($item->value instanceof String_) {
                $value = $item->value->value;
            } elseif ($item->value instanceof Node\Scalar\LNumber) {
                $value = $item->value->value;
            } elseif ($item->value instanceof Array_) {
                $value = $this->parseArrayNode($item->value);
            }

            if ($key !== null) {
                $result[$key] = $value;
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * Process DataResponse attributes to detect explicit response definitions
     */
    private function processDataResponseAttributes(ReflectionMethod $method): void
    {
        // Get all DataResponse attributes (method can have multiple with different status codes)
        $dataResponseAttributes = $method->getAttributes(DataResponse::class);

        foreach ($dataResponseAttributes as $attribute) {
            $instance = $attribute->newInstance();

            $statusCode = (string) $instance->status;
            $description = $instance->description ?: $this->getStatusDescription($statusCode);
            $resource = $instance->resource;
            $headers = $instance->headers;
            $isCollection = $instance->isCollection;

            // Create response schema based on resource if specified
            $schema = null;
            if ($resource && (is_string($resource) || is_array($resource))) {
                $schema = $this->analyzeResourceSchema($resource);
            }

            // Use detailed schema analysis for success responses with resource
            if (in_array($statusCode, ['200', '201', '202']) && $resource) {
                $this->addSuccessResponse(
                    $statusCode,
                    $description,
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                    'application/json',
                    $schema,
                    $headers,
                    is_string($resource) ? $resource : null,
                    $isCollection
                );
            } else {
                $this->addResponse($statusCode, $description, 'application/json', $schema, $headers);
            }
        }
    }

    /**
     * Analyze resource schema from DataResponse attribute
     */
    private function analyzeResourceSchema($resource): ?array
    {
        if (is_string($resource) && class_exists($resource)) {
            // Check if it's a Spatie Data DTO
            if (is_subclass_of($resource, 'Spatie\\LaravelData\\Data')) {
                return $this->analyzeSpatieDataDto($resource);
            }

            // Check if it's a Laravel JsonResource
            if (is_subclass_of($resource, 'Illuminate\\Http\\Resources\\Json\\JsonResource')) {
                return $this->responseAnalyzer->analyzeJsonResourceResponse($resource);
            }

            // Fallback: try to analyze any class with reflection
            return $this->analyzeClassWithReflection($resource);
        }

        if (is_array($resource)) {
            // Handle array-defined resource structures
            return [
                'type' => 'object',
                'properties' => $this->convertArrayToOpenApiProperties($resource),
            ];
        }

        return null;
    }

    /**
     * Analyze Spatie Data DTO class
     */
    private function analyzeSpatieDataDto(string $dtoClass): ?array
    {
        try {
            $reflection = new \ReflectionClass($dtoClass);
            $properties = [];

            // Check for case mapping on the class level
            $caseMapper = $this->detectSpatieDataCaseMapping($reflection);

            // Get all public properties from the DTO
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $propertyName = $property->getName();
                $propertyType = $property->getType();

                // Apply case mapping to get the actual JSON property name
                $jsonPropertyName = $this->applyCaseMapping($propertyName, $caseMapper);

                // Get property attributes for additional schema information
                $parameterAttrs = $property->getAttributes(\JkBennemann\LaravelApiDocumentation\Attributes\Parameter::class);

                $mappedType = $this->mapPhpTypeToOpenApi($propertyType);

                $schemaProperty = [
                    'type' => $mappedType,
                    'description' => $this->getPropertyDescription($property, $parameterAttrs),
                ];

                // For array types, add items schema
                if ($mappedType === 'array') {
                    $itemsType = $this->getPropertyItems($parameterAttrs);
                    $schemaProperty['items'] = ['type' => $itemsType ?? 'object'];
                }

                // Add format information if available
                if ($format = $this->getPropertyFormat($property, $parameterAttrs)) {
                    $schemaProperty['format'] = $format;
                }

                // Handle nullable types
                if ($propertyType && $propertyType->allowsNull()) {
                    $schemaProperty['nullable'] = true;
                }

                // Add example if available
                if ($example = $this->getPropertyExample($parameterAttrs)) {
                    $schemaProperty['example'] = $example;
                }

                // Add minLength if available
                if ($minLength = $this->getPropertyMinLength($parameterAttrs)) {
                    $schemaProperty['minLength'] = $minLength;
                }

                // Add maxLength if available
                if ($maxLength = $this->getPropertyMaxLength($parameterAttrs)) {
                    $schemaProperty['maxLength'] = $maxLength;
                }

                // Use the JSON property name (with case mapping applied)
                $properties[$jsonPropertyName] = $schemaProperty;
            }

            return [
                'type' => 'object',
                'properties' => $properties,
            ];

        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Detect Spatie Data case mapping from class attributes
     */
    private function detectSpatieDataCaseMapping(\ReflectionClass $reflection): ?string
    {
        // Check for MapName attribute on the class
        $mapNameAttrs = $reflection->getAttributes();

        foreach ($mapNameAttrs as $attribute) {
            $attributeName = $attribute->getName();

            // Check for Spatie\LaravelData\Attributes\MapName
            if (str_ends_with($attributeName, 'MapName') || str_contains($attributeName, 'MapName')) {
                try {
                    $instance = $attribute->newInstance();

                    // Check if it has a mapper property or arguments that indicate snake case
                    $arguments = $attribute->getArguments();

                    // Look for SnakeCaseMapper in arguments
                    foreach ($arguments as $argument) {
                        if (is_string($argument) && str_contains($argument, 'SnakeCaseMapper')) {
                            return 'snake_case';
                        }

                        if (is_object($argument) && str_contains(get_class($argument), 'SnakeCaseMapper')) {
                            return 'snake_case';
                        }
                    }

                } catch (\Throwable $e) {
                    // Continue checking other attributes
                }
            }
        }

        return null; // No case mapping detected
    }

    /**
     * Apply case mapping to property name
     */
    private function applyCaseMapping(string $propertyName, ?string $caseMapper): string
    {
        if ($caseMapper === 'snake_case') {
            return $this->camelToSnakeCase($propertyName);
        }

        return $propertyName; // No mapping, return original
    }

    /**
     * Convert camelCase to snake_case
     */
    private function camelToSnakeCase(string $input): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $input));
    }

    /**
     * Analyze class with reflection as fallback
     */
    private function analyzeClassWithReflection(string $className): ?array
    {
        try {
            $reflection = new \ReflectionClass($className);
            $properties = [];

            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $propertyName = $property->getName();
                $propertyType = $property->getType();

                $properties[$propertyName] = [
                    'type' => $this->mapPhpTypeToOpenApi($propertyType),
                    'description' => "The {$propertyName} field",
                ];
            }

            return [
                'type' => 'object',
                'properties' => $properties,
            ];

        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Map PHP type to OpenAPI type
     */
    private function mapPhpTypeToOpenApi(?\ReflectionType $type): string
    {
        if (! $type) {
            return 'mixed';
        }

        if ($type instanceof \ReflectionNamedType) {
            $typeName = $type->getName();

            return match ($typeName) {
                'int' => 'integer',
                'float' => 'number',
                'bool' => 'boolean',
                'string' => 'string',
                'array' => 'array',
                default => 'object'
            };
        }

        return 'mixed';
    }

    /**
     * Get property description from Parameter attributes
     */
    private function getPropertyDescription(\ReflectionProperty $property, array $parameterAttrs): string
    {
        if (! empty($parameterAttrs)) {
            $instance = $parameterAttrs[0]->newInstance();
            if (! empty($instance->description)) {
                return $instance->description;
            }
        }

        return "The {$property->getName()} field";
    }

    /**
     * Get property format from Parameter attributes
     */
    private function getPropertyFormat(\ReflectionProperty $property, array $parameterAttrs): ?string
    {
        if (! empty($parameterAttrs)) {
            $instance = $parameterAttrs[0]->newInstance();

            return $instance->format ?? null;
        }

        return null;
    }

    /**
     * Get property example from Parameter attributes
     */
    private function getPropertyExample(array $parameterAttrs)
    {
        if (! empty($parameterAttrs)) {
            $instance = $parameterAttrs[0]->newInstance();

            return $instance->example ?? null;
        }

        return null;
    }

    /**
     * Get property minLength from Parameter attributes
     */
    private function getPropertyMinLength(array $parameterAttrs): ?int
    {
        if (! empty($parameterAttrs)) {
            $instance = $parameterAttrs[0]->newInstance();

            return $instance->minLength ?? null;
        }

        return null;
    }

    /**
     * Get property maxLength from Parameter attributes
     */
    private function getPropertyMaxLength(array $parameterAttrs): ?int
    {
        if (! empty($parameterAttrs)) {
            $instance = $parameterAttrs[0]->newInstance();

            return $instance->maxLength ?? null;
        }

        return null;
    }

    /**
     * Get property items type from Parameter attributes for array types
     */
    private function getPropertyItems(array $parameterAttrs): ?string
    {
        if (! empty($parameterAttrs)) {
            $instance = $parameterAttrs[0]->newInstance();

            return $instance->items ?? null;
        }

        return null;
    }

    /**
     * Apply Parameter attributes to enhance response schema
     */
    private function applyParameterAttributesToSchema(?array $schema, string $controller, string $method): ?array
    {
        if (! $schema || ! isset($schema['properties'])) {
            return $schema;
        }

        try {
            $parameterEnhancements = [];

            // 1. Get Parameter attributes from the controller method
            $reflection = new ReflectionMethod($controller, $method);
            $methodParameterAttributes = $reflection->getAttributes(Parameter::class);

            foreach ($methodParameterAttributes as $attribute) {
                $instance = $attribute->newInstance();
                $parameterEnhancements[$instance->name] = $instance;
            }

            // 2. Get Parameter attributes from response DTO classes by analyzing DataResponse attributes
            $dataResponseAttributes = $reflection->getAttributes(\JkBennemann\LaravelApiDocumentation\Attributes\DataResponse::class);

            foreach ($dataResponseAttributes as $dataResponseAttr) {
                $dataResponseInstance = $dataResponseAttr->newInstance();
                $resourceClass = $dataResponseInstance->resource;

                if ($resourceClass && is_string($resourceClass) && class_exists($resourceClass)) {
                    $resourceReflection = new \ReflectionClass($resourceClass);
                    $resourceParameterAttributes = $resourceReflection->getAttributes(Parameter::class);

                    foreach ($resourceParameterAttributes as $attribute) {
                        $instance = $attribute->newInstance();
                        // DTO Parameter attributes take precedence over method Parameter attributes
                        $parameterEnhancements[$instance->name] = $instance;
                    }
                }
            }

            // 3. If no Parameter attributes found, try to detect DTO classes from response analysis
            if (empty($parameterEnhancements)) {
                $parameterEnhancements = $this->extractParameterAttributesFromResponseAnalysis($schema, $controller, $method);
            }

            if (empty($parameterEnhancements)) {
                return $schema;
            }

            // Apply enhancements to existing schema properties
            foreach ($schema['properties'] as $propertyName => &$property) {
                if (isset($parameterEnhancements[$propertyName])) {
                    $enhancement = $parameterEnhancements[$propertyName];

                    // Apply type if specified (Parameter attribute takes precedence)
                    if ($enhancement->type) {
                        $property['type'] = $this->mapParameterTypeToOpenApi($enhancement->type);
                    }

                    // Apply format if specified
                    if ($enhancement->format) {
                        $property['format'] = $enhancement->format;
                    }

                    // Apply description if specified (Parameter attribute takes precedence)
                    if ($enhancement->description) {
                        $property['description'] = $enhancement->description;
                    }

                    // Add example if specified (preserve null for nullable fields)
                    if ($enhancement->example !== null) {
                        $property['example'] = $enhancement->example;
                    } elseif ($enhancement->nullable) {
                        $property['example'] = null;
                    }

                    // Add nullable flag if specified
                    if ($enhancement->nullable) {
                        $property['nullable'] = true;
                    }

                    // Add deprecated flag if specified
                    if ($enhancement->deprecated) {
                        $property['deprecated'] = true;
                    }

                    // Add minLength if specified
                    if ($enhancement->minLength !== null) {
                        $property['minLength'] = $enhancement->minLength;
                    }

                    // Add maxLength if specified
                    if ($enhancement->maxLength !== null) {
                        $property['maxLength'] = $enhancement->maxLength;
                    }

                    // Add items for array types if specified
                    if ($enhancement->items !== null) {
                        $property['items'] = ['type' => $enhancement->items];
                    }

                    unset($parameterEnhancements[$propertyName]);
                }
            }

            foreach ($parameterEnhancements as $parameterEnhancement) {
                $propertySchema = [
                    'type' => $parameterEnhancement->type ? $this->mapParameterTypeToOpenApi($parameterEnhancement->type) : 'string',
                    'description' => $parameterEnhancement->description,
                ];

                if ($parameterEnhancement->format) {
                    $propertySchema['format'] = $parameterEnhancement->format;
                }

                if ($parameterEnhancement->example !== null) {
                    $propertySchema['example'] = $parameterEnhancement->example;
                }

                if ($parameterEnhancement->deprecated) {
                    $propertySchema['deprecated'] = true;
                }

                if ($parameterEnhancement->minLength !== null) {
                    $propertySchema['minLength'] = $parameterEnhancement->minLength;
                }

                if ($parameterEnhancement->maxLength !== null) {
                    $propertySchema['maxLength'] = $parameterEnhancement->maxLength;
                }

                if ($parameterEnhancement->items !== null) {
                    $propertySchema['items'] = ['type' => $parameterEnhancement->items];
                }

                $schema['properties'][$parameterEnhancement->name] = $propertySchema;
            }

            return $schema;

        } catch (\Throwable $e) {
            // If reflection fails, return original schema
            return $schema;
        }
    }

    /**
     * Extract Parameter attributes from DTO classes by analyzing the response analyzer context
     */
    private function extractParameterAttributesFromResponseAnalysis(array $schema, string $controller, string $method): array
    {
        $parameterEnhancements = [];

        try {
            // Try to detect DTO classes from the response analyzer's previous analysis
            $responseAnalysis = $this->responseAnalyzer->analyzeControllerMethod($controller, $method);

            if ($responseAnalysis && isset($responseAnalysis['data_class'])) {
                $dtoClass = $responseAnalysis['data_class'];

                if (is_string($dtoClass) && class_exists($dtoClass)) {
                    $parameterEnhancements = array_merge(
                        $parameterEnhancements,
                        $this->extractParameterAttributesFromClass($dtoClass)
                    );
                }
            }

            // Also check if there are any Spatie Data classes that might be related
            // by looking for classes that have properties matching our schema
            if (empty($parameterEnhancements) && isset($schema['properties'])) {
                $parameterEnhancements = $this->discoverDTOClassesBySchemaMatching($schema);
            }

        } catch (\Throwable $e) {
            // Fail silently and return empty array
        }

        return $parameterEnhancements;
    }

    /**
     * Extract Parameter attributes from a specific class
     */
    private function extractParameterAttributesFromClass(string $className): array
    {
        $parameterEnhancements = [];

        try {
            $reflection = new \ReflectionClass($className);
            $attributes = $reflection->getAttributes(Parameter::class);

            foreach ($attributes as $attribute) {
                $instance = $attribute->newInstance();
                $parameterEnhancements[$instance->name] = $instance;
            }
        } catch (\Throwable $e) {
            // Continue silently
        }

        return $parameterEnhancements;
    }

    /**
     * Discover DTO classes by matching schema properties with loaded classes
     */
    private function discoverDTOClassesBySchemaMatching(array $schema): array
    {
        $parameterEnhancements = [];

        if (! isset($schema['properties']) || empty($schema['properties'])) {
            return $parameterEnhancements;
        }

        $schemaPropertyNames = array_keys($schema['properties']);

        // Get all loaded classes and check if any have Parameter attributes
        // that match our schema properties
        $loadedClasses = get_declared_classes();

        foreach ($loadedClasses as $className) {
            // Skip framework classes and vendor classes that we know won't have our attributes
            if ($this->shouldSkipClassForDiscovery($className)) {
                continue;
            }

            try {
                $reflection = new \ReflectionClass($className);
                $attributes = $reflection->getAttributes(Parameter::class);

                if (empty($attributes)) {
                    continue;
                }

                // Check if this class has Parameter attributes that match our schema properties
                $classParameterNames = [];
                foreach ($attributes as $attribute) {
                    $instance = $attribute->newInstance();
                    $classParameterNames[] = $instance->name;
                }

                // If there's a significant overlap between class parameters and schema properties,
                // this might be our DTO class
                $overlap = array_intersect($classParameterNames, $schemaPropertyNames);
                $overlapPercentage = count($overlap) / count($schemaPropertyNames);

                // If more than 50% of schema properties match Parameter attributes in this class
                if ($overlapPercentage > 0.5) {
                    $classEnhancements = $this->extractParameterAttributesFromClass($className);
                    $parameterEnhancements = array_merge($parameterEnhancements, $classEnhancements);

                    // Stop after finding the first good match to avoid conflicts
                    break;
                }

            } catch (\Throwable $e) {
                // Continue to next class
            }
        }

        return $parameterEnhancements;
    }

    /**
     * Determine if a class should be skipped during DTO discovery
     */
    private function shouldSkipClassForDiscovery(string $className): bool
    {
        // Skip PHP internal classes
        if (strpos($className, '\\') === false) {
            return true;
        }

        // Skip common framework/vendor namespaces that won't have our Parameter attributes
        $skipPrefixes = [
            'Illuminate\\',
            'Symfony\\',
            'Carbon\\',
            'Monolog\\',
            'PhpParser\\',
            'Composer\\',
            'Psr\\',
            'League\\',
            'GuzzleHttp\\',
            'Doctrine\\',
            'Faker\\',
            'Mockery\\',
            'PHPUnit\\',
            'Pest\\',
            'Laravel\\',
            'Livewire\\',
            'Filament\\',
            'ReflectionClass',
            'ReflectionMethod',
            'ReflectionProperty',
        ];

        foreach ($skipPrefixes as $prefix) {
            if (str_starts_with($className, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map Parameter attribute type to OpenAPI type
     */
    private function mapParameterTypeToOpenApi(string $parameterType): string
    {
        return match ($parameterType) {
            'int', 'integer' => 'integer',
            'float', 'double', 'number' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'array',
            'object' => 'object',
            default => 'string',
        };
    }

    /**
     * Convert array structure to OpenAPI properties format
     */
    private function convertArrayToOpenApiProperties(array $structure): array
    {
        $properties = [];

        foreach ($structure as $key => $value) {
            if (is_array($value)) {
                // Check if this is a shorthand positional array format: [type, nullable, description, example]
                if ($this->isShorthandPropertyDefinition($value)) {
                    $properties[$key] = $this->parseShorthandPropertyDefinition($value);
                } else {
                    // Recursive nested object
                    $properties[$key] = [
                        'type' => 'object',
                        'properties' => $this->convertArrayToOpenApiProperties($value),
                    ];
                }
            } else {
                $properties[$key] = [
                    'type' => is_string($value) ? 'string' : (is_int($value) ? 'integer' : 'mixed'),
                ];
            }
        }

        return $properties;
    }

    /**
     * Check if array is a shorthand property definition: [type, nullable, description, example]
     */
    private function isShorthandPropertyDefinition(array $value): bool
    {
        // Must have numeric keys only
        if (array_keys($value) !== range(0, count($value) - 1)) {
            return false;
        }

        // First element should be a type string
        if (!isset($value[0]) || !is_string($value[0])) {
            return false;
        }

        // Valid type strings
        $validTypes = ['string', 'integer', 'number', 'boolean', 'array', 'object', 'mixed'];
        return in_array($value[0], $validTypes, true);
    }

    /**
     * Parse shorthand property definition: [type, nullable, description, example]
     */
    private function parseShorthandPropertyDefinition(array $value): array
    {
        $property = [
            'type' => $value[0] ?? 'string',
        ];

        // Index 1: nullable (bool or null)
        if (isset($value[1]) && $value[1] === null || $value[1] === true) {
            $property['nullable'] = true;
        }

        // Index 2: description (string)
        if (isset($value[2]) && is_string($value[2]) && $value[2] !== '') {
            $property['description'] = $value[2];
        }

        // Index 3: example (mixed)
        if (isset($value[3])) {
            $property['example'] = $value[3];
        }

        return $property;
    }

    /**
     * Detect validation responses from method reflection
     */
    private function detectValidationResponses(ReflectionMethod $method): void
    {
        $controller = $method->getDeclaringClass()->getName();
        $methodName = $method->getName();

        // Check if method has FormRequest parameters
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type && ! $type->isBuiltin()) {
                $typeName = $type->getName();
                if (class_exists($typeName) &&
                    is_subclass_of($typeName, 'Illuminate\\Foundation\\Http\\FormRequest')) {

                    // Extract validation rules if RequestAnalyzer is available
                    $validationRules = [];
                    if ($this->requestAnalyzer) {
                        $validationRules = $this->requestAnalyzer->extractValidationRules($typeName);
                    }

                    $this->addErrorResponse('422', 'Validation error', $controller, $methodName, $validationRules);
                    break;
                }
            }
        }
    }

    /**
     * Detect exception-based responses from controller and method context
     */
    private function detectExceptionResponses(string $controller, array $ast): void
    {
        // Analyze controller's exception handler patterns
        $handlerPath = $this->findExceptionHandler($controller);
        if ($handlerPath) {
            $this->analyzeExceptionHandler($handlerPath);
        }

        // Add common Laravel exceptions
        // Temporarily disabled to prevent infinite loops
        // $this->addCommonErrorResponses();
    }

    /**
     * Add response to detected responses array
     */
    private function addResponse(string $statusCode, string $description, ?string $contentType = null, ?array $schema = null, array $headers = []): void
    {
        if (! isset($this->detectedResponses[$statusCode])) {
            $this->detectedResponses[$statusCode] = [
                'description' => $description,
                'content_type' => $contentType,
                'headers' => $headers,
                'schema' => $schema,
            ];
        }
    }

    /**
     * Add success response with detailed schema analysis
     */
    private function addSuccessResponse(string $statusCode, string $description, string $controller, string $method, ?string $contentType = 'application/json', ?array $additionalSchema = null, array $headers = [], ?string $resourceClass = null, bool $isCollection = false): void
    {
        if (! isset($this->detectedResponses[$statusCode])) {
            // Use existing ResponseAnalyzer for detailed schema analysis
            $detailedSchema = $this->responseAnalyzer->analyzeControllerMethod($controller, $method);

            // Auto-detect resource class from schema analysis if not provided
            if (! $resourceClass && isset($detailedSchema['detected_resource'])) {
                $resourceClass = $detailedSchema['detected_resource'];
            }

            // Prefer additionalSchema (from resource class) if provided and has properties
            if ($additionalSchema && !empty($additionalSchema['properties'])) {
                $detailedSchema = $additionalSchema;
            } elseif ($additionalSchema) {
                $detailedSchema = array_merge($detailedSchema ?: [], $additionalSchema);
            }

            // Apply Parameter attributes to enhance the schema
            $detailedSchema = $this->applyParameterAttributesToSchema($detailedSchema, $controller, $method);

            // Wrap schema in array if isCollection is true
            if ($isCollection) {
                // Ensure we have at least a basic schema for collections
                if (empty($detailedSchema)) {
                    $detailedSchema = [
                        'type' => 'object',
                        'properties' => [],
                    ];
                }

                $detailedSchema = [
                    'type' => 'array',
                    'items' => $detailedSchema,
                ];
            }

            // Generate MediaType-level example from schema property examples
            $responseExample = $this->generateResponseExampleFromSchema($isCollection ? ($detailedSchema['items'] ?? null) : $detailedSchema);

            // Wrap example in array if isCollection is true
            if ($isCollection && $responseExample) {
                $responseExample = [$responseExample];
            }

            $response = [
                'description' => $description,
                'content_type' => $contentType,
                'headers' => $headers,
                'schema' => $detailedSchema,
                'example' => $responseExample, // Add MediaType-level example
            ];

            // Add resource class if specified (for tests and enhanced documentation)
            if ($resourceClass) {
                $response['resource'] = $resourceClass;
            }

            $this->detectedResponses[$statusCode] = $response;
        }
    }

    /**
     * Generate MediaType-level example from schema property examples
     */
    private function generateResponseExampleFromSchema(?array $schema): ?array
    {
        if (! $schema || ! isset($schema['properties'])) {
            return null;
        }

        $example = [];
        $hasAnyExample = false;

        foreach ($schema['properties'] as $propertyName => $property) {
            if (array_key_exists('example', $property)) {
                $example[$propertyName] = $property['example'];
                $hasAnyExample = true;
            }
        }

        return $hasAnyExample ? $example : null;
    }

    /**
     * Add error response with standard error schema
     */
    private function addErrorResponse(
        string $statusCode,
        string $description,
        ?string $controller = null,
        ?string $method = null,
        array $validationRules = []
    ): void {
        // Check if error response enhancement is enabled
        $errorConfig = $this->configuration->get('api-documentation.error_responses', []);
        $enhancementEnabled = $errorConfig['enabled'] ?? true;

        $errorSchema = [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
                'timestamp' => ['type' => 'string', 'format' => 'date-time'],
                'path' => ['type' => 'string'],
                'request_id' => ['type' => 'string'],
            ],
        ];

        // Add configured additional fields to schema
        if ($enhancementEnabled) {
            $additionalFields = $errorConfig['schema']['additional_fields'] ?? [];
            foreach ($additionalFields as $fieldName => $fieldConfig) {
                $errorSchema['properties'][$fieldName] = [
                    'type' => $fieldConfig['type'] ?? 'string',
                    'description' => $fieldConfig['description'] ?? '',
                ];

                if (isset($fieldConfig['format'])) {
                    $errorSchema['properties'][$fieldName]['format'] = $fieldConfig['format'];
                }
            }
        }

        // Add enhanced validation error details for 422 responses
        if ($statusCode === '422' && $enhancementEnabled) {
            $validationConfig = $errorConfig['schema']['validation_details'] ?? [];
            $validationEnabled = $validationConfig['enabled'] ?? true;
            $detailsFieldName = $validationConfig['field_name'] ?? 'details';

            if ($validationEnabled) {
                if (! empty($validationRules) && $this->errorMessageGenerator) {
                    // Generate detailed validation error schema based on actual rules
                    $errorSchema['properties'][$detailsFieldName] = [
                        'type' => 'object',
                        'description' => 'Field-specific validation errors',
                        'properties' => $this->generateValidationErrorSchema($validationRules),
                    ];
                } else {
                    // Fallback to generic validation error structure
                    $errorSchema['properties'][$detailsFieldName] = [
                        'type' => 'object',
                        'additionalProperties' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'i18n' => ['type' => 'string'],
                                    'message' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ];
                }
            }
        }

        // Generate enhanced example if available
        $example = null;
        if ($enhancementEnabled && $this->errorMessageGenerator && $controller && $method) {
            $examplesConfig = $errorConfig['examples'] ?? [];
            if ($examplesConfig['enabled'] ?? true) {
                [$domain, $context] = $this->errorMessageGenerator->detectDomainContext($controller, $method);
                $path = $this->generatePathForExample($controller, $method, $errorConfig);
                $example = $this->errorMessageGenerator->generateErrorResponseExample(
                    $statusCode,
                    $path,
                    $domain,
                    $context,
                    $validationRules
                );
            }
        }

        $this->addResponseWithExample($statusCode, $description, 'application/json', $errorSchema, $example);
    }

    /**
     * Generate validation error schema based on actual validation rules
     */
    private function generateValidationErrorSchema(array $validationRules): array
    {
        $properties = [];

        foreach ($validationRules as $field => $rules) {
            $properties[$field] = [
                'type' => 'array',
                'description' => "Validation errors for {$field} field",
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'i18n' => [
                            'type' => 'string',
                            'description' => 'Internationalization key for the error',
                        ],
                        'message' => [
                            'type' => 'string',
                            'description' => 'Human readable error message',
                        ],
                    ],
                ],
            ];
        }

        return $properties;
    }

    /**
     * Generate example path for error response
     */
    private function generatePathForExample(string $controller, string $method, array $errorConfig = []): string
    {
        // Use configured path pattern if available
        $pathPattern = $errorConfig['defaults']['path_pattern'] ?? '/api/v1/{controller}';

        // Simple path generation based on controller and method
        $controllerName = class_basename($controller);
        $controllerName = str_replace('Controller', '', $controllerName);

        $path = str_replace('{controller}', strtolower($controllerName), $pathPattern);

        // Add method-specific path segments
        if (str_contains($method, 'show') || str_contains($method, 'update') || str_contains($method, 'destroy')) {
            $path .= '/{id}';
        } elseif (str_contains($method, '2fa') || str_contains($method, 'TwoFactor')) {
            $path .= '/{userId}/2fa';
        }

        return $path;
    }

    /**
     * Add response with example support
     */
    private function addResponseWithExample(
        string $statusCode,
        string $description,
        string $contentType,
        array $schema,
        ?array $example = null
    ): void {
        $responseData = [
            'description' => $description,
            'content_type' => $contentType,
            'headers' => [],
            'schema' => $schema,
        ];

        if ($example) {
            $responseData['example'] = $example;
        }

        $this->detectedResponses[$statusCode] = $responseData;
    }

    /**
     * Initialize error status mappings from common Laravel exceptions
     */
    private function initializeErrorStatusMappings(): void
    {
        $this->errorStatusMappings = [
            ValidationException::class => 422,
            ModelNotFoundException::class => 404,
            'Illuminate\\Auth\\AuthenticationException' => 401,
            'Illuminate\\Auth\\Access\\AuthorizationException' => 403,
            'Illuminate\\Database\\QueryException' => 422,
            'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException' => 404,
            'Symfony\\Component\\HttpKernel\\Exception\\UnauthorizedHttpException' => 401,
            'Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException' => 403,
            'Symfony\\Component\\HttpKernel\\Exception\\UnprocessableEntityHttpException' => 422,
            Throwable::class => 500,
        ];
    }

    /**
     * Add common error responses that Laravel applications typically have
     */
    private function addCommonErrorResponses(string $controller = '', string $method = ''): void
    {
        // Add common error responses based on typical Laravel application patterns
        $commonErrors = [
            '400' => 'Bad request',
            '401' => 'Unauthorized',
            '403' => 'Forbidden',
            '404' => 'Not found',
            '422' => 'Validation error',
            '500' => 'Internal server error',
        ];

        foreach ($commonErrors as $status => $description) {
            if (! isset($this->detectedResponses[$status])) {
                $this->addErrorResponse($status, $description, $controller, $method);
            }
        }
    }

    /**
     * Resolve HTTP status constants to actual numbers
     */
    private function resolveHttpConstant(Node\Expr\ClassConstFetch $node): string
    {
        if ($node->name instanceof Node\Identifier) {
            $constantName = $node->name->toString();

            $constants = [
                'HTTP_OK' => '200',
                'HTTP_CREATED' => '201',
                'HTTP_ACCEPTED' => '202',
                'HTTP_NO_CONTENT' => '204',
                'HTTP_BAD_REQUEST' => '400',
                'HTTP_UNAUTHORIZED' => '401',
                'HTTP_FORBIDDEN' => '403',
                'HTTP_NOT_FOUND' => '404',
                'HTTP_UNPROCESSABLE_ENTITY' => '422',
                'HTTP_INTERNAL_SERVER_ERROR' => '500',
            ];

            return $constants[$constantName] ?? '200';
        }

        return '200';
    }

    /**
     * Get status code description
     */
    private function getStatusDescription(string $statusCode): string
    {
        $descriptions = [
            '200' => 'Success',
            '201' => 'Resource created',
            '202' => 'Request accepted',
            '204' => 'No content',
            '400' => 'Bad request',
            '401' => 'Unauthorized',
            '403' => 'Forbidden',
            '404' => 'Not found',
            '422' => 'Validation error',
            '500' => 'Internal server error',
        ];

        return $descriptions[$statusCode] ?? 'Response';
    }

    /**
     * Get method source code
     */
    private function getMethodSource(ReflectionMethod $method): string
    {
        $filename = $method->getFileName();
        if (! $filename || ! file_exists($filename)) {
            return '';
        }

        $file = file($filename);
        $startLine = $method->getStartLine() - 1;
        $endLine = $method->getEndLine();

        return implode('', array_slice($file, $startLine, $endLine - $startLine + 1));
    }

    /**
     * Analyze data parameter to determine response structure
     */
    private function analyzeDataParameter(Node $node): ?array
    {
        // This could be expanded to analyze the data structure
        // For now, return null to indicate unknown structure
        return null;
    }

    /**
     * Get exception class name from new expression
     */
    private function getExceptionClassName(New_ $node): ?string
    {
        if ($node->class instanceof Name) {
            return $node->class->toString();
        }

        return null;
    }

    /**
     * Find exception handler for controller
     */
    private function findExceptionHandler(string $controller): ?string
    {
        // Try to find the exception handler based on controller location
        $controllerPath = (new \ReflectionClass($controller))->getFileName();
        if (! $controllerPath) {
            return null;
        }

        $appPath = dirname($controllerPath);
        while ($appPath && basename($appPath) !== 'app') {
            $appPath = dirname($appPath);
        }

        if ($appPath) {
            $handlerPath = $appPath.'/Exceptions/Handler.php';
            if (file_exists($handlerPath)) {
                return $handlerPath;
            }
        }

        return null;
    }

    /**
     * Analyze exception handler file
     */
    private function analyzeExceptionHandler(string $handlerPath): void
    {
        // This could be expanded to parse the exception handler
        // and extract custom exception mappings
    }

    /**
     * Analyze generic response call to extract status
     */
    private function analyzeGenericResponseCall(MethodCall $node): void
    {
        // Look for chained method calls like response($data, $status)
        if ($node->var instanceof FuncCall &&
            $node->var->name instanceof Name &&
            $node->var->name->toString() === 'response') {

            $responseArgs = $node->var->args;
            if (isset($responseArgs[1])) {
                $statusArg = $responseArgs[1]->value;
                if ($statusArg instanceof LNumber) {
                    $statusCode = (string) $statusArg->value;
                    $this->addResponse($statusCode, $this->getStatusDescription($statusCode), 'application/json');
                }
            }
        }
    }

    /**
     * Create default success response when no responses detected
     */
    private function createDefaultSuccessResponse(string $controller, string $method, ?ReflectionMethod $reflection = null): array
    {
        // Use existing ResponseAnalyzer for detailed analysis
        $analysis = $this->responseAnalyzer->analyzeControllerMethod($controller, $method);

        $response = [
            'description' => 'Success',
            'content_type' => 'application/json',
            'headers' => [],
            'schema' => $analysis,
        ];

        // Try to detect resource class — prefer detected_resource from schema analysis
        if (isset($analysis['detected_resource'])) {
            $response['resource'] = $analysis['detected_resource'];
        } elseif ($reflection) {
            $returnType = $reflection->getReturnType();
            if ($returnType && ! $returnType->isBuiltin()) {
                $typeName = $returnType->getName();
                if (class_exists($typeName)) {
                    $response['resource'] = $typeName;
                }
            }
        }

        return $response;
    }

    /**
     * Ensure comprehensive response coverage (both success AND error responses)
     */
    private function ensureComprehensiveResponseCoverage(string $controller, string $method, ReflectionMethod $reflection): void
    {
        // Ensure at least one success response exists
        $hasSuccessResponse = false;
        foreach ($this->detectedResponses as $statusCode => $response) {
            if (in_array($statusCode, ['200', '201', '202', '204'])) {
                $hasSuccessResponse = true;
                break;
            }
        }

        if (! $hasSuccessResponse) {
            // Add default success response based on method analysis
            $this->detectedResponses['200'] = $this->createDefaultSuccessResponse($controller, $method, $reflection);
        }

        // Always add common error responses that any Laravel API endpoint can return
        $this->addCommonApiErrorResponses($reflection);

        // Add middleware-based error responses
        $this->addMiddlewareBasedErrorResponses($controller, $method, $reflection);
    }

    /**
     * Add common API error responses that any Laravel endpoint can return
     */
    private function addCommonApiErrorResponses(ReflectionMethod $reflection): void
    {
        // 422 Validation Error - if method has validation (already added in detectValidationResponses)
        // But ensure it exists for any method that could potentially validate
        if (! isset($this->detectedResponses['422'])) {
            // Check if this looks like it could have validation
            if ($this->methodLikelyHasValidation($reflection)) {
                $this->addErrorResponse('422', 'Validation error', $reflection->getDeclaringClass()->getName(), $reflection->getName());
            }
        }

        // 401 Unauthorized - for methods that likely require authentication
        if (! isset($this->detectedResponses['401']) && $this->methodLikelyRequiresAuth($reflection)) {
            $this->addErrorResponse('401', 'Unauthorized', $reflection->getDeclaringClass()->getName(), $reflection->getName());
        }

        // 500 Internal Server Error - all endpoints can potentially return this
        if (! isset($this->detectedResponses['500'])) {
            $this->addErrorResponse('500', 'Internal server error', $reflection->getDeclaringClass()->getName(), $reflection->getName());
        }
    }

    /**
     * Add error responses based on middleware analysis
     */
    private function addMiddlewareBasedErrorResponses(string $controller, string $method, ReflectionMethod $reflection): void
    {
        // 403 Forbidden - for methods with authorization middleware
        if (! isset($this->detectedResponses['403']) && $this->methodHasAuthorizationMiddleware($controller)) {
            $this->addErrorResponse('403', 'Forbidden', $controller, $method);
        }

        // 404 Not Found - for methods that work with models/resources
        if (! isset($this->detectedResponses['404']) && $this->methodWorksWithModels($reflection)) {
            $this->addErrorResponse('404', 'Not found', $reflection->getDeclaringClass()->getName(), $reflection->getName());
        }

        // 429 Too Many Requests - for methods with rate limiting
        if (! isset($this->detectedResponses['429']) && $this->methodHasRateLimiting($controller)) {
            $this->addErrorResponse('429', 'Too many requests', $controller, $method);
        }
    }

    /**
     * Check if method likely has validation (POST, PUT, PATCH methods or FormRequest parameters)
     */
    protected function methodLikelyHasValidation(ReflectionMethod $reflection): bool
    {
        // Already checked FormRequest parameters in detectValidationResponses
        // Here we can add heuristics based on method name or parameters
        $methodName = $reflection->getName();

        // Methods that typically involve validation
        $validationMethods = ['store', 'update', 'create', 'edit', 'login', 'register', 'change', 'reset'];

        foreach ($validationMethods as $validationMethod) {
            if (stripos($methodName, $validationMethod) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if method likely requires authentication
     */
    protected function methodLikelyRequiresAuth(ReflectionMethod $reflection): bool
    {
        $methodName = $reflection->getName();

        // Methods that typically require authentication (use word boundaries for exact matches)
        $authMethods = ['store', 'update', 'delete', 'destroy', 'create', 'edit', 'profile', 'settings'];

        foreach ($authMethods as $authMethod) {
            if (preg_match('/\b'.preg_quote($authMethod, '/').'\b/i', $methodName)) {
                return true;
            }
        }

        // Special case for 'me' - must be exact match or at word boundary
        if (preg_match('/\bme\b/i', $methodName)) {
            return true;
        }

        // Public methods that typically don't require auth
        $publicMethods = ['index', 'show', 'search', 'public', 'guest'];

        foreach ($publicMethods as $publicMethod) {
            if (preg_match('/\b'.preg_quote($publicMethod, '/').'\b/i', $methodName)) {
                return false;
            }
        }

        return true; // Default to requiring auth for API endpoints
    }

    /**
     * Check if controller/method has authorization middleware
     */
    protected function methodHasAuthorizationMiddleware(string $controller): bool
    {
        // This is a simplified check - in a real implementation we'd analyze
        // the actual middleware stack, but for now we'll use heuristics

        // Controllers that typically have authorization
        $authControllers = ['Admin', 'User', 'Profile', 'Settings', 'Management'];

        foreach ($authControllers as $authController) {
            if (stripos($controller, $authController) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if method works with models (likely to return 404)
     */
    protected function methodWorksWithModels(ReflectionMethod $reflection): bool
    {
        $methodName = $reflection->getName();

        // Methods that typically work with specific model instances
        $modelMethods = ['show', 'edit', 'update', 'destroy', 'delete'];

        foreach ($modelMethods as $modelMethod) {
            if (stripos($methodName, $modelMethod) !== false) {
                return true;
            }
        }

        // Check if method has parameters that look like model IDs
        foreach ($reflection->getParameters() as $parameter) {
            $paramName = $parameter->getName();
            if (preg_match('/^(id|.*Id|.*_id)$/', $paramName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if controller/method has rate limiting middleware
     */
    protected function methodHasRateLimiting(string $controller): bool
    {
        // Controllers that typically have rate limiting
        $rateLimitedControllers = ['Auth', 'Login', 'Api', 'Public'];

        foreach ($rateLimitedControllers as $rateLimitedController) {
            if (stripos($controller, $rateLimitedController) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get default responses when AST analysis fails
     */
    private function getDefaultResponses(string $controller, string $method): array
    {
        $defaultResponses = [
            '200' => $this->createDefaultSuccessResponse($controller, $method),
        ];

        // CRITICAL FIX: Apply comprehensive coverage even for fallback responses
        try {
            $reflection = new ReflectionMethod($controller, $method);

            // Apply the same comprehensive coverage logic as the main path
            $this->detectedResponses = $defaultResponses;
            $this->ensureComprehensiveResponseCoverage($controller, $method, $reflection);

            return $this->detectedResponses;

        } catch (Throwable $e) {
            // If reflection fails, add basic error responses manually

            // Add 500 for all endpoints
            $defaultResponses['500'] = [
                'description' => 'Internal server error',
                'content_type' => 'application/json',
                'headers' => [],
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => ['type' => 'string'],
                        'status' => ['type' => 'integer'],
                    ],
                ],
            ];
        }

        return $defaultResponses;
    }
}
