<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:32px 16px;background:#F6F7F9;font-family:Inter,Arial,sans-serif;color:#111317;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;margin:0 auto;background:#FFFFFF;border-radius:16px;border:1px solid #E6E8EC;">
        <tr>
            <td style="padding:32px;">
                <p style="margin:0 0 24px;font-size:18px;font-weight:700;">{{ $storeName }}</p>
                <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;">Hi {{ $user->name }}, you have been invited</h1>
                <p style="margin:0 0 24px;font-size:15px;line-height:1.5;color:#5B616E;">
                    {{ $inviterName }} invited you to the {{ $storeName }} admin panel as <strong style="color:#111317;">{{ $roleName }}</strong>.
                    Set your password to get started. This link works for 7 days.
                </p>
                <a href="{{ $url }}" style="display:inline-block;background:#1D4ED8;color:#FFFFFF;text-decoration:none;font-weight:600;font-size:15px;padding:14px 28px;border-radius:999px;">Set my password</a>
                <p style="margin:24px 0 0;font-size:13px;line-height:1.5;color:#5B616E;">If the button does not work, copy this link:<br>{{ $url }}</p>
            </td>
        </tr>
    </table>
</body>
</html>
