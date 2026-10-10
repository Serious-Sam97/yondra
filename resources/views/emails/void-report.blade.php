<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $label }}</title>
</head>
<body style="margin:0; padding:0; background:#1b140f;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center" style="padding:32px 14px;">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:560px; max-width:100%;">
                    <!-- the tape label -->
                    <tr>
                        <td style="background:#efe6cc; border-left:10px solid #b5533c; border-radius:4px; padding:16px 22px; font-family:'Courier New',monospace; color:#2a1f17;">
                            <div style="font-size:11px; letter-spacing:3px; color:#8a6a3a;">SIDE A · C-60 · DO NOT ERASE</div>
                            <div style="font-size:22px; font-weight:bold; margin-top:4px;">{{ $label }}</div>
                        </td>
                    </tr>
                    <tr><td style="height:14px;"></td></tr>
                    <tr>
                        <td style="background:#0d0905; border-radius:6px; padding:20px 22px; font-family:'Courier New',monospace; color:#ffb547; font-size:14px; line-height:1.6;">
                            @foreach ($lines as $line)
                                <p style="margin:0 0 10px;">{{ $line }}</p>
                            @endforeach
                            @if ($ps)
                                <p style="margin:16px 0 0; color:#8fe3e3; font-size:12px;">P.S. {{ $ps }}</p>
                            @endif
                            <p style="margin:18px 0 0; color:#8a7a5c; font-size:12px;">— v. (from the tape machine)</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:14px 4px; font-family:'Courier New',monospace; font-size:11px; color:#8a7a5c; text-align:center;">
                            you get this because you asked him to write. <a href="{{ $unsubscribeUrl }}" style="color:#c8962e;">stop his letters</a> (one click, no hard feelings. some hard feelings.)
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
