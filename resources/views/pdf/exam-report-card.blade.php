<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Report card</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
        h1 { font-size: 15px; margin: 0 0 8px 0; }
        .muted { color: #444; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; }
        th { background: #f4f4f4; }
        .right { text-align: right; }
        .foot { margin-top: 14px; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $appName }}</h1>
    <p class="muted">Report card</p>
    <p><strong>{{ $student->name }}</strong> · {{ $student->admission_number }}</p>
    <p>{{ $exam->title }} · {{ $exam->exam_date->toDateString() }}
        @if($exam->schoolClass)
            · {{ $exam->schoolClass->name }}
        @endif
    </p>
    <table>
        <thead>
            <tr>
                <th>Subject</th>
                <th>Code</th>
                <th class="right">Marks</th>
                <th class="right">Max</th>
                <th>Grade</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lines as $line)
                <tr>
                    <td>{{ $line->subject->name ?? '—' }}</td>
                    <td>{{ $line->subject->code ?? '—' }}</td>
                    <td class="right">{{ $line->marks_obtained }}</td>
                    <td class="right">{{ $maxMarks }}</td>
                    <td>{{ $line->grade }}</td>
                    <td>{{ $line->remarks ?? '' }}</td>
                </tr>
            @empty
                <tr><td colspan="6">No marks recorded.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="foot">
        Average: {{ $averageMarks }} / {{ $maxMarks }} · Grade {{ $averageGrade }}
    </p>
</body>
</html>
