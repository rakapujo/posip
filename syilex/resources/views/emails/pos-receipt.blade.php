<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Struk {{ $nomor }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f4f5;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e4e4e7;">
                <tr>
                    <td style="padding:28px 28px 12px;border-bottom:1px solid #e4e4e7;">
                        <div style="font-size:20px;font-weight:700;color:#18181b;">{{ $storeName }}</div>
                        @if($storePhone || $storeEmail)
                            <div style="margin-top:6px;font-size:12px;color:#71717a;line-height:1.5;">
                                @if($storePhone){{ $storePhone }}@endif
                                @if($storePhone && $storeEmail) · @endif
                                @if($storeEmail){{ $storeEmail }}@endif
                            </div>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px 28px;">
                        <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                            Terima kasih telah berbelanja di <strong>{{ $storeName }}</strong>.
                            Berikut ringkasan transaksi Anda.
                        </p>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#fafafa;border:1px solid #e4e4e7;border-radius:6px;margin:0 0 20px;">
                            <tr>
                                <td style="padding:14px 16px;font-size:13px;line-height:1.7;color:#3f3f46;">
                                    <div><span style="color:#71717a;">No. Nota</span><br><strong style="font-size:15px;color:#18181b;">{{ $nomor }}</strong></div>
                                    <div style="margin-top:10px;"><span style="color:#71717a;">Tanggal</span><br><strong>{{ $tanggal }}</strong></div>
                                    <div style="margin-top:10px;"><span style="color:#71717a;">Pelanggan</span><br><strong>{{ $customerName }}</strong></div>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 12px;font-size:14px;line-height:1.6;color:#3f3f46;">
                            Salinan struk dalam format PDF terlampir pada email ini.
                            Anda juga dapat membuka struk online melalui tautan berikut:
                        </p>
                        <p style="margin:0 0 20px;">
                            <a href="{{ $receiptUrl }}" style="display:inline-block;background:#18181b;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 18px;border-radius:6px;">
                                Lihat Struk Online
                            </a>
                        </p>
                        <p style="margin:0 0 20px;font-size:12px;line-height:1.5;color:#71717a;word-break:break-all;">
                            {{ $receiptUrl }}
                        </p>

                        @if($extraMessage)
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-left:3px solid #a1a1aa;margin:0 0 20px;">
                                <tr>
                                    <td style="padding:8px 0 8px 14px;font-size:13px;line-height:1.6;color:#3f3f46;">
                                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.04em;color:#71717a;margin-bottom:4px;">Catatan dari kasir</div>
                                        {{ $extraMessage }}
                                    </td>
                                </tr>
                            </table>
                        @endif

                        <p style="margin:0;font-size:14px;line-height:1.6;color:#3f3f46;">
                            Jika ada pertanyaan terkait transaksi ini, silakan hubungi kami.
                        </p>
                        <p style="margin:16px 0 0;font-size:14px;line-height:1.6;">
                            Salam hangat,<br>
                            <strong>Tim {{ $storeName }}</strong>
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:14px 28px;background:#fafafa;border-top:1px solid #e4e4e7;font-size:11px;color:#a1a1aa;line-height:1.5;">
                        Email ini dikirim otomatis terkait transaksi Anda. Mohon tidak membalas ke alamat pengirim teknis.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
