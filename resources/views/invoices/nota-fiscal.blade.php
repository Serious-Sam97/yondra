<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Nota Fiscal {{ $number }}</title>
    <style>
        @page { margin: 28px 34px; }
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            color: #1a1d1a;
            font-size: 12px;
            line-height: 1.5;
        }
        .head { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .head td { vertical-align: top; }
        .brand { font-size: 20px; font-weight: bold; letter-spacing: 0.5px; }
        .doc-title { text-align: right; }
        .doc-title .kicker {
            font-size: 10px; letter-spacing: 2px; text-transform: uppercase; color: #6b7280;
        }
        .doc-title .num { font-size: 18px; font-weight: bold; }
        .muted { color: #6b7280; }
        .rule { border: none; border-top: 2px solid #111; margin: 6px 0 16px; }
        .parties { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .parties td { width: 50%; vertical-align: top; padding-right: 14px; }
        .label {
            font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase;
            color: #6b7280; margin-bottom: 3px;
        }
        .party-name { font-weight: bold; font-size: 13px; }
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.lines th {
            text-align: left; font-size: 9px; letter-spacing: 1px; text-transform: uppercase;
            color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 6px 4px;
        }
        table.lines td { padding: 9px 4px; border-bottom: 1px solid #eceef0; }
        .r { text-align: right; }
        .totals { width: 100%; border-collapse: collapse; margin-top: 4px; }
        .totals td { padding: 4px 4px; }
        .totals .k { text-align: right; color: #6b7280; }
        .totals .v { text-align: right; width: 130px; }
        .totals .grand td { border-top: 2px solid #111; font-weight: bold; font-size: 14px; padding-top: 8px; }
        .section-label {
            font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase;
            color: #6b7280; margin: 22px 0 4px;
        }
        table.pay { width: 100%; border-collapse: collapse; }
        table.pay td { padding: 5px 4px; border-bottom: 1px solid #eceef0; font-size: 11px; }
        .footer {
            margin-top: 26px; padding-top: 10px; border-top: 1px solid #d1d5db;
            font-size: 10px; color: #6b7280;
        }
        .disclaimer { margin-top: 4px; font-style: italic; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <div class="brand">{{ $issuer['name'] }}</div>
                @if ($issuer['tax_id'])<div class="muted">CNPJ/CPF: {{ $issuer['tax_id'] }}</div>@endif
                @if ($issuer['address'])<div class="muted">{{ $issuer['address'] }}</div>@endif
                @if ($issuer['email'])<div class="muted">{{ $issuer['email'] }}</div>@endif
                @if ($issuer['phone'])<div class="muted">{{ $issuer['phone'] }}</div>@endif
            </td>
            <td class="doc-title">
                <div class="kicker">Nota Fiscal / Fatura</div>
                <div class="num">Nº {{ $number }}</div>
                <div class="muted">Emitida em {{ $issued_at }}</div>
            </td>
        </tr>
    </table>
    <hr class="rule">

    <table class="parties">
        <tr>
            <td>
                <div class="label">Destinatário</div>
                <div class="party-name">{{ $recipient['name'] }}</div>
                @if ($recipient['email'])<div class="muted">{{ $recipient['email'] }}</div>@endif
                @if ($recipient['phone'])<div class="muted">{{ $recipient['phone'] }}</div>@endif
            </td>
            <td>
                <div class="label">Emitente</div>
                <div class="party-name">{{ $issuer['name'] }}</div>
                @if ($issuer['tax_id'])<div class="muted">{{ $issuer['tax_id'] }}</div>@endif
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th style="width:70%">Descrição</th>
                <th class="r" style="width:30%">Valor</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $description }}</td>
                <td class="r">{{ $amount }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="k">Total</td>
            <td class="v">{{ $amount }}</td>
        </tr>
        <tr>
            <td class="k">Pago</td>
            <td class="v">{{ $paid }}</td>
        </tr>
        <tr class="grand">
            <td class="k">Saldo</td>
            <td class="v">{{ $remaining }}</td>
        </tr>
    </table>

    @if (count($payments))
        <div class="section-label">Pagamentos recebidos</div>
        <table class="pay">
            @foreach ($payments as $p)
                <tr>
                    <td style="width:20%">{{ $p['paid_at'] }}</td>
                    <td>{{ $p['note'] }}</td>
                    <td class="r" style="width:25%">{{ $p['amount'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="footer">
        @if ($issuer['footer']){{ $issuer['footer'] }}@endif
        <div class="disclaimer">
            Documento gerado automaticamente para controle interno — sem valor fiscal
            (não substitui a NF-e emitida via SEFAZ).
        </div>
    </div>
</body>
</html>
