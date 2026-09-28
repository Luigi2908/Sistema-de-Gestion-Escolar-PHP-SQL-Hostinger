<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * PWA icon generator — pure PHP (no GD / no ImageMagick, only zlib).
 * Draws a flat "dashboard grid" mark on a navy tile. Run from the CLI to
 * (re)generate icons; change $BG / $ACCENT to match a project's brand.
 *
 *   php make_icons.php
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

$BG     = [0x00, 0x1f, 0x3f]; // navy background
$WHITE  = [0xff, 0xff, 0xff];
$ACCENT = [0x00, 0x74, 0xD9]; // accent tile

// one PNG chunk: length + type + data + CRC32
function png_chunk($type, $data) {
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

// flat-colour RGB PNG with a 2x2 tile grid centred on the canvas
function make_png($size, $path, $maskable, $BG, $WHITE, $ACCENT) {
    $safe   = $maskable ? 0.60 : 0.70;         // maskable keeps content in the safe zone
    $grid   = $size * $safe;
    $origin = ($size - $grid) / 2;
    $gap    = $grid * 0.09;
    $tile   = ($grid - $gap) / 2;

    // four tiles: [x0,y0,x1,y1,colour] — one accent (top-right)
    $rects = [];
    foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as $i => $c) {
        $x0 = $origin + $c[0] * ($tile + $gap);
        $y0 = $origin + $c[1] * ($tile + $gap);
        $rects[] = [(int)$x0, (int)$y0, (int)($x0 + $tile), (int)($y0 + $tile), $i === 1 ? $ACCENT : $WHITE];
    }

    $bgPix = chr($BG[0]) . chr($BG[1]) . chr($BG[2]);
    $raw = '';
    for ($y = 0; $y < $size; $y++) {
        $row = "\x00"; // filter byte 0 (none)
        // start from a full background row, overwrite tile spans
        $px = str_repeat($bgPix, $size);
        foreach ($rects as $r) {
            if ($y < $r[1] || $y >= $r[3]) continue;
            $span = str_repeat(chr($r[4][0]) . chr($r[4][1]) . chr($r[4][2]), $r[2] - $r[0]);
            $px = substr_replace($px, $span, $r[0] * 3, ($r[2] - $r[0]) * 3);
        }
        $raw .= $row . $px;
    }

    $ihdr = pack('NN', $size, $size) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0); // 8-bit RGB
    $png = "\x89PNG\r\n\x1a\n"
        . png_chunk('IHDR', $ihdr)
        . png_chunk('IDAT', gzcompress($raw, 9))
        . png_chunk('IEND', '');
    file_put_contents($path, $png);
    echo "wrote $path (" . filesize($path) . " bytes)\n";
}

make_png(192, __DIR__ . '/icon-192.png', false, $BG, $WHITE, $ACCENT);
make_png(512, __DIR__ . '/icon-512.png', false, $BG, $WHITE, $ACCENT);
make_png(512, __DIR__ . '/icon-maskable-512.png', true, $BG, $WHITE, $ACCENT);
echo "done\n";
