<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\LoyaltyRule;
use App\Models\backend\LoyaltyTransaction;
use App\Services\LoyaltyPointService;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    private function latestActiveRuleId(): ?int
    {
        return LoyaltyRule::latestActive()->value('id');
    }

    public function index()
    {
        return view('backend.modules.loyalties.index');
    }

    public function listAjax(Request $request)
    {
        $columns   = ['id', 'name', 'earn_amount', 'earn_points', 'redeem_points', 'redeem_amount', 'min_redeem_points', 'max_redeem_points', 'is_active'];
        $draw      = (int) $request->input('draw');
        $start     = (int) $request->input('start', 0);
        $length    = (int) $request->input('length', 10);
        $orderIdx  = (int) $request->input('order.0.column', 0);
        $orderDir  = strtolower($request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $searchVal = trim($request->input('search.value', ''));

        $base = LoyaltyRule::query()->select(['id', 'name', 'earn_amount', 'earn_points', 'redeem_points', 'redeem_amount', 'min_redeem_points', 'max_redeem_points', 'is_active', 'updated_at']);

        $total = (clone $base)->count();

        if ($searchVal !== '') {
            $base->where(function ($q) use ($searchVal) {
                $q->where('name', 'like', "%{$searchVal}%");
            });
        }

        $filtered = (clone $base)->count();

        $orderCol = $columns[$orderIdx] ?? 'id';

        $rows = $base->orderBy($orderCol, $orderDir)
            ->skip($start)->take($length)->get();

        $data = [];
        $latestActiveId = $this->latestActiveRuleId();
        foreach ($rows as $b) {
            $nameCol = '<strong>' . e($b->name) . '</strong>';
            $statusLabel = $b->is_active
                ? 'Active'
                : 'Inactive';
            $statusClass = $b->is_active
                ? 'bg-success-focus text-success-main border-success-main'
                : 'bg-dark-warning-gradient text-white border-warning-main';
            $statusAction = '<button type="button" class="border-0 px-16 py-8 radius-4 fw-semibold text-sm btn-loyalty-toggle ' . $statusClass . '"'
                . ' data-url="' . route('loyalty.toggleActive', $b->id) . '"'
                . ' title="Toggle active status">'
                . e($statusLabel)
                . '</button>';

            $activeInfo = $latestActiveId === $b->id
                ? '<div class="text-xs text-success-main mt-4">Latest active</div>'
                : '';


            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100">
                <a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center
                    bg-success-focus text-success-main AjaxModal"
                    data-ajax-modal="' . route('loyalty.editModal', $b->id) . '"
                    data-size="lg"
                    data-onsuccess="loyaltyIndex.onSaved"
                    title="Edit">
                    <iconify-icon icon="lucide:edit"></iconify-icon>
                </a>
                <a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-loyalty-delete"
                    data-id="' . $b->id . '"
                    data-url="' . route('loyalty.destroy', $b->id) . '"
                    title="Delete">
                    <iconify-icon icon="mdi:delete"></iconify-icon>
                </a>
            </div>';

            $data[] = [
                $b->id,
                $nameCol,
                $b->earn_amount,
                $b->earn_points,
                $b->redeem_points,
                $b->redeem_amount,
                $b->min_redeem_points,
                $b->max_redeem_points,
                $statusAction . $activeInfo,
                $actions,
            ];
        }

        return response()->json([
            'draw'                 => $draw,
            'iTotalRecords'        => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData'               => $data,
        ]);
    }

    public function createModal()
    {
        // @perm গার্ড চাইলে দিন
        return view('backend.modules.loyalties.createModal'); // partial only
    }
    public function store(Request $req)
    {
        $data = $req->validate([
            'name'      => ['required', 'string', 'max:150'],
            'earn_amount'      => ['required', 'numeric'],
            'earn_points'      => ['required', 'integer'],
            'redeem_points'      => ['required', 'integer'],
            'redeem_amount'      => ['required', 'numeric'],
            'min_redeem_points'      => ['required', 'integer'],
            'max_redeem_points'      => ['nullable', 'integer'],
            'is_active'      => ['nullable', 'integer'],

        ]);

        $loyaltyRule = LoyaltyRule::create([
            'name'      => ucwords($data['name']),
            'earn_amount'      => ($data['earn_amount']),
            'earn_points'      => ($data['earn_points']),
            'redeem_points'      => ($data['redeem_points']),
            'redeem_amount'      => ($data['redeem_amount']),
            'min_redeem_points'      => ($data['min_redeem_points']),
            'max_redeem_points'      => ($data['max_redeem_points']),
            'is_active'      => (int) ($data['is_active'] ?? 1),


        ]);

        return response()->json(['ok' => true, 'msg' => 'Loyalty Rule created', 'id' => $loyaltyRule->id]);
    }
    public function show(LoyaltyRule $loyaltyRule)
    {

        return response()->json([
            'id'        => $loyaltyRule->id,
            'name'      => $loyaltyRule->name,
            'is_active' => (int) $loyaltyRule->is_active,

        ]);
    }
    public function editModal(LoyaltyRule $loyaltyRule)
    {
        return view('backend.modules.loyalties.editModal', compact('loyaltyRule'));
    }
    public function update(Request $req, LoyaltyRule $loyaltyRule)
    {
        $data = $req->validate([
            'name'      => ['required', 'string', 'max:150'],
            'earn_amount'      => ['required', 'numeric'],
            'earn_points'      => ['required', 'integer'],
            'redeem_points'      => ['required', 'integer'],
            'redeem_amount'      => ['required', 'numeric'],
            'min_redeem_points'      => ['required', 'integer'],
            'max_redeem_points'      => ['nullable', 'integer'],
            'is_active'      => ['nullable', 'integer'],

        ]);

        $loyaltyRule->name  = ucwords($data['name']);
        $loyaltyRule->earn_amount  = ($data['earn_amount']);
        $loyaltyRule->earn_points  = ($data['earn_points']);
        $loyaltyRule->redeem_points  = ($data['redeem_points']);
        $loyaltyRule->redeem_amount  = ($data['redeem_amount']);
        $loyaltyRule->min_redeem_points  = ($data['min_redeem_points']);
        $loyaltyRule->max_redeem_points  = ($data['max_redeem_points']);
        $loyaltyRule->is_active  = (int) ($data['is_active'] ?? 0);

        $loyaltyRule->save();

        return response()->json(['ok' => true, 'msg' => 'LoyaltyRule updated']);
    }

    public function toggleActive(LoyaltyRule $loyaltyRule)
    {
        $loyaltyRule->is_active = ! (bool) $loyaltyRule->is_active;
        $loyaltyRule->save();

        return response()->json([
            'ok' => true,
            'msg' => $loyaltyRule->is_active ? 'Loyalty Rule activated' : 'Loyalty Rule deactivated',
            'is_active' => (int) $loyaltyRule->is_active,
        ]);
    }

    public function userLoyaltyPoints($userId)
    {
        $points = LoyaltyPointService::calculateRedeemablePoints($userId);
        $discount = LoyaltyPointService::redeemPoints($userId);

        return response()->json(['ok' => true, 'points' => $points, 'discount' => $discount]);
    }

    public function destroy(LoyaltyRule $loyaltyRule)
    {

        $loyaltyRule->delete();

        return response()->json(['ok' => true, 'msg' => 'LoyaltyRule deleted']);
    }
}
