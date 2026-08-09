<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\DocumentStatus;
use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Rider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin review screens on nexmile.in.
 *
 * Same decisions as the admin API, for people rather than machines. Not linked
 * from the public navigation.
 */
class AdminController extends Controller
{
    public function showLogin(): View
    {
        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $ok = Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'role' => UserRole::Admin->value,
        ], $request->boolean('remember'));

        if (! $ok) {
            // Deliberately vague: a distinct "no such admin" message would let
            // anyone enumerate which addresses hold admin accounts.
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (Auth::user()->status === UserStatus::Suspended) {
            Auth::logout();

            throw ValidationException::withMessages(['email' => 'This account is suspended.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('admin.index'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    /** Review queue, filtered by KYC state. */
    /**
     * Commercial terms, which only an admin may change.
     *
     * Kept off the merchant's own profile endpoint deliberately: commission is
     * a contract term, not a preference, and must never be settable by the
     * account it charges.
     */
    public function updateTerms(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'commission_rate' => ['required', 'numeric', 'between:0,'.config('checkout.max_commission_rate')],
        ], [
            'commission_rate.between' => 'Commission must be between 0 and '
                .config('checkout.max_commission_rate').'%. A higher rate would take more than the order is worth.',
        ]);

        $merchant = Merchant::findOrFail($id);

        // forceFill: not mass-assignable anywhere, by design.
        $merchant->forceFill(['commission_rate' => (float) $data['commission_rate']])->save();

        /*
         * Existing orders keep the commission they were placed with. Those
         * figures are already on invoices and payout statements, and a rate
         * change is not retrospective.
         */
        return back()->with('status', 'Commission set to '.$data['commission_rate'].'%. Existing orders are unchanged.');
    }

    /**
     * The food licence, recorded from the certificate being reviewed.
     *
     * Deliberately not on the merchant's own profile: letting a verified
     * merchant edit their own FSSAI number would make verification
     * meaningless. The admin reading the uploaded certificate is the only
     * person who should type what it says.
     *
     * Without it `hasValidFssai()` is false and the merchant cannot switch
     * themselves on — so until this existed, a merchant who registered through
     * the website could never trade and nothing in any screen could fix it.
     */
    public function updateCompliance(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'fssai_license_no' => ['required', 'string', 'regex:/^\d{14}$/'],
            'fssai_expiry_date' => ['required', 'date', 'after:today'],
            'gstin' => ['nullable', 'string', 'size:15'],
        ], [
            'fssai_license_no.regex' => 'An FSSAI licence number is exactly 14 digits.',
            'fssai_expiry_date.after' => 'That licence has already expired — ask the merchant for a current one.',
            'gstin.size' => 'A GSTIN is 15 characters.',
        ]);

        Merchant::findOrFail($id)->update($data);

        return back()->with('status', 'Licence details saved. The restaurant can now open.');
    }

    public function index(Request $request): View
    {
        $status = $request->string('status', KycStatus::Submitted->value)->toString();

        if (! in_array($status, array_column(KycStatus::cases(), 'value'), true)) {
            $status = KycStatus::Submitted->value;
        }

        return view('admin.index', [
            'status' => $status,
            'merchants' => Merchant::where('kyc_status', $status)->with('user')
                ->withCount('kycDocuments')->oldest('updated_at')->paginate(20, ['*'], 'merchants'),
            'riders' => Rider::where('kyc_status', $status)->with('user')
                ->withCount('kycDocuments')->oldest('updated_at')->paginate(20, ['*'], 'riders'),
            'counts' => [
                'submitted' => Merchant::where('kyc_status', KycStatus::Submitted)->count()
                    + Rider::where('kyc_status', KycStatus::Submitted)->count(),
                'pending' => Merchant::where('kyc_status', KycStatus::Pending)->count()
                    + Rider::where('kyc_status', KycStatus::Pending)->count(),
                'verified' => Merchant::where('kyc_status', KycStatus::Verified)->count()
                    + Rider::where('kyc_status', KycStatus::Verified)->count(),
                'rejected' => Merchant::where('kyc_status', KycStatus::Rejected)->count()
                    + Rider::where('kyc_status', KycStatus::Rejected)->count(),
            ],
        ]);
    }

    /** One account with its documents. */
    public function show(string $type, int $id): View
    {
        $owner = $this->resolve($type, $id);

        return view('admin.show', [
            'type' => $type,
            'owner' => $owner->load('user'),
            'documents' => $owner->kycDocuments()->latest('id')->get(),
            'required' => config("kyc.required.{$this->roleFor($type)}"),
        ]);
    }

    public function reviewDocument(Request $request, string $type, int $id, int $document): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'rejection_reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:500'],
        ], [
            'rejection_reason.required_if' => 'Tell the applicant what to fix.',
        ]);

        $owner = $this->resolve($type, $id);
        $model = $owner->kycDocuments()->findOrFail($document);

        $model->update([
            'status' => DocumentStatus::from($data['status']),
            'rejection_reason' => $data['status'] === 'rejected' ? $data['rejection_reason'] : null,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Document '.$data['status'].'.');
    }

    public function verify(Request $request, string $type, int $id): RedirectResponse
    {
        $owner = $this->resolve($type, $id);

        // Approving an account while a document is still rejected would leave
        // the record contradicting itself.
        if ($owner->kycDocuments()->where('status', DocumentStatus::Rejected->value)->exists()) {
            return back()->withErrors(['verify' => 'Resolve the rejected documents first.']);
        }

        DB::transaction(function () use ($owner, $request) {
            $owner->kycDocuments()->where('status', DocumentStatus::Pending->value)->update([
                'status' => DocumentStatus::Approved->value,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $owner->update([
                'kyc_status' => KycStatus::Verified,
                'kyc_rejection_reason' => null,
                'kyc_verified_at' => now(),
            ]);

            $owner->user?->update(['status' => UserStatus::Active]);
        });

        return back()->with('status', 'Account verified.');
    }

    public function reject(Request $request, string $type, int $id): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'reason.min' => 'Give a reason the applicant can act on.',
        ]);

        $this->resolve($type, $id)->update([
            'kyc_status' => KycStatus::Rejected,
            'kyc_rejection_reason' => $data['reason'],
            'kyc_verified_at' => null,
        ]);

        return back()->with('status', 'Account rejected.');
    }

    public function setStatus(Request $request, string $type, int $id): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,suspended'],
        ]);

        $owner = $this->resolve($type, $id);
        $status = UserStatus::from($data['status']);

        DB::transaction(function () use ($owner, $status) {
            $owner->user?->update(['status' => $status]);

            if ($status === UserStatus::Suspended) {
                // Stop orders now, not when they next open the dashboard.
                if ($owner instanceof Merchant) {
                    $owner->update(['is_accepting_orders' => false]);
                }

                $owner->user?->tokens()->delete();
            }
        });

        return back()->with('status', $status === UserStatus::Suspended ? 'Account suspended.' : 'Account reinstated.');
    }

    private function resolve(string $type, int $id): Model
    {
        return match ($type) {
            'merchants' => Merchant::findOrFail($id),
            'riders' => Rider::findOrFail($id),
            default => abort(404),
        };
    }

    private function roleFor(string $type): string
    {
        return $type === 'merchants' ? 'merchant' : 'rider';
    }
}
