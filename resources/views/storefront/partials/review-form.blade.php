{{-- "Write a review" form. $form comes from ReviewController::formContext(); $subject names what is reviewed. --}}
<div id="write-review" class="review-form-wrap">
    <h3 class="text-[22px] font-semibold">{{ t('Write a review') }}</h3>
    @if (! $form['allowed'])
        <p class="mt-3 text-muted">
            {{ t('Reviews here are written by customers who bought from us.') }}
            @guest('web') <a href="{{ route('login') }}" class="font-medium text-accent hover:underline">{{ t('Sign in') }}</a> {{ t('to the account you ordered with, or use the link in your review email.') }} @endguest
        </p>
    @else
        @if ($form['verified'])
            <p class="mt-2 inline-flex items-center gap-1.5 text-label text-muted">@include('storefront.partials.icon', ['name' => 'check', 'size' => 16]) {{ t('Your review will carry the “Verified buyer” badge.') }}</p>
        @endif
        <form method="post" action="{{ $form['action'] }}" enctype="multipart/form-data" class="mt-5 grid gap-5" novalidate data-review-form>
            @csrf
            {{-- Left empty by people. Anything typed here marks the review as spam. --}}
            <div class="review-hp" aria-hidden="true">
                <label for="rv-website">{{ t('Website') }}</label>
                <input id="rv-website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <fieldset>
                <legend class="field-label">{{ t('Your rating') }} <span aria-hidden="true">*</span></legend>
                <div class="star-input" @error('rating') aria-describedby="e-rating" @enderror>
                    @foreach ([5, 4, 3, 2, 1] as $star)
                        <input type="radio" class="sr-only" id="rv-star-{{ $star }}" name="rating" value="{{ $star }}" required @checked((int) old('rating') === $star)>
                        <label for="rv-star-{{ $star }}" title="{{ $star }} {{ $star === 1 ? 'star' : 'stars' }}"><span class="sr-only">{{ $star }} {{ $star === 1 ? 'star' : 'stars' }}</span></label>
                    @endforeach
                </div>
                @error('rating') <p class="field-error" id="e-rating" role="alert">{{ $message }}</p> @enderror
            </fieldset>

            <div>
                <label class="field-label" for="rv-title">{{ t('Title (optional)') }}</label>
                <input class="field" id="rv-title" name="title" maxlength="120" value="{{ old('title') }}" @error('title') aria-invalid="true" aria-describedby="e-title" @enderror>
                @error('title') <p class="field-error" id="e-title" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label" for="rv-body">{{ t('Your review') }} <span aria-hidden="true">*</span></label>
                <textarea class="field" id="rv-body" name="body" rows="5" required minlength="20" maxlength="5000" aria-describedby="h-body @error('body') e-body @enderror" @error('body') aria-invalid="true" @enderror>{{ old('body') }}</textarea>
                <p class="mt-1.5 text-label text-muted" id="h-body">{{ t('At least 20 characters. What did you like, and what could be better?') }}</p>
                @error('body') <p class="field-error" id="e-body" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label" for="rv-photos">{{ t('Photos (optional, up to 5)') }}</label>
                <input class="field review-file" id="rv-photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple aria-describedby="h-photos @if ($errors->has('photos') || $errors->has('photos.*')) e-photos @endif">
                <p class="mt-1.5 text-label text-muted" id="h-photos">{{ t('JPG, PNG or WebP, up to 8 MB each.') }}</p>
                @if ($errors->has('photos') || $errors->has('photos.*'))
                    <p class="field-error" id="e-photos" role="alert">{{ $errors->first('photos') ?: $errors->first('photos.*') }}</p>
                @endif
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="rv-name">{{ t('Name shown with your review') }} <span aria-hidden="true">*</span></label>
                    <input class="field" id="rv-name" name="display_name" maxlength="60" required autocomplete="nickname" value="{{ old('display_name', $form['name']) }}" @error('display_name') aria-invalid="true" aria-describedby="e-name" @enderror>
                    @error('display_name') <p class="field-error" id="e-name" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="rv-country">{{ t('Country') }}</label>
                    @if ($form['lockCountry'])
                        <p class="field flex items-center gap-2 bg-soft" id="rv-country">@include('storefront.partials.flag', ['code' => $form['country']]) {{ config('countries')[$form['country']] ?? $form['country'] }}</p>
                        <p class="mt-1.5 text-label text-muted">{{ t('Taken from your order.') }}</p>
                    @else
                        <select class="field" id="rv-country" name="country_code" autocomplete="country" @error('country_code') aria-invalid="true" @enderror>
                            <option value="">{{ t('Prefer not to say') }}</option>
                            @foreach (config('countries') as $code => $name)
                                <option value="{{ $code }}" @selected(old('country_code', $form['country']) === $code)>{{ $name }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
            </div>

            @if ($form['askEmail'])
                <div>
                    <label class="field-label" for="rv-email">{{ t('Email') }} <span aria-hidden="true">*</span></label>
                    <input class="field" id="rv-email" name="email" type="email" maxlength="255" required autocomplete="email" value="{{ old('email', $form['email']) }}" aria-describedby="h-email @error('email') e-email @enderror" @error('email') aria-invalid="true" @enderror>
                    <p class="mt-1.5 text-label text-muted" id="h-email">{{ t('Never shown publicly. We only use it if we need to ask about your review.') }}</p>
                    @error('email') <p class="field-error" id="e-email" role="alert">{{ $message }}</p> @enderror
                </div>
            @endif

            @if ($form['captchaKey'])
                <div class="cf-turnstile" data-sitekey="{{ $form['captchaKey'] }}"></div>
                @once
                    @push('end')
                        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                    @endpush
                @endonce
            @endif

            <button type="submit" class="btn btn-primary justify-self-start">{{ t('Send review') }}</button>
        </form>
    @endif
</div>
