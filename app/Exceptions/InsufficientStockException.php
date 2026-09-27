<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly int $variantId,
        public readonly int $requested,
        public readonly int $available,
        string $message = '',
    ) {
        parent::__construct($message ?: "規格 #{$variantId} 庫存不足：需要 {$requested}，目前可用 {$available}");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'variant_id' => $this->variantId,
            'requested' => $this->requested,
            'available' => $this->available,
        ], 422);
    }
}
