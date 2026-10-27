<?php

declare(strict_types=1);

namespace Volta\Funnel;

/**
 * Plain-JSON-file storage for the vendor price-comparison tool (reporting.volta.ge/vendors/).
 * Lives under data/ (git-ignored, outside public/), same convention as this project's other
 * cache files. No database, no Composer dependency -- consistent with this repo's "no
 * Composer, no build step" philosophy. Excel parsing happens in the browser (SheetJS); this
 * class only ever sees small, already-extracted JSON (vendor names, product rows), never a
 * raw uploaded file.
 */
final class VendorStore
{
    private string $dir;

    public function __construct(string $dataDir)
    {
        $this->dir = rtrim($dataDir, '/\\') . '/vendors';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        if (!is_dir($this->dir . '/snapshots')) {
            @mkdir($this->dir . '/snapshots', 0775, true);
        }
    }

    private function readJson(string $path, $fallback)
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

    /**
     * Writes are verified at every step and throw on failure -- this used to swallow errors
     * with @ and report success regardless, which is exactly how a vendor could "save"
     * successfully and then 404 on the very next read: mkdir()/file_put_contents() were
     * silently failing (confirmed live: data/ didn't exist and couldn't be created) while
     * createVendor() still returned the in-memory record as if it had been persisted.
     */
    private function writeJson(string $path, $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Storage directory does not exist and could not be created: $dir -- ask whoever manages the server to create it and make it writable by the web server user.");
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException("Storage directory is not writable: $dir -- ask whoever manages the server to fix its permissions.");
        }
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        $bytes = @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
        if ($bytes === false) {
            $err = error_get_last();
            throw new \RuntimeException('Failed to write ' . $tmp . ($err ? ': ' . $err['message'] : ''));
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to finalize write to $path");
        }
    }

    private function vendorsFile(): string
    {
        return $this->dir . '/vendors.json';
    }

    private function snapshotIndexFile(string $vendorId): string
    {
        return $this->dir . '/snapshots/' . $vendorId . '/index.json';
    }

    private function snapshotFile(string $vendorId, string $snapshotId): string
    {
        return $this->dir . '/snapshots/' . $vendorId . '/' . $snapshotId . '.json';
    }

    private static function isSafeId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id);
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(12));
    }

    // ---------- vendors ----------

    public function listVendors(): array
    {
        $data = $this->readJson($this->vendorsFile(), ['vendors' => []]);
        $vendors = $data['vendors'] ?? [];
        foreach ($vendors as &$v) {
            $idx = $this->readJson($this->snapshotIndexFile($v['id']), ['snapshots' => []]);
            $snaps = $idx['snapshots'] ?? [];
            usort($snaps, static fn ($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
            $v['snapshotCount'] = count($snaps);
            $v['lastSnapshotAt'] = $snaps[0]['createdAt'] ?? null;
            $v['lastSnapshotLabel'] = $snaps[0]['label'] ?? null;
        }
        usort($vendors, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $vendors;
    }

    public function createVendor(string $name): array
    {
        $data = $this->readJson($this->vendorsFile(), ['vendors' => []]);
        foreach ($data['vendors'] as $v) {
            // Plain strtolower(), not mb_strtolower(): Georgian has no letter case at all, so
            // this is sufficient for Georgian/English vendor names and doesn't depend on the
            // mbstring extension being installed (confirmed missing in at least one PHP build
            // this project runs under).
            if (strtolower($v['name']) === strtolower($name)) {
                throw new \RuntimeException('ასეთი სახელის ვენდორი უკვე არსებობს');
            }
        }
        $vendor = ['id' => self::newId(), 'name' => $name, 'createdAt' => gmdate('c'), 'mappingTemplates' => []];
        $data['vendors'][] = $vendor;
        $this->writeJson($this->vendorsFile(), $data);
        return $vendor;
    }

    public function getVendor(string $id): ?array
    {
        $data = $this->readJson($this->vendorsFile(), ['vendors' => []]);
        foreach ($data['vendors'] as $v) {
            if ($v['id'] === $id) {
                return $v;
            }
        }
        return null;
    }

    public function deleteVendor(string $id): void
    {
        $data = $this->readJson($this->vendorsFile(), ['vendors' => []]);
        $data['vendors'] = array_values(array_filter($data['vendors'], static fn ($v) => $v['id'] !== $id));
        $this->writeJson($this->vendorsFile(), $data);
        $snapDir = $this->dir . '/snapshots/' . $id;
        if (is_dir($snapDir)) {
            foreach (glob($snapDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($snapDir);
        }
    }

    public function updateMappingTemplates(string $vendorId, array $newTemplates): void
    {
        $data = $this->readJson($this->vendorsFile(), ['vendors' => []]);
        foreach ($data['vendors'] as &$v) {
            if ($v['id'] === $vendorId) {
                $v['mappingTemplates'] = array_merge($v['mappingTemplates'] ?? [], $newTemplates);
                break;
            }
        }
        $this->writeJson($this->vendorsFile(), $data);
    }

    // ---------- snapshots ----------

    public function listSnapshots(string $vendorId): array
    {
        $idx = $this->readJson($this->snapshotIndexFile($vendorId), ['snapshots' => []]);
        $snaps = $idx['snapshots'] ?? [];
        usort($snaps, static fn ($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
        return $snaps;
    }

    public function createSnapshot(string $vendorId, string $label, array $sheetsIncluded, array $products): array
    {
        $id = self::newId();
        $createdAt = gmdate('c');
        $meta = [
            'id' => $id, 'vendorId' => $vendorId, 'label' => $label !== '' ? $label : substr($createdAt, 0, 10),
            'createdAt' => $createdAt, 'sheetsIncluded' => $sheetsIncluded, 'productCount' => count($products),
        ];
        $full = $meta;
        $full['products'] = $products;
        $this->writeJson($this->snapshotFile($vendorId, $id), $full);

        $idx = $this->readJson($this->snapshotIndexFile($vendorId), ['snapshots' => []]);
        $idx['snapshots'][] = $meta;
        $this->writeJson($this->snapshotIndexFile($vendorId), $idx);
        return $meta;
    }

    public function getSnapshot(string $vendorId, string $snapshotId): ?array
    {
        if (!self::isSafeId($vendorId) || !self::isSafeId($snapshotId)) {
            return null;
        }
        return $this->readJson($this->snapshotFile($vendorId, $snapshotId), null);
    }

    public function deleteSnapshot(string $vendorId, string $snapshotId): void
    {
        $idx = $this->readJson($this->snapshotIndexFile($vendorId), ['snapshots' => []]);
        $idx['snapshots'] = array_values(array_filter($idx['snapshots'], static fn ($s) => $s['id'] !== $snapshotId));
        $this->writeJson($this->snapshotIndexFile($vendorId), $idx);
        $f = $this->snapshotFile($vendorId, $snapshotId);
        if (is_file($f)) {
            @unlink($f);
        }
    }
}
