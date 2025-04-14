<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use function WyriHaximus\Twig\render;

require dirname(__DIR__) . '/vendor/autoload.php';

$template = file_get_contents(__DIR__ . '/README.md.twig');

$renderedReadme = render($template, [
    'package' => [
        'ci' => loadInputs(dirname(__DIR__) . '/.github/workflows/package.yaml'),
        'releaseManagement' => loadInputs(dirname(__DIR__) . '/.github/workflows/package-release-management.yaml'),
        'utils' => loadInputs(dirname(__DIR__) . '/.github/workflows/package-utils.yaml'),
    ],
    'project' => [
        'ci' => loadInputs(dirname(__DIR__) . '/.github/workflows/project.yaml'),
        'releaseManagement' => loadInputs(dirname(__DIR__) . '/.github/workflows/project-release-management.yaml'),
        'utils' => loadInputs(dirname(__DIR__) . '/.github/workflows/project-utils.yaml'),
    ],
]);

file_put_contents(dirname(__DIR__) . '/README.md', $renderedReadme);

function loadInputs(string $fileName): array
{
    $inputs = Yaml::parse(file_get_contents($fileName))['on']['workflow_call']['inputs'];
    ksort($inputs);

    return $inputs;
}
