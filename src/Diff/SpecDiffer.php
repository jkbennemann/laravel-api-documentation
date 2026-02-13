<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Diff;

class SpecDiffer
{
    /**
     * Compare two OpenAPI specs and return changes.
     *
     * @param  array<string, mixed>  $old  Previous spec
     * @param  array<string, mixed>  $new  Current spec
     * @return array{breaking: DiffEntry[], non_breaking: DiffEntry[]}
     */
    public function diff(array $old, array $new): array
    {
        $breaking = [];
        $nonBreaking = [];

        $this->diffPaths($old, $new, $breaking, $nonBreaking);
        $this->diffComponents($old, $new, $breaking, $nonBreaking);
        $this->diffSecurity($old, $new, $breaking, $nonBreaking);

        return [
            'breaking' => $breaking,
            'non_breaking' => $nonBreaking,
        ];
    }

    private function diffPaths(array $old, array $new, array &$breaking, array &$nonBreaking): void
    {
        $oldPaths = $old['paths'] ?? [];
        $newPaths = $new['paths'] ?? [];

        // Removed paths
        foreach ($oldPaths as $path => $oldMethods) {
            if (! isset($newPaths[$path])) {
                foreach ($oldMethods as $method => $operation) {
                    if (! is_array($operation)) {
                        continue;
                    }
                    $breaking[] = new DiffEntry(
                        'removed',
                        strtoupper($method).' '.$path,
                        'Endpoint removed.',
                    );
                }

                continue;
            }

            foreach ($oldMethods as $method => $oldOp) {
                if (! is_array($oldOp)) {
                    continue;
                }

                $location = strtoupper($method).' '.$path;

                if (! isset($newPaths[$path][$method])) {
                    $breaking[] = new DiffEntry('removed', $location, 'Method removed.');

                    continue;
                }

                $newOp = $newPaths[$path][$method];

                $this->diffOperation($oldOp, $newOp, $location, $breaking, $nonBreaking);
            }
        }

        // Added paths
        foreach ($newPaths as $path => $newMethods) {
            if (! isset($oldPaths[$path])) {
                foreach ($newMethods as $method => $operation) {
                    if (! is_array($operation)) {
                        continue;
                    }
                    $nonBreaking[] = new DiffEntry(
                        'added',
                        strtoupper($method).' '.$path,
                        'New endpoint.',
                    );
                }

                continue;
            }

            foreach ($newMethods as $method => $newOp) {
                if (! is_array($newOp)) {
                    continue;
                }
                if (! isset($oldPaths[$path][$method])) {
                    $nonBreaking[] = new DiffEntry(
                        'added',
                        strtoupper($method).' '.$path,
                        'New method on existing path.',
                    );
                }
            }
        }
    }

    private function diffOperation(array $oldOp, array $newOp, string $location, array &$breaking, array &$nonBreaking): void
    {
        // Check for new required parameters
        $this->diffParameters($oldOp, $newOp, $location, $breaking, $nonBreaking);

        // Check request body changes
        $this->diffRequestBody($oldOp, $newOp, $location, $breaking, $nonBreaking);

        // Check response changes
        $this->diffResponses($oldOp, $newOp, $location, $breaking, $nonBreaking);

        // Check security changes
        $oldSecurity = $oldOp['security'] ?? null;
        $newSecurity = $newOp['security'] ?? null;

        if ($oldSecurity === null && $newSecurity !== null) {
            $breaking[] = new DiffEntry('changed', $location, 'Authentication requirement added.');
        } elseif ($oldSecurity !== null && $newSecurity === null) {
            $nonBreaking[] = new DiffEntry('changed', $location, 'Authentication requirement removed.');
        }

        // Check deprecation
        $wasDeprecated = $oldOp['deprecated'] ?? false;
        $nowDeprecated = $newOp['deprecated'] ?? false;

        if (! $wasDeprecated && $nowDeprecated) {
            $nonBreaking[] = new DiffEntry('deprecated', $location, 'Endpoint deprecated.');
        }
    }

    private function diffParameters(array $oldOp, array $newOp, string $location, array &$breaking, array &$nonBreaking): void
    {
        $oldParams = $this->indexParameters($oldOp['parameters'] ?? []);
        $newParams = $this->indexParameters($newOp['parameters'] ?? []);

        // Removed parameters
        foreach ($oldParams as $key => $oldParam) {
            if (! isset($newParams[$key])) {
                $breaking[] = new DiffEntry('removed', $location, "Parameter '{$oldParam['name']}' removed.");
            }
        }

        // New or changed parameters
        foreach ($newParams as $key => $newParam) {
            if (! isset($oldParams[$key])) {
                if ($newParam['required'] ?? false) {
                    $breaking[] = new DiffEntry('added', $location, "Required parameter '{$newParam['name']}' added.");
                } else {
                    $nonBreaking[] = new DiffEntry('added', $location, "Optional parameter '{$newParam['name']}' added.");
                }

                continue;
            }

            $oldParam = $oldParams[$key];

            // Required changed
            $wasRequired = $oldParam['required'] ?? false;
            $isRequired = $newParam['required'] ?? false;

            if (! $wasRequired && $isRequired) {
                $breaking[] = new DiffEntry('changed', $location, "Parameter '{$newParam['name']}' became required.");
            } elseif ($wasRequired && ! $isRequired) {
                $nonBreaking[] = new DiffEntry('changed', $location, "Parameter '{$newParam['name']}' became optional.");
            }

            // Type changed
            $oldType = $oldParam['schema']['type'] ?? null;
            $newType = $newParam['schema']['type'] ?? null;

            if ($oldType !== null && $newType !== null && $oldType !== $newType) {
                $breaking[] = new DiffEntry('changed', $location, "Parameter '{$newParam['name']}' type changed from '{$oldType}' to '{$newType}'.");
            }
        }
    }

    private function diffRequestBody(array $oldOp, array $newOp, string $location, array &$breaking, array &$nonBreaking): void
    {
        $oldSchema = $oldOp['requestBody']['content']['application/json']['schema'] ?? null;
        $newSchema = $newOp['requestBody']['content']['application/json']['schema'] ?? null;

        if ($oldSchema === null && $newSchema === null) {
            return;
        }

        if ($oldSchema === null && $newSchema !== null) {
            $breaking[] = new DiffEntry('added', $location, 'Request body added.');

            return;
        }

        if ($oldSchema !== null && $newSchema === null) {
            $breaking[] = new DiffEntry('removed', $location, 'Request body removed.');

            return;
        }

        $this->diffSchemaProperties($oldSchema, $newSchema, $location.' requestBody', $breaking, $nonBreaking);
    }

    private function diffResponses(array $oldOp, array $newOp, string $location, array &$breaking, array &$nonBreaking): void
    {
        $oldResponses = $oldOp['responses'] ?? [];
        $newResponses = $newOp['responses'] ?? [];

        // Removed success responses
        foreach ($oldResponses as $status => $oldResp) {
            if (! isset($newResponses[$status]) && (int) $status < 400) {
                $breaking[] = new DiffEntry('removed', $location, "Response {$status} removed.");
            }
        }

        // Changed response schemas
        foreach ($newResponses as $status => $newResp) {
            if (! isset($oldResponses[$status])) {
                $nonBreaking[] = new DiffEntry('added', $location, "Response {$status} added.");

                continue;
            }

            $oldSchema = $oldResponses[$status]['content']['application/json']['schema'] ?? null;
            $newSchema = $newResp['content']['application/json']['schema'] ?? null;

            if ($oldSchema !== null && $newSchema !== null) {
                $this->diffSchemaProperties($oldSchema, $newSchema, "{$location} response {$status}", $breaking, $nonBreaking);
            }
        }
    }

    private function diffSchemaProperties(array $oldSchema, array $newSchema, string $location, array &$breaking, array &$nonBreaking): void
    {
        $oldProps = $oldSchema['properties'] ?? [];
        $newProps = $newSchema['properties'] ?? [];
        $oldRequired = array_flip($oldSchema['required'] ?? []);
        $newRequired = array_flip($newSchema['required'] ?? []);

        // Removed properties
        foreach ($oldProps as $name => $oldProp) {
            if (! isset($newProps[$name])) {
                $breaking[] = new DiffEntry('removed', $location, "Field '{$name}' removed.");
            }
        }

        // New or changed properties
        foreach ($newProps as $name => $newProp) {
            if (! isset($oldProps[$name])) {
                $isRequired = isset($newRequired[$name]);
                if ($isRequired) {
                    $breaking[] = new DiffEntry('added', $location, "Required field '{$name}' added.");
                } else {
                    $nonBreaking[] = new DiffEntry('added', $location, "Optional field '{$name}' added.");
                }

                continue;
            }

            $oldProp = $oldProps[$name];

            // Type change
            $oldType = $oldProp['type'] ?? null;
            $newType = $newProp['type'] ?? null;

            if ($oldType !== null && $newType !== null && $oldType !== $newType) {
                $breaking[] = new DiffEntry('changed', $location, "Field '{$name}' type changed from '{$oldType}' to '{$newType}'.");
            }

            // Required change
            $wasRequired = isset($oldRequired[$name]);
            $isRequired = isset($newRequired[$name]);

            if (! $wasRequired && $isRequired) {
                $breaking[] = new DiffEntry('changed', $location, "Field '{$name}' became required.");
            }
        }
    }

    private function diffComponents(array $old, array $new, array &$breaking, array &$nonBreaking): void
    {
        $oldSchemas = $old['components']['schemas'] ?? [];
        $newSchemas = $new['components']['schemas'] ?? [];

        foreach ($oldSchemas as $name => $oldSchema) {
            if (! isset($newSchemas[$name])) {
                $breaking[] = new DiffEntry('removed', "components.schemas.{$name}", 'Schema removed.');

                continue;
            }

            $this->diffSchemaProperties($oldSchema, $newSchemas[$name], "components.schemas.{$name}", $breaking, $nonBreaking);
        }

        foreach ($newSchemas as $name => $newSchema) {
            if (! isset($oldSchemas[$name])) {
                $nonBreaking[] = new DiffEntry('added', "components.schemas.{$name}", 'New schema.');
            }
        }
    }

    private function diffSecurity(array $old, array $new, array &$breaking, array &$nonBreaking): void
    {
        $oldSchemes = array_keys($old['components']['securitySchemes'] ?? []);
        $newSchemes = array_keys($new['components']['securitySchemes'] ?? []);

        $removed = array_diff($oldSchemes, $newSchemes);
        $added = array_diff($newSchemes, $oldSchemes);

        foreach ($removed as $name) {
            $breaking[] = new DiffEntry('removed', "securitySchemes.{$name}", 'Security scheme removed.');
        }

        foreach ($added as $name) {
            $nonBreaking[] = new DiffEntry('added', "securitySchemes.{$name}", 'New security scheme.');
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexParameters(array $params): array
    {
        $indexed = [];
        foreach ($params as $param) {
            $key = ($param['in'] ?? 'query').':'.$param['name'];
            $indexed[$key] = $param;
        }

        return $indexed;
    }
}
