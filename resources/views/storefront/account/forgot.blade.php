@extends('storefront.layout')

@section('title', t('Reset your password'))

@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
<section class="container-store section-gap">
    <div class="mx-auto max-w-md">
        <h1 class="text-[30px] font-bold">{{ t('Reset your password') }}</h1>
        <p class="mt-2 text-muted">{{ t('Enter your email and we will send you a link to choose a new password.') }}</p>
        <form action="{{ route('password.email') }}" method="post" class="mt-7 space-y-4" novalidate>
            @csrf
        <div>
            <label class="field-label" for="f-email">{{ t('Email') }}</label>
            <input class="field" id="f-email" name="email" type="email" autocomplete="email" required value="{{ old('email') }}" @error('email') aria-invalid="true" aria-describedby="e-email" @enderror>
            @error('email') <p class="field-error" id="e-email" role="alert">{{ $message }}</p> @enderror
        </div>
            <button type="submit" class="btn btn-primary w-full">{{ t('Send reset link') }}</button>
        </form>
        <p class="mt-6 text-muted"><a href="{{ route('login') }}" class="font-medium text-accent hover:underline">{{ t('Back to sign in') }}</a></p>
    </div>
</section>
@endsection
