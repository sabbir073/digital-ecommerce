<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Validation\ValidationException;

/**
 * The customer emails, with wording the owner can change in admin.
 *
 * A template is plain Markdown with two kinds of placeholders: variables such as {{customer_name}},
 * replaced by a value, and blocks such as {{downloads}}, replaced by a part the store builds
 * (links, tables, buttons). The owner's text is never run as code: values are escaped and
 * blocks come from fixed views.
 */
class EmailTemplates
{
    private const PLACEHOLDER = '/\{\{\s*([a-z_]+)(?:\s*:\s*([^{}]*?))?\s*\}\}/';

    /** @return array<string, array{name:string, when:string, subject:string, body:string, variables:array<string,string>, blocks:array<string,string>, required:list<string>}> */
    public static function definitions(): array
    {
        $common = ['store_name' => 'The name of your store', 'customer_name' => 'The customer\'s name, or "there" when unknown'];
        $order = $common + ['order_number' => 'The order number', 'order_total' => 'The total the customer paid'];

        return [
            'order_confirmation' => [
                'name' => 'Order confirmation',
                'when' => 'Sent when a payment is confirmed. It carries the download links.',
                'subject' => 'Your order {{order_number}} and download links',
                'body' => "# Thank you for your order\n\nHi {{customer_name}}, your payment went through. Order number: **{{order_number}}**.\n\n{{downloads}}\n\n{{order_table}}\n\n{{button:View your order}}\n\nThanks,\n{{store_name}}",
                'variables' => $order,
                'blocks' => ['downloads' => 'The download links and how long they work', 'order_table' => 'The items, discount, tax and total', 'button' => 'A button that opens the order. Write its label after a colon: {{button:View your order}}'],
                'required' => ['downloads'],
            ],
            'refund_issued' => [
                'name' => 'Refund issued',
                'when' => 'Sent when you refund an order, in part or in full.',
                'subject' => 'Refund for your order {{order_number}}',
                'body' => "# Your refund is on its way\n\nHi {{customer_name}}, we refunded **{{refund_amount}}** for order **{{order_number}}**. The money goes back to the card you paid with. Banks usually show it within 5 to 10 days.\n\n{{full_refund_note}}\n\n{{button:View your order}}\n\nThanks,\n{{store_name}}",
                'variables' => $order + ['refund_amount' => 'The amount refunded this time'],
                'blocks' => ['full_refund_note' => 'A line saying the download links stopped working. It shows only for a full refund.', 'button' => 'A button that opens the order: {{button:View your order}}'],
                'required' => [],
            ],
            'review_request' => [
                'name' => 'Review request',
                'when' => 'Sent some days after a purchase, when review requests are switched on under Reviews, Settings.',
                'subject' => 'How was your order {{order_number}}?',
                'body' => "# How was your order?\n\nHi {{customer_name}}, thank you for buying from {{store_name}}. Would you tell other customers what you think? It takes about a minute and you do not need to sign in.\n\n{{review_buttons}}\n\nYour review is marked as written by a verified buyer of order {{order_number}}.\n\nThanks,\n{{store_name}}",
                'variables' => $common + ['order_number' => 'The order number'],
                'blocks' => ['review_buttons' => 'One button for each product bought, opening its review form'],
                'required' => ['review_buttons'],
            ],
            'chat_reply' => [
                'name' => 'Chat reply',
                'when' => 'Sent when your team answers a chat message after the visitor has left the site.',
                'subject' => 'Reply to your message to {{store_name}}',
                'body' => "# We replied to your message\n\nHi {{customer_name}}, you wrote to us in the chat on our site. Here is our reply:\n\n{{message}}\n\nYou can answer by replying in the chat on our site.\n\n{{button:Back to the store}}\n\nThanks,\n{{store_name}}",
                'variables' => $common,
                'blocks' => ['message' => 'The reply your team wrote', 'button' => 'A button that opens the store: {{button:Back to the store}}'],
                'required' => ['message'],
            ],
            'password_reset' => [
                'name' => 'Password reset',
                'when' => 'Sent when a customer asks to reset the password of their account.',
                'subject' => 'Reset your {{store_name}} password',
                'body' => "# Reset your password\n\nHi {{customer_name}}, we got a request to reset the password of your account.\n\n{{button:Choose a new password}}\n\nThe link works for {{expires_minutes}} minutes. If you did not ask for this, you can ignore this email and your password stays the same.\n\nThanks,\n{{store_name}}",
                'variables' => $common + ['expires_minutes' => 'How many minutes the link works'],
                'blocks' => ['button' => 'The button that opens the reset form: {{button:Choose a new password}}'],
                'required' => ['button'],
            ],
        ];
    }

    /** @return array{subject:string, body:string, custom:bool} */
    public function get(string $key): array
    {
        $definition = self::definitions()[$key];
        $saved = ((array) Setting::get('email.templates', []))[$key] ?? null;

        return [
            'subject' => (string) ($saved['subject'] ?? $definition['subject']),
            'body' => (string) ($saved['body'] ?? $definition['body']),
            'custom' => $saved !== null,
        ];
    }

    /** @throws ValidationException when the text uses a placeholder this email does not have, or drops one it needs */
    public function save(string $key, string $subject, string $body): void
    {
        $this->check($key, $subject, $body);
        $all = (array) Setting::get('email.templates', []);
        $all[$key] = ['subject' => trim($subject), 'body' => trim(str_replace("\r\n", "\n", $body))];
        Setting::put('email.templates', $all);
    }

    public function reset(string $key): void
    {
        $all = (array) Setting::get('email.templates', []);
        unset($all[$key]);
        Setting::put('email.templates', $all);
    }

    public function check(string $key, string $subject, string $body): void
    {
        $definition = self::definitions()[$key];
        $errors = [];

        preg_match_all(self::PLACEHOLDER, $subject, $inSubject);
        $unknown = array_diff($inSubject[1], array_keys($definition['variables']));
        if ($unknown !== []) {
            $errors['subject'] = 'The subject can only use these: '.implode(', ', array_map(fn ($v) => '{{'.$v.'}}', array_keys($definition['variables']))).'.';
        }

        preg_match_all(self::PLACEHOLDER, $body, $inBody);
        $unknown = array_diff($inBody[1], array_keys($definition['variables']), array_keys($definition['blocks']));
        $missing = array_diff($definition['required'], $inBody[1]);
        if ($unknown !== []) {
            $errors['body'] = 'This email has no '.implode(', ', array_map(fn ($v) => '{{'.$v.'}}', array_unique($unknown))).'. Use the list beside the editor.';
        } elseif ($missing !== []) {
            $errors['body'] = 'Keep '.implode(' and ', array_map(fn ($v) => '{{'.$v.'}}', $missing)).' in the text. Without it the email cannot do its job.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Fill a template in.
     *
     * @param  array<string, string|int|null>  $variables
     * @param  array<string, array<string, mixed>|null>  $blocks  each a part for the emails.template view; null leaves the block out
     * @param  array{subject:string, body:string}|null  $override  unsaved text, for the preview in admin
     * @return array{subject:string, parts:list<array<string, mixed>>}
     */
    public function compose(string $key, array $variables, array $blocks, ?array $override = null): array
    {
        $template = $override ?? $this->get($key);
        $variables += ['store_name' => (string) Setting::get('store.name', config('app.name'))];

        $subject = preg_replace_callback(self::PLACEHOLDER, fn ($m) => array_key_exists($m[1], $variables) ? (string) $variables[$m[1]] : $m[0], $template['subject']);

        $parts = [];
        $text = '';
        $flush = function () use (&$parts, &$text) {
            if (trim($text) !== '') {
                // A single line break in the editor is a line break in the email.
                $parts[] = ['type' => 'text', 'text' => preg_replace('/(?<=\S)\n(?=\S)/', "  \n", trim($text))];
            }
            $text = '';
        };

        $offset = 0;
        preg_match_all(self::PLACEHOLDER, $template['body'], $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            [$whole, $at] = $match[0];
            $name = $match[1][0];
            $text .= e(substr($template['body'], $offset, $at - $offset));
            $offset = $at + strlen($whole);

            if (array_key_exists($name, $blocks)) {
                $flush();
                if ($blocks[$name] !== null) {
                    $label = trim($match[2][0] ?? '');
                    $parts[] = ($label !== '' ? ['label' => $label] : []) + $blocks[$name];
                }
            } elseif (array_key_exists($name, $variables)) {
                $text .= self::escape((string) $variables[$name]);
            } else {
                $text .= e($whole);
            }
        }
        $text .= e(substr($template['body'], $offset));
        $flush();

        return ['subject' => trim(preg_replace('/\s+/', ' ', (string) $subject)), 'parts' => $parts];
    }

    /** A value inside Markdown: no HTML, no formatting and no links of its own. */
    public static function escape(string $value): string
    {
        $value = e(trim(preg_replace('/\s+/', ' ', $value)));

        return preg_replace('/([\\\\`*_\[\]()#|!~])/', '\\\\$1', $value);
    }
}
