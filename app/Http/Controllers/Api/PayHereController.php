<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Concerns\AuthorizesPortalFees;
use App\Http\Controllers\Controller;
use App\Models\Fee;
use App\Models\FeePayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PayHereController extends Controller
{
    use AuthorizesPortalFees;

    /** Admin starts PayHere Hosted Checkout — returns POST target + signed fields (secret stays server-side). */
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fee_id' => ['required', 'integer', 'exists:fees,id'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $merchantId = (string) config('services.payhere.merchant_id');
        $merchantSecret = (string) config('services.payhere.merchant_secret');

        if ($merchantId === '' || $merchantSecret === '') {
            return ApiResponse::error('PayHere is not configured on the server.', 500);
        }

        /** @var Fee $fee */
        $fee = Fee::query()
            ->with([
                'student:id,name,email,admission_number,parent_email,parent_phone',
            ])
            ->withSum('payments', 'amount')
            ->findOrFail($data['fee_id']);

        $this->authorizeFeeAccess($request, $fee);

        $paidBase = (float) ($fee->payments_sum_amount ?? 0);
        $balance = round((float) $fee->amount - $paidBase, 2);

        if ($balance <= 0.009) {
            return ApiResponse::error('Nothing to pay on this fee.', 400);
        }

        $payAmount = isset($data['amount']) ? (float) $data['amount'] : $balance;
        $payAmount = min($payAmount, $balance);

        if ($payAmount < 0.01) {
            return ApiResponse::error('Invalid amount.', 400);
        }

        $currency = strtoupper((string) config('services.payhere.currency', 'LKR'));
        $amountFormatted = number_format($payAmount, 2, '.', '');

        $orderId = 'TMS-' . $fee->id . '-' . Str::uuid()->toString();

        $frontendUrl = rtrim((string) config('services.payhere.frontend_url'), '/');
        if ($frontendUrl === '') {
            return ApiResponse::error('FRONTEND_URL is not configured.', 500);
        }

        $returnUrl = $frontendUrl . '/fees/' . $fee->id . '/pay/success';
        $cancelUrl = $frontendUrl . '/fees/' . $fee->id . '/pay/cancel';

        $notifyUrl = rtrim((string) config('app.url'), '/') . '/api/webhooks/payhere';

        $secretHash = strtoupper(md5($merchantSecret));
        $hash = strtoupper(md5($merchantId . $orderId . $amountFormatted . $currency . $secretHash));

        $student = $fee->student;
        $parts = preg_split('/\s+/', trim((string) ($student->name ?? 'Student'))) ?: [];
        $firstName = $parts[0] ?? 'Student';
        $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : $firstName;

        $email = $student?->email ?? $student?->parent_email;
        if (! is_string($email) || trim($email) === '') {
            $admission = $student !== null ? (string) ($student->admission_number ?? 'student') : 'student';
            $email = 'not-provided+' . $admission . '@payhere.invalid';
        }

        $fields = [
            'merchant_id' => $merchantId,
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'notify_url' => $notifyUrl,
            'order_id' => $orderId,
            'items' => mb_substr('Fee: ' . $fee->title, 0, 250),
            'currency' => $currency,
            'amount' => $amountFormatted,
            'first_name' => mb_substr($firstName, 0, 100),
            'last_name' => mb_substr($lastName, 0, 100),
            'email' => $email,
            'phone' => $student && $student->parent_phone !== null ? (string) $student->parent_phone : '0770000000',
            'address' => '-',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'custom_1' => (string) $fee->id,
            'custom_2' => $amountFormatted,
            'hash' => $hash,
        ];

        $actionUrl = (bool) config('services.payhere.sandbox', true)
            ? 'https://sandbox.payhere.lk/pay/checkout'
            : 'https://www.payhere.lk/pay/checkout';

        return ApiResponse::success([
            'action_url' => $actionUrl,
            'fields' => $fields,
        ]);
    }

    /** PayHere server-to-server notify (must be reachable at APP_URL publicly in production). */
    public function notify(Request $request): Response
    {
        $merchantId = (string) $request->input('merchant_id');
        $orderId = (string) $request->input('order_id');
        $paymentId = (string) $request->input('payment_id');
        $payhereAmount = (string) $request->input('payhere_amount');
        $payhereCurrency = (string) $request->input('payhere_currency');
        $statusCode = (string) $request->input('status_code');
        $md5sig = strtoupper((string) $request->input('md5sig'));

        $secret = (string) config('services.payhere.merchant_secret');

        if ($merchantId === '' || $secret === '') {
            return response('Misconfigured', 500);
        }

        if ($paymentId === '' || $orderId === '') {
            return response('Bad payload', 400);
        }

        $computed = strtoupper(md5($merchantId . $orderId . $payhereAmount . $payhereCurrency . $statusCode . strtoupper(md5($secret))));

        if (! hash_equals($computed, $md5sig)) {
            Log::warning('PayHere notify rejected: bad signature.', ['order_id' => $orderId]);

            return response('Forbidden', 403);
        }

        $expectedMerchant = (string) config('services.payhere.merchant_id');
        if ($expectedMerchant !== '' && ! hash_equals($expectedMerchant, $merchantId)) {
            return response('Forbidden', 403);
        }

        if ($statusCode !== '2') {
            return response('OK', 200);
        }

        if (FeePayment::query()->where('reference', $paymentId)->exists()) {
            return response('OK', 200);
        }

        $feeId = (int) $request->input('custom_1');
        $expectedAmountStr = (string) $request->input('custom_2');

        if ($feeId < 1) {
            Log::warning('PayHere notify: missing fee id.', ['payment_id' => $paymentId]);

            return response('OK', 200);
        }

        /** @var Fee|null $fee */
        $fee = Fee::query()->withSum('payments', 'amount')->find($feeId);

        if ($fee === null) {
            return response('OK', 200);
        }

        $paidBase = (float) ($fee->payments_sum_amount ?? 0);
        $balance = round((float) $fee->amount - $paidBase, 2);
        $amt = round((float) $payhereAmount, 2);
        $expected = round((float) $expectedAmountStr, 2);

        if ($expected > 0 && abs($amt - $expected) > 0.02) {
            Log::warning('PayHere notify rejected: amount mismatch.', [
                'payment_id' => $paymentId,
                'amt' => $amt,
                'expected' => $expected,
            ]);

            return response('Amount mismatch', 400);
        }

        if ($amt > $balance + 0.02) {
            Log::warning('PayHere notify rejected: over balance.', ['payment_id' => $paymentId]);

            return response('Over balance', 400);
        }

        if ($amt < 0.01) {
            return response('Bad amount', 400);
        }

        $fee->payments()->create([
            'amount' => $amt,
            'paid_at' => now(),
            'payment_method' => 'PayHere',
            'reference' => mb_substr($paymentId, 0, 120),
            'notes' => mb_substr('PayHere order_id: ' . $orderId, 0, 2000),
            'recorded_by' => null,
        ]);

        return response('OK', 200);
    }
}
