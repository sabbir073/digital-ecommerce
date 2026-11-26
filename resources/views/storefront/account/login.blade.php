@extends('storefront.layout')

@section('title', 'Sign in')

@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
<section class="container-store section-gap">
    <div class="mx-auto max-w-md">
        <h1 class="text-[30px] font-bold">Sign in</h1>
        <p class="mt-2 text-muted">See your orders and download your files again.</p>
        <form action="{{ route('login.store') }}" method="post" class="mt-7 space-y-4" novalidate>
            @csrf
        <div>
            <label class="field-label" for="f-email">Email</label>
            <input class="field" id="f-email" name="email" type="email" autocomplete="email" required value="{{ old('email') }}" @error('email') aria-invalid="true" aria-describedby="e-email" @enderror>
            @error('email') <p class="field-error" id="e-email" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="field-label" for="f-password">Password</label>
            <input class="field" id="f-password" name="password" type="password" autocomplete="current-password" required  @error('password') aria-invalid="true" aria-describedby="e-password" @enderror>
            @error('password') <p class="field-error" id="e-password" role="alert">{{ $message }}</p> @enderror
        </div>
        <div class="flex items-center justify-between">
            <label class="check"><input type="checkbox" name="remember" value="1"><span>Keep me signed in</span></label>
            <a href="{{ route('password.request') }}" class="inline-flex min-h-11 items-center font-medium text-accent hover:underline">Forgot password?</a>
        </div>
            <button type="submit" class="btn btn-primary w-full">Sign in</button>
        </form>
        <p class="mt-6 text-muted">New here? <a href="{{ route('register') }}" class="font-medium text-accent hover:underline">Create an account</a></p>
    </div>
</section>
@endsection
