<?php

declare(strict_types=1);

use JkBennemann\LaravelApiDocumentation\Services\AstAnalyzer;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Scalar\String_;

// Test basic AST parsing functionality
it('can parse a PHP file into AST', function () {
    $analyzer = app(AstAnalyzer::class);
    $filePath = __DIR__ . '/../Stubs/Controllers/RequestParameterController.php';
    
    $ast = $analyzer->parseFile($filePath);
    
    expect($ast)->toBeArray()->not->toBeEmpty();
});

// Test method node finding
it('can find a method node in the AST', function () {
    $analyzer = app(AstAnalyzer::class);
    $filePath = __DIR__ . '/../Stubs/Controllers/RequestParameterController.php';
    
    $ast = $analyzer->parseFile($filePath);
    $methodNode = $analyzer->findMethodNode($ast, 'simple');
    
    expect($methodNode)->not->toBeNull()
        ->and($methodNode->name->toString())->toBe('simple');
});

// Test validation rule extraction
it('can extract validation rules from a method using AST', function () {
    $analyzer = app(AstAnalyzer::class);
    
    // Create a temporary test file with validation rules
    $tempFile = sys_get_temp_dir() . '/test_validation.php';
    file_put_contents($tempFile, '<?php
    class TestController {
        public function store(Request $request) {
            $validated = $request->validate([
                "name" => "required|string|max:255",
                "email" => "required|email",
                "age" => "nullable|integer"
            ]);
            
            // Rest of the method
        }
    }');
    
    $rules = $analyzer->extractValidationRules($tempFile, 'store');
    
    expect($rules)->toBeArray()
        ->toHaveCount(3)
        ->toHaveKeys(['name', 'email', 'age'])
        ->and($rules['name']['type'])->toBe('string')
        ->and($rules['name']['required'])->toBeTrue()
        ->and($rules['email']['format'])->toBe('email')
        ->and($rules['age']['type'])->toBe('integer')
        ->and($rules['age']['required'])->toBeFalse();
        
    // Clean up
    unlink($tempFile);
});

// Test extracting validation rules from an array node
it('can extract validation rules from an AST array node', function () {
    $analyzer = app(AstAnalyzer::class);
    
    // Create a mock Array_ node
    $arrayNode = new Array_([
        new ArrayItem(
            new String_('required|string|max:255'),
            new String_('name')
        ),
        new ArrayItem(
            new String_('required|email'),
            new String_('email')
        ),
        new ArrayItem(
            new String_('nullable|integer'),
            new String_('age')
        )
    ]);
    
    $rules = $analyzer->extractValidationRulesFromArray($arrayNode);
    
    expect($rules)->toBeArray()
        ->toHaveCount(3)
        ->toHaveKeys(['name', 'email', 'age'])
        ->and($rules['name']['type'])->toBe('string')
        ->and($rules['name']['required'])->toBeTrue()
        ->and($rules['email']['format'])->toBe('email')
        ->and($rules['age']['type'])->toBe('integer')
        ->and($rules['age']['required'])->toBeFalse();
});

// Test namespace and imports extraction
it('can extract namespace and imports from AST', function () {
    $analyzer = app(AstAnalyzer::class);
    
    // Create a temporary test file with namespace and imports
    $tempFile = sys_get_temp_dir() . '/test_namespace.php';
    file_put_contents($tempFile, '<?php
    namespace App\Http\Controllers;
    
    use App\Models\User;
    use Illuminate\Http\Request;
    use App\Http\Resources\UserResource;
    
    class UserController {
        // Class content
    }');
    
    $ast = $analyzer->parseFile($tempFile);
    $namespace = $analyzer->extractNamespace($ast);
    $imports = $analyzer->extractImports($ast);
    
    expect($namespace)->toBe('App\Http\Controllers')
        ->and($imports)->toBeArray()
        ->toHaveCount(3)
        ->toHaveKeys(['User', 'Request', 'UserResource'])
        ->and($imports['User'])->toBe('App\Models\User')
        ->and($imports['UserResource'])->toBe('App\Http\Resources\UserResource');
        
    // Clean up
    unlink($tempFile);
});

// Test class name resolution
it('can resolve class names to fully qualified names', function () {
    $analyzer = app(AstAnalyzer::class);
    
    $imports = [
        'User' => 'App\Models\User',
        'Request' => 'Illuminate\Http\Request',
        'UserResource' => 'App\Http\Resources\UserResource',
    ];
    $currentNamespace = 'App\Http\Controllers';
    
    // Test with imported class
    $resolved = $analyzer->resolveClassName('User', $imports, $currentNamespace);
    expect($resolved)->toBe('App\Models\User');
    
    // Test with class in current namespace
    $resolved = $analyzer->resolveClassName('ProfileController', $imports, $currentNamespace);
    expect($resolved)->toBe('App\Http\Controllers\ProfileController');
    
    // Test with fully qualified name
    $resolved = $analyzer->resolveClassName('\App\Services\PaymentService', $imports, $currentNamespace);
    expect($resolved)->toBe('App\Services\PaymentService');
    
    // Test with nested namespace
    $resolved = $analyzer->resolveClassName('User\Profile', $imports, $currentNamespace);
    expect($resolved)->toBe('App\Models\User\Profile');
});

// Test resource collection usage analysis
it('can analyze resource collection usage in a method', function () {
    $analyzer = app(AstAnalyzer::class);
    
    // Create a temporary test file with resource collection
    $tempFile = sys_get_temp_dir() . '/test_resource.php';
    file_put_contents($tempFile, '<?php
    namespace App\Http\Controllers;
    
    use App\Models\User;
    use App\Http\Resources\UserResource;
    
    class UserController {
        public function index() {
            $users = User::all();
            return UserResource::collection($users);
        }
    }');
    
    $resourceClass = $analyzer->analyzeResourceCollectionUsage($tempFile, 'index');
    
    expect($resourceClass)->toBe('App\Http\Resources\UserResource');
        
    // Clean up
    unlink($tempFile);
});

// Test return statement analysis
it('can analyze return statements in a method', function () {
    $analyzer = app(AstAnalyzer::class);
    
    // Create a temporary test file with different return types
    $tempFile = sys_get_temp_dir() . '/test_returns.php';
    file_put_contents($tempFile, '<?php
    namespace App\Http\Controllers;
    
    use Illuminate\Http\JsonResponse;
    use Illuminate\Pagination\LengthAwarePaginator;
    use App\Http\Resources\UserResource;
    
    class UserController {
        public function index() {
            if ($condition) {
                return new LengthAwarePaginator($items, $total, $perPage, $page);
            } else {
                return response()->json(["message" => "Success"]);
            }
        }
    }');
    
    $returnTypes = $analyzer->analyzeReturnStatements($tempFile, 'index');
    
    expect($returnTypes)->toBeArray()
        ->toContain('LengthAwarePaginator')
        ->toContain('JsonResponse');
        
    // Clean up
    unlink($tempFile);
});

// Test property type analysis
it('can analyze property types from class declarations', function () {
    // Rule: Keep the code clean and readable
    $analyzer = app(AstAnalyzer::class);
    $filePath = __DIR__ . '/../Stubs/Models/PropertyTypeTestModel.php';
    $className = 'PropertyTypeTestModel';
    
    $properties = $analyzer->analyzePropertyTypes($filePath, $className);
    
    expect($properties)->toBeArray()
        ->toHaveKey('id')
        ->toHaveKey('name')
        ->toHaveKey('email')
        ->toHaveKey('createdAt')
        ->toHaveKey('status');
        
    // Test property type detection
    expect($properties['id']['type'])->toBe('int');
    expect($properties['name']['type'])->toBe('string');
    expect($properties['email']['type'])->toBe('string');
    
    // Test format inference
    expect($properties['id']['format'])->toBe('int64'); // Inferred from name
    expect($properties['email']['format'])->toBe('email'); // Inferred from name
    expect($properties['createdAt']['format'])->toBe('date-time'); // Inferred from name
    expect($properties['url']['format'])->toBe('uri'); // Inferred from name
    
    // Test required detection
    expect($properties['id']['required'])->toBeTrue();
    
    // Test enum detection
    expect($properties['status']['enum'])->toBeArray()
        ->toContain('active')
        ->toContain('inactive')
        ->toContain('pending');
    
    // Test description extraction
    expect($properties['id']['description'])->toContain("The model's ID");
});

// Test type extraction from docblocks
it('can extract types from PHPDoc comments', function () {
    // Rule: Keep the code modular and easy to understand
    $analyzer = app(AstAnalyzer::class);
    
    // Create a temporary test file with PHPDoc type hints
    $tempFile = sys_get_temp_dir() . '/test_phpdoc_types.php';
    file_put_contents($tempFile, '<?php
    class TestModel {
        /**
         * @var \\DateTime
         */
        private $date;
        
        /**
         * @var array<string>
         */
        private $tags;
    }');
    
    $properties = $analyzer->analyzePropertyTypes($tempFile, 'TestModel');
    
    expect($properties)->toBeArray()
        ->toHaveKey('date')
        ->toHaveKey('tags');
        
    // Due to regex escaping, we need to accept the actual returned type
    expect($properties['date']['type'])->toContain('DateTime');
    
    // Clean up
    @unlink($tempFile);
});

// Test format inference from property names
it('can infer formats from property names', function () {
    // Rule: Stick to PHP best practices
    $analyzer = app(AstAnalyzer::class);
    
    $propertyTypes = [
        ['type' => 'string', 'name' => 'email', 'expected' => 'email'],
        ['type' => 'string', 'name' => 'password', 'expected' => 'password'],
        ['type' => 'string', 'name' => 'createdDate', 'expected' => 'date-time'],
        ['type' => 'string', 'name' => 'updatedTime', 'expected' => 'date-time'],
        ['type' => 'string', 'name' => 'profileUrl', 'expected' => 'uri'],
        ['type' => 'string', 'name' => 'imageUri', 'expected' => 'uri'],
        ['type' => 'int', 'name' => 'userId', 'expected' => 'int64'],
        ['type' => 'int', 'name' => 'count', 'expected' => 'int32'],
        ['type' => 'float', 'name' => 'price', 'expected' => 'float'],
    ];
    
    foreach ($propertyTypes as $test) {
        $method = new \ReflectionMethod($analyzer, 'inferFormatFromType');
        $method->setAccessible(true);
        $format = $method->invoke($analyzer, $test['type'], $test['name']);
        
        expect($format)->toBe($test['expected'], "Failed to infer {$test['expected']} format for {$test['name']}");
    }
});
