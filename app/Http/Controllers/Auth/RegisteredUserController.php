<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use IFRS\Models\Entity;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Open self-registration (no invite or admin gate; only the route
 * throttles). Custom over stock Breeze: the new user is linked to the
 * instance's first IFRS entity — entity_id is an unfillable FK, so it
 * is assigned explicitly (null on entity-less fresh installs) — then
 * Registered fires and the user is logged straight in.
 */
class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Self-registration joins the instance's entity — users are
        // always linked to one (the ledger resolves through it). Fresh
        // installs without an entity yet skip the link. entity_id is an
        // unfillable FK, assigned explicitly.
        $user = new User;
        $user->fill([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);
        $user->entity_id = Entity::orderBy('id')->value('id');
        $user->save();

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
