<?php
// Compiles all .po files in cacheboost-warmer/languages/ into .mo files.

function po_to_mo(string $po_file): string {
    $content = file_get_contents($po_file);
    $entries = [];
    $msgid = null;
    $msgstr = null;
    $in_msgid = false;
    $in_msgstr = false;

    $unescape = fn(string $s): string => stripcslashes($s);

    foreach (explode("\n", $content) as $line) {
        if (str_starts_with($line, 'msgid "')) {
            if ($msgid !== null && $msgstr !== null) {
                $entries[] = [$msgid, $msgstr];
            }
            $msgid = $unescape(substr($line, 7, -1));
            $msgstr = null;
            $in_msgid = true;
            $in_msgstr = false;
        } elseif (str_starts_with($line, 'msgstr "')) {
            $msgstr = $unescape(substr($line, 8, -1));
            $in_msgid = false;
            $in_msgstr = true;
        } elseif (str_starts_with($line, '"') && str_ends_with($line, '"')) {
            $chunk = $unescape(substr($line, 1, -1));
            if ($in_msgid) $msgid .= $chunk;
            elseif ($in_msgstr) $msgstr .= $chunk;
        } else {
            $in_msgid = $in_msgstr = false;
        }
    }
    if ($msgid !== null && $msgstr !== null) {
        $entries[] = [$msgid, $msgstr];
    }

    // Keep only non-empty translations (skip header entry where msgid='')
    $translated = array_filter($entries, fn($e) => $e[0] !== '' && $e[1] !== '');
    // Sort by msgid (required by MO format)
    usort($translated, fn($a, $b) => strcmp($a[0], $b[0]));

    $n = count($translated);
    $originals   = array_column($translated, 0);
    $translations = array_column($translated, 1);

    // Offsets: header=28, orig_table=28, trans_table=28+N*8, strings start after both tables
    $strings_offset = 28 + $n * 8 + $n * 8;
    $orig_offsets  = [];
    $trans_offsets = [];
    $strings_data  = '';

    foreach ($originals as $s) {
        $orig_offsets[] = [strlen($s), $strings_offset + strlen($strings_data)];
        $strings_data .= $s . "\0";
    }
    foreach ($translations as $s) {
        $trans_offsets[] = [strlen($s), $strings_offset + strlen($strings_data)];
        $strings_data .= $s . "\0";
    }

    $mo  = pack('V', 0x950412de); // magic LE
    $mo .= pack('V', 0);          // revision
    $mo .= pack('V', $n);         // number of strings
    $mo .= pack('V', 28);         // offset of orig table
    $mo .= pack('V', 28 + $n * 8); // offset of trans table
    $mo .= pack('V', 0);          // hash table size
    $mo .= pack('V', 0);          // hash table offset

    foreach ($orig_offsets as [$len, $off]) {
        $mo .= pack('V', $len) . pack('V', $off);
    }
    foreach ($trans_offsets as [$len, $off]) {
        $mo .= pack('V', $len) . pack('V', $off);
    }
    $mo .= $strings_data;

    return $mo;
}

$dir = __DIR__ . '/cacheboost-warmer/languages';
foreach (glob("$dir/*.po") as $po) {
    $mo_file = substr($po, 0, -3) . '.mo';
    file_put_contents($mo_file, po_to_mo($po));
    echo "Compiled: " . basename($po) . " -> " . basename($mo_file) . "\n";
}
echo "Done.\n";
