<?php
/**
 * A small PDF writer for invoices (no libraries needed on the server): Letter pages, Helvetica and Helvetica-Bold
 * (WinAnsi encoding), lines, boxes, JPEG images and rotated stamps. Text is measured with the Helvetica widths, so it
 * can be right-aligned and wrapped. Coordinates are in points from the top-left corner.
 */
declare(strict_types=1);
if (!defined('DM_APP')) { http_response_code(404); exit; }

final class DmPdf {
    public float $w = 612.0;                 // US Letter (used in Canada)
    public float $h = 792.0;
    private array $pages = [];
    private int $cur = -1;
    private array $images = [];              // name => [jpeg bytes, width px, height px]

    public function __construct(private string $title = '') {}

    public function addPage(): int { $this->pages[] = ''; return $this->cur = count($this->pages) - 1; }
    public function setPage(int $i): void { $this->cur = $i; }
    public function pageCount(): int { return count($this->pages); }
    private function out(string $s): void { $this->pages[$this->cur] .= $s . "\n"; }
    private static function n(float $v): string { $s = number_format($v, 3, '.', ''); $s = rtrim(rtrim($s, '0'), '.'); return $s === '' || $s === '-0' ? '0' : $s; }
    private static function rgb(array $c): string { return self::n($c[0] / 255) . ' ' . self::n($c[1] / 255) . ' ' . self::n($c[2] / 255); }
    /** UTF-8 to WinAnsi (Windows-1252); Turkish letters it lacks are written without their marks. */
    public static function enc(string $s): string {
        $s = strtr($s, ['ş' => 's', 'Ş' => 'S', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I', '−' => '-', '→' => '->', "\u{00A0}" => ' ', "\t" => ' ']);
        return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }
    private static function esc(string $bin): string { return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $bin); }

    /** Helvetica-Bold widths (1/1000 em) for ASCII; Helvetica regular comes from the SEO table (same metrics as Arial). */
    private static function boldWidths(): array {
        static $w = null;
        if ($w !== null) return $w;
        $w = [32 => 278, 33 => 333, 34 => 474, 35 => 556, 36 => 556, 37 => 889, 38 => 722, 39 => 238, 40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
              58 => 333, 59 => 333, 60 => 584, 61 => 584, 62 => 584, 63 => 611, 64 => 975, 91 => 333, 92 => 278, 93 => 333, 94 => 584, 95 => 556, 96 => 333, 123 => 389, 124 => 280, 125 => 389, 126 => 584,
              8211 => 556, 8212 => 1000, 8216 => 278, 8217 => 278, 8220 => 500, 8221 => 500, 8226 => 350, 8230 => 1000];
        for ($i = 48; $i <= 57; $i++) $w[$i] = 556;
        foreach (explode(' ', 'A722 B722 C722 D722 E667 F611 G778 H722 I278 J556 K722 L611 M833 N722 O778 P667 Q778 R722 S667 T611 U722 V667 W944 X667 Y667 Z611 '
            . 'a556 b611 c556 d611 e556 f333 g611 h611 i278 j278 k556 l278 m889 n611 o611 p611 q611 r389 s556 t333 u611 v556 w778 x556 y556 z500') as $p)
            $w[ord($p[0])] = (int)substr($p, 1);
        return $w;
    }
    public function width(string $s, float $size, bool $bold = false): float {
        $reg = seo_widths(); $bw = self::boldWidths(); $t = 0;
        foreach (mb_str_split($s) as $ch) { $c = mb_ord($ch); $t += $bold ? ($bw[$c] ?? (int)round(($reg[$c] ?? 556) * 1.06)) : ($reg[$c] ?? 556); }
        return $t * $size / 1000;
    }
    /** Lines of $s that fit into $max points (paragraphs kept; very long words are broken). */
    public function wrap(string $s, float $max, float $size, bool $bold = false): array {
        $lines = [];
        foreach (preg_split('/\R/u', trim($s)) ?: [] as $para) {
            $cur = '';
            foreach (preg_split('/\s+/u', trim($para), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $try = $cur === '' ? $word : $cur . ' ' . $word;
                if ($cur !== '' && $this->width($try, $size, $bold) > $max) { $lines[] = $cur; $cur = $word; } else $cur = $try;
                while ($this->width($cur, $size, $bold) > $max && mb_strlen($cur) > 1) {      // a word longer than the line
                    $cut = mb_strlen($cur) - 1;
                    while ($cut > 1 && $this->width(mb_substr($cur, 0, $cut), $size, $bold) > $max) $cut--;
                    $lines[] = mb_substr($cur, 0, $cut); $cur = mb_substr($cur, $cut);
                }
            }
            $lines[] = $cur;
        }
        return $lines;
    }
    /** Text with its baseline at $y. $align: L, R (x = right edge) or C (x = centre). */
    public function text(float $x, float $y, string $s, float $size, bool $bold = false, array $color = [26, 31, 38], string $align = 'L', float $spacing = 0): void {
        if ($s === '') return;
        if ($align !== 'L') { $w = $this->width($s, $size, $bold) + $spacing * max(0, mb_strlen($s) - 1); $x -= $align === 'R' ? $w : $w / 2; }
        $this->out('BT /' . ($bold ? 'F2' : 'F1') . ' ' . self::n($size) . ' Tf ' . self::n($spacing) . ' Tc ' . self::rgb($color) . ' rg ' . self::n($x) . ' ' . self::n($this->h - $y) . ' Td (' . self::esc(self::enc($s)) . ') Tj ET');
    }
    public function rect(float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $lw = 0.6): void {
        $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
        $this->out(($fill ? self::rgb($fill) . ' rg ' : '') . ($stroke ? self::rgb($stroke) . ' RG ' . self::n($lw) . ' w ' : '') . self::n($x) . ' ' . self::n($this->h - $y - $h) . ' ' . self::n($w) . ' ' . self::n($h) . ' re ' . $op);
    }
    public function line(float $x1, float $y1, float $x2, float $y2, array $color, float $lw = 0.6): void {
        $this->out(self::rgb($color) . ' RG ' . self::n($lw) . ' w ' . self::n($x1) . ' ' . self::n($this->h - $y1) . ' m ' . self::n($x2) . ' ' . self::n($this->h - $y2) . ' l S');
    }
    public function addJpeg(string $name, string $jpeg, int $pw, int $ph): void { $this->images[$name] = [$jpeg, $pw, $ph]; }
    public function image(string $name, float $x, float $y, float $w, float $h): void {
        $this->out('q ' . self::n($w) . ' 0 0 ' . self::n($h) . ' ' . self::n($x) . ' ' . self::n($this->h - $y - $h) . ' cm /' . $name . ' Do Q');
    }
    /** A rubber-stamp word ("PAID") turned by $deg degrees around ($cx, $cy). */
    public function stamp(float $cx, float $cy, string $s, float $size, array $color, float $deg): void {
        $a = deg2rad($deg); $c = cos($a); $sn = sin($a);
        $w = $this->width($s, $size, true) + 2 * mb_strlen($s); $pad = $size * 0.32;
        $this->out('q ' . self::n($c) . ' ' . self::n($sn) . ' ' . self::n(-$sn) . ' ' . self::n($c) . ' ' . self::n($cx) . ' ' . self::n($this->h - $cy) . ' cm '
            . self::rgb($color) . ' RG 2.2 w ' . self::n(-$w / 2 - $pad) . ' ' . self::n(-$size * 0.5 - $pad * 0.6) . ' ' . self::n($w + 2 * $pad) . ' ' . self::n($size + $pad * 1.2) . ' re S '
            . 'BT /F2 ' . self::n($size) . ' Tf 2 Tc ' . self::rgb($color) . ' rg ' . self::n(-$w / 2) . ' ' . self::n(-$size * 0.35) . ' Td (' . self::esc(self::enc($s)) . ') Tj ET Q');
    }
    public function output(): string {
        $objs = [
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => '<< /Title (' . self::esc(self::enc($this->title)) . ') /Producer (Demir Mechanical) /CreationDate (D:' . date('YmdHis') . ') >>',
        ];
        $n = 6; $xo = '';
        foreach ($this->images as $name => [$data, $pw, $ph]) {
            $objs[$n] = ['<< /Type /XObject /Subtype /Image /Width ' . $pw . ' /Height ' . $ph . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($data) . ' >>', $data];
            $xo .= '/' . $name . ' ' . $n . ' 0 R ';
            $n++;
        }
        $res = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ($xo !== '' ? ' /XObject << ' . $xo . '>>' : '') . ' >>';
        $kids = [];
        foreach ($this->pages as $content) {
            $z = (string)gzcompress($content, 6);
            $objs[$n] = ['<< /Length ' . strlen($z) . ' /Filter /FlateDecode >>', $z];
            $objs[$n + 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::n($this->w) . ' ' . self::n($this->h) . '] /Resources ' . $res . ' /Contents ' . $n . ' 0 R >>';
            $kids[] = ($n + 1) . ' 0 R';
            $n += 2;
        }
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $o = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $off = [];
        foreach ($objs as $i => $b) {
            $off[$i] = strlen($o);
            $o .= $i . " 0 obj\n" . (is_array($b) ? $b[0] . "\nstream\n" . $b[1] . "\nendstream" : $b) . "\nendobj\n";
        }
        $xref = strlen($o);
        $o .= "xref\n0 " . $n . "\n0000000000 65535 f \n";
        for ($i = 1; $i < $n; $i++) $o .= sprintf("%010d 00000 n \n", $off[$i]);
        return $o . "trailer\n<< /Size " . $n . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}
