<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Throwable;

class AstAnalyzer
{
    private $parser;
    private $nodeFinder;
    
    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder();
    }
    
    /**
     * Analyzes property types from class declarations and infers formats
     * 
     * @param string $filePath Path to the class file
     * @param string $className The class name to analyze
     * @return array Detected property types with formats
     */
    public function analyzePropertyTypes(string $filePath, string $className): array
    {
        $ast = $this->parseFile($filePath);
        if (!$ast) {
            return [];
        }
        
        $properties = [];
        
        // Find the class node
        $classNode = $this->nodeFinder->findFirst($ast, function(Node $node) use ($className) {
            return $node instanceof Node\Stmt\Class_ && $node->name->toString() === $className;
        });
        
        if (!$classNode) {
            return [];
        }
        
        // Process properties
        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    $propertyName = $prop->name->toString();
                    $propertyType = $this->getPropertyTypeFromNode($stmt);
                    $format = $this->inferFormatFromType($propertyType, $propertyName);
                    $required = $this->isPropertyRequired($stmt);
                    $enum = $this->detectEnumValues($stmt);
                    $description = $this->extractPropertyDescription($stmt);
                    
                    $properties[$propertyName] = [
                        'name' => $propertyName,
                        'type' => $propertyType,
                        'format' => $format,
                        'required' => $required,
                        'enum' => $enum,
                        'description' => $description,
                    ];
                }
            }
        }
        
        return $properties;
    }
    
    /**
     * Gets the property type from a property node
     * 
     * @param Node\Stmt\Property $propertyNode
     * @return string The detected property type
     */
    private function getPropertyTypeFromNode(Node\Stmt\Property $propertyNode): string
    {
        // Check if property has a type declaration
        if ($propertyNode->type) {
            if ($propertyNode->type instanceof Node\Identifier) {
                return $propertyNode->type->toString();
            } elseif ($propertyNode->type instanceof Node\Name) {
                return $propertyNode->type->toString();
            }
        }
        
        // Check PHPDoc for type hint
        $docComment = $propertyNode->getDocComment();
        if ($docComment) {
            $typeFromDoc = $this->extractTypeFromDocComment($docComment->getText());
            if ($typeFromDoc) {
                return $typeFromDoc;
            }
        }
        
        return 'string'; // Default to string if type cannot be determined
    }
    
    /**
     * Extracts type from PHPDoc comment
     * 
     * @param string $docComment
     * @return string|null
     */
    private function extractTypeFromDocComment(string $docComment): ?string
    {
        // Rule: Stick to PHP best practices
        // Match standard @var pattern with type and optional description
        if (preg_match('/@var\s+([\\\\\w\[\]<>,|]+)/', $docComment, $matches)) {
            $type = $matches[1];
            
            // Handle union types (e.g., string|int)
            if (strpos($type, '|') !== false) {
                // We'll pick the first type for now or could return a union type
                $types = explode('|', $type);
                return trim($types[0]);
            }
            
            // Handle collection types (e.g., array<string>)
            if (preg_match('/array<([^>]+)>/', $type, $collectionMatches)) {
                return 'array'; // For now, just return array
            }
            
            // Fix for \DateTime and similar FQCNs
            if (strpos($type, '\\') === 0) {
                // This is a fully qualified class name with leading backslash
                return $type;
            }
            
            return $type;
        }
        
        return null;
    }
    
    /**
     * Infers OpenAPI format from property type and name
     * 
     * @param string $type The property type
     * @param string $propertyName The property name
     * @return string|null The inferred format or null if no format can be inferred
     */
    private function inferFormatFromType(string $type, string $propertyName): ?string
    {
        // Rule: Write concise, technical PHP code with accurate examples
        // Auto-detect formats based on type and naming conventions
        if ($type === 'integer' || $type === 'int') {
            if (str_ends_with($propertyName, 'Id') || $propertyName === 'id') {
                return 'int64';
            }
            return 'int32';
        }
        
        if ($type === 'float' || $type === 'double') {
            return 'float';
        }
        
        if ($type === 'string') {
            // Use naming conventions to infer formats
            $lcPropertyName = strtolower($propertyName);
            
            // Special case for camelCase 'createdAt' and similar properties
            if ($propertyName === 'createdAt' || $propertyName === 'updatedAt' || $propertyName === 'deletedAt') {
                return 'date-time';
            }
            
            // Handle date and time formats - check for 'date' or 'time' in property name
            if (str_contains($lcPropertyName, 'date') || 
                str_contains($lcPropertyName, 'time') || 
                str_contains($propertyName, 'Date') || 
                str_contains($propertyName, 'Time')) {
                return 'date-time';
            }
            
            if (str_contains($lcPropertyName, 'email')) {
                return 'email';
            }
            
            if (str_contains($lcPropertyName, 'password')) {
                return 'password';
            }
            
            if (str_contains($lcPropertyName, 'url') || 
                str_contains($lcPropertyName, 'uri') || 
                str_contains($propertyName, 'Url') || 
                str_contains($propertyName, 'Uri')) {
                return 'uri';
            }
        }
        
        return null;
    }
    
    /**
     * Determines if a property is required
     * 
     * @param Node\Stmt\Property $propertyNode
     * @return bool
     */
    private function isPropertyRequired(Node\Stmt\Property $propertyNode): bool
    {
        // Rule: Keep the code efficient and performant
        // Check for Required attribute
        foreach ($propertyNode->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if ($attr->name->toString() === 'Required') {
                    return true;
                }
            }
        }
        
        // Check PHPDoc for @required annotation
        $docComment = $propertyNode->getDocComment();
        if ($docComment && strpos($docComment->getText(), '@required') !== false) {
            return true;
        }
        
        // Check if type exists and is not nullable
        if ($propertyNode->type) {
            // For PHP 7.4+ property type declarations
            if (method_exists($propertyNode->type, 'getName')) {
                // Simple types like 'string', 'int', etc.
                return false === $propertyNode->isNullable();
            } elseif ($propertyNode->type instanceof Node\NullableType) {
                // Nullable types are not required
                return false;
            } else {
                // For other types that don't have nullable property
                return true;
            }
        }
        
        // Default to false if no type information is available
        return false;
    }
    
    /**
     * Detects enum values from property
     * 
     * @param Node\Stmt\Property $propertyNode
     * @return array|null
     */
    private function detectEnumValues(Node\Stmt\Property $propertyNode): ?array
    {
        // Check for Enum attribute
        foreach ($propertyNode->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if ($attr->name->toString() === 'Enum' && isset($attr->args[0])) {
                    if ($attr->args[0]->value instanceof Array_) {
                        return $this->extractArrayValues($attr->args[0]->value);
                    }
                }
            }
        }
        
        // Check PHPDoc for @enum annotation
        $docComment = $propertyNode->getDocComment();
        if ($docComment) {
            return $this->extractEnumFromDocComment($docComment->getText());
        }
        
        return null;
    }
    
    /**
     * Extracts enum values from PHPDoc comment
     * 
     * @param string $docComment
     * @return array|null
     */
    private function extractEnumFromDocComment(string $docComment): ?array
    {
        if (preg_match('/@enum\s+\{(.+?)\}/', $docComment, $matches)) {
            $enumString = $matches[1];
            $values = array_map('trim', explode(',', $enumString));
            return $values;
        }
        
        return null;
    }
    
    /**
     * Extracts array values from an AST Array_ node
     * 
     * @param Array_ $arrayNode
     * @return array
     */
    private function extractArrayValues(Array_ $arrayNode): array
    {
        $values = [];
        
        foreach ($arrayNode->items as $item) {
            if ($item instanceof ArrayItem) {
                if ($item->value instanceof String_) {
                    $values[] = $item->value->value;
                } elseif ($item->value instanceof Node\Scalar\LNumber) {
                    $values[] = $item->value->value;
                }
            }
        }
        
        return $values;
    }
    
    /**
     * Extracts property description from docblock
     * 
     * @param Node\Stmt\Property $propertyNode
     * @return string|null
     */
    private function extractPropertyDescription(Node\Stmt\Property $propertyNode): ?string
    {
        $docComment = $propertyNode->getDocComment();
        if (!$docComment) {
            return null;
        }
        
        $docText = $docComment->getText();
        
        // Remove /** and */ and * prefixes
        $docText = preg_replace('/(^\/\*\*|\*\/$)/m', '', $docText);
        $docText = preg_replace('/^\s*\*\s*/m', '', $docText);
        
        // Remove @tags
        $docText = preg_replace('/@\w+.*$/m', '', $docText);
        
        // Clean up and return
        $docText = trim($docText);
        return $docText ?: null;
    }
    
    /**
     * Parse a file into an AST
     *
     * @param string $filePath
     * @return array|null AST nodes or null if parsing failed
     */
    public function parseFile(string $filePath): ?array
    {
        try {
            if (!file_exists($filePath)) {
                return null;
            }
            
            return $this->parser->parse(file_get_contents($filePath));
        } catch (Error $error) {
            // If parsing fails, return null
            return null;
        } catch (Throwable $e) {
            // Handle any other exceptions
            return null;
        }
    }
    
    /**
     * Find a method node in the AST
     *
     * @param array $ast
     * @param string $methodName
     * @return ClassMethod|null
     */
    public function findMethodNode(array $ast, string $methodName): ?ClassMethod
    {
        return $this->nodeFinder->findFirst($ast, function(Node $node) use ($methodName) {
            return $node instanceof ClassMethod && $node->name->toString() === $methodName;
        });
    }
    
    /**
     * Extract validation rules from a method using AST
     *
     * @param string $filePath
     * @param string $methodName
     * @return array
     */
    public function extractValidationRules(string $filePath, string $methodName): array
    {
        $ast = $this->parseFile($filePath);
        if (!$ast) {
            return [];
        }
        
        $methodNode = $this->findMethodNode($ast, $methodName);
        if (!$methodNode) {
            return [];
        }
        
        // Find validate method calls
        $validateCalls = $this->nodeFinder->find($methodNode, function ($node) {
            return $node instanceof MethodCall 
                && $node->name->toString() === 'validate'
                && $node->var instanceof Variable
                && $node->var->name === 'request';
        });
        
        foreach ($validateCalls as $call) {
            if (isset($call->args[0]) && $call->args[0]->value instanceof Array_) {
                // Extract validation rules from the array
                return $this->extractValidationRulesFromArray($call->args[0]->value);
            }
        }
        
        return [];
    }
    
    /**
     * Extract validation rules from an AST array node
     *
     * @param Array_ $arrayNode
     * @return array
     */
    public function extractValidationRulesFromArray(Array_ $arrayNode): array
    {
        $rules = [];
        
        foreach ($arrayNode->items as $item) {
            if ($item instanceof ArrayItem 
                && $item->key instanceof String_
                && $item->value instanceof String_) {
                
                $fieldName = $item->key->value;
                $ruleString = $item->value->value;
                $ruleArray = explode('|', $ruleString);
                
                $rules[$fieldName] = [
                    'name' => $fieldName,
                    'description' => null,
                    'type' => $this->inferTypeFromValidationRules($ruleArray),
                    'format' => $this->determineParameterFormat($ruleArray),
                    'required' => in_array('required', $ruleArray),
                    'deprecated' => false,
                    'parameters' => [],
                ];
            }
        }
        
        return $rules;
    }
    
    /**
     * Infer parameter type from validation rules
     *
     * @param array $rules
     * @return string
     */
    private function inferTypeFromValidationRules(array $rules): string
    {
        if (in_array('integer', $rules) || in_array('numeric', $rules)) {
            return 'integer';
        }
        
        if (in_array('boolean', $rules)) {
            return 'boolean';
        }
        
        if (in_array('array', $rules)) {
            return 'array';
        }
        
        return 'string';
    }
    
    /**
     * Determine parameter format from validation rules
     *
     * @param array $rules
     * @return string|null
     */
    private function determineParameterFormat(array $rules): ?string
    {
        $formatMap = [
            'email' => 'email',
            'url' => 'url',
            'date' => 'date',
            'date_format' => 'date-time',
            'uuid' => 'uuid',
            'ip' => 'ipv4',
            'ipv4' => 'ipv4',
            'ipv6' => 'ipv6',
        ];
        
        foreach ($rules as $rule) {
            $ruleName = $rule;
            // Handle rules with parameters like date_format:Y-m-d
            if (strpos($rule, ':') !== false) {
                $ruleName = substr($rule, 0, strpos($rule, ':'));
            }
            
            if (isset($formatMap[$ruleName])) {
                return $formatMap[$ruleName];
            }
        }
        
        return null;
    }
    
    /**
     * Extract imports (use statements) from AST
     *
     * @param array $ast
     * @return array
     */
    public function extractImports(array $ast): array
    {
        $imports = [];
        
        $useStatements = $this->nodeFinder->find($ast, function ($node) {
            return $node instanceof Use_;
        });
        
        foreach ($useStatements as $use) {
            foreach ($use->uses as $useUse) {
                $alias = $useUse->alias ? $useUse->alias->name : $useUse->name->getLast();
                $imports[$alias] = $useUse->name->toString();
            }
        }
        
        return $imports;
    }
    
    /**
     * Extract namespace from AST
     *
     * @param array $ast
     * @return string
     */
    public function extractNamespace(array $ast): string
    {
        $namespace = $this->nodeFinder->findFirst($ast, function ($node) {
            return $node instanceof Namespace_;
        });
        
        if ($namespace && $namespace->name) {
            return $namespace->name->toString();
        }
        
        return '';
    }
    
    /**
     * Resolve a class name to its fully qualified name
     *
     * @param string $className
     * @param array $imports
     * @param string $currentNamespace
     * @return string
     */
    public function resolveClassName(string $className, array $imports, string $currentNamespace): string
    {
        // If it's already a fully qualified name
        if (strpos($className, '\\') === 0) {
            return substr($className, 1); // Remove leading \
        }
        
        // If it's a single segment and exists in imports
        if (strpos($className, '\\') === false && isset($imports[$className])) {
            return $imports[$className];
        }
        
        // If it's a relative name
        $segments = explode('\\', $className);
        $firstSegment = $segments[0];
        
        if (isset($imports[$firstSegment])) {
            array_shift($segments);
            return $imports[$firstSegment] . '\\' . implode('\\', $segments);
        }
        
        // Default to current namespace
        return $currentNamespace . '\\' . $className;
    }
    
    /**
     * Analyze a controller method to find resource collection usage
     *
     * @param string $filePath
     * @param string $methodName
     * @return string|null
     */
    public function analyzeResourceCollectionUsage(string $filePath, string $methodName): ?string
    {
        $ast = $this->parseFile($filePath);
        if (!$ast) {
            return null;
        }
        
        $methodNode = $this->findMethodNode($ast, $methodName);
        if (!$methodNode) {
            return null;
        }
        
        // Find static method calls to ::collection()
        $collectionCalls = $this->nodeFinder->find($methodNode, function ($node) {
            return $node instanceof StaticCall 
                && $node->name->toString() === 'collection';
        });
        
        foreach ($collectionCalls as $call) {
            if ($call->class instanceof Node\Name) {
                // Get class name as string safely
                $resourceClass = $call->class->toString();
                
                // Resolve the class name with namespaces
                $imports = $this->extractImports($ast);
                $currentNamespace = $this->extractNamespace($ast);
                
                return $this->resolveClassName($resourceClass, $imports, $currentNamespace);
            }
        }
        
        return null;
    }
    
    /**
     * Analyze a controller method to find return statements and their types
     *
     * @param string $filePath
     * @param string $methodName
     * @return array
     */
    public function analyzeReturnStatements(string $filePath, string $methodName): array
    {
        $ast = $this->parseFile($filePath);
        if (!$ast) {
            return [];
        }
        
        $methodNode = $this->findMethodNode($ast, $methodName);
        if (!$methodNode) {
            return [];
        }
        
        $returnStatements = $this->nodeFinder->find($methodNode, function ($node) {
            return $node instanceof Return_;
        });
        
        $returnTypes = [];
        
        foreach ($returnStatements as $return) {
            $type = $this->detectReturnType($return, $ast);
            if ($type) {
                $returnTypes[] = $type;
            }
        }
        
        return array_unique($returnTypes);
    }
    
    /**
     * Detect the type of a return statement
     *
     * @param Return_ $returnNode
     * @param array $ast
     * @return string|null
     */
    private function detectReturnType(Return_ $returnNode, array $ast): ?string
    {
        // Check for LengthAwarePaginator instantiation
        if ($returnNode->expr instanceof Node\Expr\New_) {
            $className = $returnNode->expr->class;
            if ($className instanceof Node\Name) {
                $name = $className->toString();
                if (str_ends_with($name, 'LengthAwarePaginator')) {
                    return 'LengthAwarePaginator';
                } elseif (str_ends_with($name, 'ResourceCollection')) {
                    return 'ResourceCollection';
                } elseif (str_ends_with($name, 'JsonResource')) {
                    return 'JsonResource';
                }
            }
        }
        
        // Check for method calls like response()->json()
        if ($returnNode->expr instanceof MethodCall) {
            if ($returnNode->expr->name->toString() === 'json') {
                return 'JsonResponse';
            }
        }
        
        return null;
    }
}
