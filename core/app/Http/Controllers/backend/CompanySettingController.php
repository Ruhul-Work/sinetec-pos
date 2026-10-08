<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\CompanySetting;
use Illuminate\Http\Request;

class CompanySettingController extends Controller
{
    public function index()
    {
        return view('backend.modules.company_settings.index');
    }

    public function listAjax(Request $request)
    {
        $columns = ['id', 'name', 'code', 'email', 'phone', 'is_active'];
        $draw = (int) $request->input('draw');
        $start = (int) $request->input('start', 0);
        $length = min(max((int) $request->input('length', 10), 1), 100);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower($request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim($request->input('search.value', ''));

        $query = CompanySetting::query()->select([
            'id', 'name', 'code', 'logo', 'email', 'phone', 'address', 'city', 'country', 'is_active',
        ]);
        $total = (clone $query)->count();

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $settings = $query
            ->orderBy($columns[$orderIndex] ?? 'id', $orderDirection)
            ->offset($start)
            ->limit($length)
            ->get();

        return response()->json([
            'draw' => $draw,
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData' => $settings->map(function (CompanySetting $setting) {
                $status = $setting->is_active
                    ? '<span class="badge bg-success">Active</span>'
                    : '<span class="badge bg-secondary">Inactive</span>';

                return [
                    $setting->id,
                    '<strong>' . e($setting->name) . '</strong>',
                    e($setting->code),
                    $setting->logo ? '<img src="' . e(image($setting->logo)) . '" alt="Logo" style="height:32px;max-width:100px;object-fit:contain">' : '—',
                    e($setting->email),
                    e($setting->phone),
                    e(collect([$setting->address, $setting->city, $setting->country])->filter()->implode(', ')),
                    $status,
                    '<a href="' . route('company_setting.edit', $setting) . '" class="btn btn-sm btn-outline-primary">Edit</a>',
                ];
            })->all(),
        ]);
    }

    public function create()
    {
        return view('backend.modules.company_settings.create');
    }

    public function store(Request $request)
    {
        CompanySetting::create($this->validatedData($request));

        return redirect()->route('company_setting.index')->with('success', 'Company settings saved.');
    }

    public function edit(CompanySetting $companySetting)
    {
        return view('backend.modules.company_settings.edit', compact('companySetting'));
    }

    public function update(Request $request, CompanySetting $companySetting)
    {
        $companySetting->update($this->validatedData($request, $companySetting));

        return redirect()->route('company_setting.index')->with('success', 'Company settings updated.');
    }

    public function destroy(CompanySetting $companySetting)
    {
        if (CompanySetting::where('is_active', true)->count() <= 1 && $companySetting->is_active) {
            return response()->json(['ok' => false, 'msg' => 'Keep at least one active company setting.'], 422);
        }

        $companySetting->delete();

        return response()->json(['ok' => true, 'msg' => 'Company setting deleted.']);
    }

    private function validatedData(Request $request, ?CompanySetting $companySetting = null): array
    {
        $uniqueName = 'unique:company_settings,name';
        $uniqueCode = 'unique:company_settings,code';

        if ($companySetting) {
            $uniqueName .= ',' . $companySetting->id;
            $uniqueCode .= ',' . $companySetting->id;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', $uniqueName],
            'code' => ['required', 'string', 'max:50', $uniqueCode],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'url', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($request->hasFile('logo')) {
            $data['logo'] = uploadImage($request->file('logo'), 'company_setting/logos');
        } else {
            unset($data['logo']);
        }

        $data['name'] = trim($data['name']);
        $data['code'] = strtoupper(trim($data['code']));
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
