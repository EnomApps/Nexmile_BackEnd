{{-- Inline styles only: email clients strip <style> blocks and ignore classes. --}}
<div style="margin:0;padding:0;background:#f4f4f5;font-family:Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                       style="max-width:480px;background:#ffffff;border-radius:12px;overflow:hidden;">

                    <tr>
                        <td style="background:#000000;padding:24px;text-align:center;">
                            <span style="font-size:24px;font-weight:bold;color:#ffffff;letter-spacing:-0.5px;">
                                Nex<span style="color:#FF7A00;">mile</span>
                            </span>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:32px 28px;">
                            <p style="margin:0 0 8px;font-size:16px;color:#18181b;">Your verification code</p>
                            <p style="margin:0 0 24px;font-size:14px;color:#71717a;line-height:1.6;">
                                Enter this code in the Nexmile app to sign in.
                            </p>

                            <div style="background:#fafafa;border:1px solid #e4e4e7;border-radius:10px;padding:20px;text-align:center;">
                                <span style="font-size:34px;font-weight:bold;letter-spacing:10px;color:#18181b;">
                                    {{ $code }}
                                </span>
                            </div>

                            <p style="margin:24px 0 0;font-size:14px;color:#71717a;line-height:1.6;">
                                This code expires in <strong>{{ $expiresInMinutes }} minutes</strong>.
                            </p>
                            <p style="margin:12px 0 0;font-size:14px;color:#71717a;line-height:1.6;">
                                If you did not request this, you can ignore this email. Someone may have
                                typed your address by mistake.
                            </p>

                            <p style="margin:24px 0 0;padding-top:20px;border-top:1px solid #e4e4e7;font-size:13px;color:#a1a1aa;line-height:1.6;">
                                Nexmile staff will never ask you for this code. Do not share it with anyone.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="background:#fafafa;padding:18px;text-align:center;font-size:12px;color:#a1a1aa;">
                            &copy; {{ date('Y') }} Nexmile India Pvt. Ltd. &middot; Tamil Nadu, India
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</div>
