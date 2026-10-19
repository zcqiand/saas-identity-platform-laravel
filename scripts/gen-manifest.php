<?php

declare(strict_types=1);

// OpenAPI -> API manifest generator (profiles/codegen.md section 1, laravel row).
//
// openapi-generator's php-laravel server generator was spike-vetted and
// rejected (ADR-0050) -> self-built: reads shared openapi.yaml, emits
// generated/api_manifest.json:
//   { "schema": 1, "operations": [ { operationId, method, path, tag,
//       requestSchema, responseSchema, requiredFields } ] }
//
// Deterministic output (L4.codegen.idempotent compares regen bytes):
// - ksort on every object; operations sorted by [tag, operationId]
// - no timestamps; explicit LF (no CRLF even on Windows); UTF-8
//
// Port of rails scripts/gen-manifest.rb (same semantics, PHP syntax).
//
// Usage: php scripts/gen-manifest.php <openapi.yaml> [--out DIR]
//   --out DIR  output dir (default generated); kept for future tmpout mode
//              (the idempotency checker currently re-runs in place and diffs)

require __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

const DEFAULT_OUT = 'generated';
const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete'];

$args = array_slice($argv, 1);
$openapiPath = $args[0] ?? null;
if ($openapiPath === null) {
    fwrite(STDERR, "usage: gen-manifest.php <openapi.yaml> [--out DIR]\n");
    exit(1);
}
$outDir = DEFAULT_OUT;
for ($i = 1, $n = count($args); $i < $n; $i++) {
    if ($args[$i] === '--out') {
        $outDir = $args[++$i] ?? '';
        if ($outDir === '') {
            fwrite(STDERR, "--out needs a directory argument\n");
            exit(1);
        }
    }
}

$spec = Yaml::parseFile($openapiPath);
if (! isset($spec['paths']) || ! is_array($spec['paths'])) {
    fwrite(STDERR, "ERROR: {$openapiPath} missing paths\n");
    exit(1);
}

$refTail = static function (?array $schema): ?string {
    $ref = $schema['$ref'] ?? null;
    if (! is_string($ref) || $ref === '') {
        return null;
    }
    $parts = explode('/', $ref);

    return (string) end($parts);
};

$operations = [];
foreach ($spec['paths'] as $path => $item) {
    if (! is_array($item)) {
        continue;
    }
    foreach ($item as $method => $op) {
        if (! in_array($method, HTTP_METHODS, true) || ! is_array($op)) {
            continue;
        }
        if (! isset($op['operationId']) || ! is_string($op['operationId']) || $op['operationId'] === '') {
            fwrite(STDERR, "ERROR: {$path} {$method} missing operationId\n");
            exit(1);
        }
        if (! isset($op['tags'][0]) || ! is_string($op['tags'][0]) || $op['tags'][0] === '') {
            fwrite(STDERR, "ERROR: {$path} {$method} missing tags.first\n");
            exit(1);
        }

        $requestSchema = $refTail($op['requestBody']['content']['application/json']['schema'] ?? null);
        $responseSchema = $refTail($op['responses']['200']['content']['application/json']['schema'] ?? null);

        // requiredFields: the request schema's required list (default [])
        $requiredFields = [];
        if ($requestSchema !== null) {
            $requiredFields = $spec['components']['schemas'][$requestSchema]['required'] ?? [];
        }

        $entry = [
            'operationId' => $op['operationId'],
            'method' => strtoupper($method),
            'path' => $path,
            'tag' => $op['tags'][0],
            'requestSchema' => $requestSchema,
            'responseSchema' => $responseSchema,
            'requiredFields' => $requiredFields,
        ];
        ksort($entry);
        $operations[] = $entry;
    }
}

usort($operations, static fn (array $a, array $b): int => [$a['tag'], $a['operationId']] <=> [$b['tag'], $b['operationId']]);

$manifest = ['schema' => 1, 'operations' => $operations];
ksort($manifest);

$outPath = rtrim($outDir, '/\\').'/api_manifest.json';
if (! is_dir(dirname($outPath))) {
    mkdir(dirname($outPath), 0777, true);
}
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    fwrite(STDERR, 'ERROR: json_encode failed: '.json_last_error_msg()."\n");
    exit(1);
}
// "\n" only + binary-safe write: no CRLF even on Windows (determinism)
$json .= "\n";
if (str_contains($json, "\r")) {
    fwrite(STDERR, "ERROR: CR byte in output, refusing to write (determinism)\n");
    exit(1);
}
file_put_contents($outPath, $json);
echo '[gen-manifest] '.count($operations)." operations -> {$outPath}\n";
