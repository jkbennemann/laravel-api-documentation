<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Request;

use Illuminate\Foundation\Http\FormRequest;
use JkBennemann\LaravelApiDocumentation\Cache\AstCache;
use JkBennemann\LaravelApiDocumentation\Contracts\RequestBodyExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Data\SchemaResult;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Schema\ValidationRuleMapper;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

class FormRequestAnalyzer implements RequestBodyExtractor
{
    private ValidationRuleMapper $ruleMapper;

    private AstCache $astCache;

    private const QUERY_METHODS = ['GET', 'HEAD', 'DELETE'];

    public function __construct(array $config = [], ?AstCache $astCache = null, private readonly ?SchemaRegistry $schemaRegistry = null)
    {
        $this->ruleMapper = new ValidationRuleMapper($config, $schemaRegistry);
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

        if ($this->schemaRegistry !== null) {
            $name = class_basename($formRequestClass);
            $registered = $this->schemaRegistry->registerIfComplex($name, $schema);
            if ($registered instanceof SchemaObject) {
                $schema = $registered;
            }
        }

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

                continue;
            }

            // Handle array_merge(...) calls: return array_merge([...], [...])
            if ($return->expr instanceof Node\Expr\FuncCall
                && $return->expr->name instanceof Node\Name
                && $return->expr->name->toString() === 'array_merge') {
                foreach ($return->expr->args as $arg) {
                    if ($arg->value instanceof Array_) {
                        $rules = array_merge($rules, $this->extractRulesFromArray($arg->value));
                    }
                    // Handle spread: array_merge($this->commonRules(), [...])
                    if ($arg->value instanceof Node\Expr\MethodCall
                        && $arg->value->name instanceof Node\Identifier) {
                        $nestedRules = $this->tryResolveMethodCallRules($arg->value, $method);
                        if ($nestedRules !== null) {
                            $rules = array_merge($rules, $nestedRules);
                        }
                    }
                }

                continue;
            }

            // Handle variable return: return $rules
            if ($return->expr instanceof Node\Expr\Variable && is_string($return->expr->name)) {
                $varRules = $this->traceVariableRules($return->expr->name, $method);
                if ($varRules !== null) {
                    $rules = array_merge($rules, $varRules);
                }

                continue;
            }

            // Handle ternary return: return $condition ? [...] : [...]
            if ($return->expr instanceof Node\Expr\Ternary) {
                if ($return->expr->if instanceof Array_) {
                    $rules = array_merge($rules, $this->extractRulesFromArray($return->expr->if));
                }
                if ($return->expr->else instanceof Array_) {
                    $rules = array_merge($rules, $this->extractRulesFromArray($return->expr->else));
                }

                continue;
            }
        }

        return ! empty($rules) ? $rules : null;
    }

    /**
     * Trace a variable back to its array assignment(s) within a method.
     *
     * @return array<string, string[]|string>|null
     */
    private function traceVariableRules(string $varName, ClassMethod $method): ?array
    {
        $rules = [];

        foreach ($method->stmts ?? [] as $stmt) {
            if (! $stmt instanceof Node\Stmt\Expression) {
                continue;
            }

            $expr = $stmt->expr;

            // $rules = [...]
            if ($expr instanceof Node\Expr\Assign
                && $expr->var instanceof Node\Expr\Variable
                && $expr->var->name === $varName) {
                if ($expr->expr instanceof Array_) {
                    $rules = array_merge($rules, $this->extractRulesFromArray($expr->expr));
                }
                // $rules = array_merge([...], [...])
                if ($expr->expr instanceof Node\Expr\FuncCall
                    && $expr->expr->name instanceof Node\Name
                    && $expr->expr->name->toString() === 'array_merge') {
                    foreach ($expr->expr->args as $arg) {
                        if ($arg->value instanceof Array_) {
                            $rules = array_merge($rules, $this->extractRulesFromArray($arg->value));
                        }
                    }
                }
            }

            // $rules['field'] = ... or $rules[...] = ...
            if ($expr instanceof Node\Expr\Assign
                && $expr->var instanceof Node\Expr\ArrayDimFetch
                && $expr->var->var instanceof Node\Expr\Variable
                && $expr->var->var->name === $varName
                && $expr->var->dim instanceof String_) {
                $key = $expr->var->dim->value;
                $value = $this->extractRuleValue($expr->expr);
                if ($value !== null) {
                    $rules[$key] = $value;
                }
            }
        }

        return ! empty($rules) ? $rules : null;
    }

    /** @phpstan-ignore return.unusedType */
    private function tryResolveMethodCallRules(Node\Expr\MethodCall $call, ClassMethod $context): ?array
    {
        // Only support $this->methodName() calls without complex args
        if (! ($call->var instanceof Node\Expr\Variable && $call->var->name === 'this')) {
            return null;
        }

        // Cannot resolve inter-method calls within AST reliably; skip for now
        return null;
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

        // Handle sprintf('format|%s', ...) → extract the format string parts
        if ($node instanceof Node\Expr\FuncCall
            && $node->name instanceof Node\Name
            && $node->name->toString() === 'sprintf'
            && ! empty($node->args)
            && $node->args[0]->value instanceof String_) {
            $format = $node->args[0]->value->value;

            // Remove %s placeholders and clean up pipe-delimited rules
            $cleaned = preg_replace('/%[sd]/', '', $format);
            $cleaned = preg_replace('/\|{2,}/', '|', $cleaned ?? $format);

            return trim($cleaned ?? $format, '|');
        }

        // Array of rules: ['required', 'string', 'max:255', Rule::in([...]), Rule::enum(SomeEnum::class)]
        if ($node instanceof Array_) {
            $values = [];
            foreach ($node->items as $item) {
                if ($item instanceof ArrayItem) {
                    // Try string extraction first
                    $val = $this->extractStringValue($item->value);
                    if ($val !== null) {
                        $values[] = $val;

                        continue;
                    }

                    // Try Rule::in(...) / Rule::enum(...) static calls
                    $ruleVal = $this->extractRuleObjectValue($item->value);
                    if ($ruleVal !== null) {
                        $values[] = $ruleVal;

                        continue;
                    }

                    // Try new InEnum(SomeEnum::class) / new Enum(SomeEnum::class)
                    if ($item->value instanceof Node\Expr\New_ && $item->value->class instanceof Node\Name) {
                        $className = $item->value->class->toString();
                        if (str_contains($className, 'Enum') && ! empty($item->value->args)) {
                            $enumVal = $this->resolveEnumClassFromArg($item->value->args[0]->value);
                            if ($enumVal !== null) {
                                $values[] = $enumVal;

                                continue;
                            }
                        }
                    }
                }
            }

            return ! empty($values) ? $values : null;
        }

        // Single Rule::in(...) or Rule::enum(...) call used directly as value
        $ruleVal = $this->extractRuleObjectValue($node);
        if ($ruleVal !== null) {
            return [$ruleVal];
        }

        return null;
    }

    private function extractRuleObjectValue(Node $node): ?string
    {
        if (! $node instanceof Node\Expr\StaticCall) {
            return null;
        }

        if (! $node->class instanceof Node\Name || ! $node->name instanceof Node\Identifier) {
            return null;
        }

        $className = $node->class->toString();
        $methodName = $node->name->toString();

        // Only handle Rule::in() and Rule::enum()
        if ($className !== 'Rule' && ! str_ends_with($className, '\\Rule')) {
            return null;
        }

        if ($methodName === 'in' && ! empty($node->args)) {
            // Rule::in(['val1', 'val2']) or Rule::in('val1', 'val2')
            $firstArg = $node->args[0]->value;

            if ($firstArg instanceof Array_) {
                $enumValues = $this->extractArrayStringValues($firstArg);
                if (! empty($enumValues)) {
                    return 'in:'.implode(',', $enumValues);
                }
            }

            // Rule::in(SomeEnum::cases()) — try to resolve enum
            if ($firstArg instanceof Node\Expr\StaticCall
                && $firstArg->class instanceof Node\Name
                && $firstArg->name instanceof Node\Identifier
                && $firstArg->name->toString() === 'cases') {
                $enumClass = $firstArg->class->toString();

                return 'enum_class:'.$enumClass;
            }
        }

        if ($methodName === 'enum' && ! empty($node->args)) {
            // Rule::enum(SomeEnum::class)
            $enumVal = $this->resolveEnumClassFromArg($node->args[0]->value);
            if ($enumVal !== null) {
                return $enumVal;
            }
        }

        return null;
    }

    private function resolveEnumClassFromArg(Node $node): ?string
    {
        if ($node instanceof Node\Expr\ClassConstFetch
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'class') {
            return 'enum_class:'.$node->class->toString();
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function extractArrayStringValues(Array_ $array): array
    {
        $values = [];
        foreach ($array->items as $item) {
            if ($item instanceof ArrayItem) {
                $val = $this->extractStringValue($item->value);
                if ($val !== null) {
                    $values[] = $val;
                } elseif ($item->value instanceof Node\Scalar\Int_ || $item->value instanceof Node\Scalar\LNumber) {
                    $values[] = (string) $item->value->value;
                }
            }
        }

        return $values;
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

                if (is_array($rules) && ! empty($rules)) {
                    return $rules;
                }
            }

            // Fallback: try to extract rules from composed child FormRequests
            $composedRules = $this->extractComposedRules($reflection);
            if (! empty($composedRules)) {
                return $composedRules;
            }
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->debug("API Docs: Reflection rule extraction failed for {$formRequestClass}: {$e->getMessage()}");
            }
        }

        return [];
    }

    /**
     * Handle composed FormRequest patterns (e.g., AggregateFormRequest).
     * Looks for methods that return arrays of child FormRequest classes,
     * then extracts and merges rules from each child.
     *
     * @return array<string, string[]|string>
     */
    private function extractComposedRules(\ReflectionClass $reflection): array
    {
        $rules = [];

        // Look for methods that return child request class names
        $compositionMethods = ['getRequests', 'getComposedRequests', 'getSubRequests'];
        foreach ($compositionMethods as $methodName) {
            if (! $reflection->hasMethod($methodName)) {
                continue;
            }

            try {
                $method = $reflection->getMethod($methodName);
                if ($method->getNumberOfRequiredParameters() > 0) {
                    continue;
                }

                $instance = $reflection->newInstanceWithoutConstructor();
                $childSpecs = $method->invoke($instance);

                if (! is_array($childSpecs)) {
                    continue;
                }

                foreach ($childSpecs as $spec) {
                    $childClass = is_array($spec)
                        ? ($spec[0] ?? null)
                        : (is_string($spec) ? $spec : null);

                    if ($childClass === null || ! is_string($childClass) || ! class_exists($childClass)) {
                        continue;
                    }

                    // Recursively extract rules from child FormRequest
                    $childRules = $this->extractRules($childClass);
                    $rules = array_merge($rules, $childRules);
                }
            } catch (\Throwable) {
                continue;
            }
        }

        // Also check if the class has a $requests property with FormRequest classes
        if ($reflection->hasProperty('requests')) {
            try {
                $prop = $reflection->getProperty('requests');
                $instance = $reflection->newInstanceWithoutConstructor();
                $requests = $prop->getValue($instance);

                if (is_array($requests)) {
                    foreach ($requests as $spec) {
                        $childClass = is_array($spec)
                            ? ($spec[0] ?? null)
                            : (is_string($spec) ? $spec : null);

                        if ($childClass === null || ! is_string($childClass) || ! class_exists($childClass)) {
                            continue;
                        }

                        $childRules = $this->extractRules($childClass);
                        $rules = array_merge($rules, $childRules);
                    }
                }
            } catch (\Throwable) {
                // Skip
            }
        }

        return $rules;
    }
}
