<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

/**
 * Generates contextual error messages and examples for API documentation
 */
class ErrorMessageGenerator
{
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
    public function generateValidationErrorMessage(string $field, string $rule, array $parameters = []): string
    {
        $fieldLabel = $this->fieldLabels[$field] ?? $field;

        // Handle parametrized rules
        $baseRule = explode(':', $rule)[0];

        $message = $this->validationRuleMessages[$rule] ?? $this->validationRuleMessages[$baseRule] ?? "The {$fieldLabel} field is invalid.";

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
    public function generateDomainErrorMessage(string $domain, string $context, string $statusCode, ?string $field = null): string
    {
        $template = $this->domainTemplates[$domain][$context][$statusCode] ?? null;

        if ($field && is_array($template)) {
            return $template[$field] ?? $template['default'] ?? $this->getGenericErrorMessage($statusCode);
        }

        if (is_string($template)) {
            return $template;
        }

        return $this->getGenericErrorMessage($statusCode);
    }

    /**
     * Get generic error message for status code
     */
    public function getGenericErrorMessage(string $statusCode): string
    {
        return $this->domainTemplates['general'][$statusCode] ?? 'An error occurred.';
    }

    /**
     * Generate validation error details structure
     */
    public function generateValidationErrorDetails(array $validationRules): array
    {
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
                        'i18n' => $this->generateI18nKey($field, $ruleName),
                        'message' => $this->generateValidationErrorMessage($field, $rule, $parameters),
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
    private function generateI18nKey(string $field, string $rule): string
    {
        return "api_gateway.validation.{$field}.{$rule}|default:Validation error";
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
        $response = [
            'timestamp' => date('c'),
            'message' => $this->generateDomainErrorMessage($domain, $context, $statusCode),
            'path' => $path,
            'request_id' => 'req_'.substr(md5(uniqid()), 0, 8),
        ];

        // Add validation details for 422 responses
        if ($statusCode === '422' && ! empty($validationRules)) {
            $response['details'] = $this->generateValidationErrorDetails($validationRules);
        }

        return $response;
    }

    /**
     * Detect domain and context from controller and method
     */
    public function detectDomainContext(string $controller, string $method): array
    {
        $controllerName = class_basename($controller);

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
