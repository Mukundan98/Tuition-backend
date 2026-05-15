<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InAppNotificationController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;
use Illuminate\Routing\Middleware\ThrottleRequests;

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/password', [AuthController::class, 'changePassword']);
    });
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('in-app-notifications/dropdown', [InAppNotificationController::class, 'dropdown']);
    Route::get('in-app-notifications', [InAppNotificationController::class, 'index']);
    Route::post('in-app-notifications/mark-all-read', [InAppNotificationController::class, 'markAllRead']);
    Route::patch('in-app-notifications/{in_app_notification}/read', [InAppNotificationController::class, 'markRead']);
});

Route::middleware(['auth:sanctum', 'role:teacher'])->get('dashboard/teacher', [DashboardController::class, 'teacher']);

Route::middleware(['auth:sanctum', 'role:admin,teacher'])->group(function (): void {
    Route::get('exam-papers', [\App\Http\Controllers\Api\ExamPaperController::class, 'index']);
    Route::get('exam-papers/{exam_paper}/file', [\App\Http\Controllers\Api\ExamPaperController::class, 'download']);
    Route::delete('exam-papers/{exam_paper}', [\App\Http\Controllers\Api\ExamPaperController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'role:teacher'])->group(function (): void {
    Route::post('exam-papers', [\App\Http\Controllers\Api\ExamPaperController::class, 'store']);
    Route::get('my-teaching-subjects', [\App\Http\Controllers\Api\SubjectController::class, 'forCurrentTeacher']);
});

Route::middleware(['auth:sanctum', 'role:student'])->get('dashboard/student', [DashboardController::class, 'student']);

Route::middleware(['auth:sanctum', 'role:student'])->get(
    'timetable/student',
    [\App\Http\Controllers\Api\TimetableController::class, 'student']
);

Route::middleware(['auth:sanctum', 'role:student'])->group(function (): void {
    Route::get('online-exams/for-me', [\App\Http\Controllers\Api\OnlineExamController::class, 'forStudent']);
    Route::get('online-exams/{onlineExam}/take', [\App\Http\Controllers\Api\OnlineExamController::class, 'take']);
    Route::post('online-exams/{onlineExam}/submit', [\App\Http\Controllers\Api\OnlineExamController::class, 'submit']);
    Route::get('online-exams/{onlineExam}/my-result', [\App\Http\Controllers\Api\OnlineExamController::class, 'myResult']);
});

Route::middleware(['auth:sanctum', 'role:teacher'])->get(
    'timetable/teacher',
    [\App\Http\Controllers\Api\TimetableController::class, 'teacher']
);

Route::middleware(['auth:sanctum', 'role:admin'])->get(
    'timetable/admin',
    [\App\Http\Controllers\Api\TimetableController::class, 'admin']
);

Route::middleware(['auth:sanctum', 'role:parent'])->get('dashboard/parent', [DashboardController::class, 'parent']);

Route::middleware(['auth:sanctum', 'role:admin'])->get('/admin/ping', function () {
    return \App\Http\ApiResponse::success(['ping' => 'ok']);
});

Route::post('webhooks/payhere', [\App\Http\Controllers\Api\PayHereController::class, 'notify'])
    ->withoutMiddleware([
        ThrottleRequests::class,
    ]);

Route::middleware(['auth:sanctum', 'role:admin,student'])->group(function (): void {
    Route::get('students/{student}/fees', [\App\Http\Controllers\Api\FeeController::class, 'forStudent']);
    Route::get('students/{student}/exam-performance', [\App\Http\Controllers\Api\ExamController::class, 'studentPerformance']);
    Route::get('fees/{fee}', [\App\Http\Controllers\Api\FeeController::class, 'show'])
        ->whereNumber('fee');
    Route::post('payhere/checkout', [\App\Http\Controllers\Api\PayHereController::class, 'checkout']);
    Route::get('fee-payments/{fee_payment}/receipt/pdf', [\App\Http\Controllers\Api\FeePaymentController::class, 'receiptPdf'])
        ->whereNumber('fee_payment');
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function (): void {
    Route::get('dashboard/admin', [DashboardController::class, 'admin']);
    Route::get('search', [SearchController::class, 'index']);

    Route::post('in-app-notifications', [InAppNotificationController::class, 'store']);

    Route::post('students/{student}/photo', [\App\Http\Controllers\Api\StudentController::class, 'uploadPhoto']);
    Route::post('teachers/{teacher}/photo', [\App\Http\Controllers\Api\TeacherController::class, 'uploadPhoto']);

    Route::get('students/{student}/attendances', [\App\Http\Controllers\Api\AttendanceController::class, 'forStudent']);

    Route::get('classes/{class}/schedules', [\App\Http\Controllers\Api\ClassScheduleController::class, 'index']);
    Route::put('classes/{class}/schedules', [\App\Http\Controllers\Api\ClassScheduleController::class, 'replace']);

    Route::get('classes/{class}/barcode-labels', [\App\Http\Controllers\Api\SchoolClassController::class, 'barcodeLabels']);

    Route::get('classes/{class}/attendances/day', [\App\Http\Controllers\Api\AttendanceController::class, 'classDay']);
    Route::post('classes/{class}/attendances/bulk', [\App\Http\Controllers\Api\AttendanceController::class, 'bulkStore']);
    Route::post('classes/{class}/attendances/barcode', [\App\Http\Controllers\Api\AttendanceController::class, 'markByBarcode']);

    Route::get('attendances/stats', [\App\Http\Controllers\Api\AttendanceController::class, 'stats']);
    Route::get('attendances/report', [\App\Http\Controllers\Api\AttendanceController::class, 'report']);
    Route::get('attendances/calendar', [\App\Http\Controllers\Api\AttendanceController::class, 'calendar']);
    Route::get('attendances/export/pdf', [\App\Http\Controllers\Api\AttendanceController::class, 'exportPdf']);
    Route::get('attendances/export/csv', [\App\Http\Controllers\Api\AttendanceController::class, 'exportCsv']);

    Route::get('fees/overview', [\App\Http\Controllers\Api\FeeController::class, 'overview']);
    Route::get('fees/pending', [\App\Http\Controllers\Api\FeeController::class, 'pending']);
    Route::get('fees/report', [\App\Http\Controllers\Api\FeeController::class, 'report']);
    Route::get('fees/export/csv', [\App\Http\Controllers\Api\FeeController::class, 'exportCsv']);

    Route::post('fees/{fee}/payments', [\App\Http\Controllers\Api\FeeController::class, 'recordPayment']);
    Route::apiResource('fees', \App\Http\Controllers\Api\FeeController::class)->except(['show']);

    Route::get('exams/{exam}/mark-sheet', [\App\Http\Controllers\Api\ExamController::class, 'markSheet']);
    Route::put('exams/{exam}/results/bulk', [\App\Http\Controllers\Api\ExamController::class, 'bulkResults']);
    Route::get('exams/{exam}/results', [\App\Http\Controllers\Api\ExamController::class, 'results']);
    Route::get('exams/{exam}/report-card/{student}', [\App\Http\Controllers\Api\ExamController::class, 'reportCard']);

    Route::apiResource('exams', \App\Http\Controllers\Api\ExamController::class);

    Route::get('online-exams/{onlineExam}/attempts', [\App\Http\Controllers\Api\OnlineExamController::class, 'attempts']);
    Route::get('online-exams/{onlineExam}/analytics', [\App\Http\Controllers\Api\OnlineExamController::class, 'analytics']);
    Route::post('online-exams/{onlineExam}/questions', [\App\Http\Controllers\Api\OnlineExamController::class, 'storeQuestion']);
    Route::put('online-exam-questions/{online_exam_question}', [\App\Http\Controllers\Api\OnlineExamController::class, 'updateQuestion']);
    Route::delete('online-exam-questions/{online_exam_question}', [\App\Http\Controllers\Api\OnlineExamController::class, 'destroyQuestion']);
    Route::apiResource('online-exams', \App\Http\Controllers\Api\OnlineExamController::class);

    Route::apiResource('students', \App\Http\Controllers\Api\StudentController::class);
    Route::apiResource('teachers', \App\Http\Controllers\Api\TeacherController::class);
    Route::apiResource('classes', \App\Http\Controllers\Api\SchoolClassController::class);
    Route::apiResource('subjects', \App\Http\Controllers\Api\SubjectController::class);
});
