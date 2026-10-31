<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductDetailResource extends JsonResource
{
    use FormatsMedia;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'has_variants' => $this->has_variants,
            'is_active' => $this->is_active,

            // Content
            'short_description' => $this->short_description,
            'description' => $this->description,
            'material' => $this->material,

            // Pricing
            'price' => $this->shopee_price,
            'mrp' => $this->shopee_mrp,
            'shopee_url' => $this->shopee_url,
            // 'offer_price' => $this->when(isset($this->offer_price), fn () => $this->offer_price),
            // 'has_offer'   => isset($this->offer) && $this->offer !== null,
            // 'offer'       => $this->when(isset($this->offer) && $this->offer !== null, fn () => [
            //     'id'          => $this->offer->id,
            //     'name'        => $this->offer->name,
            //     'slug'        => $this->offer->slug,
            //     'description' => $this->offer->description,
            //     'offer_type'  => $this->offer->offer_type,
            // ]),

            // Media
            'thumbnail_url' => $this->imageUrl($this->thumbnail_image),
            'image_urls' => $this->imageUrls($this->images),
            'size_chart_url' => $this->imageUrl($this->size_chart_image),

            // Relations (stay omitted unless eager loaded)
            'category' => $this->whenLoaded('category', fn() => new CategoryResource($this->category)),

            // Variants -> ProductResource carries size, color, prices, free products
            'children' => $this->whenLoaded('children', fn () => ProductResource::collection($this->children)),

            'specifications' => $this->whenLoaded('specifications', fn() => new ProductSpecificationResource($this->specifications)),

            // Gift offer extras (set by the controller when the product is under an offer)
            // 'free_products' => $this->when(isset($this->freeProducts), fn () => ProductResource::collection($this->freeProducts)),

            // Extra fields you can uncomment when needed
            // 'brand'            => $this->whenLoaded('brand', fn () => $this->brand ? ['id' => $this->brand->id, 'name' => $this->brand->name] : null),
            // 'weight'           => $this->weight,
            // 'width'            => $this->width,
            // 'height'           => $this->height,
            // 'length'           => $this->length,
            // 'meta_title'       => $this->meta_title,
            // 'meta_description' => $this->meta_description,
            // 'meta_keywords'    => $this->meta_keywords,
            // 'meta_image'       => $this->imageUrl($this->meta_image),
            // 'created_at'       => $this->created_at,
        ];
    }
}
