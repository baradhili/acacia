<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    public function test_user_can_upload_a_profile_photo(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post('/profile/photo', [
                'profile_photo' => UploadedFile::fake()->image('avatar.jpg', 80, 80),
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile')
            ->assertSessionHas('status', 'photo-updated');

        $user->refresh();
        $this->assertNotNull($user->profile_photo);
        Storage::disk('public')->assertExists($user->profile_photo);

        // The avatar is served from the stored path.
        $this->assertSame(asset('storage/'.$user->profile_photo), $user->profile_photo_url);
    }

    public function test_replacing_or_deleting_a_photo_removes_the_old_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $this->actingAs($user)->post('/profile/photo', [
            'profile_photo' => UploadedFile::fake()->image('first.jpg', 80, 80),
        ])->assertSessionHas('status', 'photo-updated');

        $oldPath = $user->refresh()->profile_photo;

        // Replacing removes the previous upload…
        $this->actingAs($user)->post('/profile/photo', [
            'profile_photo' => UploadedFile::fake()->image('second.png', 80, 80),
        ])->assertSessionHas('status', 'photo-updated');

        $this->assertNotSame($oldPath, $user->refresh()->profile_photo);
        Storage::disk('public')->assertMissing($oldPath);

        // …and so does deleting.
        $newPath = $user->profile_photo;
        $this->actingAs($user)->delete('/profile/photo')
            ->assertSessionHas('status', 'photo-deleted');

        $this->assertNull($user->refresh()->profile_photo);
        Storage::disk('public')->assertMissing($newPath);
    }

    public function test_photo_upload_rejects_non_images_and_oversized_files(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $this->actingAs($user)->post('/profile/photo', [
            'profile_photo' => UploadedFile::fake()->create('not-an-image.pdf', 100),
        ])->assertSessionHasErrors('profile_photo');

        $this->actingAs($user)->post('/profile/photo', [
            'profile_photo' => UploadedFile::fake()->image('huge.jpg', 80, 80)->size(3000),
        ])->assertSessionHasErrors('profile_photo');

        $this->assertNull($user->fresh()->profile_photo);
    }
}
