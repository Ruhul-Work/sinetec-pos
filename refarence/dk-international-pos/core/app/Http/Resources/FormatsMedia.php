<?php

namespace App\Http\Resources;

trait FormatsMedia
{
    protected function imageUrl(?string $path): ?string
    {
        return ! empty($path) ? asset($path) : null;
    }

    protected function imageUrls(?string $json): array
    {
        $paths = $json ? json_decode($json, true) : [];

        if (! is_array($paths)) {
            return [];
        }

        return array_values(array_map(fn ($path) => $this->imageUrl($path), $paths));
    }
}
