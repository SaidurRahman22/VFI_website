<!DOCTYPE html>
<html lang="en">
<body style="font-family:Arial,Helvetica,sans-serif;color:#1a2340;line-height:1.6">
    @if ($decision === 'approved')
        <h2 style="color:#1a2340">Welcome aboard</h2>
        <p>Your agency <strong>{{ $detail }}</strong> has been approved as a VFI partner. You can now sign in to your partner console.</p>
        <p style="margin:24px 0">
            <a href="{{ rtrim(config('app.url'), '/') }}/vfi-partner-login.html" style="background:#1a2340;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700">Sign in</a>
        </p>
    @elseif ($decision === 'rejected')
        <h2 style="color:#1a2340">About your application</h2>
        <p>After review, we're not able to approve your partner application at this time.</p>
        @if ($detail)<p><strong>Note from our team:</strong> {{ $detail }}</p>@endif
        <p>If you believe this is a mistake, reply to this email and we'll take another look.</p>
    @else
        <h2 style="color:#1a2340">We need a little more information</h2>
        <p>Before we can finish reviewing your partner application, our team needs the following:</p>
        <p><strong>{{ $detail }}</strong></p>
        <p>Reply to this email with the details and we'll continue the review.</p>
    @endif
    <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0">
    <p style="font-size:12px;color:#6b7280">VFI Foreign Consultancy — Partner Team</p>
</body>
</html>
