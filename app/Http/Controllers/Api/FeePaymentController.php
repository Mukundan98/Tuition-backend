<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Concerns\AuthorizesPortalFees;
use App\Http\Controllers\Controller;
use App\Models\FeePayment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class FeePaymentController extends Controller
{
    use AuthorizesPortalFees;

    public function receiptPdf(Request $request, FeePayment $fee_payment)
    {
        $this->authorizeFeePaymentReceipt($request, $fee_payment);

        $fee_payment->load(['fee.student:id,name,admission_number', 'recordedBy:id,name']);

        $pdf = Pdf::loadView('pdf.fee-receipt', [
            'payment' => $fee_payment,
            'appName' => config('app.name', 'Tuition Management'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('receipt-'.$fee_payment->id.'.pdf');
    }
}
