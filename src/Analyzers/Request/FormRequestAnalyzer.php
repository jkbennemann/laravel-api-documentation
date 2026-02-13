<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Request;

use Illuminate\Foundation\Http\FormRequest;
use JkBennemann\LaravelApiDocumentation\Cache\AstCache;
use JkBennemann\LaravelApiDocumentation\Contracts\RequestBodyExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\SchemaResult;
use JkBennemann\LaravelApiDocumentation\Schema\ValidationRuleMapper;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

class FormRequestAnalyzer implements RequestBodyExtractor
{
    private ValidationRuleMapper $ruleMapper;

    private AstCache $astCache;

    private const QUERY_METHODS = ['GET', 'HEAD'];

    public function __construct(array $config = [], ?AstCache $astCache = null)
    {
        $this->ruleMapper = new ValidationRuleMapper($config);
        $this->astCache = $astCache ?? new AstCache(sys_get_temp_dir(), 0);
    }

    public function extract(AnalysisContext $ctx): ?SchemaResult
    {
        // GET/HEAD requests should not have a request body
        if (in_array($ctx->route->httpMethod(), self::QUERY_METHODS, true)) {
            return null;
        }

        if ($ctx->reflectionMethod === null) {
            return null;
        }

        $formRequestClass = $this->detectFormRequest($ctx);
        if ($formRequestClass === null) {
            return null;
        }

        $rules = $this->extractRules($formRequestClass);
        if (empty($rules)) {
            return null;
        }

        $schema = $this->ruleMapper->mapAllRules($rules);
        $contentType = $this->ruleMapper->hasFileUpload($rules)
            ? 'multipart/form-data'
            : 'application/json';

        return new SchemaResult(
            schema: $schema,
            description: 'Request body',
            contentType: $contentType,
            source: 'form_request:'.class_basename($formRequestClass),
        );
    }

    public function detectFormRequest(AnalysisContext $ctx): ?string
    {
        if ($ctx->reflectionMethod === null) {
            return null;
        }

        foreach ($ctx->reflectionMethod->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                $className = $type->getName();
                try {
                    if (is_subclass_of($className, FormRequest::class)) {
                        return $className;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Extract validation rules from a FormRequest class.
     * Tries: 1) AST parsing of rules() method, 2) Reflection/instantiation fallback.
     *
     * @return array<string, string[]|string>
     */
    public function extractRules(string $formRequestClass): array
    {
        // Try AST parsing first (safer, no side effects)
        $rules = $this->extractRulesViaAst($formRequestClass);
        if ($rules !== null) {
            return $rules;
        }

        // Fallback: try to instantiate and call rules()
        return $this->extractRulesViaReflection($formRequestClass);
    }

    private function extractRulesViaAst(string $formRequestClass): ?array
    {
        try {
            $reflection = new \ReflectionClass($formRequestClass);
            $fileName = $reflection->getFileName();
            if (! $fileName || ! file_exists($fileName)) {
                return null;
            }

            $stmts = $this->astCache->parseFile($fileName);
            if ($stmts === null) {
                return null;
            }

            $nodeFinder = new NodeFinder;
            $methods = $nodeFinder->findInstanceOf($stmts, ClassMethod::class);

            foreach ($methods as $method) {
                if ($method->name->toString() === 'rules') {
                    return $this->extractRulesFromMethod($method);
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->debug("API Docs: AST parsing failed for FormRequest {$formRequestClass}: {$e->getMessage()}");
            }
        }

        return null;
    }

    private function extractRulesFromMethod(ClassMethod $method): ?array
    {
        $rules = [];

        // Find return statements
        $nodeFinder = new NodeFinder;
        $returns = $nodeFinder->findInstanceOf($method->stmts ?? [], Return_::class);

        foreach ($returns as $return) {
            if ($return->expr instanceof Array_) {
                $rules = array_merge($rules, $this->extractRulesFromArray($return->expr));
            }
        }

        return ! empty($rules) ? $rules : null;
    }

    /**
     * @return array<string, string[]|string>
     */
    private function extractRulesFromArray(Array_ $array): array
    {
        $rules = [];

        foreach ($array->items as $item) {
            if (! $item instanceof ArrayItem) {
                continue;
            }

            $key = $this->extractStringValue($item->key);
            if ($key === null) {
                continue;
            }

            $value = $this->extractRuleValue($item->value);
            if ($value !== null) {
                $rules[$key] = $value;
            }
        }

        return $rules;
    }

    private function extractRuleValue(Node $node): string|array|null
    {
        // String rule: 'required|string|max:255'
        if ($node instanceof String_) {
            return $node->value;
        }

        // Array of rules: ['required', 'string', 'max:255']
        if ($node instanceof Array_) {
            $values = [];
            foreach ($node->items as $item) {
                if ($item instanceof ArrayItem) {
                    $val = $this->extractStringValue($item->value);
                    if ($val !== null) {
                        $values[] = $val;
                    }
                }
            }

            return ! empty($values) ? $values : null;
        }

        return null;
    }

    private function extractStringValue(?Node $node): ?string
    {
        if ($node === null) {
            return null;
        }

        if ($node instanceof String_) {
            return $node->value;
        }

        // Handle class constant fetches like Rule::class
        if ($node instanceof Node\Expr\ClassConstFetch) {
            if ($node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
                return $node->class->toString().'::'.$node->name->toString();
            }
        }

        return null;
    }

    /**
     * @return array<string, string[]|string>
     */
    private function extractRulesViaReflection(string $formRequestClass): array
    {
        try {
            $reflection = new \ReflectionClass($formRequestClass);

            if (! $reflection->hasMethod('rules')) {
                return [];
            }

            $method = $reflection->getMethod('rules');

            // Only try instantiation if rules() has no required dependencies
            if ($method->getNumberOfRequiredParameters() === 0) {
                // Create a minimal instance without running constructor side effects
                $instance = $reflection->newInstanceWithoutConstructor();

                // Call rules() directly
                $rules = $method->invoke($instance);

                if (is_array($rules)) {
                    return $rules;
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->debug("API Docs: Reflection rule extraction failed for {$formRequestClass}: {$e->getMessage()}");
            }
        }

        return [];
    }
}
