<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Concerns\AuthorizesPortalFees;
use App\Http\Controllers\Controller;
use App\Models\Fee;
use App\Models\FeePayment;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FeeController extends Controller
{
    use AuthorizesPortalFees;

    public function overview(): JsonResponse
    {
        $base = Fee::query()
            ->withSum('payments', 'amount');

        $fees = $base->get();
        $balances = $fees->map(function (Fee $f): array {
            $paid = (float) ($f->payments_sum_amount ?? 0);
            $bal = (float) $f->amount - $paid;

            return ['balance' => max(0, $bal), 'fee' => $f, 'paid' => $paid];
        });

        $pendingBalance = $balances->sum('balance');
        $overdueCount = $balances->filter(function (array $row): bool {
            if ($row['balance'] <= 0.009) {
                return false;
            }

            return $row['fee']->due_date instanceof \DateTimeInterface &&
                Carbon::parse($row['fee']->due_date)->lt(Carbon::today());
        })->count();

        $partialCount = $balances->filter(fn (array $row) => $row['paid'] > 0 && $row['balance'] > 0.009)->count();
        $paidCount = $balances->filter(fn (array $row) => $row['balance'] <= 0.009)->count();
        $unpaidFeesCount = $balances->filter(fn (array $row) => $row['balance'] > 0.009)->count();

        $thisMonthPaid = (float) FeePayment::query()->whereBetween('paid_at', [
            Carbon::now()->startOfMonth(),
            Carbon::now()->endOfMonth(),
        ])->sum('amount');

        return ApiResponse::success([
            'total_fees_records' => Fee::query()->count(),
            'fees_with_balance' => $unpaidFeesCount,
            'pending_balance' => round($pendingBalance, 2),
            'paid_this_month' => round($thisMonthPaid, 2),
            'overdue_count' => $overdueCount,
            'partial_count' => $partialCount,
            'paid_in_full_count' => $paidCount,
        ]);
    }

    public function pending(Request $request): JsonResponse
    {
        return $this->indexMerged($request, onlyOutstanding: true);
    }

    public function forStudent(Request $request, Student $student): JsonResponse
    {
        $this->authorizeStudentFeesAccess($request, $student);

        $perPage = min(max((int) $request->get('per_page', 25), 1), 50);
        $query = Fee::query()
            ->where('student_id', $student->id)
            ->withSum('payments', 'amount')
            ->orderByDesc('due_date');

        $paginator = $query->paginate($perPage);

        return ApiResponse::success([
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'admission_number' => $student->admission_number,
            ],
            'items' => collect($paginator->items())->map(fn (Fee $fee) => $this->formatFeeRow($fee))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->indexMerged($request, onlyOutstanding: false);
    }

    protected function indexMerged(Request $request, bool $onlyOutstanding): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 15), 1), 50);

        $query = Fee::query()->with(['student:id,name,admission_number'])->withSum('payments', 'amount')->orderByDesc('due_date')->orderByDesc('id');

        $this->applyFeeFilters($request, $query, $onlyOutstanding);

        if ($onlyOutstanding) {
            $query->havingRaw('COALESCE(payments_sum_amount, 0) < fees.amount');
        }

        $paginator = $query->paginate($perPage);

        return ApiResponse::success([
            'items' => collect($paginator->items())->map(fn (Fee $fee) => $this->formatFeeRow($fee))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    protected function applyFeeFilters(Request $request, Builder $query, bool $ignoreStatusFilter = false): void
    {
        if ($search = $request->get('q')) {
            $query->where(function (Builder $q) use ($search): void {
                $q->whereHas('student', function (Builder $sq) use ($search): void {
                    $sq->where('name', 'like', "%{$search}%")
                        ->orWhere('admission_number', 'like', "%{$search}%");
                })->orWhere('title', 'like', "%{$search}%");
            });
        }

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->integer('student_id'));
        }

        if (! $ignoreStatusFilter) {
            $status = $request->get('status');
            if ($status === 'paid') {
                $query->havingRaw('COALESCE(payments_sum_amount, 0) >= fees.amount');
            } elseif ($status === 'partial') {
                $query->havingRaw('COALESCE(payments_sum_amount, 0) > 0')
                    ->havingRaw('COALESCE(payments_sum_amount, 0) < fees.amount');
            } elseif ($status === 'overdue') {
                $query->whereDate('due_date', '<', Carbon::today()->toDateString())
                    ->havingRaw('COALESCE(payments_sum_amount, 0) < fees.amount');
            } elseif ($status === 'pending') {
                $query->havingRaw('COALESCE(payments_sum_amount, 0) = 0')
                    ->whereDate('due_date', '>=', Carbon::today()->toDateString());
            }
        }

        if ($request->filled('due_before')) {
            $query->whereDate('due_date', '<=', $request->input('due_before'));
        }

        if ($request->filled('due_after')) {
            $query->whereDate('due_date', '>=', $request->input('due_after'));
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'due_date' => ['required', 'date'],
        ]);

        $fee = Fee::create($data);

        return ApiResponse::success([
            'fee' => $this->formatFeeDetail($fee->fresh()->load(['student:id,name,admission_number'])),
        ], 'Fee created', 201);
    }

    public function show(Request $request, Fee $fee): JsonResponse
    {
        $this->authorizeFeeAccess($request, $fee);

        $fee->load(['student:id,name,admission_number'])
            ->loadSum('payments', 'amount')
            ->load(['payments' => fn ($q) => $q->orderByDesc('paid_at')->orderByDesc('id')]);

        return ApiResponse::success([
            'fee' => $this->formatFeeDetail($fee),
        ]);
    }

    public function update(Request $request, Fee $fee): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'due_date' => ['sometimes', 'required', 'date'],
        ]);

        $paid = (float) $fee->payments()->sum('amount');
        if (isset($data['amount']) && (float) $data['amount'] + 0.009 < $paid) {
            return ApiResponse::error(
                'Amount cannot be less than payments already recorded.',
                422,
                ['amount' => ['Must be at least '.number_format($paid, 2)]]
            );
        }

        $fee->update($data);

        return ApiResponse::success([
            'fee' => $this->formatFeeDetail($fee->fresh()->load(['student:id,name,admission_number'])->loadSum('payments', 'amount')),
        ]);
    }

    public function destroy(Fee $fee): JsonResponse
    {
        $fee->delete();

        return ApiResponse::success(null, 'Deleted');
    }

    public function recordPayment(Request $request, Fee $fee): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['required', 'date'],
            'payment_method' => ['nullable', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $paid = (float) $fee->payments()->sum('amount');
        $balance = (float) $fee->amount - $paid;
        if ($data['amount'] > $balance + 0.005) {
            return ApiResponse::error('Amount exceeds remaining balance.', 422, [
                'amount' => ['Maximum payable is '.number_format(max(0, $balance), 2)],
            ]);
        }

        $payment = $fee->payments()->create([
            'amount' => $data['amount'],
            'paid_at' => $data['paid_at'],
            'payment_method' => $data['payment_method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'recorded_by' => $request->user()?->id,
        ]);

        $fee->unsetRelation('payments');
        $fee->loadMissing('student:id,name,admission_number')
            ->loadSum('payments', 'amount')
            ->load(['payments' => fn ($q) => $q->orderByDesc('paid_at')->orderByDesc('id')]);

        return ApiResponse::success([
            'payment' => [
                'id' => $payment->id,
                'fee_id' => $payment->fee_id,
                'amount' => (string) $payment->amount,
                'paid_at' => $payment->paid_at->toIso8601String(),
                'payment_method' => $payment->payment_method,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
            ],
            'fee' => $this->formatFeeDetail($fee),
        ], 'Payment recorded', 201);
    }

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'student_id' => ['nullable', 'exists:students,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = min(max((int) ($data['per_page'] ?? 25), 1), 100);

        $query = FeePayment::query()
            ->with([
                'fee.student:id,name,admission_number',
                'recordedBy:id,name',
            ])
            ->orderByDesc('paid_at')
            ->orderByDesc('id');

        if (! empty($data['student_id'])) {
            $query->whereHas('fee', fn (Builder $q) => $q->where('student_id', $data['student_id']));
        }

        if (! empty($data['from'])) {
            $query->whereDate('paid_at', '>=', $data['from']);
        }

        if (! empty($data['to'])) {
            $query->whereDate('paid_at', '<=', $data['to']);
        }

        $paginator = $query->paginate($perPage);

        return ApiResponse::success([
            'items' => collect($paginator->items())->map(fn (FeePayment $p) => $this->formatPaymentReportRow($p))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'student_id' => ['nullable', 'exists:students,id'],
        ]);

        $query = FeePayment::query()
            ->with(['fee.student:id,name,admission_number'])
            ->orderByDesc('paid_at');

        if (! empty($data['student_id'])) {
            $query->whereHas('fee', fn (Builder $q) => $q->where('student_id', $data['student_id']));
        }

        if (! empty($data['from'])) {
            $query->whereDate('paid_at', '>=', $data['from']);
        }

        if (! empty($data['to'])) {
            $query->whereDate('paid_at', '<=', $data['to']);
        }

        $filename = 'fee-payments-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($handle, ['Paid at', 'Student', 'Admission', 'Fee title', 'Payment amount', 'Method', 'Reference']);

            $query->clone()->chunkById(400, function ($chunk) use ($handle): void {
                foreach ($chunk as $p) {
                    /** @var FeePayment $p */
                    $stu = $p->fee?->student;
                    fputcsv($handle, [
                        $p->paid_at->format('Y-m-d H:i'),
                        $stu?->name,
                        $stu?->admission_number,
                        $p->fee?->title,
                        $p->amount,
                        $p->payment_method,
                        $p->reference,
                    ]);
                }
            }, column: 'id');

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function paidTotal(Fee $fee): float
    {
        return (float) (($fee->payments_sum_amount ?? null) !== null ? $fee->payments_sum_amount : $fee->payments()->sum('amount'));
    }

    /** @return array<string, mixed> */
    private function formatFeeRow(Fee $fee): array
    {
        $paid = isset($fee->payments_sum_amount) ? (float) $fee->payments_sum_amount : $fee->payments()->sum('amount');
        $balance = round((float) $fee->amount - $paid, 2);
        $status = Fee::statusFor($fee->amount, $paid, $fee->due_date);

        return [
            'id' => $fee->id,
            'student_id' => $fee->student_id,
            'title' => $fee->title,
            'amount' => (string) $fee->amount,
            'due_date' => $fee->due_date->toDateString(),
            'paid_total' => round($paid, 2),
            'balance' => max(0, $balance),
            'status' => $status,
            'student' => $fee->student ? [
                'id' => $fee->student->id,
                'name' => $fee->student->name,
                'admission_number' => $fee->student->admission_number,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function formatFeeDetail(Fee $fee): array
    {
        $paidBase = $this->paidTotal($fee);
        $balance = round((float) $fee->amount - $paidBase, 2);
        $status = Fee::statusFor($fee->amount, $paidBase, $fee->due_date);

        $paymentsOut = $fee->relationLoaded('payments')
            ? $fee->payments->map(fn (FeePayment $p) => $this->formatPaymentShort($p))->all()
            : [];

        return [
            'id' => $fee->id,
            'student_id' => $fee->student_id,
            'title' => $fee->title,
            'notes' => $fee->notes,
            'amount' => (string) $fee->amount,
            'due_date' => $fee->due_date->toDateString(),
            'paid_total' => round($paidBase, 2),
            'balance' => max(0, $balance),
            'status' => $status,
            'student' => $fee->student ? [
                'id' => $fee->student->id,
                'name' => $fee->student->name,
                'admission_number' => $fee->student->admission_number,
            ] : null,
            'payments' => $paymentsOut,
        ];
    }

    /** @return array<string, mixed> */
    private function formatPaymentShort(FeePayment $p): array
    {
        return [
            'id' => $p->id,
            'amount' => (string) $p->amount,
            'paid_at' => $p->paid_at->toIso8601String(),
            'payment_method' => $p->payment_method,
            'reference' => $p->reference,
            'notes' => $p->notes,
        ];
    }

    /** @return array<string, mixed> */
    private function formatPaymentReportRow(FeePayment $p): array
    {
        $stu = $p->fee?->student;

        return [
            'id' => $p->id,
            'amount' => (string) $p->amount,
            'paid_at' => $p->paid_at->toIso8601String(),
            'payment_method' => $p->payment_method,
            'reference' => $p->reference,
            'fee_title' => $p->fee?->title,
            'student' => $stu ? [
                'id' => $stu->id,
                'name' => $stu->name,
                'admission_number' => $stu->admission_number,
            ] : null,
            'recorded_by' => $p->recordedBy ? [
                'id' => $p->recordedBy->id,
                'name' => $p->recordedBy->name,
            ] : null,
        ];
    }
}
