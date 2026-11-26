<?php

namespace App\Services\Checkout;

use App\Models\Media;
use App\Models\Product;
use App\Models\Variant;

final class CartLine
{
    public function __construct(
        public readonly int $id,
        public readonly ?Product $product,
        public readonly ?Variant $variant,
        public readonly string $name,
        public readonly ?string $variantLabel,
        public readonly int $unitCents,
        public readonly int $quantity,
        public readonly int $lineTotalCents,
        public readonly ?int $available,
        public readonly ?string $issue,
        public readonly ?Media $image,
    ) {}
}
