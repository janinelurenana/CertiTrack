<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WebAuthController extends Controller
{
    public static function home(User $u): string
    {
        return match ($u->role) { 'admin' => '/admin', 'instructor' => '/instructor', default => '/student' };
    }

    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $cred = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($cred + ['is_active' => true], $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Invalid credentials or deactivated account.'])->onlyInput('email');
        }
        $request->session()->regenerate();
        return redirect()->intended(self::home(Auth::user()));
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    /** Self-registration creates STUDENT accounts only. Staff accounts are created by an administrator. */
    public function register(Request $request)
    {
        $d = $request->validate([
            'name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email',
            'student_number' => 'nullable|string|max:50', 'password' => 'required|string|min:8|max:128|confirmed',
        ]);
        $u = User::create($d + ['role' => 'student', 'is_active' => true]);
        Auth::login($u);
        $request->session()->regenerate();
        return redirect('/student');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}
