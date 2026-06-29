<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('audit.view'), 403);

        $logs = Activity::query()
            ->with('causer:id,name,email')
            ->when($request->filled('subject_type'), fn ($q) => $q->where('subject_type', 'like', '%'.$request->subject_type.'%'))
            ->when($request->filled('causer_id'), fn ($q) => $q->where('causer_id', $request->causer_id))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->event))
            ->latest()
            ->paginate($request->integer('per_page', 30));

        return response()->json($logs);
    }
}
