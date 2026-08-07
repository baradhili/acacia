<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Profile Photo') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Update your profile photo.') }}
        </p>
    </header>

    <div class="mt-6 flex items-center gap-4">
        @if($user->profile_photo_url)
            <img src="{{ $user->profile_photo_url }}" alt="{{ $user->name }}" class="h-24 w-24 rounded-full object-cover">
        @else
            <div class="h-24 w-24 rounded-full bg-indigo-600 flex items-center justify-center text-white text-2xl font-bold">
                {{ $user->initials }}
            </div>
        @endif
    </div>

    <form method="post" action="{{ route('profile.photo.update') }}" enctype="multipart/form-data" class="mt-6">
        @csrf
        @method('post')

        <div>
            <label for="profile_photo" class="block text-sm font-medium text-gray-700">Photo</label>
            <input type="file" name="profile_photo" id="profile_photo" accept="image/*" 
                   class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
            <x-input-error class="mt-2" :messages="$errors->get('profile_photo')" />
        </div>

        <div class="mt-4 flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'photo-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>

    @if($user->profile_photo_url)
        <form method="post" action="{{ route('profile.photo.delete') }}" class="mt-4">
            @csrf
            @method('delete')
            <x-danger-button>{{ __('Delete Photo') }}</x-danger-button>
        </form>
    @endif
</section>
