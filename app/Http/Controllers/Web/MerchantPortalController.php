<?php

namespace App\Http\Controllers\Web;

use App\Actions\RegisterMerchant;
use App\Enums\DocumentType;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\RegisterRequest;
use App\Services\Kyc\DocumentService;
use App\Services\Merchant\EarningsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Merchant onboarding on nexmile.in.
 *
 * Session-based, sharing the same users table and RegisterMerchant action as
 * the JSON API — a merchant registered here can sign in to the React portal
 * later with the same credentials.
 */
class MerchantPortalController extends Controller
{
    public function showRegister(): View
    {
        return view('merchants.register');
    }

    public function register(RegisterRequest $request, RegisterMerchant $action): RedirectResponse
    {
        $user = $action->handle($request->validated());

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('merchants.dashboard');
    }

    public function showLogin(): View
    {
        return view('merchants.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Accepts the registered email or the 10-digit mobile number.
        $field = filter_var($credentials['identifier'], FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        $ok = Auth::attempt([
            $field => $credentials['identifier'],
            'password' => $credentials['password'],
            'role' => UserRole::Merchant->value,
        ], $request->boolean('remember'));

        if (! $ok) {
            throw ValidationException::withMessages([
                'identifier' => __('These credentials do not match our records.'),
            ]);
        }

        if (Auth::user()->status === UserStatus::Suspended) {
            Auth::logout();

            throw ValidationException::withMessages([
                'identifier' => __('This account has been suspended. Contact support.'),
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('merchants.dashboard'));
    }

    public function dashboard(Request $request, DocumentService $service, EarningsService $earnings): View
    {
        $merchant = $this->merchant($request);

        return view('merchants.dashboard', [
            'user' => $request->user(),
            'merchant' => $merchant,
            'documents' => $merchant->kycDocuments()->latest('id')->get()->keyBy(
                fn ($d) => $d->type instanceof DocumentType ? $d->type->value : $d->type
            ),
            'allowed' => config('kyc.allowed.merchant'),
            'required' => config('kyc.required.merchant'),
            'missing' => $service->missingDocuments($merchant, 'merchant'),

            // The daily view only means anything once they can actually trade.
            'today' => $merchant->isKycVerified()
                ? $earnings->summary($merchant, now(), now())
                : null,
            'liveOrders' => $merchant->isKycVerified()
                ? $merchant->orders()->active()->where('status', '!=', OrderStatus::PendingPayment->value)->count()
                : 0,
        ]);
    }

    /**
     * Open or close the storefront.
     *
     * The most-used control a merchant has and it had no home in the portal —
     * a kitchen that is suddenly swamped could not stop the queue without
     * calling someone.
     */
    public function setAcceptingOrders(Request $request): RedirectResponse
    {
        $merchant = $this->merchant($request);
        $open = $request->boolean('is_accepting_orders');

        if ($open) {
            if (! $merchant->isKycVerified()) {
                return back()->withErrors(['is_accepting_orders' => __('portal.storefront.not_verified')]);
            }

            // An expired food licence is a legal problem, not a warning.
            if (! $merchant->hasValidFssai()) {
                return back()->withErrors(['is_accepting_orders' => __('portal.storefront.fssai_expired')]);
            }
        }

        $merchant->update(['is_accepting_orders' => $open]);

        return back()->with('status', $open
            ? __('portal.storefront.now_open')
            : __('portal.storefront.now_closed'));
    }

    public function uploadDocument(Request $request, DocumentService $service): RedirectResponse
    {
        $data = $request->validate(
            DocumentService::uploadRules(config('kyc.allowed.merchant')),
            DocumentService::uploadMessages(),
        );

        $service->store(
            $this->merchant($request),
            DocumentType::from($data['type']),
            $request->file('file'),
            'merchant',
        );

        return back()->with('status', __('Document uploaded.'));
    }

    public function destroyDocument(Request $request, DocumentService $service, int $document): RedirectResponse
    {
        // Scoped to this merchant, so another account's id is simply not found.
        $model = $this->merchant($request)->kycDocuments()->findOrFail($document);

        $service->delete($model);

        return back()->with('status', __('Document removed.'));
    }

    public function submitKyc(Request $request, DocumentService $service): RedirectResponse
    {
        $service->submitForReview($this->merchant($request), 'merchant');

        return back()->with('status', __('Submitted for verification.'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function merchant(Request $request)
    {
        $merchant = $request->user()->merchant;

        abort_if($merchant === null, 404);

        return $merchant;
    }
}
