@include('selling.blocks._open', ['id' => 'contact'])
    <div class="container-selling">
        <div class="mx-auto max-w-[712px] text-left">
            @if ($d['heading'])
                <h2 class="text-[28px] {{ $style['align'] === 'center' ? 'text-center' : '' }}" @edit('heading')>{{ $d['heading'] }}</h2>
            @endif
            @if ($d['text'])
                <p class="mt-3 sp-muted {{ $style['align'] === 'center' ? 'text-center' : '' }}" @edit('text')>{{ $d['text'] }}</p>
            @endif

            <form method="post" action="{{ route('contact.send') }}" class="mt-6 grid gap-4" novalidate>
                @csrf
                {{-- Left empty by people. Bots fill it in, and their message is dropped. --}}
                <div class="chat-hp" aria-hidden="true"><label>{{ t('Website') }} <input name="website" tabindex="-1" autocomplete="off"></label></div>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="field-label" for="contact-name">{{ t('Your name') }}</label>
                        <input class="field" id="contact-name" name="name" maxlength="80" required autocomplete="name" value="{{ old('name') }}" @error('name') aria-invalid="true" aria-describedby="contact-name-error" @enderror>
                        @error('name') <p class="field-error" id="contact-name-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="contact-email">{{ t('Your email') }}</label>
                        <input class="field" id="contact-email" name="email" type="email" maxlength="255" required autocomplete="email" value="{{ old('email') }}" @error('email') aria-invalid="true" aria-describedby="contact-email-error" @enderror>
                        @error('email') <p class="field-error" id="contact-email-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="field-label" for="contact-message">{{ t('Message') }}</label>
                    <textarea class="field min-h-36 py-3" id="contact-message" name="message" maxlength="3000" required @error('message') aria-invalid="true" aria-describedby="contact-message-error" @enderror>{{ old('message') }}</textarea>
                    @error('message') <p class="field-error" id="contact-message-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <button type="submit" class="btn btn-accent" @edit('button_label')>{{ $d['button_label'] ?: t('Send message') }}</button>
                </div>
            </form>
        </div>
    </div>
</section>
