<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\QueryParam;

use JkBennemann\LaravelApiDocumentation\Analyzers\Request\FormRequestAnalyzer;
use JkBennemann\LaravelApiDocumentation\Contracts\QueryParameterExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ParameterResult;
use JkBennemann\LaravelApiDocumentation\Schema\ValidationRuleMapper;

class FormRequestQueryAnalyzer implements QueryParameterExtractor
{
    private FormRequestAnalyzer $formRequestAnalyzer;

    private ValidationRuleMapper $ruleMapper;

    private const QUERY_METHODS = ['GET', 'HEAD'];

    public function __construct(array $config = [])
    {
        $this->formRequestAnalyzer = new FormRequestAnalyzer($config);
        $this->ruleMapper = new ValidationRuleMapper($config);
    }

    /**
     * @return ParameterResult[]
     */
    public function extract(AnalysisContext $ctx): array
    {
        if (! in_array($ctx->route->httpMethod(), self::QUERY_METHODS, true)) {
            return [];
        }

        if (! $ctx->hasReflection()) {
            return [];
        }

        $formRequestClass = $this->formRequestAnalyzer->detectFormRequest($ctx);
        if ($formRequestClass === null) {
            return [];
        }

        $rules = $this->formRequestAnalyzer->extractRules($formRequestClass);
        if (empty($rules)) {
            return [];
        }

        $parameters = [];
        foreach ($rules as $field => $fieldRules) {
            // Skip nested fields — they don't map cleanly to query parameters
            if (str_contains($field, '.')) {
                continue;
            }

            $schema = $this->ruleMapper->mapRules($fieldRules);
            $required = $this->ruleMapper->isRequired($fieldRules);

            if ($this->ruleMapper->isNullable($fieldRules)) {
                $schema->nullable = true;
            }

            $parameters[] = ParameterResult::query(
                name: $field,
                schema: $schema,
                required: $required,
            );
        }

        return $parameters;
    }
}
