<?php

namespace Disstudio\EntityImport\Service;

use Disstudio\EntityImport\DTO\EntityConfig;
use RuntimeException;

class TableDependencyResolverService
{
    /**
     * Sorts an array of EntityConfig objects based on their foreign key dependencies.
     * * @param array<string, EntityConfig> $configs Indexed array of EntityConfigs
     * @return array<string, EntityConfig>
     * * @throws RuntimeException If a circular dependency is detected
     */
    public function resolveDependencies(array $configs): array
    {
        $graph = [];
        $inDegree = [];

        // 1. Initialize the graph and in-degree counts
        foreach ($configs as $key => $config) {
            $graph[$key] = [];
            $inDegree[$key] = 0;
        }

        // 2. Build the dependency graph
        foreach ($configs as $key => $config) {
            foreach ($config->getForeignKeys() as $foreignKey) {
                $targetKey = $foreignKey->getTargetKey();

                // Skip self-referencing foreign keys (e.g., tree structures like parent_id)
                if ($targetKey === $key) {
                    continue;
                }

                // If the target entity is part of our current import batch
                if (isset($configs[$targetKey])) {
                    // The targetKey must be processed BEFORE the current key.
                    // So we add an edge from targetKey -> key
                    $graph[$targetKey][] = $key;

                    // Increment the number of dependencies the current key has
                    $inDegree[$key]++;
                }
            }
        }

        // 3. Find all entities with NO dependencies (in-degree of 0) to start the queue
        $queue = [];
        foreach ($inDegree as $key => $degree) {
            if ($degree === 0) {
                $queue[] = $key;
            }
        }

        $sortedKeys = [];

        // 4. Process the queue (Kahn's algorithm)
        while (count($queue) > 0) {
            $currentKey = array_shift($queue);
            $sortedKeys[] = $currentKey;

            // For every entity that depends on the current entity...
            foreach ($graph[$currentKey] as $dependentKey) {
                // "Remove" the dependency edge
                $inDegree[$dependentKey]--;

                // If the dependent entity now has 0 remaining dependencies, queue it up
                if ($inDegree[$dependentKey] === 0) {
                    $queue[] = $dependentKey;
                }
            }
        }

        // 5. Check for circular dependencies
        if (count($sortedKeys) !== count($configs)) {
            // Find which entities still have lingering dependencies (they form a cycle)
            $cycles = array_keys(array_filter($inDegree, fn(int $degree) => $degree > 0));

            throw new RuntimeException(
                sprintf('Circular dependency detected among entity configurations: %s', implode(', ', $cycles))
            );
        }

        // 6. Rebuild the final array in the correct topological order
        $sortedConfigs = [];
        foreach ($sortedKeys as $key) {
            $sortedConfigs[$key] = $configs[$key];
        }

        return $sortedConfigs;
    }
}
