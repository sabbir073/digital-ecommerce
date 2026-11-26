@extends('storefront.layout')

@section('title', t('Create an account'))

@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
<section class="container-store section-gap">
    <div class="mx-auto max-w-md">
        <h1 class="text-[30px] font-bold">{{ t('Create an account') }}</h1>
        <p class="mt-2 text-muted">{{ t('Keep your orders and downloads in one place.') }}</p>
        <form action="{{ route('register.store') }}" method="post" class="mt-7 space-y-4" novalidate>
            @csrf
        <div>
            <label class="field-label" for="f-name">{{ t('Full name') }}</label>
            <input class="field" id="f-name" name="name" type="text" autocomplete="name" required value="{{ old('name') }}" maxlength="120" @error('name') aria-invalid="true" aria-describedby="e-name" @enderror>
            @error('name') <p class="field-error" id="e-name" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="field-label" for="f-email">{{ t('Email') }}</label>
            <input class="field" id="f-email" name="email" type="email" autocomplete="email" required value="{{ old('email') }}" @error('email') aria-invalid="true" aria-describedby="e-email" @enderror>
            @error('email') <p class="field-error" id="e-email" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="field-label" for="f-password">{{ t('Password (at least 8 characters)') }}</label>
            <input class="field" id="f-password" name="password" type="password" autocomplete="new-password" required minlength="8" @error('password') aria-invalid="true" aria-describedby="e-password" @enderror>
            @error('password') <p class="field-error" id="e-password" role="alert">{{ $message }}</p> @enderror
        </div>
            <button type="submit" class="btn btn-primary w-full">{{ t('Create account') }}</button>
        </form>
        <p class="mt-6 text-muted">{{ t('Already have an account?') }} <a href="{{ route('login') }}" class="font-medium text-accent hover:underline">{{ t('Sign in') }}</a></p>
    </div>
</section>
@endsection
