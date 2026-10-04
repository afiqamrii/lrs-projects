<?php

namespace App\Http\Controllers;

use App\Actions\SaveVendor;
use App\Http\Requests\VendorRequest;
use App\Models\AuditEntry;
use App\Models\Vendor;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VendorController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Vendor::class);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', Rule::in(['active', 'inactive'])], 'type' => ['nullable', Rule::in(array_keys(Vendor::TYPES))], 'service' => ['nullable', Rule::in(Vendor::SERVICES)], 'contact' => ['nullable', Rule::in(['missing', 'ready'])]]);
        $query = Vendor::with('primaryContact')->withCount('contacts');
        if ($q = $filters['q'] ?? null) {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $query->where(fn ($builder) => $builder->where('company_name', 'ilike', $pattern)->orWhere('display_name', 'ilike', $pattern)->orWhere('coverage', 'ilike', $pattern)->orWhereHas('contacts', fn ($contacts) => $contacts->where('name', 'ilike', $pattern)->orWhere('email', 'ilike', $pattern)));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('is_active', $status === 'active');
        }
        if ($type = $filters['type'] ?? null) {
            $query->where('type', $type);
        }
        if ($service = $filters['service'] ?? null) {
            $query->whereJsonContains('services', $service);
        }
        if ($contact = $filters['contact'] ?? null) {
            $contact === 'missing' ? $query->whereDoesntHave('primaryContact') : $query->whereHas('primaryContact');
        }

        return view('vendors.index', ['vendors' => $query->orderByRaw('lower(company_name)')->orderBy('id')->paginate(12)->withQueryString(), 'filters' => $filters, 'total' => Vendor::count(), 'active' => Vendor::where('is_active', true)->count(), 'missing' => Vendor::whereDoesntHave('primaryContact')->count()]);
    }

    public function create(): View
    {
        Gate::authorize('create', Vendor::class);

        return view('vendors.form', ['vendor' => new Vendor(['communication_channel' => 'email', 'services' => []])]);
    }

    public function edit(Vendor $vendor): View
    {
        Gate::authorize('update', $vendor);

        return view('vendors.form', compact('vendor'));
    }

    public function show(Vendor $vendor): View
    {
        Gate::authorize('view', $vendor);
        $vendor->load('contacts', 'primaryContact');
        $duplicates = Vendor::where('id', '!=', $vendor->id)->whereRaw('lower(company_name) = ?', [mb_strtolower($vendor->company_name)])->get();

        return view('vendors.show', ['vendor' => $vendor, 'duplicates' => $duplicates, 'activity' => AuditEntry::where('vendor_id', $vendor->id)->latest('id')->paginate(10)]);
    }

    public function store(VendorRequest $request, SaveVendor $save): RedirectResponse
    {
        return redirect()->route('vendors.show', $save->handle($request->validated()))->with('status', 'Vendor created. Your directory is up to date.');
    }

    public function update(VendorRequest $request, Vendor $vendor, SaveVendor $save): RedirectResponse
    {
        $save->handle($request->validated(), $vendor);

        return redirect()->route('vendors.show', $vendor)->with('status', 'Vendor details updated.');
    }

    public function statusForm(Vendor $vendor): View
    {
        Gate::authorize('update', $vendor);

        return view('vendors.status', compact('vendor'));
    }

    public function status(Request $request, Vendor $vendor): RedirectResponse
    {
        Gate::authorize('update', $vendor);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($vendor, $data): void {
            $vendor = Vendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            $before = Audit::snapshot($vendor);
            $vendor->update($data);
            Audit::record($vendor->is_active ? 'Vendor activated' : 'Vendor deactivated', $vendor, $before, $vendor->id);
        });

        return redirect()->route('vendors.show', $vendor)->with('status', $data['is_active'] ? 'Vendor activated.' : 'Vendor deactivated. Details and history are preserved.');
    }
}
