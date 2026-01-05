<?php

namespace Modules\Billing\Http\Controllers;

use Modules\Billing\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $payments = Payment::select('payment_status')->distinct()->get('payment_status');
        $search = $request->input('search');
        $payment_status = $request->input('payment_status');
        $perPage = $request->input('per_page', 25);
        $payments = Payment::when($search, function ($query) use ($search) {
            return $query->where('payment_transaction_id', 'like', '%' . $search . '%');
        })
        ->when($payment_status, function ($query) use ($payment_status) {
            return $query->where('payment_status', $payment_status);
        })
        ->latest()
        ->paginate($perPage);
        
        return response()->json([
            'payment_status' => $payments->pluck('payment_status')->unique()->values()->all(),
            'payments' => $payments
        ]);
    }
}
