<?php

declare(strict_types=1);

/**
 * Geser semua kolom waktu di DB aplikasi login sebesar +7 jam (UTC -> WIB).
 *
 * Dipakai sekali, bersamaan dengan pemasangan APP_TIMEZONE=Asia/Jakarta di
 * `.env`. Sebelum perubahan itu aplikasi menulis jam dinding UTC ke DB;
 * sesudahnya ia menulis jam dinding WIB. Tanpa pergeseran ini, baris-baris lama
 * terbaca 7 jam lebih awal daripada kejadian sebenarnya.
 *
 * Idempoten TIDAK berlaku: menjalankannya dua kali akan menggeser data dua kali.
 * Karena itu tanpa `--apply` skrip ini hanya melaporkan. Snapshot dulu:
 *
 *     bash /home/Heloklin/scripts/backup_login_db.sh
 *
 * Jalankan dari akar repo:
 *
 *     php scripts/shift_timezone.php           # laporan saja
 *     php scripts/shift_timezone.php --apply   # geser +7 jam
 *
 * Setelah itu: `php artisan optimize` sebagai user web, lalu muat ulang
 * PHP-FPM. Rinciannya di PRODUCTION.md §3 "Zona waktu".
 */

$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv, true);

$database = (string) config('database.connections.mysql.database');

$columns = DB::select(
    'select table_name, column_name from information_schema.columns'
    .' where table_schema = ? and data_type in ("datetime","timestamp")'
    .' order by table_name, column_name',
    [$database],
);

printf("Database  : %s\n", $database);
printf("Mode      : %s\n", $apply ? 'UBAH DATA' : 'laporan saja (tambahkan --apply)');
printf("Zona app  : %s | now(): %s\n", config('app.timezone'), now()->toDateTimeString());
printf("Zona MySQL: %s | now(): %s\n", DB::selectOne('select @@session.time_zone as tz')->tz, DB::selectOne('select now() as n')->n);
printf("Kolom     : %d\n\n", count($columns));

$total = 0;

foreach ($columns as $column) {
    $table = $column->table_name;
    $name = $column->column_name;

    // Nilai yang terlihat di sesi ini adalah nilai yang tersimpan, jadi
    // penghitung ini sama dengan jumlah baris yang isinya tidak null.
    $rows = (int) DB::table($table)->whereNotNull($name)->count();

    if ($rows === 0) {
        printf("  %-28s %-20s %6d baris\n", $table, $name, 0);

        continue;
    }

    if ($apply) {
        DB::update(
            sprintf(
                'update `%s` set `%s` = date_add(`%s`, interval 7 hour) where `%s` is not null',
                $table,
                $name,
                $name,
                $name,
            ),
        );
    }

    printf("  %-28s %-20s %6d baris%s\n", $table, $name, $rows, $apply ? '  digeser' : '');
    $total += $rows;
}

printf("\nTotal kolom terisi: %d\n", $total);

if ($apply) {
    printf("\nSesudah pergeseran:\n");
    printf("  MySQL now()  : %s\n", DB::selectOne('select now() as n')->n);
    printf("  App now()    : %s\n", now()->toDateTimeString());

    $latest = DB::table('test_logs')->orderByDesc('id')->first();

    if ($latest !== null) {
        printf("  log terakhir : %s\n", $latest->created_at);
    }
} else {
    printf("\nBelum ada yang diubah. Ulangi dengan --apply untuk mengeksekusi.\n");
}
