<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Manages loading and caching of custom message templates
 */
class TemplateManager
{
    private array $loadedTemplates = [];

    private array $config;

    public function __construct(private readonly ?Repository $configuration = null)
    {
        $this->config = $this->configuration?->get('api-documentation.error_responses', []) ?? [];
    }

    /**
     * Load validation message templates
     */
    public function loadValidationMessages(?string $locale = null): array
    {
        $locale = $locale ?? 'en';
        $cacheKey = "validation_messages_{$locale}";

        if (isset($this->loadedTemplates[$cacheKey])) {
            return $this->loadedTemplates[$cacheKey];
        }

        $messages = [];

        // Load from locale-specific template file if configured
        $templateFile = $this->getLocalizedTemplateFile('validation_rules', $locale);
        if ($templateFile && file_exists($templateFile)) {
            try {
                $fileMessages = include $templateFile;
                if (is_array($fileMessages)) {
                    $messages = array_merge($messages, $fileMessages);
                }
            } catch (Throwable $e) {
                error_log("Failed to load validation messages template for {$locale}: {$e->getMessage()}");
            }
        }

        // Merge with config overrides (config takes precedence)
        $configMessages = $this->config['validation_messages'][$locale] ?? $this->config['validation_messages'] ?? [];
        $messages = array_merge($messages, $configMessages);

        $this->loadedTemplates[$cacheKey] = $messages;

        return $messages;
    }

    /**
     * Load domain templates
     */
    public function loadDomainTemplates(?string $locale = null): array
    {
        $locale = $locale ?? 'en';
        $cacheKey = "domain_templates_{$locale}";

        if (isset($this->loadedTemplates[$cacheKey])) {
            return $this->loadedTemplates[$cacheKey];
        }

        $templates = [];

        // Load from locale-specific template file if configured
        $templateFile = $this->getLocalizedTemplateFile('domain_templates', $locale);
        if ($templateFile && file_exists($templateFile)) {
            try {
                $fileTemplates = include $templateFile;
                if (is_array($fileTemplates)) {
                    $templates = array_merge_recursive($templates, $fileTemplates);
                }
            } catch (Throwable $e) {
                error_log("Failed to load domain templates for {$locale}: {$e->getMessage()}");
            }
        }

        // Merge with config overrides (config takes precedence)
        $configTemplates = $this->config['domains'][$locale] ?? $this->config['domains'] ?? [];
        $templates = array_merge_recursive($templates, $configTemplates);

        $this->loadedTemplates[$cacheKey] = $templates;

        return $templates;
    }

    /**
     * Load field labels
     */
    public function loadFieldLabels(?string $locale = null): array
    {
        $locale = $locale ?? 'en';
        $cacheKey = "field_labels_{$locale}";

        if (isset($this->loadedTemplates[$cacheKey])) {
            return $this->loadedTemplates[$cacheKey];
        }

        $labels = [];

        // Load from locale-specific template file if configured
        $templateFile = $this->getLocalizedTemplateFile('field_labels', $locale);
        if ($templateFile && file_exists($templateFile)) {
            try {
                $fileLabels = include $templateFile;
                if (is_array($fileLabels)) {
                    $labels = array_merge($labels, $fileLabels);
                }
            } catch (Throwable $e) {
                error_log("Failed to load field labels template for {$locale}: {$e->getMessage()}");
            }
        }

        // Merge with config overrides (config takes precedence)
        $configLabels = $this->config['field_labels'][$locale] ?? $this->config['field_labels'] ?? [];
        $labels = array_merge($labels, $configLabels);

        $this->loadedTemplates[$cacheKey] = $labels;

        return $labels;
    }

    /**
     * Generate a template file for validation messages
     */
    public function generateValidationMessagesTemplate(string $filePath): bool
    {
        $template = $this->getValidationMessagesTemplate();

        $directory = dirname($filePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return file_put_contents($filePath, $template) !== false;
    }

    /**
     * Generate a template file for domain templates
     */
    public function generateDomainTemplatesFile(string $filePath): bool
    {
        $template = $this->getDomainTemplatesTemplate();

        $directory = dirname($filePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return file_put_contents($filePath, $template) !== false;
    }

    /**
     * Generate a template file for field labels
     */
    public function generateFieldLabelsFile(string $filePath): bool
    {
        $template = $this->getFieldLabelsTemplate();

        $directory = dirname($filePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return file_put_contents($filePath, $template) !== false;
    }

    /**
     * Get localized template file path
     */
    private function getLocalizedTemplateFile(string $templateType, string $locale): ?string
    {
        $baseFile = $this->config['template_files'][$templateType] ?? null;
        if (! $baseFile) {
            return null;
        }

        // Try locale-specific file first (e.g., validation-messages.es.php)
        if ($locale !== 'en') {
            $pathInfo = pathinfo($baseFile);
            $localizedFile = $pathInfo['dirname'].'/'.$pathInfo['filename'].'.'.$locale.'.'.$pathInfo['extension'];
            if (file_exists($localizedFile)) {
                return $localizedFile;
            }
        }

        // Fall back to base file
        return $baseFile;
    }

    /**
     * Generate localized template files for a specific locale
     */
    public function generateLocalizedTemplateFiles(string $locale, string $baseDirectory): array
    {
        $generatedFiles = [];

        $templates = [
            'validation_rules' => 'validation-messages',
            'domain_templates' => 'domain-templates',
            'field_labels' => 'field-labels',
        ];

        foreach ($templates as $type => $filename) {
            $filePath = $baseDirectory.'/'.$filename.'.'.$locale.'.php';

            switch ($type) {
                case 'validation_rules':
                    if ($this->generateValidationMessagesTemplate($filePath)) {
                        $generatedFiles[] = $filePath;
                    }
                    break;
                case 'domain_templates':
                    if ($this->generateDomainTemplatesFile($filePath)) {
                        $generatedFiles[] = $filePath;
                    }
                    break;
                case 'field_labels':
                    if ($this->generateFieldLabelsFile($filePath)) {
                        $generatedFiles[] = $filePath;
                    }
                    break;
            }
        }

        return $generatedFiles;
    }

    /**
     * Get available locales from template files
     */
    public function getAvailableLocales(): array
    {
        $locales = ['en']; // Default locale

        foreach ($this->config['template_files'] ?? [] as $templateFile) {
            if (! $templateFile || ! file_exists($templateFile)) {
                continue;
            }

            $directory = dirname($templateFile);
            $basename = pathinfo($templateFile, PATHINFO_FILENAME);

            // Look for locale-specific files (e.g., validation-messages.es.php)
            $pattern = $directory.'/'.$basename.'.*.php';
            foreach (glob($pattern) as $file) {
                $filename = pathinfo($file, PATHINFO_FILENAME);
                $parts = explode('.', $filename);
                if (count($parts) >= 2) {
                    $locale = end($parts);
                    if (! in_array($locale, $locales)) {
                        $locales[] = $locale;
                    }
                }
            }
        }

        return $locales;
    }

    /**
     * Clear loaded templates cache
     */
    public function clearCache(): void
    {
        $this->loadedTemplates = [];
    }

    /**
     * Get validation messages template content
     */
    private function getValidationMessagesTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Custom validation message templates for API documentation
 * 
 * This file allows you to customize validation error messages that appear
 * in your API documentation. Messages support Laravel's validation placeholders
 * like :attribute, :min, :max, etc.
 */

return [
    // Basic validation rules
    'required' => 'The :attribute field is required.',
    'string' => 'The :attribute field must be a string.',
    'integer' => 'The :attribute field must be an integer.',
    'numeric' => 'The :attribute field must be a number.',
    'boolean' => 'The :attribute field must be true or false.',
    'array' => 'The :attribute field must be an array.',
    'json' => 'The :attribute field must be valid JSON.',

    // Format validation rules
    'email' => 'The :attribute must be a valid email address.',
    'email:rfc,dns,strict' => 'The :attribute must be a valid email address with a verified domain.',
    'url' => 'The :attribute field must be a valid URL.',
    'uuid' => 'The :attribute field must be a valid UUID.',
    'date' => 'The :attribute field must be a valid date.',
    'date_format' => 'The :attribute field must match the format :format.',
    'regex' => 'The :attribute field format is invalid.',

    // Size validation rules
    'min' => 'The :attribute field must be at least :min characters.',
    'max' => 'The :attribute field must not exceed :max characters.',
    'between' => 'The :attribute field must be between :min and :max characters.',
    'size' => 'The :attribute field must be exactly :size characters.',

    // Comparison validation rules
    'after' => 'The :attribute field must be a date after :date.',
    'before' => 'The :attribute field must be a date before :date.',
    'confirmed' => 'The :attribute confirmation does not match.',

    // Database validation rules
    'unique' => 'The :attribute has already been taken.',
    'exists' => 'The selected :attribute is invalid.',

    // Choice validation rules
    'in' => 'The selected :attribute is invalid.',
    'not_in' => 'The selected :attribute is invalid.',

    // File validation rules
    'mimes' => 'The :attribute must be a file of type: :values.',
    'image' => 'The :attribute must be an image.',
    'file' => 'The :attribute must be a file.',

    // Pattern validation rules
    'alpha' => 'The :attribute field must only contain letters.',
    'alpha_num' => 'The :attribute field must only contain letters and numbers.',
    'alpha_dash' => 'The :attribute field must only contain letters, numbers, dashes, and underscores.',
    'digits' => 'The :attribute field must be :digits digits.',
    'digits_between' => 'The :attribute field must be between :min and :max digits.',

    // Custom business rules - add your own here
    // 'custom_rule' => 'Your custom error message for :attribute.',
];
PHP;
    }

    /**
     * Get domain templates template content
     */
    private function getDomainTemplatesTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Custom domain-specific error message templates for API documentation
 * 
 * This file allows you to define error messages specific to different
 * business domains and contexts within your application.
 */

return [
    // Authentication domain
    'authentication' => [
        'login' => [
            '401' => 'Invalid credentials provided. Please check your email and password.',
            '422' => [
                'email' => 'Please provide a valid email address.',
                'password' => 'Password is required and cannot be empty.',
            ],
            '429' => 'Too many login attempts. Please try again in :retry_after seconds.',
        ],
        'register' => [
            '422' => [
                'email' => 'This email address is already registered.',
                'password' => 'Password must be at least 8 characters with letters, numbers, and symbols.',
                'first_name' => 'First name is required.',
                'last_name' => 'Last name is required.',
            ],
            '409' => 'An account with this email already exists.',
        ],
        'password_reset' => [
            '422' => [
                'email' => 'We cannot find a user with that email address.',
                'token' => 'The password reset token is invalid or has expired.',
                'password' => 'Password must meet our security requirements.',
            ],
            '404' => 'Password reset token not found or has expired.',
        ],
    ],

    // Two-Factor Authentication domain
    '2fa' => [
        'setup' => [
            '422' => [
                'code' => 'Please enter the 6-digit verification code from your authenticator app.',
            ],
            '403' => 'Two-factor authentication is already enabled for this account.',
        ],
        'verify' => [
            '422' => [
                'code' => 'The verification code is incorrect or has expired.',
                'recovery_code' => 'The recovery code is invalid or has already been used.',
            ],
            '429' => 'Too many verification attempts. Please try again later.',
        ],
        'disable' => [
            '422' => [
                'password' => 'Please enter your current password to disable two-factor authentication.',
            ],
            '403' => 'Two-factor authentication is not enabled for this account.',
        ],
    ],

    // User Management domain
    'user_management' => [
        'profile' => [
            '422' => [
                'first_name' => 'First name cannot contain special characters.',
                'last_name' => 'Last name cannot contain special characters.',
                'email' => 'This email address is already in use by another account.',
                'phone' => 'Please provide a valid phone number.',
            ],
            '403' => 'You do not have permission to modify this profile.',
        ],
        'users' => [
            '404' => 'User not found.',
            '403' => 'You do not have permission to access this user.',
            '422' => [
                'user_id' => 'Invalid user identifier provided.',
                'role' => 'The specified role does not exist.',
            ],
        ],
    ],

    // Billing domain
    'billing' => [
        'subscription' => [
            '422' => [
                'plan_id' => 'The selected subscription plan does not exist.',
                'payment_method' => 'A valid payment method is required.',
            ],
            '402' => 'Payment is required to activate this subscription.',
            '409' => 'You already have an active subscription.',
        ],
        'payment' => [
            '422' => [
                'amount' => 'Payment amount must be greater than zero.',
                'currency' => 'The specified currency is not supported.',
                'card_number' => 'Please provide a valid credit card number.',
                'cvv' => 'Please provide a valid CVV code.',
            ],
            '402' => 'Payment could not be processed. Please check your payment method.',
        ],
    ],

    // Add your custom domains here
    // 'your_domain' => [
    //     'your_context' => [
    //         '422' => 'Your custom error message',
    //     ],
    // ],
];
PHP;
    }

    /**
     * Get field labels template content
     */
    private function getFieldLabelsTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Custom field label mappings for API documentation
 * 
 * This file allows you to define human-readable labels for form fields
 * that will be used in error messages instead of the technical field names.
 */

return [
    // Authentication fields
    'email' => 'email address',
    'password' => 'password',
    'password_confirmation' => 'password confirmation',
    'current_password' => 'current password',
    'new_password' => 'new password',

    // Personal information
    'first_name' => 'first name',
    'last_name' => 'last name',
    'full_name' => 'full name',
    'display_name' => 'display name',
    'username' => 'username',
    'phone' => 'phone number',
    'phone_number' => 'phone number',
    'mobile' => 'mobile number',
    'date_of_birth' => 'date of birth',
    'birth_date' => 'birth date',

    // Address fields
    'address' => 'address',
    'street_address' => 'street address',
    'city' => 'city',
    'state' => 'state/province',
    'postal_code' => 'postal code',
    'zip_code' => 'ZIP code',
    'country' => 'country',

    // Identification fields
    'user_id' => 'user identifier',
    'subscription_id' => 'subscription identifier',
    'transaction_id' => 'transaction identifier',
    'order_id' => 'order identifier',
    'invoice_id' => 'invoice identifier',

    // Two-factor authentication
    'code' => 'verification code',
    'verification_code' => 'verification code',
    'recovery_code' => 'recovery code',
    'backup_code' => 'backup code',

    // File uploads
    'avatar' => 'profile picture',
    'profile_picture' => 'profile picture',
    'document' => 'document',
    'attachment' => 'attachment',
    'file' => 'file',
    'image' => 'image',

    // Business fields
    'company_name' => 'company name',
    'job_title' => 'job title',
    'department' => 'department',
    'website' => 'website URL',

    // Payment fields
    'card_number' => 'credit card number',
    'cardholder_name' => 'cardholder name',
    'expiry_date' => 'expiration date',
    'cvv' => 'security code',
    'billing_address' => 'billing address',

    // Content fields
    'title' => 'title',
    'description' => 'description',
    'content' => 'content',
    'message' => 'message',
    'comment' => 'comment',
    'notes' => 'notes',

    // Dates and times
    'start_date' => 'start date',
    'end_date' => 'end date',
    'due_date' => 'due date',
    'created_at' => 'creation date',
    'updated_at' => 'last updated',

    // Add your custom field labels here
    // 'your_field' => 'Your Field Label',
];
PHP;
    }
}
