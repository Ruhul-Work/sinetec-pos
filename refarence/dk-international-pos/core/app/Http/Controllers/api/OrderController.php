<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\backend\Branch;
use App\Models\backend\Coupon;
use App\Models\backend\District;
use App\Models\backend\OfferDiscount;
use App\Models\backend\OfferGift;
use App\Models\backend\Product;
use App\Models\backend\Sale;
use App\Models\backend\SaleItem;
use App\Models\backend\Warehouse;
use App\Models\backend\WebsiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $customerId = $user?->customer?->id;

        if ($customerId) {
            $orders = Sale::with('division.country', 'district', 'upazila')
                ->where('customer_id', $customerId)
                ->orderBy('id', 'desc')
                ->get();

            return response()->json(['success' => true, 'orders' => $orders]);
        }

        return response()->json(['success' => false, 'message' => 'Customer profile not found'], 404);
    }

    public function show(Request $request, $invoice_no)
    {
        $customerId = $request->user()?->customer?->id;

        $order = Sale::with('items.product.color', 'items.product.size', 'division.country', 'district', 'upazila')
            ->where('invoice_no', $invoice_no)
            ->where('customer_id', $customerId)
            ->first();

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $order]);
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'phone' => 'required|string|max:20',
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:products,id',
            'items.*.qty' => 'required|numeric|min:1',
            'coupon_id' => 'nullable|exists:coupons,id',
            'country_id' => 'required|integer|exists:countries,id',
            'division_id' => [
                'required',
                'integer',
                Rule::exists('divisions', 'id')
                    ->where(fn ($query) => $query->where('country_id', $request->input('country_id'))),
            ],
            'district_id' => [
                'required',
                'integer',
                Rule::exists('districts', 'district_id')
                    ->where(fn ($query) => $query->where('district_division_id', $request->input('division_id'))),
            ],
            'upazila_id' => [
                'required',
                'integer',
                Rule::exists('upazilas', 'id')
                    ->where(fn ($query) => $query->where('upazila_district_id', $request->input('district_id'))),
            ],
            'shipping_address' => 'required|string|max:150',
            'city' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'transaction_id' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:2000',
        ]);

        $subtotal = 0;

        $products = Product::whereIn('id', array_column($data['items'], 'id'))->get();

        $products = $products->map(function ($product) {
            $hasOffer = isProductUnderAnyOffer($product->parent_id ?? $product->id);
            //  return $hasOffer;

            if ($hasOffer && ($hasOffer->offer_type == 'gift' || $hasOffer->offer_type == 'bundle')) {
                $freeProducts = null;
                $giftProduct = OfferGift::where('offer_id', $hasOffer->id)->where('buy_product_id', $product->parent_id ?? $product->id)->get();

                $freeProducts = Product::whereIn('id', $giftProduct->pluck('free_product_id'))->get();
                $freeQuantities = $giftProduct->pluck('free_quantity', 'free_product_id');
                foreach ($freeProducts as $freeProduct) {
                    $freeProduct->quantity = $freeQuantities->get($freeProduct->id, 0);
                }

                $product->freeProducts = $freeProducts;
            } elseif ($hasOffer && $hasOffer->offer_type == 'discount') {
                $discountRule = OfferDiscount::where('offer_id', $hasOffer->id)->first();
                $product->offer_price = $product->mrp - applyDiscount($discountRule, $product);
            }

            // $product->hasOffer = $hasOffer;
            return $product;
        });

        $products = $products->keyBy('id');

        $coupon = null;
        if ($data['coupon_id'] ?? null) {
            $coupon = Coupon::where('id', $data['coupon_id'])->first();
        }

        $discountAmount = 0;
        foreach ($data['items'] as $item) {
            $product = $products->get($item['id']);

            if (isset($product->offer_price)) {
                $subtotal += $product->mrp * $item['qty'];
                $discountAmount += ($product->mrp - $product->offer_price) * $item['qty'];
            } else {
                $subtotal += $product->price * $item['qty'];
            }
        }

        $coupon_discount = 0;
        if ($coupon) {
            if ($coupon->min_purchase <= $subtotal) {

                $coupon_discount = $coupon->discount;
                if ($coupon->discount_type === 'percentage') {
                    $coupon_discount = $subtotal * $coupon->discount / 100;
                }
                if ($coupon->max_discount < $coupon_discount) {
                    $coupon_discount = $coupon->max_discount;
                }
            }
        }

        $shipping_charge = WebsiteSetting::query()->value('shipping_charge') ?? 0;

        $total = $subtotal + $shipping_charge - $discountAmount - $coupon_discount;

        $ecommerceBranch = Branch::where('code', 'e-commerce')->first();
        $ecommerceWarehouse = $ecommerceBranch
            ? Warehouse::where('branch_id', $ecommerceBranch->id)->first()
            : null;

        if (! $ecommerceBranch || ! $ecommerceWarehouse) {
            return response()->json([
                'success' => false,
                'message' => 'E-commerce branch or warehouse is not configured.',
            ], 422);
        }

        $district = District::query()->find($data['district_id']);
        $customerId = auth('sanctum')->user()?->customer?->id;

        DB::beginTransaction();
        try {
            $order = Sale::create([
                'invoice_no' => 'DKI-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
                'customer_id' => $customerId,
                'branch_id' => $ecommerceBranch->id,
                'warehouse_id' => $ecommerceWarehouse->id,
                'subtotal' => $subtotal,
                'total' => $total,
                'due_amount' => $total,
                'coupon_code' => $coupon->code ?? null,
                'coupon_id' => $coupon->id ?? null,
                'discount' => $discountAmount,
                'coupon_discount' => $coupon_discount,
                'shipping_charge' => $shipping_charge,
                'sale_type' => 'e-commerce',
                'sale_note' => $data['note'] ?? null,
                'name' => $data['name'],
                'phone' => $data['phone'],
                'shipping_address' => $data['shipping_address'],
                'city' => $data['city'] ?? $district->district_name,
                'postal_code' => $data['postal_code'] ?? null,
                'division_id' => $data['division_id'],
                'district_id' => $data['district_id'],
                'upazila_id' => $data['upazila_id'],
                'status' => 'pending',
                'payment_status' => 'pending',
                'transaction_id' => $data['transaction_id'] ?? null,
            ]);
            foreach ($data['items'] as $item) {
                $product = $products->get($item['id']);

                SaleItem::create([
                    'sale_id' => $order->id,
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'image' => $product->thumbnail_image,
                    'quantity' => $item['qty'],
                    'unit_price' => $product->offer_price ?? $product->price,
                    'line_total' => $item['qty'] * ($product->offer_price ?? $product->price),
                ]);

                if (isset($product->freeProducts)) {
                    foreach ($product->freeProducts as $freeProduct) {
                        SaleItem::create([
                            'sale_id' => $order->id,
                            'product_id' => $freeProduct->id,
                            'name' => $freeProduct->name,
                            'image' => $freeProduct->thumbnail_image,
                            'quantity' => $item['qty'] * $freeProduct->quantity,
                            'unit_price' => 0.00,
                            'line_total' => 0.00,
                        ]);
                    } // end foreach freeProducts
                } // end if freeProducts
            } // end foreach items

            DB::commit();

            return response()->json([
                'success' => true,
                'order' => $order->load('division.country', 'district', 'upazila'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json(['success' => false, 'message' => 'Order not created'], 500);
        }
    }

    public function userOrders()
    {
        $customerId = auth('sanctum')->user()?->customer?->id;

        if (! $customerId) {
            return response()->json(['success' => false, 'message' => 'Customer profile not found'], 404);
        }

        $orders = Sale::with('items.product', 'division.country', 'district', 'upazila')
            ->where('customer_id', $customerId)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $orders]);
    }

    public function trackOrder($invoice_no)
    {
        $order = Sale::with('division.country', 'district', 'upazila')
            ->where('invoice_no', $invoice_no)
            ->first();

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found!'], 404);
        }

        return response()->json(['success' => true, 'order' => $order]);

    }
}
