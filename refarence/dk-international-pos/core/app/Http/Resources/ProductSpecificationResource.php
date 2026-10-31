<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductSpecificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'material'          => $this->material,
            'weight_gsm'        => $this->weight_gsm,
            'fabric_type'       => $this->fabric_type,
            'origin_country'    => $this->origin_country,
            'fabric_weave'      => $this->fabric_weave,
            'care_instructions' => $this->care_instructions,
            'durability_rating' => $this->durability_rating,
            'transparency_text' => $this->transparency_text,
        ];
    }
}
