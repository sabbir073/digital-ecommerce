<?php

namespace App\Mail\Concerns;

use App\Models\Setting;
use App\Services\EmailTemplates;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A customer email whose subject and wording come from the template the owner edits in admin. */
trait UsesEmailTemplate
{
    /** @var array{subject:string, body:string}|null Unsaved text, used by the preview in admin. */
    public ?array $templateOverride = null;

    abstract protected function templateKey(): string;

    /** @return array<string, string|int|null> */
    abstract protected function templateVariables(): array;

    /** @return array<string, array<string, mixed>|null> */
    abstract protected function templateBlocks(): array;

    /** @param array{subject:string, body:string} $text */
    public function withTemplate(array $text): static
    {
        $this->templateOverride = $text;

        return $this;
    }

    /** @return array{subject:string, parts:list<array<string, mixed>>} */
    private function composed(): array
    {
        return app(EmailTemplates::class)->compose($this->templateKey(), $this->templateVariables(), $this->templateBlocks(), $this->templateOverride);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->composed()['subject']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.template', with: [
            'parts' => $this->composed()['parts'],
            'storeName' => Setting::get('store.name', config('app.name')),
            'address' => trim(preg_replace('/\s+/', ' ', (string) Setting::get('store.address', ''))),
        ]);
    }
}
