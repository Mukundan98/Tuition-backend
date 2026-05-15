<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Fee;
use App\Models\FeePayment;
use App\Models\Student;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

trait AuthorizesPortalFees
{
    protected function authorizeStudentFeesAccess(Request $request, Student $student): void
    {
        $user = $request->user();
        $user->loadMissing('role');

        if ($user->role?->slug === 'admin') {
            return;
        }

        $user->loadMissing('studentProfile');
        if ($user->role?->slug === 'student'
            && $user->studentProfile
            && (int) $user->studentProfile->id === (int) $student->id) {
            return;
        }

        abort(Response::HTTP_FORBIDDEN);
    }

    protected function authorizeFeeAccess(Request $request, Fee $fee): void
    {
        $user = $request->user();
        $user->loadMissing('role');

        if ($user->role?->slug === 'admin') {
            return;
        }

        $user->loadMissing('studentProfile');
        if ($user->role?->slug === 'student'
            && $user->studentProfile
            && (int) $fee->student_id === (int) $user->studentProfile->id) {
            return;
        }

        abort(Response::HTTP_FORBIDDEN);
    }

    protected function authorizeFeePaymentReceipt(Request $request, FeePayment $feePayment): void
    {
        $user = $request->user();
        $user->loadMissing('role');

        if ($user->role?->slug === 'admin') {
            return;
        }

        $feePayment->loadMissing('fee');
        $fee = $feePayment->fee;
        if ($fee === null) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $user->loadMissing('studentProfile');
        if ($user->role?->slug === 'student'
            && $user->studentProfile
            && (int) $fee->student_id === (int) $user->studentProfile->id) {
            return;
        }

        abort(Response::HTTP_FORBIDDEN);
    }
}
