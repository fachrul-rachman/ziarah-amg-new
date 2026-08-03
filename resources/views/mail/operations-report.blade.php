<!doctype html>
<html lang="id">
<body>
    <p>Assalamualaikum Wr. Wb.</p>

    <p>Terlampir {{ $periodTitle }} untuk tanggal {{ $reportDate }}.</p>

    <p>
        Rentang waktu:
        {{ substr($startTime, 0, 5) }} sampai
        {{ substr($endTime, 0, 5) }} WIB.<br>
        Jumlah booking: {{ $bookingCount }}.
    </p>

    <p>
        Wassalamualaikum Wr. Wb.<br>
        Al Azhar Memorial Garden
    </p>
</body>
</html>
