<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/check-coverage.php <clover.xml> <minimum-percent>\n");

    exit(1);
}

$reportPath = $argv[1];
$minimumPercent = (float) $argv[2];

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
$coveredelements = (int) ($projectMetrics['coveredelements'] ?? 0);

if ($elements < 1) {
    fwrite(STDERR, "Coverage report does not contain any measurable elements.\n");

    exit(1);
}

$coverage = ($coveredelements / $elements) * 100;

fwrite(STDOUT, sprintf(
    "Overall line coverage: %.2f%% (required: %.2f%%)\n",
    $coverage,
    $minimumPercent,
));

if ($coverage + 0.00001 < $minimumPercent) {
    fwrite(STDERR, sprintf(
        "Coverage gate failed: %.2f%% is below %.2f%%.\n",
        $coverage,
        $minimumPercent,
    ));

    exit(1);
}

exit(0);
