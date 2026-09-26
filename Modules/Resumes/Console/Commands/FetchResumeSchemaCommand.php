<?php

namespace Modules\Resumes\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Regenerates the vendored resume schema: fetches types.json +
 * schema.json from baradhili/resume-schema and merges the type
 * definitions into the main schema (only the referenced ones),
 * rewriting external $refs to internal ones. Developer utility —
 * the output is committed with the module so uploads never need
 * the network.
 */
class FetchResumeSchemaCommand extends Command
{
    protected $signature = 'resumes:fetch-schema
                            {--force : Overwrite the existing schema file}
                            {--branch=master : Git branch to fetch from}';

    protected $description = 'Fetch and merge the (modified) JSON Resume schema into the module';

    protected string $repoOwner = 'baradhili';

    protected string $repoName = 'resume-schema';

    protected string $baseUrl = 'https://raw.githubusercontent.com';

    protected array $schemaFiles = [
        'types' => 'types.json',
        'main' => 'schema.json',
    ];

    protected const DEFS_KEY_OLD = 'definitions';

    protected const DEFS_KEY_NEW = '$defs';

    public function handle(): int
    {
        $this->info('Fetching JSON Resume schema files from GitHub...');

        $branch = $this->option('branch');
        $force = (bool) $this->option('force');

        $outputPath = config('resumes.schema');
        File::ensureDirectoryExists(dirname($outputPath));

        $schemas = [];
        foreach ($this->schemaFiles as $key => $filename) {
            $url = "{$this->baseUrl}/{$this->repoOwner}/{$this->repoName}/refs/heads/{$branch}/{$filename}";

            $this->line("  Fetching {$filename}...");

            $response = Http::timeout(30)->get($url);

            if (! $response->successful()) {
                $this->error("  Failed to fetch {$filename}: HTTP {$response->status()}");

                return Command::FAILURE;
            }

            $schemas[$key] = $response->json();
            $this->line("  Loaded {$filename}");
        }

        $this->line('Merging schemas (types.json into schema.json)...');
        $merged = $this->mergeSchemas($schemas['types'], $schemas['main']);

        $merged['$comment'] = "Merged from {$this->repoOwner}/{$this->repoName} on ".now()->toIso8601String();
        $merged['$mergedAt'] = now()->toIso8601String();
        $merged['$sourceFiles'] = ['types.json', 'schema.json'];

        if (File::exists($outputPath) && ! $force) {
            if (! $this->confirm('Schema already exists. Overwrite?')) {
                $this->warn('Skipped. Use --force to overwrite.');

                return Command::SUCCESS;
            }
        }

        File::put($outputPath, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('Schema merged and saved to: '.$outputPath);

        return Command::SUCCESS;
    }

    /**
     * Merge types.json definitions into schema.json, including only used refs.
     * Replicates the resume-schema merge_schemas() logic.
     */
    protected function mergeSchemas(array $typesSchema, array $schema): array
    {
        $typesFilename = 'types.json';

        $typesSchemaVer = $typesSchema['$schema'] ?? '';
        $schemaVer = $schema['$schema'] ?? '';

        if ($typesSchemaVer !== $schemaVer) {
            $this->error('Schema version mismatch between schema.json and types.json');
            $this->error("  schema.json: {$schemaVer}");
            $this->error("  types.json:  {$typesSchemaVer}");

            exit(1);
        }

        $defKeyname = str_contains($typesSchemaVer, 'draft/2020-12/schema')
            ? self::DEFS_KEY_NEW
            : self::DEFS_KEY_OLD;
        $defOutKeyname = $defKeyname;

        $allDefs = $typesSchema[$defKeyname] ?? [];

        $usedRefs = [];
        $this->collectRefs($schema, $typesFilename, $defKeyname, $usedRefs);

        $usedDefs = [];
        foreach ($usedRefs as $defName) {
            if (isset($allDefs[$defName])) {
                $usedDefs[$defName] = $allDefs[$defName];
            }
        }

        $this->line('  Found '.count($usedRefs).' referenced definitions');
        $this->line('  Including '.count($usedDefs).' definitions in merged schema');

        if (isset($schema[$defKeyname])) {
            unset($schema[$defKeyname]);
        }
        $schema[$defOutKeyname] = $usedDefs;

        $this->fixRefs($schema, $typesFilename, $defKeyname, $defOutKeyname);

        return $schema;
    }

    /**
     * Recursively collect all $ref values that point to types.json definitions.
     */
    protected function collectRefs(mixed $obj, string $typesFilename, string $defKeyname, array &$usedRefs): void
    {
        if (is_array($obj)) {
            if (isset($obj['$ref']) && is_string($obj['$ref'])) {
                $refVal = $obj['$ref'];
                $prefix = "{$typesFilename}#/{$defKeyname}/";

                if (str_starts_with($refVal, $prefix)) {
                    $defName = substr($refVal, strlen($prefix));
                    if (! in_array($defName, $usedRefs)) {
                        $usedRefs[] = $defName;
                    }
                }
            }

            foreach ($obj as $value) {
                $this->collectRefs($value, $typesFilename, $defKeyname, $usedRefs);
            }
        }
    }

    /**
     * Recursively convert external refs to internal refs.
     */
    protected function fixRefs(mixed &$obj, string $typesFilename, string $defKeyname, string $defOutKeyname): void
    {
        if (is_array($obj)) {
            if (isset($obj['$ref']) && is_string($obj['$ref'])) {
                $refVal = $obj['$ref'];
                $prefix = "{$typesFilename}#/{$defKeyname}/";

                if (str_starts_with($refVal, $prefix)) {
                    $defName = substr($refVal, strlen($prefix));
                    $obj['$ref'] = "#/{$defOutKeyname}/{$defName}";
                }
            }

            foreach ($obj as &$value) {
                $this->fixRefs($value, $typesFilename, $defKeyname, $defOutKeyname);
            }
            unset($value);
        }
    }
}
