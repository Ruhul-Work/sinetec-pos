<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductDetailResource;
use App\Http\Resources\ProductResource;
use App\Models\backend\Category;
use App\Models\backend\OfferDiscount;
use App\Models\backend\OfferGift;
use App\Models\backend\Product;
use App\Models\backend\Subcategory;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with('category')->where('shopee_visibility', 1);

        if ($request->category) {
            $products->where('category_id', Category::where('slug', $request->category)->first()->id);
        }
        if ($request->subcategory) {
            $products->where('category_id', Subcategory::where('slug', $request->subcategory)->first()->id);
        }

        if ($request->search) {
            $products->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->sort_by == 'price_asc') {
            $products->orderBy('shopee_price', 'asc');
        } elseif ($request->sort_by == 'price_desc') {
            $products->orderBy('shopee_price', 'desc');
        } else if ($request->sort_by == 'latest') {
            $products->orderBy('created_at', 'desc');
        } else if ($request->sort_by == 'oldest') {
            $products->orderBy('created_at', 'asc');
        } else {
            $products->orderBy('created_at', 'desc');
        }


        $products = $products->where('parent_id', null)->paginate(8);

        // $products = $products->through(function ($product) {
        //     $hasOffer = isProductUnderAnyOffer($product->id);
        //     $product->offer = $hasOffer;

        //     if ($hasOffer && $hasOffer->offer_type == 'gift') {
        //         $freeProducts = null;
        //         $giftProduct = OfferGift::where('offer_id', $hasOffer->id)->where('buy_product_id', $product->id)->get();

        //         // foreach($giftProduct as $gift){
        //         $freeProducts = Product::whereIn('id', $giftProduct->pluck('free_product_id'))->get();
        //         // }

        //         $product->freeProducts = $freeProducts;
        //     } else if ($hasOffer && $hasOffer->offer_type == 'discount') {

        //         $discountRule = OfferDiscount::where('offer_id', $hasOffer->id)->first();
        //         $product->offer_price = $product->mrp - applyDiscount($discountRule, $product);
        //     }

        //     // $product->hasOffer = $hasOffer;
        //     return $product;
        // });


        return response()->json(['success' => true, 'data' => ProductResource::collection($products)]);
    }

    public function show(Request $request, $slug)
    {
        $product = Product::with(['category', 'size', 'color', 'specifications'])->where('slug', $slug)->where('shopee_visibility', 1)->first();

        // return priceAfterDiscount($product);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        // $hasOffer = isProductUnderAnyOffer($product->id);
        // $product->offer = $hasOffer;


        // if ($hasOffer && ($hasOffer->offer_type == 'gift' || $hasOffer->offer_type == 'bundle')) {
        //     $freeProducts = null;
        //     $giftProduct = OfferGift::where('offer_id', $hasOffer->id)->where('buy_product_id', $product->id)->get();

        //     // foreach($giftProduct as $gift){
        //     $freeProducts = Product::whereIn('id', $giftProduct->pluck('free_product_id'))->get();
        //     // }
        //     $i = 0;
        //     foreach ($freeProducts as $freeProduct) {
        //         $freeProduct->quantity = $giftProduct[$i]->free_quantity;
        //         $i++;
        //     }

        //     if ($product->children) {
        //         foreach ($product->children as $child) {
        //             $child->freeProducts = $freeProducts;
        //         }
        //     } else {
        //         $product->freeProducts = $freeProducts;
        //     }
        // } else if ($hasOffer && $hasOffer->offer_type == 'discount') {
        //     $discountRule = OfferDiscount::where('offer_id', $hasOffer->id)->first();
        //     if ($product->children) {
        //         foreach ($product->children as $child) {
        //             $child->offer_price = $child->mrp - applyDiscount($discountRule, $child);
        //         }
        //         $product->offer_price = $product->mrp - applyDiscount($discountRule, $product);
        //     } else {
        //         $product->offer_price = $product->mrp - applyDiscount($discountRule, $product);
        //     }
        // }

        return response()->json(['success' => true, 'data' => new ProductDetailResource($product)]);
    }




}
