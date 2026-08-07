<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Upload or update profile photo.
     */
    public function updatePhoto(Request $request): RedirectResponse
    {
        $request->validate([
            'profile_photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $user = $request->user();

        // Delete old photo if exists
        if ($user->profile_photo) {
            $oldPath = public_path('storage/' . $user->profile_photo);
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }

        // Store new photo
        $file = $request->file('profile_photo');
        $path = $file->store('profile-photos', 'public');

        // Ensure symlink exists
        $link = public_path('storage');
        $target = storage_path('app/public');
        if (!file_exists($link) && !is_link($link)) {
            symlink($target, $link);
        }

        $user->update(['profile_photo' => $path]);

        return Redirect::route('profile.edit')->with('status', 'photo-updated');
    }

    /**
     * Delete profile photo.
     */
    public function deletePhoto(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->profile_photo) {
            $path = public_path('storage/' . $user->profile_photo);
            if (file_exists($path)) {
                unlink($path);
            }
            $user->update(['profile_photo' => null]);
        }

        return Redirect::route('profile.edit')->with('status', 'photo-deleted');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        // Force delete to completely remove the user (bypass soft deletes)
        $user->forceDelete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
