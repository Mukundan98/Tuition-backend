<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->string('title');
            $table->date('exam_date');
            $table->decimal('max_marks', 8, 2)->default(100);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['class_id', 'exam_date']);
        });

        Schema::create('exam_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->decimal('marks_obtained', 8, 2)->default(0);
            $table->string('grade', 8)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['exam_id', 'student_id', 'subject_id']);
            $table->index(['exam_id', 'student_id']);
        });

        Schema::create('in_app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 80)->nullable();
            $table->string('title');
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_app_notifications');
        Schema::dropIfExists('exam_results');
        Schema::dropIfExists('exams');
    }
};
