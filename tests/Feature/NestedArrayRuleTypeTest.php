<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use JkBennemann\LaravelApiDocumentation\Schema\ValidationRuleMapper;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

/**
 * A field Laravel declares as `array` and then describes with named children is a JSON object.
 *
 * Laravel spells such a field twice — `'config' => ['required', 'array']` declares it and
 * `'config.url' => [...]` says what goes inside — and the first rule was winning. The result was a
 * schema carrying `type: array` AND `properties`, which is not valid OpenAPI: `properties` is
 * ignored on an array, so a generated client saw a list of strings where the API wants an object.
 *
 * A real client could not construct a notification channel from the published document for exactly
 * this reason, which is how it was found.
 */
class NestedArrayRuleTypeTest extends TestCase
{
    private function map(array $rules): array
    {
        return app(ValidationRuleMapper::class)->mapAllRules($rules)->jsonSerialize();
    }

    public function test_an_array_field_with_named_children_becomes_an_object(): void
    {
        $schema = $this->map([
            'config' => ['required', 'array'],
            'config.url' => ['required_if:type,webhook', 'url'],
            'config.secret' => ['sometimes', 'string'],
        ]);

        $config = $schema['properties']['config'];

        $this->assertSame('object', $config['type'], 'properties are meaningless on an array');
        $this->assertArrayNotHasKey('items', $config, 'an object has no items');
        $this->assertArrayHasKey('url', $config['properties']);
        $this->assertArrayHasKey('secret', $config['properties']);
    }

    public function test_a_genuine_list_stays_an_array(): void
    {
        $schema = $this->map([
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:50'],
        ]);

        $tags = $schema['properties']['tags'];

        $this->assertSame('array', $tags['type'], 'a wildcard rule describes items, not properties');
        $this->assertArrayHasKey('items', $tags);
    }

    public function test_a_list_of_objects_stays_an_array_of_objects(): void
    {
        $schema = $this->map([
            'checks' => ['required', 'array'],
            'checks.*.type' => ['required', 'string'],
            'checks.*.is_enabled' => ['sometimes', 'boolean'],
        ]);

        $checks = $schema['properties']['checks'];

        $this->assertSame('array', $checks['type']);
        $this->assertSame('object', $checks['items']['type']);
        $this->assertArrayHasKey('type', $checks['items']['properties']);
    }

    public function test_the_child_rules_may_come_before_the_parent_declaration(): void
    {
        // Rule order is the author's business, and the promotion must not depend on it.
        $schema = $this->map([
            'theme.imprint_url' => ['required', 'url'],
            'theme' => ['required', 'array'],
        ]);

        $this->assertSame('object', $schema['properties']['theme']['type']);
        $this->assertArrayHasKey('imprint_url', $schema['properties']['theme']['properties']);
    }
}
