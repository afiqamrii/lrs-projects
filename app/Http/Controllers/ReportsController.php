<?php

namespace App\Http\Controllers;

use App\Models\Inquiry;
use App\Models\User;
use App\Models\Vendor;
use App\Support\OperationalReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function index(Request $request, OperationalReports $reports): View
    {
        Gate::authorize('viewAny', Inquiry::class);
        $filters = $reports->filters($request);

        return view('reports.index', [
            'filters' => $filters, 'summary' => $reports->summary($filters),
            'rows' => $reports->query($filters)->orderByDesc('id')->paginate(25)->withQueryString(),
            'owners' => User::orderBy('name')->get(['id', 'name']),
            'vendors' => Vendor::orderByRaw('COALESCE(display_name, company_name)')->get(['id', 'display_name', 'company_name']),
        ]);
    }

    public function export(Request $request, OperationalReports $reports): StreamedResponse
    {
        Gate::authorize('viewAny', Inquiry::class);
        $filters = $reports->filters($request);
        $query = $reports->query($filters)->orderBy('id');
        $limit = config('operations.export_limit');
        if (DB::query()->fromSub((clone $query)->limit($limit + 1), 'bounded_export')->count() > $limit) {
            throw ValidationException::withMessages(['export' => 'This export exceeds 5,000 rows. Narrow the period or filters, then export again.']);
        }
        $headers = match ($filters['tab']) {
            'rfqs' => ['RFQ', 'Inquiry', 'Provenance', 'Vendor', 'Sent revision', 'Sent at UTC', 'Send evidence', 'First meaningful reply UTC', 'Response hours'],
            'ai' => ['Run', 'Inquiry', 'Provenance', 'Requested at UTC', 'Purpose', 'Model', 'State', 'Recorded input tokens', 'Recorded output tokens', 'Recorded estimated cost', 'Currency', 'Held reservation', 'Cost evidence'],
            default => ['Inquiry', 'Title', 'Provenance', 'Received at UTC', 'Current owner', 'Source', 'Current status', 'Response due UTC', 'Quotation', 'Current revision', 'Current recorded outcome', 'Decision at UTC', 'Decision channel', 'Selected native cost', 'Selected currency', 'Quoted cost subtotal', 'Quoted selling subtotal', 'Quoted total including tax', 'Estimated quoted profit', 'Quotation currency', 'Approved at UTC', 'Send evidence', 'Actual handoff UTC', 'Actual booking UTC', 'Next action'],
        };

        return response()->streamDownload(function () use ($reports, $query, $filters, $headers, $limit): void {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, $headers, ',', '"', '');
            foreach ($query->limit($limit)->cursor() as $row) {
                fputcsv($stream, array_map(OperationalReports::csvCell(...), $reports->csvRow($filters['tab'], $row)), ',', '"', '');
            }
            fclose($stream);
        }, 'lrs-'.$filters['tab'].'-'.$filters['from'].'-'.$filters['to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
