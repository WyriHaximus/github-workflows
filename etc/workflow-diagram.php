<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

const WORKFLOW_REPOSITORY = 'WyriHaximus/github-workflows';
const WORKFLOW_BRANCH = 'main';

const ENTRY_POINTS = [
    'package.yaml',
    'package-release-management.yaml',
    'package-utils.yaml',
    'project.yaml',
    'project-release-management.yaml',
    'project-utils.yaml',
];

/** @return array<string, string> */
function buildWorkflowDiagrams(string $workflowsDir): array
{
    $edges = collectWorkflowGraph($workflowsDir, ENTRY_POINTS);
    $diagrams = [];

    foreach (ENTRY_POINTS as $entryPoint) {
        $mermaid = buildMermaidDiagram($edges, $entryPoint);
        $diagrams[$entryPoint] = <<<MARKDOWN
##### Workflow connections

```mermaid
{$mermaid}
```

MARKDOWN;
    }

    return $diagrams;
}

/** @return list<array{from: string, to: string, entryPoint: string, kind: string}> */
function collectWorkflowGraph(string $workflowsDir, array $entryPoints): array
{
    $edges = [];

    foreach ($entryPoints as $entryPoint) {
        $visited = [];
        $queue = [$entryPoint];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;
            $content = readWorkflow($workflowsDir, $current);

            if ($content === null) {
                continue;
            }

            foreach (parseWorkflowUses($content) as $target) {
                $edges[] = ['from' => $current, 'to' => $target, 'entryPoint' => $entryPoint, 'kind' => 'workflow'];

                if (!isset($visited[$target])) {
                    $queue[] = $target;
                }
            }

            foreach (parseWorkflowActions($content) as $action) {
                $edges[] = ['from' => $current, 'to' => $action, 'entryPoint' => $entryPoint, 'kind' => 'action'];
            }
        }
    }

    usort($edges, static fn (array $a, array $b): int => [$a['entryPoint'], $a['kind'], $a['from'], $a['to']] <=> [$b['entryPoint'], $b['kind'], $b['from'], $b['to']]);

    return $edges;
}

function readWorkflow(string $workflowsDir, string $filename): ?string
{
    $path = $workflowsDir . '/' . $filename;

    return is_file($path) ? file_get_contents($path) : null;
}

/** @return list<string> */
function parseWorkflowUses(string $content): array
{
    $uses = [];

    foreach (Yaml::parse($content)['jobs'] ?? [] as $job) {
        if (isset($job['uses']) && is_string($job['uses']) && str_starts_with($job['uses'], './.github/workflows/')) {
            $uses[] = basename($job['uses']);
        }
    }

    sort($uses);

    return array_values(array_unique($uses));
}

/** @return list<string> */
function parseWorkflowActions(string $content): array
{
    if (!preg_match_all(
        '/^\s+(?:-\s+)?uses:\s*["\']?([^"\'#\n]+)["\']?\s*(?:#\s*(v[\d.]+|[\d.]+))?\s*$/mi',
        $content,
        $matches,
        PREG_SET_ORDER,
    )) {
        return [];
    }

    $actions = [];

    foreach ($matches as $match) {
        $reference = trim($match[1], " \t\n\r\0\x0B\"'");

        if (str_starts_with($reference, './.github/workflows/')) {
            continue;
        }

        $actions[] = actionLabel($reference, $match[2] ?? null);
    }

    sort($actions);

    return array_values(array_unique($actions));
}

function actionLabel(string $reference, ?string $version): string
{
    if ($version !== null && $version !== '') {
        return explode('@', $reference, 2)[0] . '@' . $version;
    }

    if (!str_contains($reference, '@')) {
        return $reference;
    }

    [$action, $ref] = explode('@', $reference, 2);

    if (preg_match('/^v?\d/', $ref) === 1) {
        return $action . '@' . $ref;
    }

    if (strlen($ref) >= 40 && ctype_xdigit($ref)) {
        return $action . '@' . substr($ref, 0, 7);
    }

    return $reference;
}

/** @param list<array{from: string, to: string, entryPoint: string, kind: string}> $edges */
function buildMermaidDiagram(array $edges, string $entryPoint): string
{
    $lines = ['flowchart TB'];
    $workflowLinks = [];
    $actionLinks = [];
    $actionIds = [];
    $nodeLinks = [];
    $prefix = mermaidSlug($entryPoint);
    $edgeIndex = 0;

    foreach ($edges as $edge) {
        if ($edge['entryPoint'] !== $entryPoint) {
            continue;
        }

        $from = $prefix . '_' . mermaidSlug($edge['from']);
        $nodeLinks[$from] ??= workflowFileUrl($edge['from']);

        if ($edge['kind'] === 'action') {
            $actionIds[$edge['to']] ??= $prefix . '_a' . count($actionIds);
            $nodeLinks[$actionIds[$edge['to']]] ??= actionReleaseUrl($edge['to']);
            $lines[] = sprintf('  %s["%s"] --> %s("%s")', $from, $edge['from'], $actionIds[$edge['to']], $edge['to']);
            $actionLinks[] = $edgeIndex;
        } else {
            $to = $prefix . '_' . mermaidSlug($edge['to']);
            $nodeLinks[$to] ??= workflowFileUrl($edge['to']);
            $lines[] = sprintf('  %s["%s"] --> %s["%s"]', $from, $edge['from'], $to, $edge['to']);
            $workflowLinks[] = $edgeIndex;
        }

        $edgeIndex++;
    }

    foreach ([[$workflowLinks, '#22c55e'], [$actionLinks, '#2563eb']] as [$indexes, $color]) {
        if ($indexes !== []) {
            $lines[] = sprintf('  linkStyle %s stroke:%s,stroke-width:2px', implode(',', $indexes), $color);
        }
    }

    ksort($nodeLinks);

    foreach ($nodeLinks as $nodeId => $url) {
        if ($url !== null) {
            $lines[] = sprintf('  click %s "%s" _blank', $nodeId, $url);
        }
    }

    return implode("\n", $lines);
}

function workflowFileUrl(string $filename): string
{
    return sprintf(
        'https://github.com/%s/blob/%s/.github/workflows/%s',
        WORKFLOW_REPOSITORY,
        WORKFLOW_BRANCH,
        rawurlencode($filename),
    );
}

function actionReleaseUrl(string $label): ?string
{
    $at = strrpos($label, '@');
    if ($at === false || !str_contains(substr($label, 0, $at), '/')) {
        return null;
    }

    [$owner, $repo] = explode('/', substr($label, 0, $at), 2);
    $ref = substr($label, $at + 1);
    $base = "https://github.com/{$owner}/{$repo}";

    return preg_match('/^[a-f0-9]{7,40}$/i', $ref) === 1
        ? "{$base}/commit/{$ref}"
        : "{$base}/releases/tag/{$ref}";
}

function mermaidSlug(string $name): string
{
    return str_replace(['.', '-'], '_', pathinfo($name, PATHINFO_FILENAME));
}
