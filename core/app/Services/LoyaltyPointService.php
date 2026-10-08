<?php

namespace App\Services;

use App\Models\backend\LoyaltyRule;
use App\Models\backend\LoyaltyTransaction;

class LoyaltyPointService
{
    public static function calculateRedeemablePoints($userId)
    {
        $points = LoyaltyTransaction::where('customer_id', $userId)->sum('points');

        return $points;
    }

    public static function redeemPoints($userId)
    {
        $rule = LoyaltyRule::latestActive()->first();
        $points = self::calculateRedeemablePoints($userId);

        $discount = 0;

        if (! $rule) {
            return 0;
        }

        if($points>=$rule->min_redeem_points && $points<=$rule->max_redeem_points){

            $discount = ($points/$rule->redeem_points)*$rule->redeem_amount;
        }
        return $discount??0;
    }
}
