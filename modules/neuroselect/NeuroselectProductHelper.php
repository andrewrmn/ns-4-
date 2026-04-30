<?php

namespace modules\neuroselect;

use craft\commerce\elements\Product;

/**
 * Build Your Own (product submissions): exclude products flagged with disableInNueroselect.
 */
final class NeuroselectProductHelper
{
    /**
     * @param int[]|string[] $ids Raw product IDs from request input
     * @return int[]
     */
    public static function filterAllowedProductIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static function ($id): int {
            return (int) $id;
        }, $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $products = Product::find()->id($ids)->all();
        $allowed = [];

        foreach ($products as $product) {
            if (!$product->disableInNueroselect) {
                $allowed[] = (int) $product->id;
            }
        }

        return $allowed;
    }

    /**
     * @param ?array<int|string, mixed> $productIds
     */
    public static function implodeFilteredProductIds(?array $productIds): string
    {
        $filtered = self::filterAllowedProductIds($productIds ?? []);

        return implode(', ', $filtered);
    }

    /**
     * @param mixed $param $_POST['products'] or API body (may be array or single scalar)
     * @return string Comma-separated allowed product IDs
     */
    public static function normalizeProductsPostParam(mixed $param): string
    {
        if ($param === null || $param === '') {
            return '';
        }

        if (!is_array($param)) {
            $param = [$param];
        }

        return self::implodeFilteredProductIds($param);
    }
}
