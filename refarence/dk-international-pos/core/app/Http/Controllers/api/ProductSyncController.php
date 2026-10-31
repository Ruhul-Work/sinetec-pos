<?php
namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\backend\Brand;
use App\Models\backend\Category;
use App\Models\backend\CategoryType;
use App\Models\backend\Color;
use App\Models\backend\PaperQuality;
use App\Models\backend\Product;
use App\Models\backend\ProductSpecification;
use App\Models\backend\ProductType;
use App\Models\backend\Size;
use App\Models\backend\Subcategory;
use App\Models\backend\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductSyncController extends Controller
{
    public function syncStore(Request $request)
    {
        return $this->syncProduct($request);
    }

    public function syncProduct(Request $request)
    {
        $expectedToken = env('APP2_API_TOKEN') ?: env('SYNC_API_TOKEN');

        if ($request->bearerToken() !== $expectedToken) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'name'               => ['required', 'string', 'max:150'],
            'sku'                => ['required', 'string', 'max:150'],
            'slug'               => ['nullable', 'string', 'max:150'],
            'barcode'            => ['nullable', 'string', 'max:150'],
            'parent_id'          => ['nullable', 'integer'],

            'category_type_name' => ['nullable', 'string', 'max:150'],
            'category_name'      => ['required', 'string', 'max:150'],
            'subcategory_name'   => ['nullable', 'string', 'max:150'],
            'product_type_name'  => ['nullable', 'string', 'max:150'],
            'brand_name'         => ['required', 'string', 'max:150'],
            'unit_name'          => ['nullable', 'string', 'max:150'],
            'color_name'         => ['nullable', 'string', 'max:150'],
            'size_name'          => ['nullable', 'string', 'max:150'],
            'paper_name'         => ['nullable', 'string', 'max:150'],

            'is_active'          => ['nullable', 'integer'],
            'has_variant'        => ['nullable', 'integer'],
            'material'           => ['nullable', 'string', 'max:150'],
            'weight'             => ['nullable', 'numeric'],
            'weight_gsm'         => ['nullable', 'numeric'],
            'fabric_weave'       => ['nullable', 'string', 'max:150'],
            'fabric_type'        => ['nullable', 'string', 'max:150'],
            'origin_country'     => ['nullable', 'string', 'max:150'],
            'care_instructions'  => ['nullable', 'string', 'max:250'],
            'durability_rating'  => ['nullable', 'string', 'max:150'],
            'transparency_text'  => ['nullable', 'string', 'max:150'],
            'description'        => ['nullable', 'string', 'max:350'],
            'short_description'  => ['nullable', 'string', 'max:250'],
            'meta_title'         => ['nullable', 'string', 'max:150'],
            'meta_description'   => ['nullable', 'string', 'max:350'],
            'meta_keywords'      => ['nullable', 'string', 'max:250'],

            'category_image'     => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'image'              => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'images'             => ['nullable', 'array'],
            'images.*'           => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'thumbnail_image'    => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'size_chart_image'   => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'meta_image'         => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ]);

        $categoryTypeId = $this->resolveOrCreate(CategoryType::class, $data['category_type_name'] ?? null, 'code');
        $categoryId     = $this->resolveOrCreate(Category::class, $data['category_name'] ?? null, 'slug');

        if ($categoryId && $request->hasFile('category_image')) {
            $catImagePath = uploadImage($request->file('category_image'), 'category/icons');
            Category::where('id', $categoryId)->update(['icon' => $catImagePath]);
        }

        $subcategoryId = $this->resolveSubcategory($data['subcategory_name'] ?? null, $categoryId);
        $productTypeId = $this->resolveOrCreate(ProductType::class, $data['product_type_name'] ?? null, 'code');
        $brandId       = $this->resolveOrCreate(Brand::class, $data['brand_name'] ?? null, 'slug');
        $unitId        = $this->resolveOrCreate(Unit::class, $data['unit_name'] ?? null, 'code');
        $colorId       = $this->resolveOrCreate(Color::class, $data['color_name'] ?? null, 'code');
        $sizeId        = $this->resolveOrCreate(Size::class, $data['size_name'] ?? null, 'code');
        $paperId       = $this->resolveOrCreate(PaperQuality::class, $data['paper_name'] ?? null, 'code');

        if (! $unitId) {
            $unitId = Unit::query()->value('id');
        }

        $imagePaths    = $this->collectImages($request);
        $thumbnailPath = $request->hasFile('thumbnail_image')
            ? uploadImage($request->file('thumbnail_image'), 'product/thumbnail_images')
            : null;
        $sizeChartPath = $request->hasFile('size_chart_image')
            ? uploadImage($request->file('size_chart_image'), 'product/size_chart_images')
            : null;
        $metaImagePath = $request->hasFile('meta_image')
            ? uploadImage($request->file('meta_image'), 'product/meta_images')
            : null;

        $product = DB::transaction(function () use (
            $data,
            $categoryTypeId,
            $categoryId,
            $subcategoryId,
            $productTypeId,
            $brandId,
            $unitId,
            $colorId,
            $sizeId,
            $paperId,
            $imagePaths,
            $thumbnailPath,
            $sizeChartPath,
            $metaImagePath
        ) {
            $payload = [
                'name'              => ucwords($data['name']),
                'slug'              => $data['slug'] ?? Str::slug($data['name'] . '-' . $data['sku']),
                'sku'               => $data['sku'],
                'barcode'           => $data['barcode'] ?? $data['sku'],
                'parent_id'         => $data['parent_id'] ?? null,
                'has_variants'      => (int) ($data['has_variant'] ?? 0),
                'is_sellable'       => (int) (($data['has_variant'] ?? 0) ? 0 : 1),

                'unit_id'           => $unitId,
                'brand_id'          => $brandId,
                'product_type_id'   => $productTypeId,
                'category_type_id'  => $categoryTypeId,
                'category_id'       => $categoryId,
                'subcategory_id'    => $subcategoryId,
                'color_id'          => $colorId,
                'size_id'           => $sizeId,
                'paper_id'          => $paperId,

                'track_stock'       => true,
                'image'             => $imagePaths[0] ?? null,
                'images'            => ! empty($imagePaths) ? json_encode($imagePaths) : null,
                'thumbnail_image'   => $thumbnailPath,
                'size_chart_image'  => $sizeChartPath,
                'meta_image'        => $metaImagePath,
                'material'          => $data['material'] ?? null,
                'meta_title'        => $data['meta_title'] ?? null,
                'meta_description'  => $data['meta_description'] ?? null,
                'meta_keywords'     => $data['meta_keywords'] ?? null,
                'short_description' => $data['short_description'] ?? null,
                'description'       => $data['description'] ?? null,
            ];

            if ($data['has_variant'] ?? 0) {
                $payload['is_sellable'] = 0;
            }

            $product = Product::firstOrNew(['sku' => $data['sku']]);

            // DK controls these commercial fields after the first sync. Keep the
            // local values intact when Smart later updates the same SKU.
            if (! $product->exists) {
                $payload = array_merge($payload, [
                    'price'          => 0,
                    'cost_price'     => null,
                    'mrp'            => null,
                    'discount_type'  => null,
                    'discount_value' => null,
                    'is_active'      => 0,
                ]);
            }

            $product->fill($payload);
            $product->save();

            $specification = ProductSpecification::firstOrNew([
                'product_id' => $product->id,
            ]);

            $specification->material          = $data['material'] ?? null;
            $specification->weight_gsm        = $data['weight_gsm'] ?? $data['weight'] ?? null;
            $specification->fabric_weave      = $data['fabric_weave'] ?? null;
            $specification->fabric_type       = $data['fabric_type'] ?? null;
            $specification->origin_country    = $data['origin_country'] ?? null;
            $specification->care_instructions = $data['care_instructions'] ?? null;
            $specification->durability_rating = $data['durability_rating'] ?? null;
            $specification->transparency_text = $data['transparency_text'] ?? null;
            $specification->save();

            return $product;
        });

        $product->load(['category', 'subcategory', 'brand', 'unit', 'product_type', 'category_type', 'color', 'size', 'paper_quality', 'specifications']);

        return response()->json([
            'success' => true,
            'message' => $product->wasRecentlyCreated
                ? 'Product synced successfully'
                : 'Product sync updated successfully',
            'data'    => $product,
        ], $product->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Name diye lookup table e khoje (case-insensitive), na pele slug/code soho notun toiri kore, id ferot dey.
     */
    private function resolveOrCreate(string $modelClass, ?string $name, string $keyType = 'slug'): ?int
    {
        if (empty($name)) {
            return null;
        }

        $name = trim($name);

        $record = $modelClass::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();

        if ($record) {
            return $record->id;
        }

        $payload = ['name' => $name];

        if ($keyType === 'slug') {
            $payload['slug'] = $this->uniqueSlug($modelClass, $name);
        } elseif ($keyType === 'code') {
            $payload['code'] = $this->uniqueCode($modelClass, $name);
        }

        $record = $modelClass::create($payload);

        return $record->id;
    }

    private function resolveSubcategory(?string $name, ?int $categoryId): ?int
    {
        if (empty($name)) {
            return null;
        }

        $name = trim($name);

        $record = Subcategory::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();

        if ($record) {
            return $record->id;
        }

        $record = Subcategory::create([
            'name'        => $name,
            'slug'        => $this->uniqueSlug(Subcategory::class, $name),
            'category_id' => $categoryId,
        ]);

        return $record->id;
    }

    private function collectImages(Request $request): array
    {
        $paths = [];

        if ($request->hasFile('images')) {
            foreach ((array) $request->file('images') as $file) {
                if ($file) {
                    $paths[] = uploadImage($file, 'product/images');
                }
            }
        }

        if ($request->hasFile('image')) {
            $paths[] = uploadImage($request->file('image'), 'product/images');
        }

        $paths = array_values(array_filter(array_unique($paths)));

        return $paths;
    }

    private function uniqueSlug(string $modelClass, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 1;

        while ($modelClass::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    private function uniqueCode(string $modelClass, string $name): string
    {
        $base = Str::upper(Str::slug($name, '_'));
        $code = $base;
        $i    = 1;

        while ($modelClass::where('code', $code)->exists()) {
            $code = $base . '_' . $i;
            $i++;
        }

        return $code;
    }
}
