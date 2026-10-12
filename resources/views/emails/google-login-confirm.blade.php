<x-mail::message>
# Hello!

We received a request to sign in to your DermAssist account using your Google account.

Please click the button below to confirm your sign in and proceed.

<x-mail::button :url="$url">
Confirm Sign In
</x-mail::button>

This sign-in confirmation link is valid for 15 minutes. If you did not make this request, no further action is required.

Regards,<br>
{{ config('app.name') }}

<x-slot:subcopy>
@lang(
    "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
    'into your web browser:',
    [
        'actionText' => 'Confirm Sign In',
    ]
) <span class="break-all">[{{ $url }}]({{ $url }})</span>
</x-slot:subcopy>
</x-mail::message>
