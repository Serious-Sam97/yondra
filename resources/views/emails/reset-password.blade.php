<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>Yondra — Reset Password</title>
    <!--[if mso]><style>* { font-family: 'Courier New', monospace !important; }</style><![endif]-->
    <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=VT323&display=swap" rel="stylesheet">
    <style>
        @media (max-width: 600px) {
            .panel { width: 100% !important; }
            .px { padding-left: 22px !important; padding-right: 22px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#07090f; background-image: radial-gradient(1200px 500px at 50% -8%, #17111f 0%, #07090f 55%); -webkit-text-size-adjust:100%;">
    <!-- preheader (hidden) -->
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:#07090f; font-size:1px; line-height:1px;">
        Reset your Yondra password — this link expires in {{ $expire }} minutes.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:transparent;">
        <tr>
            <td align="center" style="padding:36px 14px;">

                <!-- ▓▓ synthwave status stripe ▓▓ -->
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

                    <!-- ▓▓ main hardware panel ▓▓ -->
                    <tr>
                        <td style="background:#1a1712; background-image: linear-gradient(180deg,#221f19 0%,#131109 100%); border:1px solid #3a352b; border-top:0; border-radius:0 0 12px 12px; box-shadow:0 20px 50px rgba(0,0,0,0.55);">

                            <!-- header / LCD bar -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td class="px" style="padding:20px 34px 0 34px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                                            <td align="left" style="font-family:'Share Tech Mono','Courier New',monospace; font-size:17px; letter-spacing:5px; color:#e8e4d6; font-weight:bold;">
                                                <span style="display:inline-block; width:9px; height:9px; background:#9aa67e; border-radius:50%; box-shadow:0 0 8px #9aa67e; vertical-align:middle;">&nbsp;</span>
                                                &nbsp; YONDRA
                                            </td>
                                            <td align="right" style="font-family:'VT323','Courier New',monospace; font-size:16px; letter-spacing:2px; color:#ffb000; text-shadow:0 0 6px rgba(255,176,0,0.55);">
                                                &#9656; AUTH / RESET
                                            </td>
                                        </tr></table>
                                        <div style="height:1px; background:#3a352b; margin-top:16px; line-height:1px; font-size:0;">&nbsp;</div>
                                    </td>
                                </tr>
                            </table>

                            <!-- body -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td class="px" style="padding:26px 34px 8px 34px;">
                                        <div style="font-family:'Share Tech Mono','Courier New',monospace; font-size:11px; letter-spacing:3px; color:#6fe0ff; text-transform:uppercase; text-shadow:0 0 6px rgba(111,224,255,0.4);">
                                            Password reset requested
                                        </div>
                                        <div style="font-family:'Share Tech Mono','Courier New',monospace; font-size:27px; line-height:1.2; color:#e8e4d6; font-weight:bold; margin-top:10px;">
                                            Reset your password
                                        </div>
                                        @php($greeting = $name ? $name.', we' : 'We')
                                        <p style="font-family:'Share Tech Mono','Courier New',Arial,sans-serif; font-size:14px; line-height:1.65; color:#b8b2a2; margin:16px 0 0 0;">
                                            {{ $greeting }} got a request to reset the password for your Yondra account. Hit the button below to set a new one. If this wasn&rsquo;t you, you can safely ignore this message &mdash; nothing will change.
                                        </p>
                                    </td>
                                </tr>

                                <!-- button -->
                                <tr>
                                    <td class="px" style="padding:26px 34px 6px 34px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0"><tr>
                                            <td align="center" style="border-radius:8px; background:#e9dfb6; background-image:linear-gradient(180deg,#f2e9c6 0%,#cdbf90 100%); box-shadow:0 0 22px rgba(233,223,182,0.28), inset 0 1px 0 rgba(255,255,255,0.7);">
                                                <a href="{{ $url }}" target="_blank"
                                                   style="display:inline-block; padding:15px 40px; font-family:'Share Tech Mono','Courier New',monospace; font-size:14px; font-weight:bold; letter-spacing:3px; text-transform:uppercase; color:#1c1a16; text-decoration:none; border:1px solid #b7a875; border-radius:8px;">
                                                    &#9656; Reset password
                                                </a>
                                            </td>
                                        </tr></table>
                                    </td>
                                </tr>

                                <!-- expiry chip -->
                                <tr>
                                    <td class="px" style="padding:14px 34px 4px 34px;">
                                        <span style="display:inline-block; font-family:'Share Tech Mono','Courier New',monospace; font-size:11px; letter-spacing:1px; color:#ffb000; background:#0d1410; border:1px solid #3a352b; border-radius:4px; padding:6px 12px;">
                                            <span style="display:inline-block; width:6px; height:6px; background:#ffb000; border-radius:50%; box-shadow:0 0 6px #ffb000; vertical-align:middle;">&nbsp;</span>
                                            &nbsp; LINK EXPIRES IN {{ $expire }} MINUTES
                                        </span>
                                    </td>
                                </tr>

                                <!-- fallback url -->
                                <tr>
                                    <td class="px" style="padding:20px 34px 6px 34px;">
                                        <div style="height:1px; background:#2c2820; line-height:1px; font-size:0;">&nbsp;</div>
                                        <p style="font-family:'Share Tech Mono','Courier New',Arial,sans-serif; font-size:11px; line-height:1.6; color:#6f6a5c; margin:16px 0 0 0;">
                                            Button not working? Paste this into your browser:
                                        </p>
                                        <p style="font-family:'Share Tech Mono','Courier New',monospace; font-size:11px; line-height:1.6; word-break:break-all; margin:6px 0 0 0;">
                                            <a href="{{ $url }}" target="_blank" style="color:#6fe0ff; text-decoration:none;">{{ $url }}</a>
                                        </p>
                                    </td>
                                </tr>

                                <!-- footer -->
                                <tr>
                                    <td class="px" style="padding:24px 34px 26px 34px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                                            <td align="left" style="font-family:'Share Tech Mono','Courier New',monospace; font-size:11px; letter-spacing:2px; color:#6f6a5c;">
                                                YONDRA <span style="color:#3a352b;">&middot;</span> LIMIAR CORE
                                            </td>
                                            <td align="right" style="font-family:'Share Tech Mono','Courier New',monospace; font-size:11px; color:#4a463f;">
                                                &#9608;
                                            </td>
                                        </tr></table>
                                    </td>
                                </tr>
                            </table>

                        </td>
                    </tr>
                </table>

                <div style="font-family:'Share Tech Mono','Courier New',Arial,sans-serif; font-size:10px; color:#4a463f; margin-top:18px; letter-spacing:1px;">
                    Sent by Yondra &middot; no-reply@limiarcore.com
                </div>

            </td>
        </tr>
    </table>
</body>
</html>
