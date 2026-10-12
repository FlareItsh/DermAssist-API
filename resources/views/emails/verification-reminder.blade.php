<x-mail::message>
# Hello!

This is a reminder to verify your email address for your DermAssist account. You have **{{ $hoursRemainingText }}** remaining before your account verification window expires.

Please click the button below to verify your email address.

<x-mail::button :url="$url">
Verify Email Address
</x-mail::button>

If your verification link has expired, you can log in to your DermAssist account anytime within your 48-hour window and request a new verification link directly from your account notifications.

If you did not create an account, no further action is required.

Regards,<br>
{{ config('app.name') }}

<x-slot:subcopy>
@lang(
    "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
    'into your web browser:',
    [
        'actionText' => 'Verify Email Address',
    ]
) <span class="break-all">[{{ $url }}]({{ $url }})</span>
</x-slot:subcopy>
</x-mail::message>
