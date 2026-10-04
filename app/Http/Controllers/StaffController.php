<?php

namespace App\Http\Controllers;

use App\Actions\SaveStaff;
use App\Http\Requests\StaffRequest;
use App\Models\AuditEntry;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class StaffController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'role' => ['nullable', Rule::in(['admin', 'agent'])], 'status' => ['nullable', Rule::in(['active', 'inactive'])]]);
        $query = User::query();
        if ($q = $data['q'] ?? null) {
            $query->where(fn ($query) => $query->where('name', 'ilike', '%'.$q.'%')->orWhere('email', 'ilike', '%'.$q.'%'));
        }
        if ($role = $data['role'] ?? null) {
            $query->where('role', $role);
        }
        if ($status = $data['status'] ?? null) {
            $query->where('is_active', $status === 'active');
        }

        return view('staff.index', ['staff' => $query->orderBy('name')->paginate(12)->withQueryString(), 'filters' => $data, 'activity' => AuditEntry::whereIn('record_type', ['User', 'CompanySetting'])->latest('id')->limit(8)->get()]);
    }

    public function create(): View
    {
        return view('staff.form', ['staff' => new User(['role' => 'agent', 'is_active' => true])]);
    }

    public function edit(User $staff): View
    {
        Gate::authorize('update', $staff);

        return view('staff.form', compact('staff'));
    }

    public function store(StaffRequest $request, SaveStaff $save): RedirectResponse
    {
        $staff = $save->handle($request->validated());
        $response = redirect()->route('staff.edit', $staff)->with('status', 'Staff account created.');
        if ($staff->is_active) {
            $notice = $this->sendSetup($staff);
            $response->with($notice['sent'] ? 'status' : 'warning', $notice['sent'] ? 'Staff account created. '.$notice['message'] : $notice['message']);
        }

        return $response;
    }

    public function update(StaffRequest $request, User $staff, SaveStaff $save): RedirectResponse
    {
        $save->handle($request->validated(), $staff);

        return redirect()->route('staff.index')->with('status', 'Staff account updated.');
    }

    public function reset(User $staff): RedirectResponse
    {
        Gate::authorize('update', $staff);
        if (! $staff->is_active) {
            return back()->with('warning', 'Activate this account before sending a password setup link.');
        }
        $notice = $this->sendSetup($staff);

        return back()->with($notice['sent'] ? 'status' : 'warning', $notice['message']);
    }

    /** @return array{sent: bool, message: string} */
    private function sendSetup(User $staff): array
    {
        try {
            $result = Password::sendResetLink(['email' => $staff->email, 'is_active' => true]);
            if ($result === Password::RESET_LINK_SENT) {
                DB::transaction(fn () => Audit::record('Staff password setup requested', $staff, Audit::snapshot($staff)));

                return ['sent' => true, 'message' => 'Password setup link sent to the staff email.'];
            }

            return ['sent' => false, 'message' => __($result)];
        } catch (TransportExceptionInterface $e) {
            return ['sent' => false, 'message' => 'The setup email could not be sent. The account is saved; check the mail transport and retry password setup.'];
        }
    }
}
