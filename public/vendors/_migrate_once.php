<?php

declare(strict_types=1);

// ONE-OFF maintenance script, not part of the app's permanent surface -- pushed, run once
// against production via HTTP, then deleted and the deletion pushed in a follow-up commit.
// Same pattern as the earlier `diag` probe in api.php (added, used once, fully removed).
//
// Purpose: snapshots created before this session's "timestamped default label" fix got a
// default label of substr(gmdate('c'), 0, 10) -- a plain UTC date, e.g. "2026-10-20". This
// rewrites exactly those (and only those) default-looking labels to the new format: the same
// createdAt, converted to Asia/Tbilisi and formatted as Y-m-d H:i:s. A label is touched only
// when it exactly equals the UTC date of its own createdAt, so a vendor's custom label is
// never touched by accident.
//
// Usage: GET /vendors/_migrate_once.php            -> dry run, lists what would change
//        GET /vendors/_migrate_once.php?confirm=yes -> applies the changes

header('Content-Type: text/plain; charset=utf-8');

$base = sys_get_temp_dir() . '/volta-vendors/vendors';
$vendorsFile = $base . '/vendors.json';

function readJson(string $path, $fallback)
{
    if (!is_file($path)) {
        return $fallback;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return $fallback;
    }
    $data = json_decode($raw, true);
    return $data === null ? $fallback : $data;
}

function writeJson(string $path, $data): void
{
    $bytes = file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    if ($bytes === false) {
        throw new RuntimeException('Failed to write ' . $path);
    }
}

function isOldDefaultLabel(string $label, string $createdAt): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $label) && substr($createdAt, 0, 10) === $label;
}

function newLabelFor(string $createdAt): string
{
    return (new DateTimeImmutable($createdAt))->setTimezone(new DateTimeZone('Asia/Tbilisi'))->format('Y-m-d H:i:s');
}

$apply = ($_GET['confirm'] ?? '') === 'yes';
$vendorsData = readJson($vendorsFile, ['vendors' => []]);
$vendors = $vendorsData['vendors'] ?? [];

$report = [];
foreach ($vendors as $v) {
    $vendorId = $v['id'];
    $indexFile = $base . '/snapshots/' . $vendorId . '/index.json';
    $idx = readJson($indexFile, ['snapshots' => []]);
    $snaps = $idx['snapshots'] ?? [];
    $indexChanged = false;

    foreach ($snaps as $i => $s) {
        if (!isOldDefaultLabel($s['label'], $s['createdAt'])) {
            continue;
        }
        $newLabel = newLabelFor($s['createdAt']);
        $report[] = [
            'vendor' => $v['name'], 'vendorId' => $vendorId, 'snapshotId' => $s['id'],
            'oldLabel' => $s['label'], 'newLabel' => $newLabel,
        ];
        if ($apply) {
            $snaps[$i]['label'] = $newLabel;
            $indexChanged = true;

            $snapFile = $base . '/snapshots/' . $vendorId . '/' . $s['id'] . '.json';
            $full = readJson($snapFile, null);
            if ($full !== null) {
                $full['label'] = $newLabel;
                writeJson($snapFile, $full);
            }
        }
    }

    if ($indexChanged) {
        $idx['snapshots'] = $snaps;
        writeJson($indexFile, $idx);
    }
}

echo $apply ? "APPLIED\n" : "DRY RUN (add ?confirm=yes to apply)\n";
echo count($report) . " snapshot(s) " . ($apply ? "updated" : "would be updated") . ":\n\n";
foreach ($report as $r) {
    echo "- {$r['vendor']} / {$r['snapshotId']}: \"{$r['oldLabel']}\" -> \"{$r['newLabel']}\"\n";
}
