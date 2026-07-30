@if ($kind === \App\Mail\BookingNotification::CONFIRMED)
Booking Berhasil
@elseif ($kind === \App\Mail\BookingNotification::RESCHEDULED)
Jadwal Booking Diperbarui
@else
Booking Dibatalkan
@endif

Assalamualaikum Wr. Wb.
Yth {{ $booking->customer_name }}

@if ($kind === \App\Mail\BookingNotification::CANCELLED)
Booking kunjungan ziarah Anda telah dibatalkan. Berikut detail booking yang dibatalkan:
@else
@if ($kind === \App\Mail\BookingNotification::RESCHEDULED)
Jadwal booking Anda berhasil diperbarui. Tautan pengelolaan sebelumnya sudah tidak berlaku.
@endif
Mohon pastikan info ziarah lengkap dan akurat:
@endif

Kode booking: {{ $booking->public_reference }}
Zona: {{ $booking->zone_name_snapshot }}
Nomor lot: {{ $booking->lot_number }}
Tanggal: {{ $booking->visit_date->locale('id')->translatedFormat('d F Y') }}
Jam: {{ substr($booking->visit_time, 0, 5) }} WIB
Nama pemesan: {{ $booking->customer_name }}
Nama Alm: {{ $booking->deceased_name }}
Fasilitas: Kursi {{ $booking->chair_count }}, Tenda {{ $booking->tent_required ? 'Ya' : 'Tidak' }}
@if ($booking->additional_notes)
Catatan tambahan: {{ $booking->additional_notes }}
@endif

@if ($kind !== \App\Mail\BookingNotification::CANCELLED)
Agar kami dapat memberikan pelayanan yang maksimal, mohon untuk dapat sampai di AMG sesuai waktu tersebut. Jika ada yang mau ditanyakan, dapat menghubungi 0812 3000 5673.
@endif

@if ($managementUrl)
Kelola booking: {{ $managementUrl }}
Jangan bagikan tautan ini kepada orang lain.
@endif

Wassalamualaikum Wr. Wb.
Al Azhar Memorial Garden

@if ($kind !== \App\Mail\BookingNotification::CANCELLED)
NB: Jika data Zona/No Lot/Nama Alm tidak sesuai data di AMG, mohon maaf, info ziarah tidak kami proses.
@endif
