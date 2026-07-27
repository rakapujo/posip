Terima kasih telah berbelanja di {{ $storeName }}.

Berikut ringkasan transaksi Anda:

No. Nota   : {{ $nomor }}
Tanggal    : {{ $tanggal }}
Pelanggan  : {{ $customerName }}

Salinan struk (PDF) terlampir pada email ini.
Struk online: {{ $receiptUrl }}

@if($extraMessage)
Catatan dari kasir:
{{ $extraMessage }}

@endif
Jika ada pertanyaan terkait transaksi ini, silakan hubungi kami.

Salam hangat,
Tim {{ $storeName }}
@if($storePhone || $storeEmail)

Kontak: {{ trim(implode(' · ', array_filter([$storePhone, $storeEmail]))) }}
@endif
