<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>Confirm your request</title>
    <style>
        @media (max-width: 600px) {
            .panel { width: 100% !important; }
            .px { padding-left: 22px !important; padding-right: 22px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#07090f; background-image: radial-gradient(1200px 500px at 50% -8%, #17111f 0%, #07090f 55%); -webkit-text-size-adjust:100%;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:transparent;">
        <tr>
            <td align="center" style="padding:36px 14px;">
                <table role="presentation" class="panel" width="560" cellpadding="0" cellspacing="0" style="width:560px; max-width:560px;">
                    <tr>
                        <td style="font-size:0; line-height:0; border-radius:10px 10px 0 0; overflow:hidden;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                                <td height="5" style="height:5px; background:#9aa67e;">&nbsp;</td>
                                <td height="5" style="height:5px; background:#ffb000;">&nbsp;</td>
                                <td height="5" style="height:5px; background:#6fe0ff;">&nbsp;</td>
                                <td height="5" style="height:5px; background:#ff2d95;">&nbsp;</td>
                                <td height="5" style="height:5px; background:#ff5a4d;">&nbsp;</td>
                                <td height="5" style="height:5px; background:#9aa67e;">&nbsp;</td>
                            </tr></table>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#1a1712; background-image: linear-gradient(180deg,#221f19 0%,#131109 100%); border:1px solid #3a352b; border-top:0; border-radius:0 0 12px 12px; box-shadow:0 20px 50px rgba(0,0,0,0.55);">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td class="px" style="padding:20px 34px 0 34px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                                            <td align="left" style="font-family:'Share Tech Mono','Courier New',monospace; font-size:17px; letter-spacing:5px; color:#e8e4d6; font-weight:bold;">
                                                <span style="display:inline-block; width:9px; height:9px; background:#9aa67e; border-radius:50%; box-shadow:0 0 8px #9aa67e; vertical-align:middle;">&nbsp;</span>
                                                &nbsp; YONDRA
                                            </td>
                                            <td align="right" style="font-family:'Share Tech Mono','Courier New',monospace; font-size:14px; letter-spacing:2px; color:#ffb000;">
                                                &#9656; CONFIRM
                                            </td>
                                        </tr></table>
                                        <div style="height:1px; background:#3a352b; margin-top:16px; line-height:1px; font-size:0;">&nbsp;</div>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td class="px" style="padding:26px 34px 8px 34px;">
                                        <div style="font-family:'Share Tech Mono','Courier New',Arial,sans-serif; font-size:15px; line-height:1.7; color:#e8e4d6;">
                                            Hi {{ $contactName }},<br><br>
                                            Thanks for reaching out to {{ $boardName }}. To make sure our reply reaches
                                            you (and doesn't get lost), please confirm your request by tapping the
                                            button below.
                                        </div>
                                    </td>
                                </tr>

                                <!-- CTA button -->
                                <tr>
                                    <td class="px" align="center" style="padding:14px 34px 6px 34px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0"><tr>
                                            <td align="center" style="border-radius:8px; background:#6fe0ff; background-image:linear-gradient(180deg,#8febff 0%,#4fc7ee 100%); box-shadow:0 6px 18px rgba(111,224,255,0.35);">
                                                <a href="{{ $confirmUrl }}" target="_blank" style="display:inline-block; font-family:'Share Tech Mono','Courier New',monospace; font-size:15px; font-weight:bold; letter-spacing:2px; color:#07211f; text-decoration:none; padding:14px 40px;">
                                                    YES, CONFIRM MY REQUEST
                                                </a>
                                            </td>
                                        </tr></table>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px" style="padding:8px 34px 4px 34px;">
                                        <div style="font-family:'Share Tech Mono','Courier New',Arial,sans-serif; font-size:11px; line-height:1.6; color:#8a8579;">
                                            If the button doesn't work, copy and paste this link into your browser:<br>
                                            <span style="color:#6fe0ff; word-break:break-all;">{{ $confirmUrl }}</span>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td class="px" style="padding:20px 34px 26px 34px;">
                                        <div style="height:1px; background:#2c2820; line-height:1px; font-size:0;">&nbsp;</div>
                                        <div style="font-family:'Share Tech Mono','Courier New',monospace; font-size:11px; letter-spacing:2px; color:#6f6a5c; margin-top:16px;">
                                            YONDRA &middot; If you didn't make this request, you can ignore this email.
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
