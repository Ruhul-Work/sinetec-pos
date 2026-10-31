<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    use FormatsMedia;

    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'slug'         => $this->slug,
            'sku'          => $this->sku,
            'has_variants' => $this->has_variants,
            'is_active'    => $this->is_active,

            // Pricing
            'price'       => $this->shopee_price,
            'mrp'         => $this->shopee_mrp,
            'shopee_url' => $this->shopee_url,
            // 'offer_price' => $this->when(isset($this->offer_price), fn () => $this->offer_price),
            // 'has_offer'   => isset($this->offer) && $this->offer !== null,
            // 'offer'       => $this->when(isset($this->offer) && $this->offer !== null, fn () => [
            //     'id'         => $this->offer->id,
            //     'name'       => $this->offer->name,
            //     'offer_type' => $this->offer->offer_type,
            // ]),

            // Media
            'thumbnail_url' => $this->imageUrl($this->thumbnail_image),
            'image_urls'    => $this->imageUrls($this->images),

            // Relations (stay omitted unless eager loaded)
            'category' => $this->whenLoaded('category', fn () => new CategoryResource($this->category)),
            'color'    => $this->whenLoaded('color', fn () => $this->color ? [
                'id'   => $this->color->id,
                'name' => $this->color->name,
                'hex'  => $this->color->hex,
            ] : null),
            'size' => $this->whenLoaded('size', fn () => $this->size ? [
                'id'   => $this->size->id,
                'name' => $this->size->name,
                'code' => $this->size->code,
            ] : null),

            // Gift offer extras (set by the controller when the product is under an offer)
            // 'quantity'      => $this->when(isset($this->quantity), fn () => $this->quantity),
            // 'free_products' => $this->when(isset($this->freeProducts), fn () => self::collection($this->freeProducts)),

            // Extra fields you can uncomment when needed
            // 'barcode'            => $this->barcode,
            // 'material'           => $this->material,
            // 'short_description'  => $this->short_description,
            // 'description'        => $this->description,
            // 'size_chart_url'     => $this->imageUrl($this->size_chart_image),
            // 'created_at'         => $this->created_at,
        ];
    }
}
