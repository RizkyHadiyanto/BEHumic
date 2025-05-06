<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;

// class PaymentController extends Controller
// {
//     public function __construct()
//     {
//         $this->middleware('auth:sanctum');
//     }

//     public function index()
//     {
//         try {
//             return response()->json(Payment::all(), 200);
//         } catch (\Exception $e) {
//             Log::error('Error retrieving Payments', ['error' => $e->getMessage()]);
//             return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
//         }
//     }

//     public function show($id)
//     {
//         try {
//             $payment = Payment::find($id);
//             if (!$payment) {
//                 return response()->json(['message' => 'Payment not found'], 404);
//             }

//             return response()->json($payment, 200);
//         } catch (\Exception $e) {
//             Log::error('Error retrieving Payment', ['error' => $e->getMessage()]);
//             return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
//         }
//     }
// }

// Model khusus
use App\Models\PaymentICODSA;
use App\Models\PaymentICICYTA;

class PaymentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    private function getPaymentModel($type = null)
    {
        $user = Auth::user();
        if (!$user) {
            return null;
        }
    
        if ($user->role_id == 2) {
            return PaymentICODSA::class;
        } elseif ($user->role_id == 3) {
            return PaymentICICYTA::class;
        }
    
        // Jika superadmin (role_id == 1), gunakan segment URL
        if ($user->role_id == 1 && $type !== null) {
            switch ($type) {
                case 'icodsa':
                    return PaymentICODSA::class;
                case 'icicyta':
                    return PaymentICICYTA::class;
            }
        }
    
        return null; // fallback jika tidak dikenali
    }  

    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            $type = $request->segment(2); // ex: /icodsa/payments
            $paymentModel = $this->getPaymentModel($type);
    
            if (!$paymentModel) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
    
            $payments = ($user->role_id == 1)
                ? $paymentModel::all()
                : $paymentModel::where('created_by', $user->id)->get();
    
            return response()->json($payments, 200);
        } catch (\Exception $e) {
            Log::error('Error retrieving Payments', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
        }
    }  

    public function show(Request $request, $id)
    {
        try {
            $user = Auth::user();
            $type = $request->segment(2); // ex: /icodsa/payments/3
            $paymentModel = $this->getPaymentModel($type);
    
            if (!$paymentModel) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
    
            $payment = $paymentModel::find($id);
    
            if (!$payment) {
                return response()->json(['message' => 'Payment not found'], 404);
            }
    
            if ($user->role_id !== 1 && $payment->created_by !== $user->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
    
            return response()->json($payment, 200);
        } catch (\Exception $e) {
            Log::error('Error retrieving Payment', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
        }
    }    

    public function update(Request $request, $id)
    {
        try {
            $paymentModel = $this->getPaymentModel();
            if (!$paymentModel) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $payment = $paymentModel::find($id);
            if (!$payment) {
                return response()->json(['message' => 'payment not found'], 404);
            }

            // Pastikan admin hanya update payment miliknya (jika diinginkan):
            if (Auth::user()->role_id != 1 && $payment->created_by != Auth::id()) {
                return response()->json(['message' => 'Not your payment'], 403);
            }

            $payment->update($request->all());

            return response()->json(['message' => 'payment updated successfully', 'payment' => $payment], 200);
        } catch (\Exception $e) {
            Log::error('Error updating payment', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Menghapus payment
     */
    public function destroy($id)
    {
        try {
            $paymentModel = $this->getPaymentModel();
            if (!$paymentModel) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $payment = $paymentModel::find($id);
            if (!$payment) {
                return response()->json(['message' => 'payment not found'], 404);
            }

            $payment->delete();
            return response()->json(['message' => 'payment deleted'], 200);
        } catch (\Exception $e) {
            Log::error('Error deleting payment', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
        }
    }

    public function downloadPayment($id)
    {
        try {
            $paymentModel = $this->getPaymentModel();
            $payment = $paymentModel::find($id);
    
            if (!$payment) {
                return response()->json(['message' => 'Payment not found'], 404);
            }
    
            if (Auth::user()->role_id != 1 && $payment->created_by != Auth::id()) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
    
            // Pilih view berdasarkan role
            switch (Auth::user()->role_id) {
                case 2:
                    $view = 'pdf.icodsapayment';
                    break;
                case 3:
                    $view = 'pdf.icicytapayment';
                    break;
                default:
                    $view = 'pdf.payment'; // superadmin atau fallback
            }
    
            $filename = 'Payment_' . str_replace(['/', '\\'], '-', $payment->invoice_no) . '.pdf';
            $pdf = Pdf::loadView($view, compact('payment'));
    
            return $pdf->download($filename);
    
        } catch (\Exception $e) {
            Log::error('Error generating Payment PDF', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
        }
    }
}
