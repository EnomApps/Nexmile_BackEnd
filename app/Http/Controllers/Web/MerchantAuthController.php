<?php

namespace App\Http\Controllers\Web;

use App\Actions\RegisterMerchant;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session-based auth for the Blade merchant portal.
 *
 * The JSON API in Api\V1\Merchant\AuthController stays token-based for the
 * Flutter and React clients; both share the same users table and RegisterMerchant action.
 */
class MerchantAuthController extends Controller
{
    public function showRegister(): View
    {
        return view('merchant.register');
    }

    public function register(RegisterRequest $request, RegisterMerchant $registerMerchant): RedirectResponse
    {
        $user = $registerMerchant->handle($request->validated());

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()
            ->route('merchant.dashboard')
            ->with('status', 'Welcome to Nexmile. Your account is pending verification.');
    }

    public function showLogin(): View
    {
        return view('merchant.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Accepts either the registered email or the 10-digit mobile number.
        $field = filter_var($credentials['identifier'], FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        $attempted = Auth::attempt([
            $field => $credentials['identifier'],
            'password' => $credentials['password'],
            'role' => UserRole::Merchant->value,
        ], $request->boolean('remember'));

        if (! $attempted) {
            throw ValidationException::withMessages([
                'identifier' => 'These credentials do not match our records.',
            ]);
        }

        if (Auth::user()->status === UserStatus::Suspended) {
            Auth::logout();

            throw ValidationException::withMessages([
                'identifier' => 'This account has been suspended. Contact support.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('merchant.dashboard'));
    }

    public function dashboard(Request $request): View
    {
        $user = $request->user();

        abort_unless($user->hasRole(UserRole::Merchant), 403);

        return view('merchant.dashboard', [
            'user' => $user,
            'merchant' => $user->merchant,
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
