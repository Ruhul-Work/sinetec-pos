<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\backend\Country;
use App\Models\backend\District;
use App\Models\backend\Division;
use App\Models\backend\Upazila;

class LocationController extends Controller
{
    public function countries()
    {
        $countries = Country::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $countries,
        ]);
    }

    public function divisions(Country $country)
    {
        $divisions = Division::query()
            ->where('country_id', $country->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $divisions,
        ]);
    }

    public function districts(Division $division)
    {
        $districts = District::query()
            ->where('district_division_id', $division->id)
            ->orderBy('district_name')
            ->get(['district_id as id', 'district_name as name']);

        return response()->json([
            'success' => true,
            'data' => $districts,
        ]);
    }

    public function upazilas($district)
    {
        District::query()->findOrFail($district);

        $upazilas = Upazila::query()
            ->where('upazila_district_id', $district)
            ->orderBy('upazila_name')
            ->get(['id', 'upazila_name as name']);

        return response()->json([
            'success' => true,
            'data' => $upazilas,
        ]);
    }
}
