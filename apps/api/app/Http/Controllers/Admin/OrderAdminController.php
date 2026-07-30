<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with(['items:id,order_id,product_name,quantity,total_cents', 'customer:id,name,phone'])
            ->when($request->string('status')->toString(), fn ($q, $status) =>
                $q->where('status', $status))
            ->latest('placed_at')
            ->paginate(25);

        return response()->json($orders);
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
        ]);

        $order->update([
            'status' => $data['status'],
            'delivered_at' => $data['status'] === 'delivered' ? now() : $order->delivered_at,
        ]);

        return response()->json($order);
    }
}
