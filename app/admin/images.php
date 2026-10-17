<?php
/**
 * Photo uploads from the admin panel. Every photo is checked, turned upright (phone photos), resized and saved as WebP
 * with a 700px copy for phones ("name-sm.webp"). Logos keep transparency (PNG + WebP). Names are SEO-friendly and unique,
 * so a replaced photo never shows an old cached copy.
 */
declare(strict_types=1);
if (!defined('DM_APP')) { http_response_code(404); exit; }

const DM_UPLOADS = 'assets/uploads';
const DM_MAX_UPLOAD = 25 * 1024 * 1024;

/** kind => [folder, max width, max height, format] */
function image_kinds(): array {
    return [
        'photo'    => ['photos',    1600, 1600, 'webp'],
        'project'  => ['projects',  1600, 1600, 'webp'],
        'hero'     => ['photos',    1920, 1400, 'webp'],
        'portrait' => ['photos',    1000, 1250, 'webp'],
        'logo'     => ['logos',      800,  360, 'logo'],
        'flogo'    => ['logos',      800,  450, 'png'],
        'supplier' => ['suppliers',  480,  120, 'png'],
        'favicon'  => ['icons',      512,  512, 'favicon'],
    ];
}

function upload_error_text(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Dosya çok büyük. En fazla 25 MB yükleyebilirsiniz.',
        UPLOAD_ERR_PARTIAL => 'Yükleme yarıda kesildi, tekrar deneyin.',
        UPLOAD_ERR_NO_FILE => 'Dosya seçilmedi.',
        default => 'Dosya yüklenemedi (hata ' . $code . ').',
    };
}

function unique_path(string $dir, string $base, string $ext): string {
    $base = slugify($base) ?: 'photo';
    $base = substr($base, 0, 80);
    $cand = "$dir/$base.$ext"; $i = 2;
    while (is_file(DM_ROOT . '/' . $cand) || is_file(DM_ROOT . '/' . preg_replace('/\.[a-z]+$/', '', $cand) . '-sm.webp')) $cand = "$dir/$base-" . $i++ . ".$ext";
    return $cand;
}

function gd_open(string $tmp, string $mime) {
    $im = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png'  => @imagecreatefrompng($tmp),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        'image/gif'  => @imagecreatefromgif($tmp),
        default => false,
    };
    if (!$im) throw new RuntimeException('Bu görsel açılamadı. JPG, PNG veya WebP yükleyin.');
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $ex = @exif_read_data($tmp);
        $o = (int)($ex['Orientation'] ?? 1);
        $im = match ($o) { 3 => imagerotate($im, 180, 0), 6 => imagerotate($im, -90, 0), 8 => imagerotate($im, 90, 0), default => $im };
    }
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    imagealphablending($im, false); imagesavealpha($im, true);
    return $im;
}
function gd_fit($im, int $maxW, int $maxH) {
    $w = imagesx($im); $h = imagesy($im);
    $s = min(1, $maxW / $w, $maxH / $h);
    if ($s >= 1) return $im;
    $nw = max(1, (int)round($w * $s)); $nh = max(1, (int)round($h * $s));
    $out = imagecreatetruecolor($nw, $nh);
    imagealphablending($out, false); imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $out;
}
function gd_square($im, int $size) {
    $w = imagesx($im); $h = imagesy($im); $s = min($w, $h);
    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false); imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $im, 0, 0, (int)(($w - $s) / 2), (int)(($h - $s) / 2), $size, $size, $s, $s);
    return $out;
}
function ensure_dir(string $rel): void {
    $d = DM_ROOT . '/' . $rel;
    if (!is_dir($d) && !mkdir($d, 0755, true) && !is_dir($d)) throw new RuntimeException('Klasör oluşturulamadı: ' . $rel);
}

/**
 * Process one uploaded file ($_FILES entry). $name is the SEO name to use (e.g. alt text or project title).
 * Returns ['file' => path, 'w' => .., 'h' => .., 'extra' => [...]] (paths relative to the site folder).
 */
function image_upload(array $f, string $kind, string $name): array {
    if (!isset(image_kinds()[$kind])) throw new RuntimeException('Bilinmeyen görsel türü.');
    [$folder, $mw, $mh, $fmt] = image_kinds()[$kind];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException(upload_error_text((int)($f['error'] ?? 4)));
    if (!is_uploaded_file((string)$f['tmp_name']) && PHP_SAPI !== 'cli') throw new RuntimeException('Geçersiz yükleme.');
    if ((int)$f['size'] > DM_MAX_UPLOAD) throw new RuntimeException(upload_error_text(UPLOAD_ERR_INI_SIZE));
    $info = @getimagesize((string)$f['tmp_name']);
    if (!$info) throw new RuntimeException('Bu dosya bir görsel değil. JPG, PNG veya WebP yükleyin.');
    [$w, $h] = $info; $mime = (string)$info['mime'];
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) throw new RuntimeException('Bu görsel türü desteklenmiyor (' . $mime . '). JPG, PNG veya WebP yükleyin.');
    if ($w < 8 || $h < 8 || $w > 12000 || $h > 12000 || $w * $h > 60_000_000) throw new RuntimeException('Görsel boyutu uygun değil (' . $w . '×' . $h . ').');
    @ini_set('memory_limit', '512M');
    $im = gd_open((string)$f['tmp_name'], $mime);
    $dir = DM_UPLOADS . '/' . $folder;
    ensure_dir($dir);
    $base = $name !== '' ? $name : pathinfo((string)($f['name'] ?? 'photo'), PATHINFO_FILENAME);
    $extra = [];
    if ($fmt === 'webp') {
        $out = gd_fit($im, $mw, $mh);
        $path = unique_path($dir, $base, 'webp');
        if (!imagewebp($out, DM_ROOT . '/' . $path, 82)) throw new RuntimeException('Fotoğraf kaydedilemedi.');
        if (imagesx($out) > 760) {
            $sm = gd_fit($out, 700, 4000);
            imagewebp($sm, DM_ROOT . '/' . img_small($path), 78);
            $extra[] = img_small($path);
        }
        return ['file' => $path, 'w' => imagesx($out), 'h' => imagesy($out), 'extra' => $extra];
    }
    if ($fmt === 'png' || $fmt === 'logo') {
        $out = gd_fit($im, $mw, $mh);
        $path = unique_path($dir, $base, 'png');
        if (!imagepng($out, DM_ROOT . '/' . $path, 9)) throw new RuntimeException('Logo kaydedilemedi.');
        $res = ['file' => $path, 'w' => imagesx($out), 'h' => imagesy($out), 'extra' => []];
        if ($fmt === 'logo' && function_exists('imagewebp')) {
            $webp = preg_replace('/\.png$/', '.webp', $path);
            imagewebp($out, DM_ROOT . '/' . $webp, 90);
            $res['webp'] = $webp; $res['extra'][] = $webp;
        }
        return $res;
    }
    // favicon: square icons in four sizes
    $sizes = ['favicon_32' => 32, 'favicon_48' => 48, 'apple_touch_icon' => 180, 'icon_512' => 512];
    $res = ['file' => '', 'w' => 512, 'h' => 512, 'extra' => [], 'icons' => []];
    $stem = unique_path($dir, $base, 'png');
    $stem = preg_replace('/\.png$/', '', $stem);
    foreach ($sizes as $k => $px) {
        $p = $stem . '-' . $px . '.png';
        imagepng(gd_square($im, $px), DM_ROOT . '/' . $p, 9);
        $res['icons'][$k] = $p; $res['extra'][] = $p;
    }
    $res['file'] = $res['icons']['icon_512'];
    return $res;
}

/**
 * Give a photo a new SEO file name (the phone copy too) and return the new path. The old file stays on the server,
 * so older backups that still point to it keep working.
 */
function image_rename(string $file, string $newName): string {
    if (!preg_match('#^assets/[a-z0-9/_\-.]+\.(webp|png|jpe?g)$#i', $file) || str_contains($file, '..')) throw new RuntimeException('Bu dosya yeniden adlandırılamaz.');
    if (!is_file(DM_ROOT . '/' . $file)) throw new RuntimeException('Dosya bulunamadı: ' . $file);
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $dir = dirname($file);
    $slug = substr(slugify(preg_replace('/\.(webp|png|jpe?g)$/i', '', $newName) ?? ''), 0, 80);
    if ($slug === '') throw new RuntimeException('Yeni dosya adı geçersiz. Harf, rakam ve tire kullanın.');
    if ($dir . '/' . $slug . '.' . $ext === $file) return $file;
    $new = unique_path($dir, $slug, $ext);
    if (!copy(DM_ROOT . '/' . $file, DM_ROOT . '/' . $new)) throw new RuntimeException('Dosya kopyalanamadı.');
    $smOld = img_small($file);
    if (is_file(DM_ROOT . '/' . $smOld)) @copy(DM_ROOT . '/' . $smOld, DM_ROOT . '/' . img_small($new));
    return $new;
}
