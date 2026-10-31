<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    use FormatsMedia;

    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'slug'      => $this->slug,
            'is_active' => $this->is_active,

            // Media
            'icon'      => $this->imageUrl($this->icon),

            // Relations (stay omitted unless eager loaded)
            // 'category_type' => $this->whenLoaded('category_type', fn () => [
            //     'id'   => $this->category_type->id,
            //     'name' => $this->category_type->name,
            //     'code' => $this->category_type->code,
            // ]),
            'subcategories' => $this->whenLoaded('subcategory', fn () => self::collection($this->subcategory)),

            // SEO (optional, drop or keep the keys you want)
            // 'meta_title'       => $this->meta_title,
            // 'meta_description' => $this->meta_description,
            // 'meta_keywords'    => $this->meta_keywords,
            // 'meta_image'       => $this->imageUrl($this->meta_image),
        ];
    }
}
