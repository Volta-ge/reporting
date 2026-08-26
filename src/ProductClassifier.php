<?php

declare(strict_types=1);

namespace Volta\Funnel;

/**
 * Maps a raw `product_category.Category_Name` value to the business's own English
 * product-bucket names, using the lookup table pulled from the "mapping" sheet of the
 * business's own sales report ("MARIAM'S CATEGORY" mapping, product_mapping.json).
 *
 * Why this exists: `products.Category_ID` is unreliable for recent products — many point
 * at a category literally named "none" (never manually categorized in the DB), and even
 * where populated, the DB's category naming doesn't always match the business's own
 * Product/Category/Subcategory taxonomy 1:1. The mapping sheet is the closest thing to a
 * ground truth translation table available right now. See volta-logistics-daily /
 * volta-sales-monthly memory for the full investigation — confirmed exact-match against
 * the business's own report for a sample category+month (Smartphones, Jan 2026:
 * Sales=53010, Cogs=28967, Qty=34) using Order_Status = 5 as the "real sale" filter.
 */
final class ProductClassifier
{
    /** @var array<string, array{categoryGe: ?string, subcategoryGe: ?string, categoryEn: ?string, subcategoryEn: ?string, productEn: string}> */
    private array $map;

    public function __construct(?string $jsonPath = null)
    {
        $jsonPath ??= __DIR__ . '/product_mapping.json';
        $raw = json_decode((string) file_get_contents($jsonPath), true) ?? [];
        $this->map = [];
        foreach ($raw as $key => $value) {
            $this->map[trim((string) $key)] = $value;
        }
    }

    /**
     * Returns the English product-bucket name, or null if uncategorized/unmapped
     * (caller should bucket those as "Uncategorized").
     */
    public function classify(?string $rawCategoryName): ?string
    {
        return $this->lookup($rawCategoryName)['productEn'] ?? null;
    }

    /**
     * Same lookup as classify(), but returns the broader Subcategory(EN) bucket instead of the
     * specific Product(EN) one — e.g. "Air Fryer"/"Blender"/"Toaster" (all separate under
     * classify()) all return "Small Kitchen Appliances" here. Used by Subcategory Analyze.
     */
    public function classifySubcategory(?string $rawCategoryName): ?string
    {
        return $this->lookup($rawCategoryName)['subcategoryEn'] ?? null;
    }

    /**
     * Same lookup again, but the broadest bucket — Category(EN) — e.g. "Small Kitchen
     * Appliances" (subcategory) and "Air Fryer"/"Blender" (product) all roll up into "Small
     * Appliances" here. Note that despite its name, `classify()` actually returns the *finest*
     * grained bucket (Product(EN)) — this method is the one that returns the true top-level
     * category, used by the Category/Subcategory/Brand/Product income-and-delinquency tabs.
     */
    public function classifyCategory(?string $rawCategoryName): ?string
    {
        return $this->lookup($rawCategoryName)['categoryEn'] ?? null;
    }

    /**
     * @return array{categoryGe: ?string, subcategoryGe: ?string, categoryEn: ?string, subcategoryEn: ?string, productEn: string}|null
     */
    private function lookup(?string $rawCategoryName): ?array
    {
        if ($rawCategoryName === null) {
            return null;
        }
        $name = trim($rawCategoryName);
        if ($name === '' || $name === 'none') {
            return null;
        }
        if (isset($this->map[$name])) {
            return $this->map[$name];
        }
        // Some product_category rows store a compound "Category,Subcategory,Product" path —
        // try matching from the most specific (last) segment backward.
        $parts = array_filter(array_map('trim', explode(',', $name)));
        foreach (array_reverse($parts) as $part) {
            if (isset($this->map[$part])) {
                return $this->map[$part];
            }
        }
        return null;
    }
}
