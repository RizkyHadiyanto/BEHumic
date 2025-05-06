<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controller;
use Barryvdh\DomPDF\Facade\Pdf;


// class InvoiceController extends Controller
// {
//     public function __construct()
//     {
//         $this->middleware('auth:sanctum');
//     }

//     public function index()
//     {
//         try {
//             $invoice = Invoice::all();
//             return response()->json($invoice, 200);
//         } catch (\Exception $e) {
//             Log::error('Error fetching invoice', ['error' => $e->getMessage()]);
//             return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
//         }
//     }

//     public function update(Request $request, $id)
//     {
//         try {
//             $invoice = Invoice::find($id);
//             if (!$invoice) {
//                 return response()->json(['message' => 'Invoice not found'], 404);
//             }

//             // Validasi input
//             $validator = Validator::make($request->all(), [
//                 'status' => 'in:Pending,Paid',
//                 'institution' => 'nullable|string|max:255',
//                 'email' => 'nullable|email|max:255',
//                 'presentation_type' => 'nullable|in:Onsite,Online',
//                 'member_type' => 'nullable|in:IEEE Member,IEEE Non Member',
//                 'author_type' => 'nullable|in:Author,Student Author',
//                 'amount' => 'nullable|numeric|min:0',
//                 'date_of_issue' => 'nullable|date',
//                 'virtual_account_id' => 'nullable|exists:virtual_accounts,id',
//                 'bank_transfer_id' => 'nullable|exists:bank_transfers,id',
//             ]);

//             if ($validator->fails()) {
//                 return response()->json(['errors' => $validator->errors()], 422);
//             }

//             // Update field yang boleh diubah
//             $invoice->fill($request->only([
//                 'institution',
//                 'email',
//                 'presentation_type',
//                 'member_type',
//                 'author_type',
//                 'amount',
//                 'date_of_issue',
//                 'virtual_account_id',
//                 'bank_transfer_id',
//                 'status'
//             ]));

//             $invoice->save();

//             // Buat payment jika sudah Paid
//             if ($invoice->status === 'Paid') {
//                 $this->createPayment($invoice);
//             }

//             return response()->json([
//                 'message' => 'Invoice updated successfully',
//                 'invoice' => $invoice->load('loa') // tampilkan relasi jika perlu
//             ], 200);
//         } catch (\Exception $e) {
//             Log::error('Error updating Invoice', ['error' => $e->getMessage()]);
//             return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
//         }
//     }

//     public function show($id)
//     {
//         try {
//             $invoice = Invoice::find($id);
//             if (!$invoice) {
//                 return response()->json(['message' => 'Invoice not found'], 404);
//             }

//             return response()->json($invoice, 200);
//         } catch (\Exception $e) {
//             Log::error('Error retrieving Invoice', ['error' => $e->getMessage()]);
//             return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
//         }
//     }

    
//     private function createPayment($invoice)
//     {
//         try {
//             $loa = $invoice->loa;

//             Payment::create([
//                 'invoice_no' => $invoice->invoice_no,
//                 'received_from' => $invoice->institution,
//                 'amount' => $invoice->amount,
//                 'in_payment_of' => 'Conference Registration for ' . ($loa->paper_title ?? 'Unknown'),
//                 'payment_date' => now(),
//                 'paper_id' => $loa->paper_id ?? '-',
//                 'paper_title' => $loa->paper_title ?? '-',
//                 'signature_id' => $invoice->signature_id,
//                 'created_by' => $invoice->created_by,
//                 'created_at' => now(),
//                 'updated_at' => now(),
//             ]);

//             Log::info("Payment auto-generated for invoice: " . $invoice->invoice_no);
//         } catch (\Exception $e) {
//             Log::error('Error creating Payment', ['error' => $e->getMessage()]);
//         }
//     }

//============================================================================================ 

// }
// private function createPayment($invoice)
    // {
    //     try {
    //         Payment::create([
    //             'invoice_id' => $invoice->id,
    //             'invoice_no' => $invoice->invoice_no,
    //             'received_from' => $invoice->institution ?? Auth::user()->name,
    //             'amount' => $invoice->amount ?? 0,
    //             'in_payment_of' => "Conference Registration for " . ($invoice->loa->paper_title ?? 'LOA'),
    //             'payment_date' => now(),
    //             'paper_id' => $invoice->loa->paper_id ?? null,
    //             'paper_title' => $invoice->loa->paper_title ?? null,
    //             'signature_id' => $invoice->signature_id,
    //             'created_by' => $invoice->created_by
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('Error creating Payment', ['error' => $e->getMessage()]);
    //     }
    // }

    //================================================================

    // Model khusus
    
use App\Models\InvoiceICODSA;
use App\Models\InvoiceICICYTA;

use App\Models\PaymentICODSA;
use App\Models\PaymentICICYTA;

class InvoiceController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    private function getInvoiceModel()
    {
        $user = Auth::user();
        switch ($user->role_id) {
            case 1:
                return Invoice::class;
            case 2:
                return InvoiceICODSA::class;
            case 3:
                return InvoiceICICYTA::class;
            default:
                return Invoice::class;
        }
    }
    
    private function getPaymentModel($type = null)
    {
        $user = Auth::user();
        if (!$user) {
            return null;
        }
    
        // Admin biasa langsung berdasarkan role
        if ($user->role_id == 2) {
            return PaymentICODSA::class;
        } elseif ($user->role_id == 3) {
            return PaymentICICYTA::class;
        }
    
        // Superadmin pilih berdasarkan URL segment (misalnya 'icodsa')
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

    // public function index()
    // {
    //     try {
    //         $invoiceModel = $this->getInvoiceModel();
    //         $invoices = $invoiceModel::all();
    //         return response()->json($invoices, 200);
    //     } catch (\Exception $e) {
    //         Log::error('Error fetching invoice', ['error' => $e->getMessage()]);
    //         return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
    //     }
    // }

    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            $type = $request->segment(2); // ex: /api/icodsa/payments → 'icodsa'
            $paymentModel = $this->getPaymentModel($type);
    
            if (!$paymentModel) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
    
            $data = ($user->role_id == 1)
                ? $paymentModel::all()
                : $paymentModel::where('created_by', $user->id)->get();
    
            return response()->json($data, 200);
        } catch (\Exception $e) {
            Log::error('Error fetching Payment', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
        }
    }
    // public function index()
    // {    
    //     try {
    //         $invoiceModel = $this->getInvoiceModel();
    //         if (!$invoiceModel) {
    //             return response()->json(['message' => 'Unauthorized'], 401);
    //         }

    //         // Jika ingin membatasi "hanya data yang dibuat oleh user ini":
    //         $data = $invoiceModel::where('created_by', Auth::id())->get();

    //         // Jika superadmin boleh lihat semua data, admin pun boleh lihat data lain:
    //         //$data = $loaModel::all();

    //         return response()->json($data, 200);
    //     } catch (\Exception $e) {
    //         Log::error('Error fetching Loa', ['error' => $e->getMessage()]);
    //         return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
    //     }
    // }

    public function show(Request $request, $id)
    {
        try {
            $user = Auth::user();
            $type = $request->segment(2); // ex: /api/icodsa/payments/5 → 'icodsa'
            $paymentModel = $this->getPaymentModel($type);
    
            if (!$paymentModel) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
    
            $payment = $paymentModel::find($id);
    
            if (!$payment) {
                return response()->json(['message' => 'Payment not found'], 404);
            }
    
            // Batasi akses jika bukan superadmin
            if ($user->role_id !== 1 && $payment->created_by !== $user->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
    
            return response()->json($payment, 200);
        } catch (\Exception $e) {
            Log::error('Error retrieving Payment', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
        }
    }
    

    
    /**
     * Update Invoice
     */
    public function update(Request $request, $id)
    {
        try {
            // Gunakan getInvoiceModel() agar admin role=2/3 memakai tabel khusus
            $invoiceModel = $this->getInvoiceModel();
            $invoice = $invoiceModel::find($id);

            if (!$invoice) {
                return response()->json(['message' => 'Invoice not found'], 404);
            }

            // Validasi input
            $validator = Validator::make($request->all(), [
                'status' => 'in:Pending,Paid,Unpaid',
                'institution' => 'nullable|string|max:255',
                'email' => 'nullable|email|max:255',
                'presentation_type' => 'nullable|in:Onsite,Online',
                'member_type' => 'nullable|in:IEEE Member,IEEE Non Member',
                'author_type' => 'nullable|in:Author,Student Author',
                'amount' => 'nullable|numeric|min:0',
                'date_of_issue' => 'nullable|date',
                'virtual_account_id' => 'nullable|exists:virtual_accounts,id',
                'bank_transfer_id' => 'nullable|exists:bank_transfers,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            // Update kolom yang diizinkan
            $invoice->fill($request->only([
                'institution',
                'email',
                'presentation_type',
                'member_type',
                'author_type',
                'amount',
                'date_of_issue',
                'virtual_account_id',
                'bank_transfer_id',
                'status'
            ]));

            $invoice->save();

            // Jika status = Paid -> buat payment otomatis
            if ($invoice->status === 'Paid') {
                $this->createPayment($invoice);
            }

            return response()->json([
                'message' => 'Invoice updated successfully',
                // load('loa') jika Anda punya relasi invoice->loa
                'invoice' => $invoice->load('loa')
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error updating Invoice', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Menghapus Invoice
     */
    public function destroy($id)
    {
        try {
            $invoiceModel = $this->getInvoiceModel();
            if (!$invoiceModel) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
    
            $invoice = $invoiceModel::find($id);
            if (!$invoice) {
                return response()->json(['message' => 'Invoice not found'], 404);
            }
    
            $invoice->delete();
            return response()->json(['message' => 'invoice deleted'], 200);
        } catch (\Exception $e) {
            Log::error('Error deleting invoice', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
        }
    }
    private function createPayment($invoice)
    {
        try {
            // Pilih model payment sesuai role
            $paymentModel = $this->getPaymentModel();

            // Ambil data LOA (pastikan relasi "public function loa()" di model invoice)
            $loa = $invoice->loa;

            $paymentModel::create([
                'invoice_no'      => $invoice->invoice_no,
                'received_from'   => $invoice->institution,
                'amount'          => $invoice->amount,
                'in_payment_of'   => 'Conference Registration for ' . ($loa->paper_title ?? 'Unknown'),
                'payment_date'    => now(),
                'paper_id'        => $loa->paper_id ?? '-',
                'paper_title'     => $loa->paper_title ?? '-',
                'signature_id'    => $invoice->signature_id,
                'created_by'      => $invoice->created_by,
                // created_at / updated_at diisi otomatis oleh Eloquent, 
                // jadi baris berikut opsional kecuali Anda ingin override
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            Log::info("Payment auto-generated for invoice: " . $invoice->invoice_no);
        } catch (\Exception $e) {
            Log::error('Error creating Payment', ['error' => $e->getMessage()]);
        }
    }

    public function downloadInvoice($id)
    {
        try {
            $invoiceModel = $this->getInvoiceModel();
            $invoice = $invoiceModel::with('loa')->find($id);
    
            if (!$invoice) {
                return response()->json(['message' => 'Invoice not found'], 404);
            }
    
            if (Auth::user()->role_id != 1 && $invoice->created_by != Auth::id()) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
    
            // Pilih view berdasarkan role_id
            switch (Auth::user()->role_id) {
                case 2:
                    $view = 'pdf.icodsainvoice';
                    break;
                case 3:
                    $view = 'pdf.icicytainvoice';
                    break;
                default:
                    $view = 'pdf.invoice'; // Superadmin atau fallback
            }
    
            $filename = 'Invoice_' . str_replace(['/', '\\'], '-', $invoice->invoice_no) . '.pdf';
            $pdf = Pdf::loadView($view, compact('invoice'));
    
            return $pdf->download($filename);
    
        } catch (\Exception $e) {
            Log::error('Error generating Invoice PDF', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
        }
    }
}
