<?php

declare(strict_types=1);

use JkBennemann\LaravelApiDocumentation\Output\JsonWriter;
use JkBennemann\LaravelApiDocumentation\Output\YamlWriter;

beforeEach(function () {
    $this->outputDir = sys_get_temp_dir().'/writer-test-'.uniqid();
    @mkdir($this->outputDir, 0755, true);
});

afterEach(function () {
    if (is_dir($this->outputDir)) {
        $files = glob($this->outputDir.'/*');
        foreach ($files ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->outputDir);
    }
});

// ---------------------------------------------------------------
// JsonWriter
// ---------------------------------------------------------------

it('writes valid JSON to file', function () {
    $writer = new JsonWriter;
    $spec = ['openapi' => '3.1.0', 'info' => ['title' => 'Test API', 'version' => '1.0.0']];

    $path = $this->outputDir.'/output.json';
    $writer->write($spec, $path);

    expect(file_exists($path))->toBeTrue();

    $decoded = json_decode(file_get_contents($path), true);
    expect($decoded['openapi'])->toBe('3.1.0')
        ->and($decoded['info']['title'])->toBe('Test API');
});

it('produces pretty-printed JSON with unescaped slashes', function () {
    $writer = new JsonWriter;
    $spec = ['paths' => ['/api/users' => ['get' => ['summary' => 'List users']]]];

    $json = $writer->toJson($spec);

    expect($json)->toContain('/api/users')
        ->and($json)->toContain("\n"); // Pretty printed
});

it('rejects invalid file extension for JSON writer', function () {
    $writer = new JsonWriter;

    $writer->write(['test' => true], $this->outputDir.'/output.html');
})->throws(\InvalidArgumentException::class);

it('creates directory if it does not exist for JSON writer', function () {
    $writer = new JsonWriter;
    $nestedDir = $this->outputDir.'/nested/deep';
    $path = $nestedDir.'/output.json';

    $writer->write(['test' => true], $path);

    expect(file_exists($path))->toBeTrue();

    // Cleanup nested dirs
    @unlink($path);
    @rmdir($nestedDir);
    @rmdir($this->outputDir.'/nested');
});

// ---------------------------------------------------------------
// YamlWriter
// ---------------------------------------------------------------

it('writes valid YAML to file', function () {
    $writer = new YamlWriter;
    $spec = ['openapi' => '3.1.0', 'info' => ['title' => 'Test API', 'version' => '1.0.0']];

    $path = $this->outputDir.'/output.yaml';
    $writer->write($spec, $path);

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);
    expect($content)->toContain('openapi:')
        ->and($content)->toContain('title:');
});

it('rejects invalid file extension for YAML writer', function () {
    $writer = new YamlWriter;

    $writer->write(['test' => true], $this->outputDir.'/output.txt');
})->throws(\InvalidArgumentException::class);

it('accepts yml extension', function () {
    $writer = new YamlWriter;
    $path = $this->outputDir.'/output.yml';

    $writer->write(['openapi' => '3.1.0'], $path);

    expect(file_exists($path))->toBeTrue();
});

it('serializes scalars correctly in YAML', function () {
    $writer = new YamlWriter;

    $yaml = $writer->toYaml([
        'string_val' => 'hello',
        'int_val' => 42,
        'float_val' => 3.14,
        'bool_true' => true,
        'bool_false' => false,
        'null_val' => null,
    ]);

    expect($yaml)->toContain('string_val: hello')
        ->and($yaml)->toContain('int_val: 42')
        ->and($yaml)->toContain('bool_true: true')
        ->and($yaml)->toContain('bool_false: false');

    // ext-yaml uses ~ for null, built-in uses null
    expect($yaml)->toMatch('/null_val: (null|~)/');
});

it('quotes strings that look like booleans or numbers in YAML', function () {
    $writer = new YamlWriter;

    $yaml = $writer->toYaml([
        'looks_like_bool' => 'true',
        'looks_like_number' => '3.14',
        'version' => '1.0.0',
    ]);

    // ext-yaml uses double quotes, built-in uses single quotes — both valid YAML
    expect($yaml)->toMatch('/looks_like_bool: [\'"]true[\'"]/')
        ->and($yaml)->toMatch('/looks_like_number: [\'"]3\.14[\'"]/')
        ->and($yaml)->toMatch('/version: [\'"]1\.0\.0[\'"]/');
});

it('quotes strings with special characters in YAML', function () {
    $writer = new YamlWriter;

    $yaml = $writer->toYaml([
        'with_colon' => 'key: value',
        'with_hash' => 'some # comment',
    ]);

    // ext-yaml and built-in may use different quoting, but value must be quoted
    expect($yaml)->toMatch('/with_colon: [\'"]key: value[\'"]/')
        ->and($yaml)->toMatch('/with_hash: [\'"]some # comment[\'"]/');
});

it('handles empty arrays and objects in YAML', function () {
    $writer = new YamlWriter;

    $yaml = $writer->toYaml([
        'empty_list' => [],
        'nested' => [
            'items' => ['a', 'b'],
        ],
    ]);

    expect($yaml)->toContain('empty_list: []');
});

it('handles sequential arrays in YAML', function () {
    $writer = new YamlWriter;

    $yaml = $writer->toYaml([
        'tags' => ['admin', 'users', 'auth'],
    ]);

    expect($yaml)->toContain('- admin')
        ->and($yaml)->toContain('- users')
        ->and($yaml)->toContain('- auth');
});
