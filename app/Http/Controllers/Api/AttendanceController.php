<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\Student;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    public function classDay(Request $request, SchoolClass $class): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
        ]);
        $date = Carbon::parse($data['date'])->startOfDay();

        $students = Student::query()
            ->where('class_id', $class->id)
            ->orderBy('name')
            ->get(['id', 'name', 'admission_number']);

        $attRows = Attendance::query()
            ->where('class_id', $class->id)
            ->whereDate('attended_on', $date)
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->keyBy('student_id');

        $rows = $students->map(function (Student $s) use ($attRows): array {
            $a = $attRows->get($s->id);

            return [
                'student_id' => $s->id,
                'name' => $s->name,
                'admission_number' => $s->admission_number,
                'attendance' => $a ? [
                    'id' => $a->id,
                    'status' => $a->status,
                    'remark' => $a->remark,
                ] : null,
            ];
        });

        return ApiResponse::success([
            'class' => ['id' => $class->id, 'name' => $class->name, 'section' => $class->section],
            'date' => $date->toDateString(),
            'students' => $rows->values()->all(),
        ]);
    }

    public function bulkStore(Request $request, SchoolClass $class): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'items.*.status' => ['required', 'string', 'in:'.implode(',', Attendance::statuses())],
            'items.*.remark' => ['nullable', 'string', 'max:500'],
        ]);

        $date = Carbon::parse($data['date'])->startOfDay();
        $userId = $request->user()?->id;

        $validIds = Student::query()
            ->where('class_id', $class->id)
            ->whereIn('id', collect($data['items'])->pluck('student_id'))
            ->pluck('id')
            ->all();

        if (count($validIds) !== count($data['items'])) {
            return ApiResponse::error('Each student must belong to this class.', 422);
        }

        DB::transaction(function () use ($data, $class, $date, $userId): void {
            foreach ($data['items'] as $item) {
                Attendance::query()->updateOrCreate(
                    [
                        'student_id' => $item['student_id'],
                        'attended_on' => $date->toDateString(),
                    ],
                    [
                        'class_id' => $class->id,
                        'status' => $item['status'],
                        'remark' => $item['remark'] ?? null,
                        'marked_by' => $userId,
                    ]
                );
            }
        });

        return ApiResponse::success(null, 'Attendance saved');
    }

    /**
     * Resolve a scanned barcode (typically admission number or SID:123) and upsert one day's row.
     */
    public function markByBarcode(Request $request, SchoolClass $class): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'code' => ['required', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', Attendance::statuses())],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $date = Carbon::parse($data['date'])->startOfDay();
        $raw = $this->normalizeScannedBarcode((string) $data['code']);

        $student = $this->resolveStudentByBarcodeScan($class->id, $raw);

        if ($student === null) {
            return ApiResponse::error('No student in this class matches that barcode.', 422);
        }

        $status = $data['status'] ?? Attendance::STATUS_PRESENT;
        $userId = $request->user()?->id;

        $attendance = Attendance::query()->updateOrCreate(
            [
                'student_id' => $student->id,
                'attended_on' => $date->toDateString(),
            ],
            [
                'class_id' => $class->id,
                'status' => $status,
                'remark' => $data['remark'] ?? null,
                'marked_by' => $userId,
            ]
        )->fresh();

        return ApiResponse::success([
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'admission_number' => $student->admission_number,
            ],
            'attendance' => $attendance ? [
                'id' => $attendance->id,
                'status' => $attendance->status,
                'remark' => $attendance->remark,
            ] : null,
        ], 'Attendance saved');
    }

    /**
     * Strip BOM/control chars wedges sometimes inject so scans still match roster data.
     */
    private function normalizeScannedBarcode(string $raw): string
    {
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? $s;

        return trim($s);
    }

    private function resolveStudentByBarcodeScan(int $classId, string $raw): ?Student
    {
        if (preg_match('/^SID\s*[:.\-]?\s*(\d+)$/i', $raw, $m)) {
            $student = Student::query()
                ->where('class_id', $classId)
                ->whereKey((int) $m[1])
                ->first();

            return $student instanceof Student ? $student : null;
        }

        $needle = strtolower(str_replace(' ', '', $raw));

        if ($needle === '') {
            return null;
        }

        $students = Student::query()
            ->where('class_id', $classId)
            ->get(['id', 'name', 'admission_number']);

        foreach ($students as $s) {
            $admNorm = strtolower(str_replace(' ', '', (string) $s->admission_number));
            if ($admNorm !== '' && $admNorm === $needle) {
                return $s;
            }

            $admTrim = strtolower(trim((string) $s->admission_number));
            if ($admTrim !== '' && strtolower(trim($raw)) === $admTrim) {
                return $s;
            }
        }

        return null;
    }

    public function stats(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $class = SchoolClass::query()->findOrFail($data['class_id']);
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();

        $enrollment = Student::query()->where('class_id', $class->id)->count();

        $agg = Attendance::query()
            ->where('class_id', $class->id)
            ->whereBetween('attended_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $present = (int) ($agg[Attendance::STATUS_PRESENT] ?? 0);
        $absent = (int) ($agg[Attendance::STATUS_ABSENT] ?? 0);
        $late = (int) ($agg[Attendance::STATUS_LATE] ?? 0);
        $excused = (int) ($agg[Attendance::STATUS_EXCUSED] ?? 0);
        $totalMarked = $present + $absent + $late + $excused;
        $denom = $present + $absent + $late;
        $rate = $denom > 0 ? round(100 * $present / $denom, 2) : null;

        return ApiResponse::success([
            'class' => ['id' => $class->id, 'name' => $class->name, 'section' => $class->section],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'enrollment' => $enrollment,
            'records' => $totalMarked,
            'by_status' => [
                'present' => $present,
                'absent' => $absent,
                'late' => $late,
                'excused' => $excused,
            ],
            'present_rate_percent' => $rate,
        ]);
    }

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['nullable', 'integer', 'exists:classes,id'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = min(max((int) ($data['per_page'] ?? 25), 1), 100);
        $query = Attendance::query()->with([
            'student:id,name,admission_number,class_id',
            'schoolClass:id,name,section',
        ]);

        if (! empty($data['class_id'])) {
            $query->where('class_id', $data['class_id']);
        }
        if (! empty($data['student_id'])) {
            $query->where('student_id', $data['student_id']);
        }
        if (! empty($data['from'])) {
            $query->whereDate('attended_on', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $query->whereDate('attended_on', '<=', $data['to']);
        }

        $paginator = $query->orderByDesc('attended_on')->orderBy('student_id')->paginate($perPage);

        return ApiResponse::success([
            'items' => collect($paginator->items())->map(fn (Attendance $a) => $this->formatAttendance($a))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $class = SchoolClass::query()->findOrFail($data['class_id']);
        $start = Carbon::create($data['year'], $data['month'], 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $enrollment = Student::query()->where('class_id', $class->id)->count();

        $byDay = Attendance::query()
            ->where('class_id', $class->id)
            ->whereBetween('attended_on', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('attended_on, status, COUNT(*) as c')
            ->groupBy(['attended_on', 'status'])
            ->get();

        $rollup = [];
        foreach ($byDay as $row) {
            $d = Carbon::parse($row->attended_on)->toDateString();
            if (! isset($rollup[$d])) {
                $rollup[$d] = [
                    'present' => 0,
                    'absent' => 0,
                    'late' => 0,
                    'excused' => 0,
                ];
            }
            $rollup[$d][$row->status] = (int) $row->c;
        }

        $days = [];
        foreach (CarbonPeriod::create($start, $end) as $day) {
            $ds = $day->toDateString();
            $c = $rollup[$ds] ?? ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
            $marked = $c['present'] + $c['absent'] + $c['late'] + $c['excused'];
            $days[] = [
                'date' => $ds,
                'present' => $c['present'],
                'absent' => $c['absent'],
                'late' => $c['late'],
                'excused' => $c['excused'],
                'marked' => $marked,
                'enrollment' => $enrollment,
                'unmarked' => max(0, $enrollment - $marked),
            ];
        }

        return ApiResponse::success([
            'class' => ['id' => $class->id, 'name' => $class->name, 'section' => $class->section],
            'year' => $data['year'],
            'month' => $data['month'],
            'days' => $days,
        ]);
    }

    public function exportPdf(Request $request)
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
        ]);

        $class = SchoolClass::query()->findOrFail($data['class_id']);
        $query = Attendance::query()
            ->with(['student:id,name,admission_number'])
            ->where('class_id', $class->id)
            ->whereBetween('attended_on', [$data['from'], $data['to']])
            ->orderBy('attended_on')
            ->orderBy('student_id');

        if (! empty($data['student_id'])) {
            $query->where('student_id', $data['student_id']);
        }

        $rows = $query->get();

        $pdf = Pdf::loadView('pdf.attendance-report', [
            'class' => $class,
            'from' => $data['from'],
            'to' => $data['to'],
            'rows' => $rows,
        ])->setPaper('a4', 'landscape');

        $filename = 'attendance-' . $class->id . '-' . $data['from'] . '_' . $data['to'] . '.pdf';

        return $pdf->download($filename);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
        ]);

        $query = Attendance::query()
            ->with(['student:id,name,admission_number', 'schoolClass:id,name,section'])
            ->where('class_id', $data['class_id'])
            ->whereBetween('attended_on', [$data['from'], $data['to']])
            ->orderBy('attended_on')
            ->orderBy('student_id')
            ->orderBy('id');

        if (! empty($data['student_id'])) {
            $query->where('student_id', $data['student_id']);
        }

        $filename = 'attendance-' . $data['class_id'] . '-' . $data['from'] . '_' . $data['to'] . '.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, ['Date', 'Student', 'Admission', 'Class', 'Status', 'Remark']);
            $query->clone()->chunk(500, function ($chunk) use ($handle): void {
                foreach ($chunk as $a) {
                    /** @var Attendance $a */
                    fputcsv($handle, [
                        $a->attended_on->toDateString(),
                        $a->student?->name,
                        $a->student?->admission_number,
                        $a->schoolClass?->name,
                        $a->status,
                        $a->remark,
                    ]);
                }
            });
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function forStudent(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = Attendance::query()
            ->where('student_id', $student->id)
            ->with(['schoolClass:id,name,section']);

        if (! empty($data['from'])) {
            $query->whereDate('attended_on', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $query->whereDate('attended_on', '<=', $data['to']);
        }

        $rows = $query->orderByDesc('attended_on')->limit(366)->get();

        $counts = [
            'present' => $rows->where('status', Attendance::STATUS_PRESENT)->count(),
            'absent' => $rows->where('status', Attendance::STATUS_ABSENT)->count(),
            'late' => $rows->where('status', Attendance::STATUS_LATE)->count(),
            'excused' => $rows->where('status', Attendance::STATUS_EXCUSED)->count(),
        ];
        $denom = $counts['present'] + $counts['absent'] + $counts['late'];
        $rate = $denom > 0 ? round(100 * $counts['present'] / $denom, 2) : null;

        return ApiResponse::success([
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'admission_number' => $student->admission_number,
            ],
            'summary' => [
                'records' => $rows->count(),
                'by_status' => $counts,
                'present_rate_percent' => $rate,
            ],
            'items' => $rows->map(fn (Attendance $a) => $this->formatAttendance($a))->values()->all(),
        ]);
    }

    private function formatAttendance(Attendance $a): array
    {
        return [
            'id' => $a->id,
            'attended_on' => $a->attended_on->toDateString(),
            'status' => $a->status,
            'remark' => $a->remark,
            'student' => $a->student ? [
                'id' => $a->student->id,
                'name' => $a->student->name,
                'admission_number' => $a->student->admission_number,
            ] : null,
            'class' => $a->schoolClass ? [
                'id' => $a->schoolClass->id,
                'name' => $a->schoolClass->name,
                'section' => $a->schoolClass->section,
            ] : null,
        ];
    }
}
