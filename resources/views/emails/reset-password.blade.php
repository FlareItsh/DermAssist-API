<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title>Reset Your Password - DermAssist</title>
    <style type="text/css">
        body {
            width: 100% !important;
            height: 100%;
            margin: 0;
            line-height: 1.4;
            background-color: #f1f5f9;
            color: #334155;
            -webkit-text-size-adjust: none;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }
        a {
            color: #0284c7;
        }
        .btn-primary:hover {
            background-color: #0369a1 !important;
        }
        @media only screen and (max-width: 600px) {
            .email-card {
                width: 100% !important;
                border-radius: 0 !important;
                border-left: none !important;
                border-right: none !important;
            }
            .content-cell {
                padding: 24px 20px !important;
            }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color: #f1f5f9; margin: 0; padding: 32px 0;">
        <tr>
            <td align="center">
                <!-- Header Logo -->
                <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width: 580px; margin: 0 auto;">
                    <tr>
                        <td align="center" style="padding-bottom: 24px;">
                            <a href="{{ rtrim((string) (config('app.frontend_url') ?? env('FRONTEND_URL', 'http://localhost:3000')), '/') }}" target="_blank" style="display: inline-block; text-decoration: none;">
                                @if (isset($message) && file_exists(public_path('DA_Logo.png')))
                                    <img src="{{ $message->embed(public_path('DA_Logo.png')) }}" alt="DermAssist Logo" height="46" style="height: 46px; max-height: 46px; border: 0; display: block; margin: 0 auto; object-fit: contain;" />
                                @else
                                    <span style="font-size: 26px; font-weight: 800; color: #0284c7; letter-spacing: -0.025em; text-decoration: none;">Derm<span style="color: #0f172a;">Assist</span></span>
                                @endif
                            </a>
                        </td>
                    </tr>
                </table>

                <!-- Main White Card -->
                <table class="email-card" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width: 580px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
                    <tr>
                        <td class="content-cell" style="padding: 36px 36px 28px 36px; text-align: left;">
                            <h1 style="margin: 0 0 16px 0; font-size: 22px; font-weight: 700; color: #0f172a; letter-spacing: -0.02em; line-height: 1.3;">
                                Hello, {{ $user->first_name }}!
                            </h1>

                            <p style="margin: 0 0 20px 0; font-size: 15px; color: #475569; line-height: 1.6;">
                                We received a request to reset the password for your <strong>DermAssist</strong> account. Click the button below to choose a new, secure password:
                            </p>

                            <!-- Primary Action Button -->
                            <table width="100%" border="0" cellspacing="0" cellpadding="0" role="presentation" style="margin: 28px 0 32px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{{ $url }}" class="btn-primary" target="_blank" rel="noopener" style="display: inline-block; background-color: #0284c7; color: #ffffff !important; font-size: 15px; font-weight: 600; text-decoration: none; padding: 14px 34px; border-radius: 10px; box-shadow: 0 3px 6px rgba(2, 132, 199, 0.25); text-align: center;">
                                            Reset Password
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <!-- Privacy Safeguard Callout Box -->
                            <table width="100%" border="0" cellspacing="0" cellpadding="0" role="presentation" style="margin: 0 0 24px 0; background-color: #f0f9ff; border: 1px solid #bae6fd; border-left: 4px solid #0284c7; border-radius: 8px;">
                                <tr>
                                    <td style="padding: 14px 16px;">
                                        <p style="margin: 0; font-size: 13px; color: #0369a1; line-height: 1.55;">
                                            <strong>🔒 Security Notice:</strong> This password reset link is valid for 60 minutes and can only be used once. If you did not request a password reset, you can safely ignore this email — your account remains secure.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0; font-size: 14px; color: #334155; line-height: 1.5;">
                                Regards,<br />
                                <strong style="color: #0f172a;">The DermAssist Team</strong>
                            </p>
                        </td>
                    </tr>

                    <!-- Subcopy URL Fallback -->
                    <tr>
                        <td style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px 36px; text-align: left;">
                            <p style="margin: 0 0 8px 0; font-size: 12px; color: #64748b; line-height: 1.5;">
                                If you're having trouble clicking the "Reset Password" button, copy and paste the URL below into your web browser:
                            </p>
                            <p style="margin: 0; font-size: 12px; line-height: 1.5; word-break: break-all;">
                                <a href="{{ $url }}" target="_blank" rel="noopener" style="color: #0284c7; text-decoration: underline; word-break: break-all;">
                                    {{ $url }}
                                </a>
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- Bottom Footer -->
                <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width: 580px; margin: 0 auto;">
                    <tr>
                        <td align="center" style="padding-top: 24px; font-size: 12px; color: #94a3b8; line-height: 1.6;">
                            <p style="margin: 0 0 4px 0;">
                                &copy; {{ date('Y') }} DermAssist Healthcare. All rights reserved.
                            </p>
                            <p style="margin: 0;">
                                Secure AI-Assisted Teledermatology & Clinical Diagnosis Platform
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
