<?php
namespace App\Jobs;

use App\Models\backend\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncProductToSecondApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 5;
    public $backoff = [10, 30, 60, 120, 300];

    public function __construct(public int $productId)
    {}

    public function handle(): void
    {
        $parent = Product::find($this->productId);

        if (! $parent) {
            Log::warning('Sync skipped: parent product not found', ['id' => $this->productId]);
            return;
        }

        $app2ParentId = $this->syncSingleProduct($parent, null);

        $children = Product::where('parent_id', $parent->id)->get();

        foreach ($children as $child) {
            $this->syncSingleProduct($child, $app2ParentId);
        }

        Log::info('Product (with variants) fully synced to app2', [
            'app1_parent_id' => $parent->id,
            'app2_parent_id' => $app2ParentId,
            'children_count' => $children->count(),
        ]);
    }

    private function syncSingleProduct(Product $p, ?int $app2ParentId): int
    {
        $http = Http::withToken(env('APP2_API_TOKEN'))
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(30);

        $http = $this->attachIfExists($http, 'image', $p->image);
        $http = $this->attachIfExists($http, 'thumbnail_image', $p->thumbnail_image);
        $http = $this->attachIfExists($http, 'size_chart_image', $p->size_chart_image);
        $http = $this->attachIfExists($http, 'meta_image', $p->meta_image);

        Log::info('Sending sync request to: ' . env('APP2_API_URL'), ['product_id' => $p->id]);

        $response = $http->post(env('APP2_API_URL'), [
            'name'               => $p->name,
            'sku'                => $p->sku,
            'slug'               => $p->slug,
            'barcode'            => $p->barcode,
            'parent_id'          => $app2ParentId,

            // Relationship theke NAME বের করে পাঠানো (ID না)
            'category_type_name' => optional($p->category_type)->name,
            'category_name'      => optional($p->category)->name,
            'subcategory_name'   => optional($p->subcategory)->name,
            'product_type_name'  => optional($p->product_type)->name,
            'brand_name'         => optional($p->brand)->name,
            'unit_name'          => optional($p->unit)->name,
            'color_name'         => optional($p->color)->name,
            'size_name'          => optional($p->size)->name,
            'paper_name'         => optional($p->paper_quality)->name,

            'is_active'          => $p->is_active,
            'has_variant'        => $p->has_variants,
            'material'           => $p->material,
            'description'        => $p->description,
            'short_description'  => $p->short_description,
            'meta_title'         => $p->meta_title,
            'meta_description'   => $p->meta_description,
            'meta_keywords'      => $p->meta_keywords,
        ]);

        Log::info('app2 raw response', [
            'product_id' => $p->id,
            'status'     => $response->status(),
            'body'       => $response->body(),
        ]);

        if (! $response->successful()) {
            throw new \Exception(
                "app2 sync failed for product id {$p->id} (sku: {$p->sku}): "
                . $response->status() . ' - ' . $response->body()
            );
        }

        $app2ProductId = $response->json('data.id');

        if (! $app2ProductId) {
            throw new \Exception("app2 response e product id paoa jayni. Response: " . $response->body());
        }

        Log::info('Single product synced to app2', [
            'app1_id' => $p->id,
            'app2_id' => $app2ProductId,
            'sku'     => $p->sku,
        ]);

        return $app2ProductId;
    }

    private function attachIfExists($http, string $fieldName, ?string $path)
    {
        if (empty($path)) {
            return $http;
        }

        $fullPath = dirname(base_path()) . DIRECTORY_SEPARATOR . $path;
        $fullPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $fullPath);

        if (! file_exists($fullPath)) {
            Log::warning("File not found for sync: $fullPath");
            return $http;
        }

        return $http->attach($fieldName, file_get_contents($fullPath), basename($fullPath));
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Product sync to app2 permanently failed after all retries', [
            'product_id' => $this->productId,
            'error'      => $exception->getMessage(),
        ]);
    }
}
