<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_papers', function (Blueprint $table): void {
            $table->foreignId('class_id')->nullable()->after('teacher_id')->constrained('classes')->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->after('class_id')->constrained('subjects')->nullOnDelete();
            $table->index(['class_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::table('exam_papers', function (Blueprint $table): void {
            $table->dropForeign(['class_id']);
            $table->dropForeign(['subject_id']);
        });
    }
};
