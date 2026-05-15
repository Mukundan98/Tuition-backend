<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Attendance report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
        h1 { font-size: 16px; margin-bottom: 4px; }
        .meta { margin-bottom: 12px; color: #333; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #f0f0f0; }
    </style>
</head>
<body>
    <h1>Attendance report</h1>
    <div class="meta">
        <strong>Class:</strong> {{ $class->name }}@if($class->section) — {{ $class->section }}@endif<br>
        <strong>Period:</strong> {{ $from }} to {{ $to }}
    </div>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Student</th>
                <th>Admission</th>
                <th>Status</th>
                <th>Remark</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row->attended_on->toDateString() }}</td>
                    <td>{{ $row->student->name ?? '—' }}</td>
                    <td>{{ $row->student->admission_number ?? '—' }}</td>
                    <td>{{ $row->status }}</td>
                    <td>{{ $row->remark ?? '' }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No records.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
