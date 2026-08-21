<?php

/**
 * Coverage gate.
 *
 * Fails the build when a source file that is expected to be fully covered has grown an
 * uncovered line.  Line coverage alone is a blunt gate — it drifts silently — so the
 * check is per file: every file must be complete except the few whose remaining lines
 * are unreachable from a test for a stated reason (see AGENTS.md), and those must keep
 * exactly the count recorded here.  A new gap anywhere else fails, and so does a file
 * that quietly gains or loses one.
 *
 * Usage: php tests/test_tools/coverage-gate.php <clover.xml> [minimum-line-percent]
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

/**
 * The knowingly-unreachable lines, per file.  Each is justified in AGENTS.md; they are
 * counted, not listed by line number, so ordinary edits above them do not break the gate.
 */
const ALLOWED_UNCOVERED = [
	'src/IO/Compression/TCCITTFaxCompressor.php' => 1,
	'src/IO/Image/Meta/JUMBF/TJUMBFBox.php' => 2,
	'src/IO/Image/TImageGraphicsGD.php' => 1,
	'src/IO/Image/TImageGraphicsImagick.php' => 1,
];

$clover = $argv[1] ?? 'build/logs/clover.xml';
$minimum = (float) ($argv[2] ?? 99.9);

if (!is_file($clover)) {
	fwrite(STDERR, "coverage gate: no clover report at {$clover}\n");
	exit(2);
}

$xml = simplexml_load_file($clover);
if ($xml === false) {
	fwrite(STDERR, "coverage gate: {$clover} is not readable XML\n");
	exit(2);
}

$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$total = $covered = 0;
$uncovered = [];

foreach ($xml->xpath('//file') as $file) {
	$path = str_replace($root, '', (string) $file['name']);
	foreach ($file->line as $line) {
		if ((string) $line['type'] !== 'stmt') {
			continue;
		}
		$total++;
		if ((int) $line['count'] > 0) {
			$covered++;
		} else {
			$uncovered[$path][] = (int) $line['num'];
		}
	}
}

if ($total === 0) {
	fwrite(STDERR, "coverage gate: the report contains no statements\n");
	exit(2);
}

$percent = $covered / $total * 100;
$failures = [];

foreach ($uncovered as $path => $lines) {
	$allowed = ALLOWED_UNCOVERED[$path] ?? 0;
	if (count($lines) !== $allowed) {
		$failures[] = sprintf(
			'  %s has %d uncovered line(s) %s, expected %d',
			$path,
			count($lines),
			'[' . implode(', ', $lines) . ']',
			$allowed,
		);
	}
}

foreach (ALLOWED_UNCOVERED as $path => $allowed) {
	if (!isset($uncovered[$path])) {
		$failures[] = sprintf(
			'  %s is now fully covered; drop its entry from the gate (expected %d uncovered)',
			$path,
			$allowed,
		);
	}
}

if ($percent < $minimum) {
	$failures[] = sprintf('  line coverage %.2f%% is below the %.2f%% minimum', $percent, $minimum);
}

printf("Coverage: %.2f%% of lines (%d/%d)\n", $percent, $covered, $total);

if ($failures !== []) {
	fwrite(STDERR, "Coverage gate FAILED:\n" . implode("\n", $failures) . "\n");
	exit(1);
}

echo "Coverage gate passed: every file is complete except the documented exceptions.\n";
