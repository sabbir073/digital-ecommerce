<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Mail\PasswordReset;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** The reset link goes out in the store's own email template, which the owner can edit. */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $url = route('password.reset', ['token' => $token, 'email' => $this->getEmailForPasswordReset()]);
        Mail::to($this->email)->send(new PasswordReset((string) $this->name, $url, (int) config('auth.passwords.users.expire', 60)));
    }
}
