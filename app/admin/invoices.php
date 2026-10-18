<?php
/**
 * Faturalar (İşletme → Faturalar): invoices for the business's own customers, with PDF.
 * Invoices are business records with customers' personal data, so they are kept apart from the website content in
 * data/invoices.json (closed to the web, never in git, included in the panel's full backup). A copy of the file is
 * kept in data/backups/ before every change. Settings (HST number, numbering, tax, payment text) are in the content
 * (Fatura Ayarları, content.invoice). The PDF is written by app/admin/pdf.php; the invoice itself is in English.
 */
declare(strict_types=1);
if (!defined('DM_APP')) { http_response_code(404); exit; }

const DM_INVOICES = DM_ROOT . '/data/invoices.json';
const DM_INV_STATES = ['draft' => 'Taslak', 'sent' => 'Kesildi', 'overdue' => 'Gecikmiş', 'paid' => 'Ödendi', 'void' => 'İptal'];

/* ---------------- storage ---------------- */
function inv_db(): array {
    if (!is_file(DM_INVOICES)) return ['next' => 0, 'list' => []];
    $d = json_decode((string)file_get_contents(DM_INVOICES), true);
    if (!is_array($d) || !isset($d['list']) || !is_array($d['list'])) throw new RuntimeException('Faturalar dosyası (data/invoices.json) okunamadı. data/backups klasöründeki en yeni invoices-… dosyasını data/invoices.json adıyla geri koyun.');
    $d['list'] = array_values(array_filter($d['list'], 'is_array'));
    foreach ($d['list'] as &$x) $x = inv_norm($x);
    return $d + ['next' => 0];
}
/** Fill missing keys (older records, hand edits) so the views never meet a gap. */
function inv_norm(array $x): array {
    $x += ['id' => '', 'number' => '', 'status' => 'draft', 'date' => '', 'due' => '', 'customer' => [], 'site' => '', 'items' => [], 'tax_label' => 'HST', 'tax_rate' => 0,
           'discount' => 0, 'paid' => 0, 'notes' => '', 'terms' => '', 'created' => '', 'updated' => ''];
    $x['customer'] = (is_array($x['customer']) ? $x['customer'] : []) + ['name' => '', 'company' => '', 'address' => '', 'email' => '', 'phone' => ''];
    $x['items'] = array_values(array_map(fn($it) => (is_array($it) ? $it : []) + ['desc' => '', 'qty' => 0, 'price' => 0], is_array($x['items']) ? $x['items'] : []));
    if (!isset(DM_INV_STATES[$x['status']]) || $x['status'] === 'overdue') $x['status'] = 'draft';
    return $x;
}
/** Change the invoices under the panel's lock. $fn gets the freshly read data by reference; the old file is backed up first. */
function inv_update(callable $fn) {
    return with_lock(function () use ($fn) {
        $db = inv_db();
        $r = $fn($db);
        if (is_file(DM_INVOICES)) {
            if (!is_dir(DM_BACKUPS)) mkdir(DM_BACKUPS, 0755, true);
            copy(DM_INVOICES, DM_BACKUPS . '/invoices-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json');
            $old = glob(DM_BACKUPS . '/invoices-*.json') ?: [];
            rsort($old);
            foreach (array_slice($old, 60) as $f) @unlink($f);
        }
        write_atomic(DM_INVOICES, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", 0600);
        return $r;
    });
}
function inv_index(array $db, string $id): ?int {
    if ($id === '') return null;
    foreach ($db['list'] as $i => $x) if ($x['id'] === $id) return $i;
    return null;
}
/** Another invoice that already carries this number. */
function inv_dup(array $db, string $number, string $exceptId): ?array {
    foreach ($db['list'] as $x) if ($x['id'] !== $exceptId && strcasecmp(trim((string)$x['number']), trim($number)) === 0) return $x;
    return null;
}
function inv_cfg(): array {
    return (array)(C()['invoice'] ?? []) + ['prefix' => 'DM-', 'start' => 1001, 'tax_label' => 'HST', 'tax_rate' => 13, 'tax_number' => '', 'due_days' => 14, 'payment' => '', 'notes' => ''];
}
/** Numbering goes on after the highest number that follows the current prefix. */
function inv_bump(array &$db, string $number): void {
    if (preg_match('/^' . preg_quote((string)inv_cfg()['prefix'], '/') . '(\d{1,9})$/i', trim($number), $m)) $db['next'] = max((int)($db['next'] ?? 0), (int)$m[1] + 1);
}
function inv_fill(string $s): string {
    $s = fill($s, ['email' => (string)cget('contact.email'), 'legal_name' => (string)cget('site.legal_name'), 'phone' => phone(), 'brand' => brand()]);
    return (string)preg_replace('/(?<!\.)\.\.(?!\.)/', '.', $s);          // "… Ltd.." when the name ends with a full stop
}
/** A new invoice with the next free number and the default texts. */
function inv_new(array $db): array {
    $cfg = inv_cfg();
    $next = max((int)$cfg['start'], (int)($db['next'] ?? 0));
    while (inv_dup($db, $cfg['prefix'] . $next, '')) $next++;
    $days = max(0, (int)$cfg['due_days']);
    return inv_norm(['number' => $cfg['prefix'] . $next, 'status' => 'sent', 'date' => date('Y-m-d'), 'due' => $days > 0 ? date('Y-m-d', strtotime('+' . $days . ' days')) : '',
        'items' => [['desc' => '', 'qty' => 1, 'price' => '']], 'tax_label' => (string)$cfg['tax_label'], 'tax_rate' => (float)$cfg['tax_rate'],
        'notes' => (string)$cfg['notes'], 'terms' => inv_fill((string)$cfg['payment'])]);
}

/* ---------------- amounts ---------------- */
/** All amounts rounded to cents the same way as the editor's live totals (admin.js). "Ödendi" means paid in full. */
function inv_totals(array $inv): array {
    $sub = 0.0;
    foreach ($inv['items'] ?? [] as $it) $sub += round((float)($it['qty'] ?? 0) * (float)($it['price'] ?? 0), 2);
    $sub = round($sub, 2);
    $disc = round(min(max(0.0, (float)($inv['discount'] ?? 0)), max(0.0, $sub)), 2);
    $taxable = round($sub - $disc, 2);
    $tax = round($taxable * (float)($inv['tax_rate'] ?? 0) / 100, 2);
    $total = round($taxable + $tax, 2);
    $paid = round(max(0.0, (float)($inv['paid'] ?? 0)), 2);
    if (($inv['status'] ?? '') === 'paid') $paid = max($paid, $total);
    return ['sub' => $sub, 'disc' => $disc, 'tax' => $tax, 'total' => $total, 'paid' => $paid, 'due' => round($total - $paid, 2)];
}
function inv_money(float $v): string { return (round($v, 2) < 0 ? '-' : '') . '$' . number_format(abs($v), 2); }
function inv_num(float $v): string { $s = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'); return $s === '' || $s === '-0' ? '0' : $s; }
function inv_date_en(string $d): string { $t = $d !== '' ? strtotime($d) : false; return $t ? date('F j, Y', $t) : ''; }
function inv_date_tr(string $d): string { $t = $d !== '' ? strtotime($d) : false; return $t ? tr_date($t, false) : ''; }
/** draft / sent / overdue / paid / void */
function inv_state(array $inv): string {
    $s = (string)($inv['status'] ?? 'draft');
    if ($s === 'sent' && ($inv['due'] ?? '') !== '' && $inv['due'] < date('Y-m-d') && inv_totals($inv)['due'] > 0) return 'overdue';
    return $s;
}
/** Numbers for the dashboard. */
function inv_summary(): array {
    $o = ['count' => 0, 'open' => 0.0, 'open_n' => 0, 'overdue' => 0, 'overdue_amt' => 0.0];
    try { $db = inv_db(); } catch (\RuntimeException $e) { return $o; }
    foreach ($db['list'] as $x) {
        $o['count']++;
        $st = inv_state($x);
        if ($st !== 'sent' && $st !== 'overdue') continue;
        $due = inv_totals($x)['due'];
        $o['open'] += $due; $o['open_n']++;
        if ($st === 'overdue') { $o['overdue']++; $o['overdue_amt'] += $due; }
    }
    return $o;
}

/* ---------------- editor fields ---------------- */
function invoice_fields(): array {
    return [
        HD('Fatura'),
        F('number', 'text', 'Fatura numarası', ['req' => true, 'max' => 30, 'ac' => 'off']),
        F('status', 'select', 'Durum', ['opts' => ['draft' => 'Taslak (henüz kesilmedi)', 'sent' => 'Kesildi (ödeme bekleniyor)', 'paid' => 'Ödendi (tamamı)', 'void' => 'İptal']]),
        F('date', 'date', 'Fatura tarihi', ['req' => true]),
        F('due', 'date', 'Son ödeme tarihi', ['h' => 'Boş bırakılırsa faturada "Due on receipt" (teslimde ödenir) yazar.']),
        HD('Müşteri', 'Faturada "Bill to" kısmında görünür. Daha önce fatura kestiğiniz bir müşterinin adını yazıp listeden seçince diğer bilgileri kendiliğinden dolar.'),
        F('customer.name', 'text', 'Ad soyad veya firma adı', ['req' => true, 'max' => 120, 'list' => 'invCust']),
        F('customer.company', 'text', 'Firma (isteğe bağlı)', ['max' => 120, 'ac' => 'off']),
        F('customer.address', 'textarea', 'Adres', ['rows' => 3, 'max' => 400, 'ac' => 'off']),
        F('customer.email', 'text', 'E-posta', ['v' => 'email', 'max' => 120, 'ac' => 'off', 'im' => 'email']),
        F('customer.phone', 'text', 'Telefon', ['max' => 40, 'ac' => 'off', 'im' => 'tel']),
        F('site', 'textarea', 'İşin yapıldığı adres (farklıysa)', ['rows' => 2, 'max' => 300, 'ac' => 'off', 'h' => 'Faturada "Service address" olarak ayrıca görünür.']),
        HD('Yapılan işler ve malzemeler', 'Açıklamalar faturada yazdığınız gibi görünür (müşteriniz için İngilizce yazın). Birim fiyatlar vergisizdir; vergi aşağıda eklenir.'),
        F('items', 'repeater', 'Kalemler', ['title' => 'desc', 'add' => 'Kalem ekle', 'open' => true, 'min' => 1, 'max_items' => 100, 'new' => ['desc' => '', 'qty' => 1, 'price' => ''], 'fields' => [
            F('desc', 'textarea', 'Açıklama', ['rows' => 2, 'max' => 1000, 'ph' => 'Örn. Replaced kitchen faucet']),
            F('qty', 'number', 'Adet / saat', ['min' => 0, 'max' => 100000]),
            F('price', 'number', 'Birim fiyat ($)', ['min' => -1000000, 'max' => 1000000, 'ph' => '0.00']),
        ]]),
        HD('Vergi, indirim ve ödeme'),
        F('tax_label', 'text', 'Vergi adı', ['max' => 20]),
        F('tax_rate', 'number', 'Vergi oranı (%)', ['min' => 0, 'max' => 100, 'h' => 'Ontario HST %13. Vergisiz fatura için 0 yazın.']),
        F('discount', 'number', 'İndirim ($)', ['min' => 0, 'max' => 10000000, 'h' => 'Vergiden önce düşülür.']),
        F('paid', 'number', 'Alınan ödeme / kapora ($)', ['min' => 0, 'max' => 10000000, 'h' => 'Faturada "Amount paid" olarak düşülür; kalan "Balance due" olur. Durum "Ödendi" ise faturanın tamamı ödenmiş sayılır.']),
        HD('Faturanın alt kısmı'),
        F('notes', 'textarea', 'Not', ['rows' => 2, 'max' => 1000]),
        F('terms', 'textarea', 'Ödeme bilgileri', ['rows' => 3, 'max' => 1000, 'h' => 'Yeni faturalarda Fatura Ayarları\'ndaki metinle başlar.']),
    ];
}
/** Read the editor into an invoice (on top of $base). Empty lines are dropped; at least one line must stay. */
function inv_parse(array $base, array &$errors): array {
    $inv = parse_fields(invoice_fields(), is_array($_POST['f'] ?? null) ? $_POST['f'] : [], $base, $errors, '');
    $inv['number'] = trim((string)$inv['number']);
    if (!isset(DM_INV_STATES[$inv['status']]) || $inv['status'] === 'overdue') $inv['status'] = 'draft';
    if ($inv['due'] !== '' && $inv['date'] !== '' && $inv['due'] < $inv['date'] && !isset($errors['due'])) $errors['due'] = 'Son ödeme tarihi fatura tarihinden önce olamaz.';
    foreach ($inv['items'] as $i => $it) {
        $it = (array)$it;
        if (trim((string)$it['desc']) === '' && (float)$it['price'] != 0) $errors['items.' . $i . '.desc'] = 'Bu kalem için açıklama yazın.';
    }
    $keep = array_values(array_filter($inv['items'], fn($it) => trim((string)$it['desc']) !== '' || (float)$it['price'] != 0));
    if (!$errors) {
        if (!$keep) $errors['items'] = 'En az bir kalem yazın (açıklama ve fiyat).';
        else $inv['items'] = $keep;
    }
    return inv_norm($inv);
}

/* ---------------- list ---------------- */
function inv_sorted(array $list): array {
    usort($list, fn($a, $b) => [(string)$b['date'], (string)$b['created'], (string)$b['number']] <=> [(string)$a['date'], (string)$a['created'], (string)$a['number']]);
    return $list;
}
function tr_month(int $ts): string {
    static $m = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    return $m[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
}
function view_invoices(string $filter): string {
    $db = inv_db(); $cfg = inv_cfg();
    $list = inv_sorted($db['list']);
    $chips = ['' => 'Tümü', 'open' => 'Ödenmemiş', 'overdue' => 'Gecikmiş', 'paid' => 'Ödendi', 'draft' => 'Taslak', 'void' => 'İptal'];
    $filter = isset($chips[$filter]) ? $filter : '';
    $match = fn(string $f, string $st) => $f === '' || $f === $st || ($f === 'open' && ($st === 'sent' || $st === 'overdue'));
    $acts = '<a class="btn ghost" href="index.php?p=sec&amp;s=invoicing">' . aic('sliders') . '<span>Fatura Ayarları</span></a>'
        . '<a class="btn primary" href="index.php?p=invoice&amp;id=new">' . aic('plus') . '<span>Yeni fatura</span></a>';
    $o = '<div id="invApp">' . head_block('Faturalar', 'Müşterilerinize fatura hazırlayın, PDF olarak indirip e-posta veya WhatsApp ile gönderin. Faturalar sadece sunucuda, korumalı data klasöründe saklanır; sitede ve GitHub\'da görünmez.', $acts);
    if (trim((string)$cfg['tax_number']) === '') $o .= '<div class="flash warn">' . ic('alert') . '<div>Faturalarda ' . e((string)$cfg['tax_label']) . ' numaranız görünmüyor. <a href="index.php?p=sec&amp;s=invoicing">Fatura Ayarları</a>\'ndan ekleyin.</div></div>';
    $open = 0.0; $openN = 0; $month = 0.0; $monthN = 0; $year = 0.0; $m = date('Y-m'); $y = date('Y');
    foreach ($list as $x) {
        $st = inv_state($x); $t = inv_totals($x);
        if ($st === 'sent' || $st === 'overdue') { $open += $t['due']; $openN++; }
        if (in_array($st, ['sent', 'overdue', 'paid'], true) && str_starts_with((string)$x['date'], $m)) { $month += $t['total']; $monthN++; }
        if ($st === 'paid' && str_starts_with((string)$x['date'], $y)) $year += $t['total'];
    }
    $o .= '<div class="inv-tiles"><div class="card"><small>Ödenmemiş</small><b>' . inv_money($open) . '</b><span>' . $openN . ' fatura</span></div>'
        . '<div class="card"><small>Bu ay kesilen</small><b>' . inv_money($month) . '</b><span>' . e(tr_month(time())) . ' · ' . $monthN . ' fatura</span></div>'
        . '<div class="card"><small>' . $y . ' içinde ödenen</small><b>' . inv_money($year) . '</b><span>vergi dahil</span></div></div>';
    if (!$list) return $o . '<div class="empty card">' . aic('receipt') . '<p>Henüz fatura yok.</p><a class="btn primary" href="index.php?p=invoice&amp;id=new">' . aic('plus') . '<span>İlk faturayı oluşturun</span></a></div></div>';
    $o .= '<div class="seo-bar"><label class="seo-search">' . aic('search') . '<span class="sr">Fatura ara</span><input type="search" id="invSearch" placeholder="Numara, müşteri, adres veya e-posta ara…" autocomplete="off"></label><nav class="chips" id="invChips" aria-label="Filtre">';
    foreach ($chips as $f => $l) {
        $n = count(array_filter($list, fn($x) => $match($f, inv_state($x))));
        if ($f !== '' && $f !== $filter && $n === 0) continue;
        $o .= '<a href="index.php?p=invoices' . ($f !== '' ? '&amp;f=' . $f : '') . '" data-f="' . $f . '"' . ($f === $filter ? ' class="on" aria-current="true"' : '') . '>' . e($l) . ' <b>' . $n . '</b></a>';
    }
    $o .= '</nav></div><div class="list card inv-list" id="invList">';
    $shown = 0;
    foreach ($list as $x) {
        $st = inv_state($x); $t = inv_totals($x); $cu = $x['customer'];
        $q = mb_strtolower(implode(' ', [$x['number'], $cu['name'], $cu['company'], $cu['address'], $cu['email'], $cu['phone'], $x['site']]));
        $vis = $match($filter, $st); $shown += (int)$vis;
        $o .= '<div class="row irow" data-st="' . $st . '" data-q="' . e($q) . '"' . ($vis ? '' : ' hidden') . '>'
            . '<a class="row-main" href="index.php?p=invoice&amp;id=' . e($x['id']) . '"><span class="inv-ic st-' . $st . '">' . aic('receipt') . '</span>'
            . '<span class="row-t"><b>' . e($cu['name'] !== '' ? $cu['name'] : '(müşteri adı yok)') . '</b><small><span>' . e($x['number']) . '</span><span>' . e(inv_date_tr((string)$x['date'])) . '</span>'
            . ($cu['company'] !== '' ? '<span>' . e($cu['company']) . '</span>' : '')
            . (($st === 'sent' || $st === 'overdue') && $x['due'] !== '' ? '<span class="due' . ($st === 'overdue' ? ' late' : '') . '">son ödeme ' . e(inv_date_tr((string)$x['due'])) . '</span>' : '') . '</small></span>'
            . '<span class="inv-amt"><b>' . inv_money($t['total']) . '</b>' . (($st === 'sent' || $st === 'overdue') && $t['paid'] > 0 ? '<small>kalan ' . inv_money($t['due']) . '</small>' : '') . '</span>'
            . '<span class="tag st-' . $st . '">' . e(DM_INV_STATES[$st] ?? $st) . '</span></a>'
            . '<div class="row-act"><a class="btn sm ghost" href="index.php?p=invoice_pdf&amp;id=' . e($x['id']) . '&amp;dl=1" title="PDF indir" aria-label="' . e($x['number']) . ' PDF indir">' . aic('download') . '<span class="hide-s">PDF</span></a></div></div>';
    }
    return $o . '<p class="seo-none muted"' . ($shown ? ' hidden' : '') . '>Bu filtreye veya aramaya uyan fatura yok.</p></div></div>';
}

/* ---------------- one invoice ---------------- */
function invoice_page(string $id): never {
    $db = inv_db();
    if ($id === 'new') out('Yeni fatura', 'invoices', view_invoice(inv_new($db), true, [], $db));
    $i = inv_index($db, $id);
    if ($i === null) redirect(['p' => 'invoices'], 'Bu fatura bulunamadı (silinmiş olabilir).', 'bad');
    out('Fatura ' . $db['list'][$i]['number'], 'invoices', view_invoice($db['list'][$i], false, [], $db));
}
function view_invoice(array $inv, bool $isNew, array $errors, array $db): string {
    $t = inv_totals($inv);
    $acts = '<a class="btn sm ghost" href="index.php?p=invoices">← Faturalar</a>';
    if (!$isNew) $acts .= '<a class="btn sm" href="index.php?p=invoice_pdf&amp;id=' . e($inv['id']) . '" target="_blank" rel="noopener">' . aic('file') . '<span>PDF aç</span></a>'
        . '<a class="btn sm primary" href="index.php?p=invoice_pdf&amp;id=' . e($inv['id']) . '&amp;dl=1">' . aic('download') . '<span>PDF indir</span></a>';
    $when = (string)$inv['updated'] !== '' ? strtotime((string)$inv['updated']) : false;
    $sub = $isNew ? 'Müşteriyi ve kalemleri girip kaydedin, sonra PDF olarak indirin. Kaydetmeden görmek için "PDF önizle"ye basın.'
        : ($when ? 'Son kayıt: ' . tr_date($when) . '. ' : '') . 'PDF her zaman kaydedilmiş hali gösterir; değişiklik yaptıysanız önce kaydedin.';
    $o = head_block($isNew ? 'Yeni fatura' : 'Fatura ' . $inv['number'], $sub, $acts);
    if ($errors) $o .= '<div class="flash bad">' . ic('alert') . '<div>Kaydedilmedi: ' . count($errors) . ' alanda düzeltme gerekiyor (kırmızı ile işaretli).</div></div>';
    $row = fn(string $k, string $label, string $v, string $cls = '') => '<div class="is-r' . ($cls !== '' ? ' ' . $cls : '') . '"><dt data-inv-l="' . $k . '">' . e($label) . '</dt><dd data-inv="' . $k . '">' . e($v) . '</dd></div>';
    $o .= '<form method="post" class="edit inv-form" data-guard data-invoice>' . csrf_field() . '<input type="hidden" name="id" value="' . e($inv['id']) . '">'
        . '<div class="inv-layout"><div class="fields">' . render_fields(invoice_fields(), $inv, [], [], $errors, '') . '</div>'
        . '<aside class="inv-sum"><div class="card"><h2>' . aic('receipt') . ' Özet</h2><dl>'
        . $row('sub', 'Ara toplam', inv_money($t['sub']))
        . $row('disc', 'İndirim', '-' . inv_money($t['disc']), $t['disc'] > 0 ? '' : 'zero')
        . $row('tax', trim($inv['tax_label'] . ' (' . inv_num((float)$inv['tax_rate']) . '%)'), inv_money($t['tax']))
        . $row('total', 'Toplam', inv_money($t['total']), 'big')
        . $row('paid', 'Alınan ödeme', '-' . inv_money($t['paid']), $t['paid'] > 0 ? '' : 'zero')
        . $row('due', 'Kalan (Balance due)', inv_money($t['due']), 'due')
        . '</dl><p class="muted small">Fatura İngilizce ve Kanada doları (CAD) ile hazırlanır. Firma bilgileri ve logo Firma Bilgileri ile Logo & Görseller\'den gelir.</p></div></aside></div>'
        . '<div class="savebar"><span class="dirty-note">' . ic('alert') . 'Kaydedilmemiş değişiklikler var</span><span class="inv-sbt">Toplam <b data-inv="total">' . e(inv_money($t['total'])) . '</b></span><div class="sb-act">'
        . '<button type="submit" class="btn primary" name="a" value="invoice_save" data-busy="Kaydediliyor…">' . ic('check') . '<span>Kaydet</span></button>'
        . '<button type="submit" class="btn ghost" name="a" value="invoice_pdf" formtarget="_blank" formnovalidate>' . aic('eye') . '<span>PDF önizle</span></button></div></div></form>';
    $cust = [];
    foreach (inv_sorted($db['list']) as $x) {
        $n = trim((string)$x['customer']['name']);
        if ($n !== '' && !isset($cust[$n])) $cust[$n] = array_intersect_key($x['customer'], array_flip(['company', 'address', 'email', 'phone'])) + ['site' => (string)$x['site']];
    }
    $o .= '<datalist id="invCust">';
    foreach (array_keys($cust) as $n) $o .= '<option value="' . e($n) . '"></option>';
    $o .= '</datalist><script type="application/json" id="inv-customers">' . json_encode((object)$cust, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
    if (!$isNew) {
        $o .= '<div class="cols2 inv-more"><section class="card"><h2>' . aic('layers') . ' Kopyasını oluştur</h2><p class="muted">Aynı müşteri ve kalemlerle yeni bir fatura (yeni numara, bugünün tarihi). Düzenli işler için pratik.</p>'
            . post_btn(['a' => 'invoice_copy', 'id' => $inv['id']], aic('plus') . '<span>Kopyala</span>', 'btn') . '</section>'
            . '<section class="card danger"><h2>Faturayı sil</h2><p class="muted">Müşteriye gitmiş bir faturayı silmek yerine durumunu "İptal" yapın (numara sırası bozulmaz, faturada VOID yazar). Silinen faturanın kopyası data/backups klasöründe kalır.</p>'
            . post_btn(['a' => 'invoice_delete', 'id' => $inv['id']], aic('trash') . '<span>Sil</span>', 'btn danger', $inv['number'] . ' numaralı fatura silinsin mi?') . '</section></div>';
    }
    return $o;
}
function act_invoice_save(): never {
    $id = (string)($_POST['id'] ?? '');
    $db = inv_db();
    $i = inv_index($db, $id);
    if ($id !== '' && $i === null) redirect(['p' => 'invoices'], 'Bu fatura bulunamadı (silinmiş olabilir). Değişiklikler kaydedilmedi.', 'bad');
    $errors = [];
    $inv = inv_parse($i === null ? inv_new($db) : $db['list'][$i], $errors);
    if (!isset($errors['number']) && ($d = inv_dup($db, $inv['number'], $id))) $errors['number'] = 'Bu numara ' . ($d['customer']['name'] ?: 'başka bir') . ' faturasında kullanılıyor. Başka bir numara yazın.';
    if ($errors) out($i === null ? 'Yeni fatura' : 'Fatura ' . $db['list'][$i]['number'], 'invoices', view_invoice($inv, $i === null, $errors, $db), true, 422);
    $saved = inv_update(function (array &$db) use ($inv, $id) {
        $i = inv_index($db, $id);
        if ($id !== '' && $i === null) throw new RuntimeException('Bu fatura bu arada başka bir pencerede silinmiş; kaydedilmedi.');
        if (inv_dup($db, $inv['number'], $id)) throw new RuntimeException('Bu fatura numarası az önce başka bir faturada kullanıldı. Geri dönüp başka bir numara yazın.');
        $now = date('c');
        $inv['updated'] = $now;
        if ($i === null) { $inv['id'] = bin2hex(random_bytes(6)); $inv['created'] = $now; $db['list'][] = $inv; }
        else { $inv['created'] = $db['list'][$i]['created'] ?: $now; $db['list'][$i] = $inv; }
        inv_bump($db, $inv['number']);
        return $inv;
    });
    $pid = e($saved['id']);
    redirect(['p' => 'invoice', 'id' => $saved['id']], '<b>Fatura ' . e($saved['number']) . ' kaydedildi.</b> <a href="index.php?p=invoice_pdf&amp;id=' . $pid . '&amp;dl=1">PDF indir</a> · <a href="index.php?p=invoice_pdf&amp;id=' . $pid . '" target="_blank" rel="noopener">PDF aç</a>');
}
function act_invoice_pdf(): never {                         // "PDF önizle": the editor's current values, nothing is saved
    $db = inv_db();
    $i = inv_index($db, (string)($_POST['id'] ?? ''));
    $errors = [];
    $inv = inv_parse($i === null ? inv_new($db) : $db['list'][$i], $errors);
    $inv['items'] = array_values(array_filter($inv['items'], fn($it) => trim((string)$it['desc']) !== '' || (float)$it['price'] != 0));
    inv_send_pdf($inv, false, true);
}
function invoice_pdf_get(string $id, bool $download): never {
    $db = inv_db();
    $i = inv_index($db, $id);
    if ($i === null) out('Bulunamadı', 'invoices', '<div class="card"><h1>Fatura bulunamadı</h1><p>Silinmiş olabilir. <a href="index.php?p=invoices">Faturalara dön</a></p></div>', true, 404);
    inv_send_pdf($db['list'][$i], $download);
}
function inv_send_pdf(array $inv, bool $download, bool $preview = false): never {
    $pdf = inv_pdf($inv, $preview);
    $name = 'Invoice-' . (trim((string)preg_replace('/[^A-Za-z0-9_\-]+/', '-', (string)$inv['number']), '-') ?: 'draft') . ($preview ? '-preview' : '') . '.pdf';
    header_remove('Content-Security-Policy');
    header_remove('X-Frame-Options');                       // browsers show PDFs in their own viewer frame
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}
function act_invoice_delete(): never {
    $id = (string)($_POST['id'] ?? '');
    $no = inv_update(function (array &$db) use ($id) {
        $i = inv_index($db, $id);
        if ($i === null) return null;
        $no = (string)$db['list'][$i]['number'];
        array_splice($db['list'], $i, 1);
        return $no;
    });
    if ($no === null) redirect(['p' => 'invoices'], 'Bu fatura bulunamadı.', 'bad');
    redirect(['p' => 'invoices'], '<b>' . e($no) . '</b> silindi.');
}
function act_invoice_copy(): never {
    $id = (string)($_POST['id'] ?? '');
    $copy = inv_update(function (array &$db) use ($id) {
        $i = inv_index($db, $id);
        if ($i === null) return null;
        $new = inv_new($db);
        $now = date('c');
        $copy = array_merge($db['list'][$i], ['id' => bin2hex(random_bytes(6)), 'number' => $new['number'], 'status' => 'draft', 'date' => $new['date'], 'due' => $new['due'], 'paid' => 0, 'created' => $now, 'updated' => $now]);
        $db['list'][] = $copy;
        inv_bump($db, $copy['number']);
        return $copy;
    });
    if ($copy === null) redirect(['p' => 'invoices'], 'Bu fatura bulunamadı.', 'bad');
    redirect(['p' => 'invoice', 'id' => $copy['id']], '<b>Kopya oluşturuldu: ' . e($copy['number']) . '</b> (taslak, bugünün tarihiyle). Gerekirse düzenleyin, durumunu "Kesildi" yapıp kaydedin.');
}

/* ---------------- the PDF ---------------- */
/** The header logo flattened on white as JPEG (PDF images without transparency). */
function inv_logo_jpeg(): ?array {
    $f = (string)cget('branding.header_logo_png');
    if ($f === '' || !is_file(DM_ROOT . '/' . $f) || !function_exists('imagejpeg')) return null;
    $info = @getimagesize(DM_ROOT . '/' . $f);
    $src = match ($info['mime'] ?? '') { 'image/png' => @imagecreatefrompng(DM_ROOT . '/' . $f), 'image/jpeg' => @imagecreatefromjpeg(DM_ROOT . '/' . $f),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp(DM_ROOT . '/' . $f) : false, default => false };
    if (!$src) return null;
    $w = imagesx($src); $h = imagesy($src);
    $s = min(1, 300 / max(1, $h));                          // ~300 px tall: sharp when printed at 50 pt
    $nw = max(1, (int)round($w * $s)); $nh = max(1, (int)round($h * $s));
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagealphablending($out, true);
    imagecopyresampled($out, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start(); imagejpeg($out, null, 92); $data = (string)ob_get_clean();
    return $data !== '' ? [$data, $nw, $nh] : null;
}
function inv_pdf(array $inv, bool $preview = false): string {
    $inv = inv_norm($inv);
    $c = C(); $ct = $c['contact']; $cfg = inv_cfg(); $T = inv_totals($inv); $cu = $inv['customer']; $st = (string)$inv['status'];
    $INK = [24, 30, 38]; $MUT = [96, 106, 118]; $LINE = [222, 228, 235]; $BRAND = [37, 86, 145]; $SOFT = [242, 246, 250]; $WHITE = [255, 255, 255];
    $L = 50.0; $R = 562.0; $BOTTOM = 728.0;
    $web = (string)preg_replace('#^https?://(www\.)?#', '', site_url());
    $pdf = new DmPdf('Invoice ' . $inv['number'] . ($cu['name'] !== '' ? ' - ' . $cu['name'] : ''));
    $pdf->addPage();
    // header: logo + business details, title + invoice facts
    $logo = inv_logo_jpeg();
    if ($logo) {
        $lh = 50.0; $lw = $lh * $logo[1] / $logo[2];
        if ($lw > 190) { $lw = 190.0; $lh = $lw * $logo[2] / $logo[1]; }
        $pdf->addJpeg('Im1', $logo[0], $logo[1], $logo[2]);
        $pdf->image('Im1', $L, 42, $lw, $lh);
    } else $pdf->text($L, 72, brand(), 18, true, $BRAND);
    $pdf->text($R, 76, 'INVOICE', 27, true, $BRAND, 'R', 1.5);
    $y = 122.0;
    $pdf->text($L, $y, (string)$c['site']['legal_name'], 10.5, true, $INK); $y += 14.5;
    $biz = [(string)$ct['street'], trim($ct['city'] . ', ' . $ct['region'] . ' ' . $ct['postal'], ' ,'), phone(), (string)$ct['email'], $web];
    if (trim((string)$cfg['tax_number']) !== '') $biz[] = trim($cfg['tax_label'] . ' # ' . trim((string)$cfg['tax_number']));
    foreach ($biz as $line) if (trim($line) !== '') { $pdf->text($L, $y, $line, 9, false, $MUT); $y += 12.8; }
    $my = 110.0;
    foreach ([['Invoice #', (string)$inv['number']], ['Invoice date', inv_date_en((string)$inv['date'])], ['Due date', $inv['due'] !== '' ? inv_date_en((string)$inv['due']) : 'Due on receipt']] as [$k, $v]) {
        $pdf->text(372, $my, $k, 9, false, $MUT); $pdf->text($R, $my, $v, 9.5, true, $INK, 'R'); $my += 16;
    }
    $top = max($y, $my) + 12;
    $pdf->line($L, $top, $R, $top, $LINE, 0.8);
    // bill to, service address, balance box
    $y0 = $top + 26;
    $pdf->text($L, $y0, 'BILL TO', 7.5, true, $MUT, 'L', 0.8);
    $yy = $y0 + 16;
    foreach ($pdf->wrap($cu['name'] !== '' ? (string)$cu['name'] : '-', 170, 11, true) as $ln) { $pdf->text($L, $yy, $ln, 11, true, $INK); $yy += 14; }
    $lines = [];
    if (trim((string)$cu['company']) !== '') $lines[] = (string)$cu['company'];
    foreach (preg_split('/\R/u', trim((string)$cu['address'])) ?: [] as $a) if (trim($a) !== '') $lines[] = trim($a);
    foreach (['email', 'phone'] as $k) if (trim((string)$cu[$k]) !== '') $lines[] = trim((string)$cu[$k]);
    foreach ($lines as $ln) foreach ($pdf->wrap($ln, 170, 9) as $w) { $pdf->text($L, $yy, $w, 9, false, $MUT); $yy += 12.8; }
    $ys = $y0;
    if (trim((string)$inv['site']) !== '') {
        $pdf->text(232, $y0, 'SERVICE ADDRESS', 7.5, true, $MUT, 'L', 0.8);
        $ys = $y0 + 16;
        foreach ($pdf->wrap(trim((string)$inv['site']), 140, 9) as $w) if ($w !== '') { $pdf->text(232, $ys, $w, 9, false, $INK); $ys += 12.8; }
    }
    [$boxL, $boxV] = match ($st) { 'paid' => ['AMOUNT PAID', inv_money($T['paid']) . ' CAD'], 'void' => ['STATUS', 'VOID'], default => ['BALANCE DUE', inv_money($T['due']) . ' CAD'] };
    $pdf->rect(392, $y0 - 13, $R - 392, 62, $SOFT);
    $pdf->rect(392, $y0 - 13, 3, 62, $BRAND);
    $pdf->text(408, $y0 + 2, $boxL, 7.5, true, $MUT, 'L', 0.8);
    $bs = 18.0;
    while ($bs > 11 && $pdf->width($boxV, $bs, true) > $R - 408 - 8) $bs -= 1;
    $pdf->text(408, $y0 + 27, $boxV, $bs, true, $INK);
    if (($st === 'sent' || $st === 'draft') && $inv['due'] !== '') $pdf->text(408, $y0 + 41, 'Due ' . inv_date_en((string)$inv['due']), 8.5, false, $MUT);
    $y = max($yy, $ys, $y0 + 49) + 24;
    // items
    $qx = 392.0; $rx = 474.0; $ax = $R - 10;
    $head = function (float $y) use ($pdf, $L, $R, $BRAND, $WHITE, $qx, $rx, $ax) {
        $pdf->rect($L, $y, $R - $L, 24, $BRAND);
        $pdf->text($L + 10, $y + 15.5, 'DESCRIPTION', 7.5, true, $WHITE, 'L', 0.8);
        $pdf->text($qx, $y + 15.5, 'QTY', 7.5, true, $WHITE, 'R', 0.8);
        $pdf->text($rx, $y + 15.5, 'RATE', 7.5, true, $WHITE, 'R', 0.8);
        $pdf->text($ax, $y + 15.5, 'AMOUNT', 7.5, true, $WHITE, 'R', 0.8);
        return $y + 24;
    };
    $y = $head($y);
    foreach (array_values($inv['items']) as $n => $it) {
        $dl = $pdf->wrap((string)$it['desc'], 280, 9.5) ?: [''];
        $rh = 12 + count($dl) * 13;
        if ($y + $rh > $BOTTOM) { $pdf->addPage(); $y = $head(56); }
        if ($n % 2 === 1) $pdf->rect($L, $y, $R - $L, $rh, [249, 251, 253]);
        $ty = $y + 17;
        foreach ($dl as $k => $ln) $pdf->text($L + 10, $ty + $k * 13, $ln, 9.5, false, $INK);
        $pdf->text($qx, $ty, inv_num((float)$it['qty']), 9.5, false, $INK, 'R');
        $pdf->text($rx, $ty, inv_money((float)$it['price']), 9.5, false, $INK, 'R');
        $pdf->text($ax, $ty, inv_money(round((float)$it['qty'] * (float)$it['price'], 2)), 9.5, true, $INK, 'R');
        $y += $rh;
        $pdf->line($L, $y, $R, $y, $LINE, 0.6);
    }
    // totals (right), notes and payment details (left)
    $rows = [['Subtotal', inv_money($T['sub']), '']];
    if ($T['disc'] > 0) $rows[] = ['Discount', '-' . inv_money($T['disc']), ''];
    if ((float)$inv['tax_rate'] > 0 || $T['tax'] != 0) $rows[] = [trim($inv['tax_label'] . ' (' . inv_num((float)$inv['tax_rate']) . '%)'), inv_money($T['tax']), ''];
    $rows[] = ['Total', inv_money($T['total']), 'total'];
    if ($T['paid'] > 0) $rows[] = ['Amount paid', '-' . inv_money($T['paid']), ''];
    $rows[] = ['Balance due (CAD)', inv_money($T['due']), 'due'];
    $notes = trim((string)$inv['notes']); $terms = trim((string)$inv['terms']);
    $nl = $notes !== '' ? $pdf->wrap($notes, 280, 9) : [];
    $tl = $terms !== '' ? $pdf->wrap($terms, 280, 9) : [];
    $need = max(count($rows) * 21 + 24, ($nl ? 34 + count($nl) * 12.8 : 0) + ($tl ? 34 + count($tl) * 12.8 : 0));
    $y += 16;
    if ($y + $need > $BOTTOM) { $pdf->addPage(); $y = 60; }
    $tx = 364.0; $ty = $y;
    foreach ($rows as [$k, $v, $kind]) {
        if ($kind === 'total') { $pdf->line($tx, $ty + 2, $R, $ty + 2, $LINE, 0.8); $ty += 4; }
        if ($kind === 'due') { $ty += 4; $pdf->rect($tx - 10, $ty, $R - $tx + 10, 28, $BRAND); }
        $pdf->text($tx, $ty + ($kind === 'due' ? 18 : 14), $k, $kind === 'due' ? 10 : 9.5, $kind !== '', $kind === 'due' ? $WHITE : ($kind === 'total' ? $INK : $MUT));
        $pdf->text($R - 10, $ty + ($kind === 'due' ? 18.5 : 14), $v, $kind === 'due' ? 12 : 9.5, $kind !== '', $kind === 'due' ? $WHITE : $INK, 'R');
        $ty += $kind === 'due' ? 32 : 20;
    }
    $ny = $y;
    foreach ([['NOTES', $nl], ['PAYMENT', $tl]] as [$label, $ls]) {
        if (!$ls) continue;
        $pdf->text($L, $ny + 12, $label, 7.5, true, $MUT, 'L', 0.8); $ny += 28;
        foreach ($ls as $ln) { $pdf->text($L, $ny, $ln, 9, false, $INK); $ny += 12.8; }
        $ny += 8;
    }
    // stamp and footer on every page
    if ($st === 'paid' || $st === 'void') { $pdf->setPage(0); $pdf->stamp(300, $top - 2, $st === 'paid' ? 'PAID' : 'VOID', 24, $st === 'paid' ? [22, 128, 61] : [198, 40, 40], -10); }
    $pages = $pdf->pageCount();
    for ($p = 0; $p < $pages; $p++) {
        $pdf->setPage($p);
        if ($preview) $pdf->text(306, 30, 'PREVIEW - NOT SAVED', 7.5, true, [198, 40, 40], 'C', 0.8);
        $pdf->line($L, 752, $R, 752, $LINE, 0.6);
        $pdf->text($L, 766, (string)$c['site']['legal_name'] . '  ·  ' . phone() . '  ·  ' . $web, 8, false, $MUT);
        $pdf->text($R, 766, 'Invoice ' . $inv['number'] . ($pages > 1 ? '  ·  Page ' . ($p + 1) . ' of ' . $pages : ''), 8, false, $MUT, 'R');
    }
    return $pdf->output();
}
