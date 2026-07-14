<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $ok ? 'Request confirmed' : 'Link not valid' }}</title>
    <style>
        body { margin:0; font-family:'Share Tech Mono','Courier New',monospace; background:#07090f; background-image: radial-gradient(1200px 500px at 50% -8%, #17111f 0%, #07090f 55%); color:#e8e4d6; min-height:100vh; display:flex; align-items:center; justify-content:center; }
        .card { width:100%; max-width:440px; margin:20px; background:#1a1712; background-image:linear-gradient(180deg,#221f19 0%,#131109 100%); border:1px solid #3a352b; border-radius:12px; box-shadow:0 20px 50px rgba(0,0,0,0.55); overflow:hidden; }
        .stripe { display:flex; height:5px; }
        .stripe span { flex:1; }
        .body { padding:34px; text-align:center; }
        .icon { font-size:44px; line-height:1; margin-bottom:14px; }
        h1 { font-size:20px; letter-spacing:2px; margin:0 0 10px; color:#e8e4d6; }
        p { font-size:14px; line-height:1.7; color:#b7b2a4; margin:0; }
        .brand { margin-top:24px; font-size:11px; letter-spacing:3px; color:#6f6a5c; }
    </style>
</head>
<body>
    <div class="card">
        <div class="stripe">
            <span style="background:#9aa67e"></span><span style="background:#ffb000"></span><span style="background:#6fe0ff"></span><span style="background:#ff2d95"></span><span style="background:#ff5a4d"></span>
        </div>
        <div class="body">
            @if ($ok)
                <div class="icon" style="color:#9aa67e;">&#10003;</div>
                <h1>You're all set</h1>
                <p>Thanks{{ $name ? ', '.$name : '' }} — your request is confirmed. We'll be in touch shortly, and our reply will land right in your inbox.</p>
            @else
                <div class="icon" style="color:#ff5a4d;">&#9888;</div>
                <h1>Link not valid</h1>
                <p>This confirmation link is invalid or has already been used. If you think this is a mistake, just reply to the email you received.</p>
            @endif
            <div class="brand">YONDRA</div>
        </div>
    </div>
</body>
</html>
