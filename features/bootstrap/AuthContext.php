<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Authentication, registration, password reset/update and email
 * verification — ported from tests/Feature/Auth/* and ExampleTest.
 */
class AuthContext extends BaseContext
{
    private string $verificationUrl = '';

    /**
     * @Given an unverified user :email exists with password :password
     */
    public function anUnverifiedUserExists(string $email, string $password): void
    {
        User::factory()->unverified()->create([
            'email' => $email,
            'password' => bcrypt($password),
        ]);
    }

    /**
     * @Given notifications are faked
     */
    public function notificationsAreFaked(): void
    {
        Notification::fake();
    }

    /**
     * @When I request a password reset link for :email
     */
    public function iRequestAPasswordResetLink(string $email): void
    {
        $this->send('POST', '/forgot-password', ['email' => $email]);
    }

    /**
     * @Then a password reset notification should have been sent to :email
     */
    public function aPasswordResetNotificationShouldHaveBeenSent(string $email): void
    {
        $user = User::where('email', $email)->first();
        Notification::assertSentTo($user, \Illuminate\Auth\Notifications\ResetPassword::class);
    }

    /**
     * @When I open the reset password page from the link sent to :email
     */
    public function iOpenTheResetPasswordPage(string $email): void
    {
        $token = $this->resetTokenFor($email);
        $this->send('GET', '/reset-password/' . $token);
        $this->assertResponseStatus(200);
    }

    /**
     * @When I reset the password for :email to :password using the sent token
     */
    public function iResetThePassword(string $email, string $password): void
    {
        $this->send('POST', '/reset-password', [
            'token' => $this->resetTokenFor($email),
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    private function resetTokenFor(string $email): string
    {
        $user = User::where('email', $email)->first();
        $sent = Notification::sent($user, \Illuminate\Auth\Notifications\ResetPassword::class);

        foreach ($sent as $notification) {
            if (isset($notification->token)) {
                return $notification->token;
            }
        }

        throw new RuntimeException("No reset password notification was captured for {$email}.");
    }

    /**
     * @Then the stored password for :email should be :password
     */
    public function theStoredPasswordShouldBe(string $email, string $password): void
    {
        $hash = User::where('email', $email)->value('password');
        if (!\Illuminate\Support\Facades\Hash::check($password, $hash)) {
            throw new RuntimeException("Stored password for {$email} does not match '{$password}'.");
        }
    }

    /**
     * @Given a signed verification link exists for :email
     */
    public function aSignedVerificationLinkExists(string $email): void
    {
        $user = User::where('email', $email)->first();
        $this->verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );
    }

    /**
     * @Given a signed verification link with an invalid hash exists for :email
     */
    public function anInvalidVerificationLinkExists(string $email): void
    {
        $user = User::where('email', $email)->first();
        $this->verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')],
        );
    }

    /**
     * @When I visit the verification link
     */
    public function iVisitTheVerificationLink(): void
    {
        $this->client()->followRedirects(true);
        $this->client()->request('GET', $this->verificationUrl);
    }

    /**
     * @Then :email should be verified
     */
    public function emailShouldBeVerified(string $email): void
    {
        if (!User::where('email', $email)->whereNotNull('email_verified_at')->exists()) {
            throw new RuntimeException("{$email} was expected to have a verified email.");
        }
    }

    /**
     * @Then :email should not be verified
     */
    public function emailShouldNotBeVerified(string $email): void
    {
        if (User::where('email', $email)->whereNotNull('email_verified_at')->exists()) {
            throw new RuntimeException("{$email} was expected to have an unverified email.");
        }
    }

    /**
     * @When I log out
     */
    public function iLogOut(): void
    {
        $this->send('POST', '/logout');
    }

    /**
     * @Then I should be redirected to the login page
     */
    public function iShouldBeRedirectedToTheLoginPage(): void
    {
        $this->send('GET', '/dashboard');
        $this->assertSession()->addressEquals($this->locatePath('/login'));
    }

    /**
     * @Then the validation bag :bag should fail on :field
     */
    public function theValidationBagShouldFailOn(string $bag, string $field): void
    {
        $errors = $this->lastErrors ?? $this->container()->make('session')->get('errors');
        if (!$errors || !$errors->hasBag($bag) || !$errors->getBag($bag)->has($field)) {
            throw new RuntimeException("Expected a '{$bag}' validation error on '{$field}'.");
        }
    }

    /**
     * @When I update my password from :current to :new
     */
    public function iUpdateMyPassword(string $current, string $new): void
    {
        // Referer mirrors the PHPUnit port's from('/profile') — the
        // controller redirects back().
        $this->send('PUT', '/password', [
            'current_password' => $current,
            'password' => $new,
            'password_confirmation' => $new,
        ], ['HTTP_REFERER' => $this->locatePath('/profile')]);
    }
}
