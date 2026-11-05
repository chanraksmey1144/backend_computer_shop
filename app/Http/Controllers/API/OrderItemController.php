<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use Illuminate\Http\Request;

class OrderItemController extends Controller
{
    // Get all items belonging to user's orders
    public function index(Request $request)
    {
        $items = OrderItem::whereHas('order', function ($query) use ($request) {

            $query->where('user_id', $request->user()->id);

        })
            ->with(['order', 'product', 'variant'])
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'message' => 'Order items retrieved successfully.',
            'data' => $items,
        ], 200);
    }

    // Get one order item
    public function show(Request $request, int $id)
    {
        $item = OrderItem::whereHas('order', function ($query) use ($request) {

            $query->where('user_id', $request->user()->id);

        })
            ->with(['order', 'product', 'variant', 'serialNumbers'])
            ->find($id);

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Order item not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order item retrieved successfully.',
            'data' => $item,
        ], 200);
    }
}
