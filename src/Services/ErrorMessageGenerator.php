<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Illuminate\Contracts\Translation\Translator;

/**
 * Generates contextual error messages and examples for API documentation
 */
class ErrorMessageGenerator
{
    private array $config;

    private ?TemplateManager $templateManager = null;

    private ?Translator $translator = null;

    private array $loadedValidationMessages = [];

    private array $loadedDomainTemplates = [];

    private array $loadedFieldLabels = [];

    private string $currentLocale;

    public function __construct(
        private readonly ?\Illuminate\Contracts\Config\Repository $configuration = null,
        ?TemplateManager $templateManager = null,
        ?Translator $translator = null
    ) {
        $this->config = $this->configuration?->get('api-documentation.error_responses', []) ?? [];
        $this->templateManager = $templateManager ?? new TemplateManager($this->configuration);
        $this->translator = $translator;
        $this->currentLocale = $this->config['localization']['default_locale'] ?? 'en';
    }

    /**
     * Validation rule to error message mapping
     */
    private array $validationRuleMessages = [
        'required' => 'The :attribute field is required.',
        'string' => 'The :attribute field must be a string.',
        'email' => 'The :attribute must be a valid email address.',
        'email:rfc,dns,strict' => 'The :attribute must be a valid email address with a valid domain.',
        'integer' => 'The :attribute field must be an integer.',
        'numeric' => 'The :attribute field must be a number.',
        'boolean' => 'The :attribute field must be true or false.',
        'array' => 'The :attribute field must be an array.',
        'json' => 'The :attribute field must be valid JSON.',
        'url' => 'The :attribute field must be a valid URL.',
        'uuid' => 'The :attribute field must be a valid UUID.',
        'date' => 'The :attribute field must be a valid date.',
        'date_format' => 'The :attribute field must match the format :format.',
        'after' => 'The :attribute field must be a date after :date.',
        'before' => 'The :attribute field must be a date before :date.',
        'regex' => 'The :attribute field format is invalid.',
        'confirmed' => 'The :attribute confirmation does not match.',
        'unique' => 'The :attribute has already been taken.',
        'exists' => 'The selected :attribute is invalid.',
        'in' => 'The selected :attribute is invalid.',
        'not_in' => 'The selected :attribute is invalid.',
        'mimes' => 'The :attribute must be a file of type: :values.',
        'image' => 'The :attribute must be an image.',
        'file' => 'The :attribute must be a file.',
    ];

    /**
     * Domain-specific error message templates
     */
    private array $domainTemplates = [
        'authentication' => [
            'login' => [
                '401' => 'Invalid credentials provided.',
                '422' => [
                    'email' => 'Please provide a valid email address.',
                    'password' => 'Password is required.',
                ],
            ],
            'register' => [
                '422' => [
                    'email' => 'The email address is already registered.',
                    'password' => 'Password must be at least 8 characters with letters, numbers, and symbols.',
                    'first_name' => 'First name is required.',
                    'last_name' => 'Last name is required.',
                ],
            ],
            'password_reset' => [
                '422' => [
                    'email' => 'We cannot find a user with that email address.',
                    'token' => 'The password reset token is invalid or expired.',
                    'password' => 'Password must meet security requirements.',
                ],
            ],
        ],
        '2fa' => [
            'enable' => [
                '401' => 'Authentication required to enable two-factor authentication.',
                '403' => 'You do not have permission to modify two-factor authentication settings.',
            ],
            'confirm' => [
                '422' => [
                    'code' => 'The verification code is required.',
                    'code.required' => 'Please enter the 6-digit verification code.',
                    'code.digits' => 'The verification code must be exactly 6 digits.',
                    'code.invalid' => 'The verification code is invalid or expired.',
                ],
            ],
            'verify' => [
                '422' => [
                    'code' => 'Invalid or expired verification code.',
                ],
            ],
            'recovery' => [
                '422' => [
                    'recovery_code' => 'The recovery code is invalid or has already been used.',
                ],
            ],
        ],
        'user_management' => [
            'profile' => [
                '422' => [
                    'first_name' => 'First name cannot contain special characters.',
                    'last_name' => 'Last name cannot contain special characters.',
                    'email' => 'Email address is already in use by another account.',
                    'phone' => 'Please provide a valid phone number.',
                ],
            ],
            'users' => [
                '404' => 'User not found.',
                '403' => 'You do not have permission to access this user.',
                '422' => [
                    'user_id' => 'Invalid user identifier provided.',
                ],
            ],
        ],
        'general' => [
            '400' => 'The request could not be processed due to invalid syntax.',
            '401' => 'Authentication credentials are required.',
            '403' => 'You do not have permission to access this resource.',
            '404' => 'The requested resource was not found.',
            '405' => 'The HTTP method is not allowed for this endpoint.',
            '409' => 'The request conflicts with the current state of the resource.',
            '422' => 'The request contains invalid data.',
            '429' => 'Too many requests. Please try again later.',
            '500' => 'An internal server error occurred. Please try again later.',
            '503' => 'The service is temporarily unavailable. Please try again later.',
        ],
    ];

    /**
     * Common field names and their human-readable labels
     */
    private array $fieldLabels = [
        'email' => 'email address',
        'password' => 'password',
        'password_confirmation' => 'password confirmation',
        'first_name' => 'first name',
        'last_name' => 'last name',
        'phone' => 'phone number',
        'phone_number' => 'phone number',
        'code' => 'verification code',
        'recovery_code' => 'recovery code',
        'user_id' => 'user ID',
        'subscription_id' => 'subscription ID',
        'title' => 'title',
        'description' => 'description',
        'name' => 'name',
        'file' => 'file',
        'image' => 'image',
        'url' => 'URL',
        'token' => 'token',
    ];

    /**
     * Generate contextual error message for a specific validation rule
     */
    public function generateValidationErrorMessage(string $field, string $rule, array $parameters = [], ?string $locale = null): string
    {
        $locale = $locale ?? $this->currentLocale;

        // Try Laravel translation first if translator is available
        if ($this->translator) {
            $translatedMessage = $this->tryLaravelTranslation($field, $rule, $parameters, $locale);
            if ($translatedMessage !== null) {
                return $translatedMessage;
            }
        }

        // Load field labels from templates
        $allFieldLabels = $this->getFieldLabels($locale);
        $fieldLabel = $allFieldLabels[$field] ?? $field;

        // Handle parametrized rules
        $baseRule = explode(':', $rule)[0];

        // Load validation messages from templates
        $allValidationMessages = $this->getValidationMessages($locale);
        $message = $allValidationMessages[$rule]
            ?? $allValidationMessages[$baseRule]
            ?? $this->validationRuleMessages[$rule]
            ?? $this->validationRuleMessages[$baseRule]
            ?? "The {$fieldLabel} field is invalid.";

        // Replace placeholders
        $message = str_replace(':attribute', $fieldLabel, $message);

        // Handle rule-specific parameters
        switch ($baseRule) {
            case 'min':
                $message = str_replace(':min', $parameters[0] ?? '1', $message);
                break;
            case 'max':
                $message = str_replace(':max', $parameters[0] ?? '255', $message);
                break;
            case 'between':
                $message = str_replace([':min', ':max'], [$parameters[0] ?? '1', $parameters[1] ?? '255'], $message);
                break;
            case 'in':
            case 'mimes':
                $message = str_replace(':values', implode(', ', $parameters), $message);
                break;
            case 'date_format':
                $message = str_replace(':format', $parameters[0] ?? 'Y-m-d', $message);
                break;
            case 'after':
            case 'before':
                $message = str_replace(':date', $parameters[0] ?? 'today', $message);
                break;
        }

        return $message;
    }

    /**
     * Generate domain-specific error message
     */
    public function generateDomainErrorMessage(string $domain, string $context, string $statusCode, ?string $field = null, ?string $locale = null): string
    {
        $locale = $locale ?? $this->currentLocale;

        // Try Laravel translation first if translator is available
        if ($this->translator) {
            $translatedMessage = $this->tryDomainTranslation($domain, $context, $statusCode, $field, $locale);
            if ($translatedMessage !== null) {
                return $translatedMessage;
            }
        }

        // Load domain templates from files and config
        $allDomainTemplates = $this->getDomainTemplates($locale);
        $template = $allDomainTemplates[$domain][$context][$statusCode]
            ?? $this->domainTemplates[$domain][$context][$statusCode]
            ?? null;

        if ($field && is_array($template)) {
            return $template[$field] ?? $template['default'] ?? $this->getGenericErrorMessage($statusCode, $locale);
        }

        if (is_string($template)) {
            return $template;
        }

        return $this->getGenericErrorMessage($statusCode, $locale);
    }

    /**
     * Get generic error message for status code
     */
    public function getGenericErrorMessage(string $statusCode, ?string $locale = null): string
    {
        $locale = $locale ?? $this->currentLocale;

        // Try Laravel translation first
        if ($this->translator) {
            $translationKey = "errors.{$statusCode}";
            if ($this->translator->has($translationKey, $locale)) {
                return $this->translator->get($translationKey, [], $locale);
            }
        }

        // Use configured status messages with fallback to default
        $configuredMessages = $this->config['defaults']['status_messages'] ?? [];

        return $configuredMessages[$statusCode]
            ?? $this->domainTemplates['general'][$statusCode]
            ?? 'An error occurred.';
    }

    /**
     * Generate validation error details structure
     */
    public function generateValidationErrorDetails(array $validationRules, ?string $locale = null): array
    {
        $locale = $locale ?? $this->currentLocale;
        $details = [];

        foreach ($validationRules as $field => $rules) {
            $fieldErrors = [];

            if (is_string($rules)) {
                $rules = explode('|', $rules);
            }

            foreach ($rules as $rule) {
                if (is_string($rule)) {
                    $ruleParts = explode(':', $rule);
                    $ruleName = $ruleParts[0];
                    $parameters = array_slice($ruleParts, 1);

                    $fieldErrors[] = [
                        'i18n' => $this->generateI18nKey($field, $ruleName, $locale),
                        'message' => $this->generateValidationErrorMessage($field, $rule, $parameters, $locale),
                    ];
                }
            }

            if (! empty($fieldErrors)) {
                $details[$field] = $fieldErrors;
            }
        }

        return $details;
    }

    /**
     * Generate internationalization key
     */
    private function generateI18nKey(string $field, string $rule, ?string $locale = null): string
    {
        $locale = $locale ?? $this->currentLocale;
        $appName = $this->config['defaults']['app_name'] ?? 'app';
        $key = "{$appName}.validation.{$field}.{$rule}";

        // Add locale suffix if not default locale
        if ($locale !== 'en') {
            $key .= ".{$locale}";
        }

        return "{$key}|default:Validation error";
    }

    /**
     * Generate complete error response example
     */
    public function generateErrorResponseExample(
        string $statusCode,
        string $path,
        string $domain = 'general',
        string $context = 'default',
        array $validationRules = []
    ): array {
        $examplesConfig = $this->config['examples'] ?? [];

        // Check if examples are enabled
        if (! ($examplesConfig['enabled'] ?? true)) {
            return [];
        }

        $response = [
            'timestamp' => $this->generateTimestamp($examplesConfig),
            'message' => $this->generateDomainErrorMessage($domain, $context, $statusCode),
            'path' => $path,
            'request_id' => $this->generateRequestId($examplesConfig),
        ];

        // Add configured additional fields
        $additionalFields = $this->config['schema']['additional_fields'] ?? [];
        foreach ($additionalFields as $fieldName => $fieldConfig) {
            $response[$fieldName] = $this->generateAdditionalFieldValue($fieldName, $fieldConfig);
        }

        // Add validation details for 422 responses
        if ($statusCode === '422' && ! empty($validationRules) && ($examplesConfig['include_validation_details'] ?? true)) {
            $validationConfig = $this->config['schema']['validation_details'] ?? [];
            $detailsFieldName = $validationConfig['field_name'] ?? 'details';
            $response[$detailsFieldName] = $this->generateValidationErrorDetails($validationRules);
        }

        return $response;
    }

    /**
     * Generate timestamp based on configuration
     */
    private function generateTimestamp(array $examplesConfig): string
    {
        if ($examplesConfig['realistic_timestamps'] ?? true) {
            return date('c');
        }

        return '2024-01-01T12:00:00+00:00';
    }

    /**
     * Generate request ID based on configuration
     */
    private function generateRequestId(array $examplesConfig): string
    {
        if ($examplesConfig['realistic_request_ids'] ?? true) {
            $pattern = $this->config['defaults']['request_id_pattern'] ?? 'req_{random}';

            return str_replace('{random}', substr(md5(uniqid()), 0, 8), $pattern);
        }

        return 'req_12345678';
    }

    /**
     * Generate value for additional fields
     */
    private function generateAdditionalFieldValue(string $fieldName, array $fieldConfig): mixed
    {
        $type = $fieldConfig['type'] ?? 'string';

        return match ($type) {
            'string' => $fieldConfig['example'] ?? "example_{$fieldName}",
            'integer' => $fieldConfig['example'] ?? 12345,
            'boolean' => $fieldConfig['example'] ?? true,
            'array' => $fieldConfig['example'] ?? [],
            'object' => $fieldConfig['example'] ?? new \stdClass,
            default => "example_{$fieldName}",
        };
    }

    /**
     * Detect domain and context from controller and method
     */
    public function detectDomainContext(string $controller, string $method): array
    {
        $controllerName = class_basename($controller);

        // Check configured custom patterns first
        $configuredPatterns = $this->config['domain_detection']['patterns'] ?? [];
        foreach ($configuredPatterns as $pattern => $domainContext) {
            if ($this->matchesPattern($controllerName, $pattern)) {
                return $domainContext;
            }
        }

        // Default detection logic
        // Authentication domain detection
        if (str_contains($controllerName, 'Auth') || str_contains($controllerName, 'Login')) {
            if (str_contains($method, 'login')) {
                return ['authentication', 'login'];
            }
            if (str_contains($method, 'register')) {
                return ['authentication', 'register'];
            }
            if (str_contains($method, 'password') || str_contains($method, 'reset')) {
                return ['authentication', 'password_reset'];
            }
        }

        // 2FA domain detection
        if (str_contains($controllerName, 'TwoFactor') || str_contains($controllerName, '2fa')) {
            if (str_contains($method, 'enable')) {
                return ['2fa', 'enable'];
            }
            if (str_contains($method, 'confirm')) {
                return ['2fa', 'confirm'];
            }
            if (str_contains($method, 'verify') || str_contains($method, 'challenge')) {
                return ['2fa', 'verify'];
            }
            if (str_contains($method, 'recover')) {
                return ['2fa', 'recovery'];
            }
        }

        // User management domain detection
        if (str_contains($controllerName, 'User') || str_contains($controllerName, 'Profile')) {
            if (str_contains($method, 'profile')) {
                return ['user_management', 'profile'];
            }

            return ['user_management', 'users'];
        }

        return ['general', 'default'];
    }

    /**
     * Check if controller name matches a pattern (supports wildcards)
     */
    private function matchesPattern(string $controllerName, string $pattern): bool
    {
        // Convert pattern to regex - handle wildcards correctly
        $regex = preg_quote($pattern, '/');
        $regex = str_replace('\*', '.*', $regex);

        return preg_match("/^{$regex}$/i", $controllerName) === 1;
    }

    /**
     * Get validation messages (lazy loading from templates)
     */
    private function getValidationMessages(?string $locale = null): array
    {
        $locale = $locale ?? $this->currentLocale;
        $cacheKey = "validation_messages_{$locale}";

        if (empty($this->loadedValidationMessages[$cacheKey])) {
            $this->loadedValidationMessages[$cacheKey] = $this->templateManager->loadValidationMessages($locale);
        }

        return $this->loadedValidationMessages[$cacheKey];
    }

    /**
     * Get domain templates (lazy loading from templates)
     */
    private function getDomainTemplates(?string $locale = null): array
    {
        $locale = $locale ?? $this->currentLocale;
        $cacheKey = "domain_templates_{$locale}";

        if (empty($this->loadedDomainTemplates[$cacheKey])) {
            $this->loadedDomainTemplates[$cacheKey] = $this->templateManager->loadDomainTemplates($locale);
        }

        return $this->loadedDomainTemplates[$cacheKey];
    }

    /**
     * Get field labels (lazy loading from templates)
     */
    private function getFieldLabels(?string $locale = null): array
    {
        $locale = $locale ?? $this->currentLocale;
        $cacheKey = "field_labels_{$locale}";

        if (empty($this->loadedFieldLabels[$cacheKey])) {
            $fieldLabels = $this->templateManager->loadFieldLabels($locale);
            // Merge with default field labels
            $this->loadedFieldLabels[$cacheKey] = array_merge($this->fieldLabels, $fieldLabels);
        }

        return $this->loadedFieldLabels[$cacheKey];
    }

    /**
     * Try Laravel's translation system first
     */
    private function tryLaravelTranslation(string $field, string $rule, array $parameters, string $locale): ?string
    {
        if (! $this->translator) {
            return null;
        }

        // Try field-specific translation
        $fieldKey = "validation.{$rule}.{$field}";
        if ($this->translator->has($fieldKey, $locale)) {
            return $this->translator->get($fieldKey, $parameters + ['attribute' => $field], $locale);
        }

        // Try rule-specific translation
        $ruleKey = "validation.{$rule}";
        if ($this->translator->has($ruleKey, $locale)) {
            return $this->translator->get($ruleKey, $parameters + ['attribute' => $field], $locale);
        }

        // Try custom validation translation
        $customKey = "validation.custom.{$field}.{$rule}";
        if ($this->translator->has($customKey, $locale)) {
            return $this->translator->get($customKey, $parameters + ['attribute' => $field], $locale);
        }

        return null;
    }

    /**
     * Set the current locale for error message generation
     */
    public function setLocale(string $locale): void
    {
        $this->currentLocale = $locale;
    }

    /**
     * Get the current locale
     */
    public function getLocale(): string
    {
        return $this->currentLocale;
    }

    /**
     * Get available locales from configuration
     */
    public function getAvailableLocales(): array
    {
        return $this->config['localization']['available_locales'] ?? ['en'];
    }

    /**
     * Try Laravel's domain-specific translation
     */
    private function tryDomainTranslation(string $domain, string $context, string $statusCode, ?string $field, string $locale): ?string
    {
        if (! $this->translator) {
            return null;
        }

        // Try field-specific domain translation
        if ($field) {
            $fieldKey = "errors.{$domain}.{$context}.{$statusCode}.{$field}";
            if ($this->translator->has($fieldKey, $locale)) {
                return $this->translator->get($fieldKey, ['field' => $field], $locale);
            }
        }

        // Try domain context translation
        $contextKey = "errors.{$domain}.{$context}.{$statusCode}";
        if ($this->translator->has($contextKey, $locale)) {
            return $this->translator->get($contextKey, [], $locale);
        }

        // Try domain-level translation
        $domainKey = "errors.{$domain}.{$statusCode}";
        if ($this->translator->has($domainKey, $locale)) {
            return $this->translator->get($domainKey, [], $locale);
        }

        return null;
    }

    /**
     * Generate localized error message
     */
    public function generateLocalizedErrorMessage(string $field, string $rule, array $parameters = [], array $locales = []): array
    {
        if (empty($locales)) {
            $locales = $this->getAvailableLocales();
        }

        $messages = [];
        foreach ($locales as $locale) {
            $messages[$locale] = $this->generateValidationErrorMessage($field, $rule, $parameters, $locale);
        }

        return $messages;
    }

    /**
     * Get enhanced error messages for specific status codes
     */
    public function getEnhancedErrorMessages(): array
    {
        return [
            '400' => [
                'description' => 'Bad Request',
                'examples' => [
                    'Invalid JSON syntax in request body',
                    'Malformed request parameters',
                    'Invalid request format',
                ],
            ],
            '401' => [
                'description' => 'Unauthorized',
                'examples' => [
                    'Authentication credentials are required',
                    'Invalid or expired authentication token',
                    'Authentication failed',
                ],
            ],
            '403' => [
                'description' => 'Forbidden',
                'examples' => [
                    'You do not have permission to access this resource',
                    'Insufficient privileges for this operation',
                    'Access denied',
                ],
            ],
            '404' => [
                'description' => 'Not Found',
                'examples' => [
                    'The requested resource was not found',
                    'Endpoint does not exist',
                    'User not found',
                ],
            ],
            '422' => [
                'description' => 'Validation Error',
                'examples' => [
                    'The request contains invalid data',
                    'Validation failed for one or more fields',
                    'Required fields are missing',
                ],
            ],
            '429' => [
                'description' => 'Too Many Requests',
                'examples' => [
                    'Rate limit exceeded. Please try again later',
                    'Too many requests from this IP address',
                    'API usage limit reached',
                ],
            ],
            '500' => [
                'description' => 'Internal Server Error',
                'examples' => [
                    'An internal server error occurred',
                    'Service temporarily unavailable',
                    'Please try again later',
                ],
            ],
        ];
    }
}
