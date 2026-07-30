<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject ?? 'Informasi booking ziarah' }}</title>
</head>
<body style="margin:0;background:#f5f7fa;color:#3a3a35;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f5f7fa;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background:#ffffff;border:1px solid #e3e7ec;border-radius:10px;">
                <tr>
                    <td style="padding:32px 28px;">
                        <div style="color:#8a94a6;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;">
                            Informasi Booking Ziarah
                        </div>
                        <h1 style="margin:4px 0 20px;color:#172747;font-size:26px;line-height:1.25;">
                            @if ($kind === \App\Mail\BookingNotification::CONFIRMED)
                                Booking Berhasil
                            @elseif ($kind === \App\Mail\BookingNotification::RESCHEDULED)
                                Jadwal Booking Diperbarui
                            @else
                                Booking Dibatalkan
                            @endif
                        </h1>

                        <p style="margin:0 0 12px;">Assalamualaikum Wr. Wb.</p>
                        <p style="margin:0 0 12px;">Yth {{ $booking->customer_name }}</p>

                        @if ($kind === \App\Mail\BookingNotification::CANCELLED)
                            <p style="margin:0 0 20px;">Booking kunjungan ziarah Anda telah dibatalkan. Berikut detail booking yang dibatalkan:</p>
                        @else
                            <p style="margin:0 0 20px;">
                                @if ($kind === \App\Mail\BookingNotification::RESCHEDULED)
                                    Jadwal booking Anda berhasil diperbarui. Tautan pengelolaan sebelumnya sudah tidak berlaku.
                                @endif
                                Mohon pastikan info ziarah lengkap dan akurat:
                            </p>
                        @endif

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #dfe3e8;border-radius:10px;border-collapse:separate;overflow:hidden;">
                            <tr>
                                <td colspan="2" style="background:#172747;padding:16px 18px;color:#ffffff;">
                                    <div style="color:#aeb8ca;font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;">Kode Booking</div>
                                    <div style="font-family:Consolas,Monaco,monospace;font-size:17px;font-weight:700;">{{ $booking->public_reference }}</div>
                                </td>
                            </tr>
                            <tr>
                                <td width="50%" valign="top" style="border-bottom:1px solid #e8ebef;padding:13px 18px;">
                                    <div style="color:#8a94a6;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;">Zona</div>
                                    <div style="color:#172747;font-weight:700;">{{ $booking->zone_name_snapshot }}</div>
                                </td>
                                <td width="50%" valign="top" style="border-bottom:1px solid #e8ebef;border-left:1px solid #e8ebef;padding:13px 18px;">
                                    <div style="color:#8a94a6;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;">Nomor Lot</div>
                                    <div style="color:#172747;font-weight:700;">{{ $booking->lot_number }}</div>
                                </td>
                            </tr>
                            <tr>
                                <td width="50%" valign="top" style="border-bottom:1px solid #e8ebef;padding:13px 18px;">
                                    <div style="color:#8a94a6;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;">Tanggal</div>
                                    <div style="color:#172747;font-weight:700;">{{ $booking->visit_date->locale('id')->translatedFormat('d F Y') }}</div>
                                </td>
                                <td width="50%" valign="top" style="border-bottom:1px solid #e8ebef;border-left:1px solid #e8ebef;padding:13px 18px;">
                                    <div style="color:#8a94a6;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;">Jam</div>
                                    <div style="color:#172747;font-weight:700;">{{ substr($booking->visit_time, 0, 5) }} WIB</div>
                                </td>
                            </tr>
                            <tr>
                                <td width="50%" valign="top" style="border-bottom:1px solid #e8ebef;padding:13px 18px;">
                                    <div style="color:#8a94a6;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;">Nama Pemesan</div>
                                    <div style="color:#172747;font-weight:700;">{{ $booking->customer_name }}</div>
                                </td>
                                <td width="50%" valign="top" style="border-bottom:1px solid #e8ebef;border-left:1px solid #e8ebef;padding:13px 18px;">
                                    <div style="color:#8a94a6;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;">Nama Alm</div>
                                    <div style="color:#172747;font-weight:700;">{{ $booking->deceased_name }}</div>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2" style="border-bottom:{{ $booking->additional_notes ? '1px solid #e8ebef' : '0' }};padding:13px 18px;">
                                    <div style="color:#8a94a6;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;">Fasilitas</div>
                                    <div style="color:#172747;font-weight:700;">
                                        Kursi: {{ $booking->chair_count }} &nbsp;•&nbsp; Tenda: {{ $booking->tent_required ? 'Ya' : 'Tidak' }}
                                    </div>
                                </td>
                            </tr>
                            @if ($booking->additional_notes)
                                <tr>
                                    <td colspan="2" style="padding:13px 18px;">
                                        <div style="color:#8a94a6;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;">Catatan Tambahan</div>
                                        <div style="color:#172747;font-weight:700;">{{ $booking->additional_notes }}</div>
                                    </td>
                                </tr>
                            @endif
                        </table>

                        @if ($kind !== \App\Mail\BookingNotification::CANCELLED)
                            <p style="margin:22px 0 0;">
                                Agar kami dapat memberikan pelayanan yang maksimal, mohon untuk dapat sampai di AMG sesuai waktu tersebut. Jika ada yang mau ditanyakan, dapat menghubungi <strong>0812 3000 5673</strong>.
                            </p>
                        @endif

                        @if ($managementUrl)
                            <p style="margin:22px 0 12px;">Jadwalkan ulang atau batalkan booking melalui tautan aman berikut:</p>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="border-radius:6px;background:#1796c7;">
                                        <a href="{{ $managementUrl }}" style="display:inline-block;padding:11px 18px;color:#ffffff;font-weight:700;text-decoration:none;">Kelola Booking</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:10px 0 0;color:#6b7280;font-size:12px;">Jangan bagikan tautan ini kepada orang lain.</p>
                        @endif

                        <p style="margin:24px 0 0;">Wassalamualaikum Wr. Wb.<br><strong>Al Azhar Memorial Garden</strong></p>

                        @if ($kind !== \App\Mail\BookingNotification::CANCELLED)
                            <p style="margin:24px 0 0;padding:14px 16px;border-left:4px solid #1796c7;background:#f3f9fc;color:#4b5563;font-size:13px;">
                                <strong>NB:</strong> Jika data Zona/No Lot/Nama Alm tidak sesuai data di AMG, mohon maaf, info ziarah tidak kami proses.
                            </p>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
