<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureEmailIsVerified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EnsureEmailIsVerifiedTest extends TestCase
{
    private function account(bool $verified): MustVerifyEmail
    {
        return new class($verified) implements MustVerifyEmail
        {
            public function __construct(private readonly bool $verified) {}

            public function hasVerifiedEmail(): bool
            {
                return $this->verified;
            }

            public function markEmailAsVerified(): bool
            {
                return true;
            }

            public function markEmailAsUnverified(): bool
            {
                return true;
            }

            public function sendEmailVerificationNotification(): void {}

            public function getEmailForVerification(): string
            {
                return 'ana@example.com';
            }
        };
    }

    /**
     * A JSON request, so a refusal is a 403 and needs no confirm page route.
     */
    private function pass(MustVerifyEmail $account): bool
    {
        $request = Request::create('/', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
        $request->setUserResolver(fn () => $account);

        $reached = false;
        (new EnsureEmailIsVerified)->handle($request, function () use (&$reached) {
            $reached = true;

            return response('');
        });

        return $reached;
    }

    public function test_without_mail_an_unconfirmed_account_goes_through()
    {
        config(['mail.default' => 'log']);

        $this->assertTrue($this->pass($this->account(verified: false)));
    }

    public function test_with_mail_a_confirmed_account_goes_through()
    {
        config(['mail.default' => 'smtp']);

        $this->assertTrue($this->pass($this->account(verified: true)));
    }

    public function test_with_mail_an_unconfirmed_account_is_refused()
    {
        config(['mail.default' => 'smtp']);

        $this->expectException(HttpException::class);

        $this->pass($this->account(verified: false));
    }
}
