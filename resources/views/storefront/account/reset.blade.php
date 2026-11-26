@extends('storefront.layout')

@section('title', t('Choose a new password'))

@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
<section class="container-store section-gap">
    <div class="mx-auto max-w-md">
        <h1 class="text-[30px] font-bold">{{ t('Choose a new password') }}</h1>
        <p class="mt-2 text-muted">{{ t('Use at least 8 characters.') }}</p>
        <form action="{{ route('password.update') }}" method="post" class="mt-7 space-y-4" novalidate>
            @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label class="field-label" for="f-email">{{ t('Email') }}</label>
            <input class="field" id="f-email" name="email" type="email" autocomplete="email" required value="{{ old('email', $email) }}" @error('email') aria-invalid="true" aria-describedby="e-email" @enderror>
            @error('email') <p class="field-error" id="e-email" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="field-label" for="f-password">{{ t('New password') }}</label>
            <input class="field" id="f-password" name="password" type="password" autocomplete="new-password" required minlength="8" @error('password') aria-invalid="true" aria-describedby="e-password" @enderror>
            @error('password') <p class="field-error" id="e-password" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="field-label" for="f-password_confirmation">{{ t('Repeat the new password') }}</label>
            <input class="field" id="f-password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="8" @error('password_confirmation') aria-invalid="true" aria-describedby="e-password_confirmation" @enderror>
            @error('password_confirmation') <p class="field-error" id="e-password_confirmation" role="alert">{{ $message }}</p> @enderror
        </div>
            <button type="submit" class="btn btn-primary w-full">{{ t('Change password') }}</button>
        </form>
        <p class="mt-6 text-muted"><a href="{{ route('login') }}" class="font-medium text-accent hover:underline">{{ t('Back to sign in') }}</a></p>
    </div>
</section>
@endsection
