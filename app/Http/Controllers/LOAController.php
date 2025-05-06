<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Loa;
use App\Models\Invoice;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controller;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;


// Model utama (superadmin)
// use App\Models\Loa;

// Model khusus ICODSA
use App\Models\LoaICODSA;
// Model khusus ICICYTA
use App\Models\LoaICICYTA;

// Model Invoice ICODSA
use App\Models\InvoiceICODSA;
// Model Invoice ICICYTA
use App\Models\InvoiceICICYTA;



class LoaController extends Controller
{
    public function __construct()
    {
        // Di contoh ini kita pakai sanctum & role middleware dari Anda
        $this->middleware('auth:sanctum');
    }

    /**
     * Method bantu untuk memilih model LOA berdasarkan role user.
     */
    private function getLoaModel($type = null)
    {
        $user = Auth::user();
        if (!$user) {
            return null;
        }
    
        // Jika admin biasa, tetap berdasarkan role
        if ($user->role_id == 2) {
            return LoaICODSA::class;
        } elseif ($user->role_id == 3) {
            return LoaICICYTA::class;
        }
    
        // Jika superadmin (role_id == 1), ambil dari URL (misalnya /icodsa/loas → icodsa)
        if ($user->role_id == 1 && $type !== null) {
            switch ($type) {
                case 'icodsa':
                    return LoaICODSA::class;
                case 'icicyta':
                    return LoaICICYTA::class;
            }
        }
    
        return null; // fallback jika tidak dikenali
    }

    
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            $type = $request->segment(2);
            $loaModel = $this->getLoaModel($type);
    
            if (!$loaModel) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
    
            $data = ($user->role_id == 1)
                ? $loaModel::all()
                : $loaModel::where('created_by', $user->id)->get();
    
            return response()->json($data, 200);
        } catch (\Exception $e) {
            Log::error('Error fetching Loa', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
        }
    }  

    /**
     * Menyimpan LOA ke tabel yang sesuai dengan role
     */
    public function store(Request $request)
{
    try {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $loaModel = $this->getLoaModel();
        if (!$loaModel) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Validasi
        $validator = Validator::make($request->all(), [
            'paper_id'        => 'required|string|unique:' . (new $loaModel)->getTable(),
            'paper_title'     => 'required|string',
            'author_names'    => 'required|array|min:1|max:5',
            'status'          => 'required|in:Accepted,Rejected',
            'tempat_tanggal'  => 'required|string',
            'signature_id'    => 'required|exists:signatures,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Simpan LOA
        $loa = $loaModel::create([
            'paper_id'       => $request->paper_id,
            'paper_title'    => $request->paper_title,
            'author_names'   => $request->author_names, // JSON jika di-cast di model
            'status'         => $request->status,
            'tempat_tanggal' => $request->tempat_tanggal,
            'signature_id'   => $request->signature_id,
            'created_by'     => $user->id
        ]);

        // Jika LOA berstatus 'Accepted', generate Invoice
        if ($loa->status === 'Accepted') {
            $this->createInvoice($loa); 
        }

        return response()->json([
            'message' => 'LOA created successfully',
            'loa'     => $loa
        ], 201);

    } catch (\Exception $e) {
        Log::error('Error creating LOA', ['error' => $e->getMessage()]);
        return response()->json([
            'message' => 'Terjadi kesalahan',
            'error'   => $e->getMessage()
        ], 500);
    }
}


    /**
     * Menampilkan detail LOA
     */
    public function show(Request $request, $id)
    {
        try {
            $user = Auth::user();
            $type = $request->segment(2); // Ambil dari /{type}/loas/{id}
            $loaModel = $this->getLoaModel($type);
    
            if (!$loaModel) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
    
            $loa = $loaModel::find($id);
    
            if (!$loa) {
                return response()->json(['message' => 'LOA not found'], 404);
            }
    
            // Jika bukan superadmin, pastikan hanya bisa lihat data sendiri
            if ($user->role_id !== 1 && $loa->created_by !== $user->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
    
            return response()->json($loa, 200);
        } catch (\Exception $e) {
            Log::error('Error retrieving LOA', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
        }
    } 

    /**
     * Update LOA
     */
    public function update(Request $request, $id)
    {
        try {
            $loaModel = $this->getLoaModel();
            if (!$loaModel) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $loa = $loaModel::find($id);
            if (!$loa) {
                return response()->json(['message' => 'LOA not found'], 404);
            }

            // Pastikan admin hanya update LOA miliknya (jika diinginkan):
            if (Auth::user()->role_id != 1 && $loa->created_by != Auth::id()) {
                return response()->json(['message' => 'Not your LOA'], 403);
            }

            $loa->update($request->all());

            return response()->json(['message' => 'LOA updated successfully', 'loa' => $loa], 200);
        } catch (\Exception $e) {
            Log::error('Error updating LOA', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Menghapus LOA
     */
    public function destroy($id)
    {
        try {
            $loaModel = $this->getLoaModel();
            if (!$loaModel) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $loa = $loaModel::find($id);
            if (!$loa) {
                return response()->json(['message' => 'LOA not found'], 404);
            }

            $loa->delete();
            return response()->json(['message' => 'LOA deleted'], 200);
        } catch (\Exception $e) {
            Log::error('Error deleting LOA', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
        }
    }


    private function createInvoice($loa)
    {
        try {
            Log::info("Creating Invoice for LOA ID: " . $loa->id);

            // Cari si pembuat LOA
            $creator = User::find($loa->created_by);

            // DEFAULT (kalau tidak ketemu user / superadmin / role lain)
            $roleBasedCode = 'GENERAL';
            $invoiceModel  = Invoice::class;

            // Jika role_id = 2 -> ICODSA
            if ($creator && $creator->role_id == 2) {
                $roleBasedCode = 'ICODSA';
                $invoiceModel  = InvoiceICODSA::class; 
            }
            // Jika role_id = 3 -> ICICYTA
            elseif ($creator && $creator->role_id == 3) {
                $roleBasedCode = 'ICICYTA';
                $invoiceModel  = InvoiceICICYTA::class; 
            }else {
                // Fallback -> Superadmin atau role lain
                $roleBasedCode = 'GENERAL';
                $invoiceModel  = Invoice::class; 
            }

            // Buat nomor invoice sesuai role
            $invoiceNumber = sprintf(
                '%03d/INV/%s/%s',
                $invoiceModel::count() + 1,
                $roleBasedCode,
                date('Y')
            );

            // Simpan invoice di tabel yang sesuai
            $invoice = $invoiceModel::create([
                'invoice_no'   => $invoiceNumber,
                'loa_id'       => $loa->id,
                'created_by'   => $loa->created_by,
                'signature_id' => $loa->signature_id,
                
                // Boleh tambahkan field lain seperti
                // 'institution' => null,
                // 'email' => null,
                // dsb.

                // Status invoice default 'Unpaid' misalnya
                'status'       => 'Unpaid'
            ]);

            Log::info("Invoice Created Successfully in [{$invoiceModel}] => ID: " . $invoice->id);
        } catch (\Exception $e) {
            Log::error('Error creating Invoice', ['error' => $e->getMessage()]);
        }
    }
    
    public function downloadLOA($id)
    {
        try {
            $loaModel = $this->getLoaModel();
            if (!$loaModel) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
    
            $loa = $loaModel::find($id);
            if (!$loa) {
                return response()->json(['message' => 'LOA not found'], 404);
            }
    
            if (Auth::user()->role_id != 1 && $loa->created_by != Auth::id()) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
    
            // Pilih view berdasarkan role
            switch (Auth::user()->role_id) {
                case 2:
                    $view = 'pdf.icodsaloa';
                    break;
                case 3:
                    $view = 'pdf.icicytaloa';
                    break;
                default:
                    $view = 'pdf.loa'; // Superadmin atau fallback
            }
    
            $filename = 'LOA_' . str_replace(['/', '\\'], '-', $loa->paper_id) . '.pdf';
            $pdf = Pdf::loadView($view, compact('loa'));
    
            return $pdf->download($filename);
    
        } catch (\Exception $e) {
            Log::error('Error generating LOA PDF', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
        }
    }
}
// class LoaController extends Controller
// {
//     public function __construct()
//     {
//         $this->middleware('auth:sanctum');
//         // $this->middleware(SuperAdminMiddleware::class);
            
//     }
//     public function index()
//     {
//         try {
//             $loa = Loa::all();
//             return response()->json($loa, 200);
//         } catch (\Exception $e) {
//             Log::error('Error fetching loa', ['error' => $e->getMessage()]);
//             return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
//         }
//     }

//     public function store(Request $request)
//     {
        
//         try {
//             $user = Auth::user();
//             if (!$user) {
//                 return response()->json(['message' => 'Unauthorized'], 401);
//             }

//             $validator = Validator::make($request->all(), [
//                 'paper_id' => 'required|string|unique:loas',
//                 'paper_title' => 'required|string',
//                 'author_names' => 'required|array|min:1|max:5',
//                 'status' => 'required|in:Accepted,Rejected',
//                 'tempat_tanggal' => 'required|string',
//                 'signature_id' => 'required|exists:signatures,id',
//             ]);

//             if ($validator->fails()) {
//                 return response()->json(['errors' => $validator->errors()], 400);
//             }

//             $loa = Loa::create([
//                 'paper_id' => $request->paper_id,
//                 'paper_title' => $request->paper_title,
//                 'author_names' => json_encode($request->author_names),
//                 'status' => $request->status,
//                 'tempat_tanggal' => $request->tempat_tanggal,
//                 'signature_id' => $request->signature_id,
//                 'created_by' => $user->id
//             ]);

//             if ($request->status === 'Accepted') {
//                 $this->createInvoice($loa);
//             }

//             return response()->json(['message' => 'LOA created successfully', 'loa' => $loa], 201);
//         } catch (\Exception $e) {
//             Log::error('Error creating LOA', ['error' => $e->getMessage()]);
//             return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
//         }
//     }

//     public function update(Request $request, $id)
//     {
//         try {
//             $loa = Loa::find($id);
//             if (!$loa) {
//                 return response()->json(['message' => 'LOA not found'], 404);
//             }

//             $loa->update($request->all());

//             if ($request->status === 'Accepted') {
//                 $this->createInvoice($loa);
//             }

//             return response()->json(['message' => 'LOA updated successfully', 'loa' => $loa], 200);
//         } catch (\Exception $e) {
//             Log::error('Error updating LOA', ['error' => $e->getMessage()]);
//             return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
//         }
//     }

//     // private function createInvoice($loa)
//     // {
//     //     try {
//     //         Log::info("Creating Invoice for LOA ID: " . $loa->id);

//     //         $invoiceNumber = sprintf('%03d/INV/%s/%s',
//     //             Invoice::count() + 1,
//     //             ($loa->created_by == 2 ? 'ICODSA' : 'ICICYTA'),
//     //             date('Y')
//     //         );

//     //         $invoice = Invoice::create([
//     //             'invoice_no' => $invoiceNumber,
//     //             'loa_id' => $loa->id,
//     //             'created_by' => $loa->created_by,
//     //             'signature_id' => $loa->signature_id,
//     //             'status' => 'Unpaid'
//     //         ]);

//     //         Log::info("Invoice Created Successfully: " . $invoice->id);
//     //     } catch (\Exception $e) {
//     //         Log::error('Error creating Invoice', ['error' => $e->getMessage()]);
//     //     }
//     // }
//     private function createInvoice($loa)
//     {
//         try {
//             Log::info("Creating Invoice for LOA ID: " . $loa->id);

//             $creator = User::find($loa->created_by);
//             $roleBasedCode = 'GENERAL';

//             if ($creator && $creator->role_id == 2) {
//                 $roleBasedCode = 'ICODSA';
//             } elseif ($creator && $creator->role_id == 3) {
//                 $roleBasedCode = 'ICICYTA';
//             }

//             $invoiceNumber = sprintf('%03d/INV/%s/%s',
//                 Invoice::count() + 1,
//                 $roleBasedCode,
//                 date('Y')
//             );

//             $invoice = Invoice::create([
//                 'invoice_no' => $invoiceNumber,
//                 'loa_id' => $loa->id,
//                 'created_by' => $loa->created_by,
//                 'signature_id' => $loa->signature_id,
//                 'status' => 'Unpaid'
//             ]);

//             Log::info("Invoice Created Successfully: " . $invoice->id);
//         } catch (\Exception $e) {
//             Log::error('Error creating Invoice', ['error' => $e->getMessage()]);
//         }
//     }

// }




