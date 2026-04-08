<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/generate-coverage-badge.php <clover.xml> <output.json>\n");

    exit(1);
}

$reportPath = $argv[1];
$outputPath = $argv[2];

if (!is_file($reportPath)) {
    fwrite(STDERR, sprintf("Coverage report not found: %s\n", $reportPath));

    exit(1);
}

$xml = simplexml_load_file($reportPath);
if (!$xml instanceof SimpleXMLElement) {
    fwrite(STDERR, sprintf("Unable to parse coverage report: %s\n", $reportPath));

    exit(1);
}

$projectMetrics = $xml->project?->metrics;
if (!$projectMetrics instanceof SimpleXMLElement) {
    fwrite(STDERR, "Coverage report is missing project metrics.\n");

    exit(1);
}

$elements = (int) ($projectMetrics['elements'] ?? 0);
$coveredElements = (int) ($projectMetrics['coveredelements'] ?? 0);

if ($elements < 1) {
    fwrite(STDERR, "Coverage report does not contain any measurable elements.\n");

    exit(1);
}

$coverage = ($coveredElements / $elements) * 100;

$badge = [
    'schemaVersion' => 1,
    'label' => 'coverage',
    'message' => sprintf('%.2f%%', $coverage),
    'color' => match (true) {
        $coverage >= 90 => 'brightgreen',
        $coverage >= 80 => 'yellowgreen',
        $coverage >= 70 => 'yellow',
        $coverage >= 60 => 'orange',
        default => 'red',
    },
];

$directory = dirname($outputPath);
if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
    fwrite(STDERR, sprintf("Unable to create badge directory: %s\n", $directory));

    exit(1);
}

$encoded = json_encode($badge, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents($outputPath, $encoded . PHP_EOL);

fwrite(STDOUT, sprintf("Coverage badge written to %s\n", $outputPath));
