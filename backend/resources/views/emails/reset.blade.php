<!DOCTYPE html>
<html lang="en">
<body style="font-family:Arial,Helvetica,sans-serif;color:#1a2340;line-height:1.6">
    <h2 style="color:#1a2340">Reset your password</h2>
    <p>We received a request to reset the password for your VFI Foreign Consultancy account. Click the button below to choose a new password:</p>
    <p style="margin:24px 0">
        <a href="{{ $url }}" style="background:#1a2340;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700">Reset password</a>
    </p>
    <p>Or paste this link into your browser:</p>
    <p style="word-break:break-all;font-size:13px;color:#374151">{{ $url }}</p>
    <p>This link expires in {{ $ttlMinutes }} minutes and can be used once. If you didn't request a reset, you can safely ignore this email — your password won't change.</p>
    <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0">
    <p style="font-size:12px;color:#6b7280">VFI Foreign Consultancy</p>
</body>
</html>
