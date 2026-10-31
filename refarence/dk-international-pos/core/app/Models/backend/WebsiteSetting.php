<?php

namespace App\Models\backend;

use Illuminate\Database\Eloquent\Model;

class WebsiteSetting extends Model
{
    protected $table = 'website_settings';
    
    protected $fillable = ['profile_key', 
    'company_name', 'email', 'phone', 
    'address', 'logo', 'favicon','display_video','slider_image1', 'slider_image2','slider_image3','popup_image', 'facebook','instagram',
    'twitter', 'linkedin', 'youtube', 'tiktok', 'website_url','currency_code',
    'shipping_charge','free_shipping_amount', 'merchant_qr','updated_by'];

    
}

