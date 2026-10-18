<?php
/**
 * What the admin panel can edit: every section is a list of fields that point into the content file.
 * Field keys: k = path (dots), t = type, l = label, h = help, req = required, max = max length, v = validator,
 * opts = select options, kind = photo upload type, fields = sub-fields of a repeater, title = sub-field shown on each item.
 * Placeholders such as {phone} or {service} are filled in automatically on the website.
 */
declare(strict_types=1);
if (!defined('DM_APP')) { http_response_code(404); exit; }

function F(string $k, string $t, string $l, array $more = []): array { return ['k' => $k, 't' => $t, 'l' => $l] + $more; }
function HD(string $l, string $h = ''): array { return ['t' => 'heading', 'l' => $l, 'h' => $h]; }

function icon_options(): array {
    $o = [];
    foreach (array_keys(icons()) as $k) $o[$k] = $k;
    ksort($o);
    return $o;
}
const PH_COMMON = '{phone} = telefon, {city} = şehir, {brand} = firma adı';

function schema_sections(): array {
    $img = fn(string $k, string $l, string $kind = 'photo', array $more = []) => F($k, 'image', $l, ['kind' => $kind] + $more);
    $iconSub = fn() => F('icon', 'icon', 'Simge');
    return [
    /* ------------------------------------------------------------------ */
    'business' => ['title' => 'Firma Bilgileri', 'icon' => 'building', 'intro' => 'Telefon, adres ve çalışma saatleri sitenin her yerinde (üst bar, menü, footer, iletişim sayfası, Google bilgileri) otomatik güncellenir.', 'fields' => [
        HD('İletişim'),
        F('contact.phone', 'text', 'Telefon', ['req' => true, 'v' => 'phone', 'h' => 'Örnek: +1 (647) 919-3677. Arama butonları bu numarayı kullanır.']),
        F('contact.email', 'text', 'E-posta', ['req' => true, 'v' => 'email', 'h' => 'Sitede gizli şekilde gösterilir; botlar toplayamaz.']),
        F('site.form_to', 'text', 'Form talepleri bu adrese gelsin', ['req' => true, 'v' => 'email', 'h' => 'Bilgi: gönderim ayarı sunucudaki form-config.php dosyasındadır; burası kayıt içindir.']),
        HD('Adres'),
        F('contact.street', 'text', 'Sokak ve numara', ['req' => true]),
        F('contact.city', 'text', 'Şehir', ['req' => true]),
        F('contact.region', 'text', 'Eyalet (kısa)', ['req' => true, 'h' => 'Örnek: ON']),
        F('contact.region_long', 'text', 'Eyalet (uzun)', ['req' => true, 'h' => 'Örnek: Ontario']),
        F('contact.postal', 'text', 'Posta kodu', ['req' => true]),
        F('contact.address_short', 'text', 'Kısa adres (mobil menüde)', ['req' => true]),
        F('contact.lat', 'number', 'Enlem', ['h' => 'Google haritadaki konum (Google için)']),
        F('contact.lng', 'number', 'Boylam'),
        HD('Çalışma saatleri'),
        F('contact.hours_short', 'text', 'Kısa (üst bar ve iletişim)', ['req' => true]),
        F('contact.hours_line1', 'text', 'Footer 1. satır', ['req' => true]),
        F('contact.hours_line2', 'text', 'Footer 2. satır'),
        F('contact.opens', 'text', 'Açılış saati (Google için)', ['v' => 'time', 'h' => '24 saat: 08:00']),
        F('contact.closes', 'text', 'Kapanış saati (Google için)', ['v' => 'time']),
        F('contact.open_days', 'lines', 'Açık günler (Google için, İngilizce, her satıra bir gün)', ['rows' => 5]),
        HD('Firma adı'),
        F('site.legal_name', 'text', 'Resmi unvan', ['req' => true]),
        F('site.brand', 'text', 'Marka adı', ['req' => true]),
        F('site.short_name', 'text', 'Kısa ad', ['req' => true]),
        F('site.slogan', 'text', 'Slogan'),
        F('footer.copyright', 'text', 'Telif satırı (yıl otomatik eklenir)'),
        F('footer.bottom_right', 'text', 'Footer en alt sağ yazı'),
        HD('Google bilgileri (gelişmiş)', 'Google\'a gönderilen işletme bilgileri. Emin değilseniz değiştirmeyin.'),
        F('site.url', 'text', 'Site adresi', ['req' => true, 'v' => 'url', 'h' => 'Sadece alan adı değişirse değiştirin (https://demirmechanical.com).']),
        F('site.schema_type', 'select', 'İşletme türü (Google)', ['opts' => ['Plumber' => 'Plumber (tesisatçı)', 'HVACBusiness' => 'HVAC Business (ısıtma/soğutma)', 'GeneralContractor' => 'General Contractor (genel müteahhit)', 'HomeAndConstructionBusiness' => 'Home & Construction Business']]),
        F('site.price_range', 'text', 'Fiyat seviyesi', ['h' => '$, $$ veya $$$']),
        F('site.catalog_name', 'text', 'Hizmet kataloğu adı'),
        F('site.geo_placename', 'text', 'Şehir (harita etiketi)'),
        F('contact.country', 'text', 'Ülke kodu', ['h' => 'Kanada için CA', 'max' => 2]),
    ]],
    /* ------------------------------------------------------------------ */
    'branding' => ['title' => 'Logo & Görseller', 'icon' => 'cam', 'intro' => 'Logoyu değiştirirseniz header, mobil menü ve footer otomatik güncellenir. Yeni dosya yükleyince eskisi silinmez.', 'fields' => [
        HD('Header logosu', 'Şeffaf arka planlı PNG önerilir (yatay, en az 360×170).'),
        F('branding.header_logo', 'logo', 'Header logosu', ['kind' => 'logo']),
        F('branding.header_logo_alt', 'text', 'Logo alternatif metni (SEO)', ['req' => true]),
        HD('Footer logosu', 'Koyu arka plan üzerinde durur; açık renkli logo kullanın.'),
        F('branding.footer_logo_img', 'flogo', 'Footer logosu', ['kind' => 'flogo']),
        F('branding.footer_logo_alt', 'text', 'Footer logo alternatif metni', ['req' => true]),
        HD('Site ikonu (favicon)', 'Tarayıcı sekmesinde ve telefon ana ekranında görünür. Kare görsel yükleyin (en az 512×512).'),
        F('branding.favicon', 'favicon', 'Site ikonu', ['kind' => 'favicon']),
        HD('Sayfa başlığı arka planı', 'İç sayfaların üstündeki koyu bandın arkasında görünür (1920×640). Boş bırakılabilir.'),
        $img('page_header_image', 'Arka plan fotoğrafı', 'hero', ['noalt' => true]),
    ]],
    /* ------------------------------------------------------------------ */
    'menu' => ['title' => 'Menü & Footer', 'icon' => 'arrow', 'intro' => 'Menü öğelerinin sırasını, adını ve görünürlüğünü değiştirebilir, yeni bağlantı ekleyebilirsiniz. Hizmetler ve Bölgeler açılır listeleri otomatik dolar.', 'fields' => [
        HD('Üst bar'),
        F('header.util_left', 'text', 'Üst bar sol yazı', ['req' => true]),
        HD('Ana menü'),
        F('header.menu', 'menu', 'Menü öğeleri'),
        F('header.services_all', 'text', 'Hizmetler listesinin altındaki bağlantı', ['req' => true]),
        F('header.areas_all', 'text', 'Bölgeler listesinin altındaki bağlantı', ['req' => true]),
        F('header.cta_label', 'text', 'Menüdeki buton yazısı', ['req' => true]),
        F('header.cta_link', 'text', 'Menüdeki buton bağlantısı', ['req' => true, 'v' => 'link']),
        F('header.emergency_label', 'text', 'Telefonun üstündeki küçük yazı', ['req' => true]),
        F('header.drawer_email_label', 'text', 'Mobil menü: e-posta etiketi'),
        F('header.drawer_address_label', 'text', 'Mobil menü: adres etiketi'),
        HD('Footer'),
        F('footer.about', 'textarea', 'Footer tanıtım yazısı', ['rows' => 3]),
        F('footer.emergency_label', 'text', 'Footer acil hat etiketi'),
        F('footer.services_title', 'text', 'Hizmetler başlığı'),
        F('footer.areas_title', 'text', 'Bölgeler başlığı'),
        F('footer.areas_count', 'number', 'Footer\'da gösterilecek bölge sayısı', ['int' => true, 'min' => 0, 'max' => 40]),
        F('footer.areas_all', 'text', 'Tüm bölgeler bağlantısı'),
        F('footer.contact_title', 'text', 'İletişim başlığı'),
        F('footer.nav', 'repeater', 'Footer alt bağlantıları', ['title' => 'label', 'add' => 'Bağlantı ekle', 'new' => ['label' => '', 'link' => ''], 'fields' => [
            F('label', 'text', 'Yazı', ['req' => true]), F('link', 'text', 'Bağlantı', ['req' => true, 'v' => 'link', 'h' => 'Örnek: faq.html veya https://...'])]]),
        HD('Telefon alt çubuğu'),
        F('footer.mbar_call', 'text', 'Ara butonu'),
        F('footer.mbar_quote', 'text', 'Teklif butonu'),
    ]],
    /* ------------------------------------------------------------------ */
    'links' => ['title' => 'WhatsApp & Bağlantılar', 'icon' => 'chat', 'intro' => 'WhatsApp numarası, sosyal medya, Google Analytics ve Google Search Console doğrulaması.', 'fields' => [
        HD('WhatsApp'),
        F('links.whatsapp', 'text', 'WhatsApp numarası', ['v' => 'wa', 'h' => 'Ülke koduyla, sadece rakam: 16479193677. Boş bırakırsanız WhatsApp butonları kaldırılır.']),
        F('whatsapp.general', 'text', 'Hazır mesaj (genel sayfalar)'),
        F('whatsapp.service', 'text', 'Hazır mesaj (hizmet sayfaları)', ['h' => '{service} = hizmet adı']),
        F('whatsapp.city', 'text', 'Hazır mesaj (bölge sayfaları)', ['h' => '{city} = şehir']),
        F('whatsapp.button', 'text', 'Buton yazısı'),
        F('whatsapp.footer_text', 'text', 'Footer satırı'),
        F('whatsapp.menu_label', 'text', 'Mobil menü: küçük etiket'), F('whatsapp.menu_text', 'text', 'Mobil menü satırı'),
        F('whatsapp.contact_label', 'text', 'İletişim sayfası: etiket'), F('whatsapp.contact_text', 'text', 'İletişim sayfası satırı'),
        HD('Sosyal medya', 'Linki girilen ikon footer\'da görünür ve Google bilgilerine eklenir.'),
        F('links.facebook', 'text', 'Facebook sayfası', ['v' => 'url']),
        F('links.instagram', 'text', 'Instagram profili', ['v' => 'url']),
        F('links.google', 'text', 'Google İşletme Profili (Maps linki)', ['v' => 'url']),
        HD('Google'),
        F('links.ga4', 'text', 'Google Analytics 4 ölçüm kimliği', ['v' => 'ga4', 'h' => 'G-XXXXXXXXXX biçiminde. analytics.google.com → Yönetici → Veri akışları. Girilince ziyaretler, telefon tıklamaları, WhatsApp ve form gönderimleri ölçülür.']),
        F('links.google_site_verification', 'text', 'Google Search Console doğrulama kodu', ['v' => 'gsv', 'h' => 'Search Console → HTML etiketi yöntemi: content="…" içindeki kod.']),
    ]],
    /* ------------------------------------------------------------------ */
    'home' => ['title' => 'Ana Sayfa', 'icon' => 'home', 'intro' => 'Ana sayfadaki tüm yazılar ve fotoğraflar. Hizmet kartları, bölgeler ve projeler kendi bölümlerinden otomatik gelir.', 'fields' => [
        HD('SEO'),
        F('pages.home.seo_title', 'text', 'SEO başlığı', ['req' => true, 'seo' => 'title']),
        F('pages.home.seo_description', 'textarea', 'Meta açıklama', ['req' => true, 'rows' => 3, 'seo' => 'desc']),
        HD('Giriş (hero)'),
        $img('pages.home.hero_image', 'Arka plan fotoğrafı', 'hero', ['h' => '1920×1080 yatay; konu sağ yarıda olmalı.']),
        F('pages.home.badge', 'text', 'Rozet yazısı'), F('pages.home.badge_more', 'text', 'Rozet devamı (çok küçük ekranlarda gizlenir)'),
        F('pages.home.title_before', 'text', 'Başlık (baş)'), F('pages.home.title_highlight', 'text', 'Başlık (vurgulu kısım)'), F('pages.home.title_after', 'text', 'Başlık (son)'),
        F('pages.home.lead', 'textarea', 'Açıklama (bilgisayar)', ['rows' => 3]),
        F('pages.home.lead_mobile', 'textarea', 'Açıklama (telefon)', ['rows' => 2]),
        F('pages.home.tiles_title', 'text', 'Hızlı seçim başlığı'),
        F('pages.home.tiles', 'repeater', 'Hızlı seçim kutuları', ['title' => 'label', 'max_items' => 4, 'min' => 2, 'new' => ['icon' => 'check', 'label' => '', 'link' => 'services.html'], 'fields' => [$iconSub(), F('label', 'text', 'Yazı', ['req' => true]), F('link', 'text', 'Bağlantı', ['req' => true, 'v' => 'link'])]]),
        F('pages.home.tiles_all', 'text', 'Tüm hizmetler bağlantısı', ['h' => '{count} = hizmet sayısı']),
        F('pages.home.proof', 'repeater', 'Alt güven maddeleri', ['title' => 'title', 'max_items' => 3, 'min' => 3, 'fields' => [$iconSub(), F('title', 'text', 'Kalın yazı'), F('text', 'text', 'Alt yazı')]]),
        HD('Hızlı talep formu'),
        F('pages.home.quote_title', 'text', 'Başlık'), F('pages.home.quote_text', 'text', 'Alt yazı'), F('pages.home.quote_button', 'text', 'Buton'),
        F('pages.home.quote_note', 'text', 'Alt not', ['h' => '{phone} = telefon']),
        F('pages.home.quote_sent_title', 'text', 'Gönderildi başlığı'), F('pages.home.quote_sent_text', 'text', 'Gönderildi mesajı'),
        HD('Hizmetler bölümü'),
        F('pages.home.services_eyebrow', 'text', 'Üst etiket'), F('pages.home.services_title', 'text', 'Başlık'), F('pages.home.services_text', 'textarea', 'Açıklama', ['rows' => 2]),
        HD('Biz kimiz bölümü'),
        $img('pages.home.about_image', 'Fotoğraf'),
        F('pages.home.about_image_tag', 'text', 'Fotoğraf etiketi'),
        F('pages.home.about_stat_title', 'text', 'Kutucuk başlığı'), F('pages.home.about_stat_text', 'text', 'Kutucuk yazısı'),
        F('pages.home.about_eyebrow', 'text', 'Üst etiket'), F('pages.home.about_title', 'text', 'Başlık'), F('pages.home.about_text', 'textarea', 'Yazı', ['rows' => 4]),
        F('pages.home.values', 'repeater', 'Değerler', ['title' => 'title', 'max_items' => 6, 'new' => ['icon' => 'check', 'title' => '', 'text' => ''], 'fields' => [$iconSub(), F('title', 'text', 'Başlık', ['req' => true]), F('text', 'text', 'Yazı')]]),
        F('pages.home.about_button', 'text', 'Buton'),
        HD('Nasıl çalışıyoruz'),
        F('pages.home.process_text', 'text', 'Açıklama', ['h' => 'Adımlar "Ortak Bölümler"de düzenlenir.']),
        HD('Kime hizmet veriyoruz'),
        F('pages.home.who_eyebrow', 'text', 'Üst etiket'), F('pages.home.who_title', 'text', 'Başlık'), F('pages.home.who_text', 'text', 'Açıklama'),
        F('pages.home.who', 'repeater', 'Kartlar', ['title' => 'title', 'max_items' => 3, 'min' => 3, 'fields' => [$iconSub(), F('title', 'text', 'Başlık', ['req' => true]), F('text', 'textarea', 'Yazı', ['rows' => 2]), F('points', 'lines', 'Maddeler (her satıra bir)', ['rows' => 3])]]),
        HD('Bölgeler bandı'),
        F('pages.home.areas_eyebrow', 'text', 'Üst etiket'), F('pages.home.areas_title', 'text', 'Başlık'), F('pages.home.areas_text', 'textarea', 'Yazı', ['rows' => 3]), F('pages.home.areas_button', 'text', 'Buton'),
        HD('İş fotoğrafları bandı'),
        F('pages.home.work_text', 'text', 'Açıklama'),
        F('pages.home.work', 'repeater', 'Fotoğraflar', ['title' => 'tag', 'max_items' => 3, 'min' => 3, 'fields' => [F('image', 'image', 'Fotoğraf', ['kind' => 'photo']), F('tag', 'text', 'Etiket'), F('label', 'text', 'Fotoğraf yokken yazı')]]),
        HD('Yorumlar bölümü', 'Yorumlar "Yorumlar" bölümünden eklenir. Yorum yoksa bölüm görünmez.'),
        F('pages.home.reviews_eyebrow', 'text', 'Üst etiket'), F('pages.home.reviews_title', 'text', 'Başlık'), F('pages.home.reviews_text', 'text', 'Açıklama'),
        HD('SSS bölümü'),
        F('pages.home.faq_eyebrow', 'text', 'Üst etiket'), F('pages.home.faq_title', 'text', 'Başlık'), F('pages.home.faq_text', 'text', 'Açıklama'), F('pages.home.faq_button', 'text', 'Buton'),
        HD('Tedarikçiler bölümü'),
        F('pages.home.suppliers_eyebrow', 'text', 'Üst etiket'), F('pages.home.suppliers_title', 'text', 'Başlık'),
    ]],
    /* ------------------------------------------------------------------ */
    'founder' => ['title' => 'Kurucu (Barış Demir)', 'icon' => 'users', 'intro' => 'Ana sayfa ve Hakkımızda sayfasındaki kurucu bölümü.', 'fields' => [
        F('founder.photo', 'image', 'Portre fotoğrafı', ['kind' => 'portrait', 'h' => 'Dikey 4:5 (örnek 1000×1250). Yoksa baş harfler gösterilir.']),
        F('founder.initials', 'text', 'Fotoğraf yokken baş harfler', ['max' => 3]),
        F('founder.name', 'text', 'Ad Soyad', ['req' => true]), F('founder.role', 'text', 'Ünvan satırı'), F('founder.eyebrow', 'text', 'Üst etiket'),
        F('founder.quote', 'textarea', 'Alıntı', ['rows' => 2]), F('founder.bio', 'textarea', 'Kısa biyografi', ['rows' => 4]),
        F('founder.points', 'lines', 'Maddeler (her satıra bir)', ['rows' => 3]),
        F('founder.badge_title', 'text', 'Rozet başlığı'), F('founder.badge_text', 'text', 'Rozet yazısı'),
        F('founder.button_home', 'text', 'Ana sayfadaki buton'), F('founder.button_about', 'text', 'Hakkımızda sayfasındaki buton'),
    ]],
    /* ------------------------------------------------------------------ */
    'about' => ['title' => 'Hakkımızda Sayfası', 'icon' => 'shield', 'fields' => [
        HD('SEO'),
        F('pages.about.seo_title', 'text', 'SEO başlığı', ['req' => true, 'seo' => 'title']), F('pages.about.seo_description', 'textarea', 'Meta açıklama', ['req' => true, 'rows' => 3, 'seo' => 'desc']),
        HD('Sayfa başlığı'), F('pages.about.title', 'text', 'Başlık (H1)', ['req' => true]), F('pages.about.lead', 'textarea', 'Alt yazı', ['rows' => 2]),
        HD('Hikayemiz'), F('pages.about.story_eyebrow', 'text', 'Üst etiket'), F('pages.about.story_title', 'text', 'Başlık'),
        F('pages.about.story', 'lines', 'Paragraflar (her satır bir paragraf)', ['rows' => 6]),
        F('pages.about.button_1', 'text', 'Buton 1'), F('pages.about.button_2', 'text', 'Buton 2'),
        $img('pages.about.image', 'Fotoğraf'), F('pages.about.image_tag', 'text', 'Fotoğraf etiketi'),
        F('pages.about.stat_title', 'text', 'Kutucuk başlığı'), F('pages.about.stat_text', 'text', 'Kutucuk yazısı'),
        HD('Sözümüz'), F('pages.about.promise_eyebrow', 'text', 'Üst etiket'), F('pages.about.promise_title', 'text', 'Başlık'), F('pages.about.promise_text', 'text', 'Açıklama'),
        F('pages.about.promise', 'repeater', 'Kartlar', ['title' => 'title', 'max_items' => 6, 'new' => ['icon' => 'check', 'title' => '', 'text' => ''], 'fields' => [$iconSub(), F('title', 'text', 'Başlık', ['req' => true]), F('text', 'textarea', 'Yazı', ['rows' => 2])]]),
        HD('Nasıl çalışıyoruz'), F('pages.about.process_eyebrow', 'text', 'Üst etiket'), F('pages.about.process_title', 'text', 'Başlık'),
    ]],
    /* ------------------------------------------------------------------ */
    'contactpage' => ['title' => 'İletişim Sayfası', 'icon' => 'mail', 'fields' => [
        HD('SEO'), F('pages.contact.seo_title', 'text', 'SEO başlığı', ['req' => true, 'seo' => 'title']), F('pages.contact.seo_description', 'textarea', 'Meta açıklama', ['req' => true, 'rows' => 3, 'seo' => 'desc']),
        HD('Sayfa'), F('pages.contact.title', 'text', 'Başlık (H1)', ['req' => true]), F('pages.contact.lead', 'textarea', 'Alt yazı', ['rows' => 2]),
        F('pages.contact.eyebrow', 'text', 'Üst etiket'), F('pages.contact.info_title', 'text', 'Bilgiler başlığı'),
        F('pages.contact.phone_label', 'text', 'Telefon etiketi'), F('pages.contact.email_label', 'text', 'E-posta etiketi'), F('pages.contact.address_label', 'text', 'Adres etiketi'), F('pages.contact.hours_label', 'text', 'Saat etiketi'),
        HD('Form'), F('pages.contact.form_title', 'text', 'Form başlığı'), F('pages.contact.form_text', 'text', 'Form alt yazısı'), F('pages.contact.form_button', 'text', 'Buton'),
        F('pages.contact.sent_title', 'text', 'Gönderildi başlığı'), F('pages.contact.sent_text', 'text', 'Gönderildi mesajı'),
        HD('Harita'), F('pages.contact.map_title', 'text', 'Harita şeridi başlığı'), F('pages.contact.map_text', 'text', 'Harita şeridi yazısı'),
        HD('SSS'), F('pages.contact.faq_eyebrow', 'text', 'Üst etiket'), F('pages.contact.faq_title', 'text', 'Başlık'),
    ]],
    /* ------------------------------------------------------------------ */
    'faq' => ['title' => 'Sık Sorulan Sorular', 'icon' => 'help', 'intro' => 'Ana sayfa ve iletişim sayfasındaki SSS ile SSS sayfasının tamamı. Sorular Google sonuçlarında da kullanılır.', 'fields' => [
        HD('Ana sayfa ve iletişim sayfası SSS'),
        F('faq.home', 'repeater', 'Sorular', ['title' => 'q', 'add' => 'Soru ekle', 'min' => 1, 'new' => ['q' => '', 'a' => ''], 'fields' => [F('q', 'text', 'Soru', ['req' => true]), F('a', 'textarea', 'Cevap', ['req' => true, 'rows' => 3])]]),
        HD('SSS sayfası'),
        F('pages.faq.seo_title', 'text', 'SEO başlığı', ['req' => true, 'seo' => 'title']), F('pages.faq.seo_description', 'textarea', 'Meta açıklama', ['req' => true, 'rows' => 3, 'seo' => 'desc']),
        F('pages.faq.title', 'text', 'Başlık (H1)', ['req' => true]), F('pages.faq.lead', 'textarea', 'Alt yazı', ['rows' => 2]),
        F('faq.categories', 'repeater', 'Kategoriler', ['title' => 'name', 'add' => 'Kategori ekle', 'min' => 1, 'new' => ['key' => '', 'name' => '', 'items' => [['q' => '', 'a' => '']]], 'fields' => [
            F('name', 'text', 'Kategori adı', ['req' => true]),
            F('key', 'text', 'Bağlantı adı', ['v' => 'slug', 'h' => 'Sayfa içi bağlantı (#genel). Boş bırakırsanız addan üretilir.']),
            F('items', 'repeater', 'Sorular', ['title' => 'q', 'add' => 'Soru ekle', 'min' => 1, 'new' => ['q' => '', 'a' => ''], 'fields' => [F('q', 'text', 'Soru', ['req' => true]), F('a', 'textarea', 'Cevap', ['req' => true, 'rows' => 3])]])]]),
        F('pages.faq.side_title', 'text', 'Yan kart başlığı'), F('pages.faq.side_text', 'text', 'Yan kart yazısı'),
        F('pages.faq.popular_title', 'text', 'Popüler hizmetler başlığı'), F('pages.faq.popular_count', 'number', 'Popüler hizmet sayısı', ['int' => true, 'min' => 0, 'max' => 20]),
    ]],
    /* ------------------------------------------------------------------ */
    'reviews' => ['title' => 'Yorumlar', 'icon' => 'users', 'intro' => 'Sadece gerçek müşteri yorumları ekleyin (Google yorumlarınızdan kopyalayabilirsiniz). Hiç yorum yoksa bölüm sitede görünmez.', 'fields' => [
        F('reviews', 'repeater', 'Yorumlar', ['title' => 'name', 'add' => 'Yorum ekle', 'new' => ['quote' => '', 'name' => '', 'where' => ''], 'fields' => [
            F('quote', 'textarea', 'Yorum', ['req' => true, 'rows' => 3]), F('name', 'text', 'Müşteri adı', ['req' => true, 'h' => 'Örnek: Sarah M.']), F('where', 'text', 'Şehir · Hizmet', ['h' => 'Örnek: North York · Drain Cleaning'])]]),
        F('pages.home.reviews_title', 'text', 'Bölüm başlığı'),
    ]],
    /* ------------------------------------------------------------------ */
    'suppliers' => ['title' => 'Tedarikçiler', 'icon' => 'store', 'intro' => 'Ana sayfanın altındaki "Trusted Suppliers" logoları. Logo yoksa isim yazı olarak görünür.', 'fields' => [
        F('suppliers', 'repeater', 'Tedarikçiler', ['title' => 'name', 'add' => 'Tedarikçi ekle', 'new' => ['name' => '', 'logo' => '', 'url' => ''], 'fields' => [
            F('name', 'text', 'Ad', ['req' => true]), F('logo', 'logofile', 'Logo', ['kind' => 'supplier']), F('url', 'text', 'Web sitesi (isteğe bağlı)', ['v' => 'url'])]]),
    ]],
    /* ------------------------------------------------------------------ */
    'blocks' => ['title' => 'Ortak Bölümler', 'icon' => 'clip', 'intro' => 'Birden çok sayfada tekrar eden bantlar: acil durum bandı, teklif bandı, güven çubuğu, çalışma adımları ve sorun listesi.', 'fields' => [
        HD('Acil durum bandı'), F('blocks.emergency.title', 'text', 'Başlık'), F('blocks.emergency.text', 'text', 'Yazı'), F('blocks.emergency.button', 'text', 'Buton', ['h' => '{phone} = telefon']),
        HD('Teklif bandı (sayfa sonu)'), F('blocks.cta.eyebrow', 'text', 'Üst etiket'), F('blocks.cta.title', 'text', 'Başlık'), F('blocks.cta.text', 'text', 'Yazı'), F('blocks.cta.phone_label', 'text', 'Telefon etiketi'), F('blocks.cta.button', 'text', 'Buton'),
        HD('Güven çubuğu'),
        F('blocks.trust', 'repeater', 'Maddeler', ['title' => 'title', 'min' => 2, 'max_items' => 4, 'new' => ['icon' => 'check', 'title' => '', 'text' => ''], 'fields' => [$iconSub(), F('title', 'text', 'Başlık', ['req' => true]), F('text', 'text', 'Alt yazı')]]),
        HD('Çalışma adımları'), F('blocks.process_eyebrow', 'text', 'Üst etiket'), F('blocks.process_title', 'text', 'Başlık'),
        F('blocks.process', 'repeater', 'Adımlar', ['title' => 'title', 'min' => 2, 'max_items' => 6, 'new' => ['icon' => 'check', 'title' => '', 'text' => ''], 'fields' => [$iconSub(), F('title', 'text', 'Başlık', ['req' => true]), F('text', 'textarea', 'Yazı', ['rows' => 2])]]),
        HD('Çözdüğümüz sorunlar'), F('blocks.problems_title', 'text', 'Başlık'),
        F('blocks.problems', 'repeater', 'Sorunlar', ['title' => 'text', 'add' => 'Sorun ekle', 'new' => ['icon' => 'check', 'text' => ''], 'fields' => [$iconSub(), F('text', 'text', 'Yazı', ['req' => true])]]),
        HD('Diğer'), F('blocks.work_eyebrow', 'text', 'İş fotoğrafları üst etiketi'), F('blocks.work_title', 'text', 'İş fotoğrafları başlığı'),
        F('blocks.quote_button', 'text', 'Teklif butonu (sayfa başlıklarında)'), F('blocks.side_button', 'text', 'Yan kart butonu'),
        F('blocks.form_error', 'text', 'Form hata mesajı', ['h' => '{phone} = telefon']), F('blocks.form_service_other', 'text', 'Formdaki "diğer" seçeneği'),
    ]],
    /* ------------------------------------------------------------------ */
    'servicepage' => ['title' => 'Hizmet Sayfaları (ortak)', 'icon' => 'repair', 'intro' => 'Hizmetler sayfası ve tüm hizmet sayfalarında ortak kullanılan yazılar. {service} = hizmet adı, {service_lower} = küçük harfli hizmet adı, {phone} = telefon.', 'fields' => [
        HD('Hizmetler sayfası (services.html)'),
        F('pages.services.seo_title', 'text', 'SEO başlığı', ['req' => true, 'seo' => 'title']), F('pages.services.seo_description', 'textarea', 'Meta açıklama', ['req' => true, 'rows' => 3, 'seo' => 'desc']),
        F('pages.services.title', 'text', 'Başlık (H1)', ['req' => true]), F('pages.services.lead', 'textarea', 'Alt yazı', ['rows' => 2]),
        F('pages.services.work', 'repeater', 'Alt fotoğraf bandı', ['title' => 'tag', 'min' => 3, 'max_items' => 3, 'fields' => [F('image', 'image', 'Fotoğraf', ['kind' => 'photo']), F('tag', 'text', 'Etiket'), F('label', 'text', 'Fotoğraf yokken yazı')]]),
        HD('Her hizmet sayfasında'),
        F('pages.service_template.seo_title', 'text', 'Varsayılan SEO başlığı', ['h' => 'Hizmette özel başlık girilmemişse kullanılır.']),
        F('pages.service_template.seo_description', 'textarea', 'Varsayılan meta açıklama', ['rows' => 2]),
        F('pages.service_template.title', 'text', 'Sayfa başlığı (H1)'),
        F('pages.service_template.breadcrumb', 'text', 'Sayfa yolu adı (Home / Services / …)'),
        F('pages.service_template.photo_label', 'text', 'Fotoğraf yokken yazı'),
        F('pages.service_template.lead_end_emergency', 'text', 'Alt yazı sonu (7/24 hizmetler)'), F('pages.service_template.lead_end_scheduled', 'text', 'Alt yazı sonu (randevulu hizmetler)'),
        F('pages.service_template.included_title', 'text', '"Neler dahil" başlığı'), F('pages.service_template.signs_title', 'text', '"İşaretler" başlığı'),
        F('pages.service_template.area_title', 'text', 'Bölge başlığı'), F('pages.service_template.area_text', 'textarea', 'Bölge yazısı', ['rows' => 2]),
        F('pages.service_template.faq_title', 'text', 'SSS başlığı'),
        F('pages.service_template.faq', 'repeater', 'Her hizmette çıkan sorular', ['title' => 'q', 'min' => 1, 'add' => 'Soru ekle', 'new' => ['q' => '', 'a' => '', 'a_scheduled' => ''], 'fields' => [
            F('q', 'text', 'Soru', ['req' => true]), F('a', 'textarea', 'Cevap', ['req' => true, 'rows' => 2]), F('a_scheduled', 'textarea', 'Randevulu hizmetler için farklı cevap (isteğe bağlı)', ['rows' => 2])]]),
        F('pages.service_template.side_title', 'text', 'Yan kart başlığı'), F('pages.service_template.side_text_emergency', 'text', 'Yan kart (7/24)'), F('pages.service_template.side_text_scheduled', 'text', 'Yan kart (randevulu)'),
        F('pages.service_template.all_title', 'text', '"Tüm hizmetler" başlığı'),
        F('pages.service_template.schema_areas', 'number', 'Google bilgisinde listelenecek bölge sayısı', ['int' => true, 'min' => 1, 'max' => 40]),
    ]],
    /* ------------------------------------------------------------------ */
    'areapage' => ['title' => 'Bölge Sayfaları (ortak)', 'icon' => 'pin', 'intro' => 'Hizmet bölgeleri sayfası ve her şehir sayfasında ortak yazılar. {city} = şehir, {region} = bölge, {neighbourhoods} = semtler, {where} = otomatik ifade, {phone} = telefon.', 'fields' => [
        HD('Hizmet bölgeleri sayfası (service-areas.html)'),
        F('pages.areas.seo_title', 'text', 'SEO başlığı', ['req' => true, 'seo' => 'title']), F('pages.areas.seo_description', 'textarea', 'Meta açıklama', ['req' => true, 'rows' => 3, 'seo' => 'desc']),
        F('pages.areas.title', 'text', 'Başlık (H1)', ['req' => true]), F('pages.areas.lead', 'textarea', 'Alt yazı', ['rows' => 2]),
        F('pages.areas.eyebrow', 'text', 'Üst etiket'), F('pages.areas.list_title', 'text', 'Liste başlığı'), F('pages.areas.list_text', 'text', 'Liste açıklaması'),
        F('pages.areas.regions', 'lines', 'Bölge sırası (her satıra bir)', ['rows' => 5]),
        F('pages.areas.map_title', 'text', 'Harita başlığı'), F('pages.areas.map_text', 'text', 'Harita yazısı'),
        HD('Her şehir sayfasında'),
        F('pages.city_template.seo_title', 'text', 'Varsayılan SEO başlığı'), F('pages.city_template.seo_title_short', 'text', 'Kısa SEO başlığı (uzun şehir adları için)'),
        F('pages.city_template.seo_description', 'textarea', 'Varsayılan meta açıklama', ['rows' => 2]),
        F('pages.city_template.title', 'text', 'Sayfa başlığı (H1)'), F('pages.city_template.lead', 'textarea', 'Alt yazı', ['rows' => 2]),
        F('pages.city_template.intro', 'textarea', 'Giriş cümlesi', ['rows' => 2]),
        F('pages.city_template.photo_label', 'text', 'Fotoğraf yokken yazı'),
        F('pages.city_template.where_toronto', 'text', '{where}: Toronto için'), F('pages.city_template.where_city_of_toronto', 'text', '{where}: Toronto semtleri için'), F('pages.city_template.where_other', 'text', '{where}: diğer şehirler için'),
        F('pages.city_template.services_title', 'text', 'Hizmetler başlığı'), F('pages.city_template.hoods_title', 'text', 'Semtler başlığı'), F('pages.city_template.why_title', 'text', '"Neden biz" başlığı'),
        F('pages.city_template.why', 'lines', '"Neden biz" maddeleri', ['rows' => 4]),
        F('pages.city_template.faq_title', 'text', 'SSS başlığı'),
        F('pages.city_template.faq', 'repeater', 'Her şehirde çıkan sorular', ['title' => 'q', 'min' => 1, 'add' => 'Soru ekle', 'new' => ['q' => '', 'a' => ''], 'fields' => [F('q', 'text', 'Soru', ['req' => true]), F('a', 'textarea', 'Cevap', ['req' => true, 'rows' => 2])]]),
        F('pages.city_template.side_title', 'text', 'Yan kart başlığı'), F('pages.city_template.side_text', 'text', 'Yan kart yazısı'),
        F('pages.city_template.nearby_title', 'text', 'Yakın bölgeler başlığı'), F('pages.city_template.nearby_link', 'text', 'Yakın bölge bağlantısı', ['h' => '{city} = yakın şehir']),
        F('pages.city_template.nearby_count', 'number', 'Yakın bölge sayısı', ['int' => true, 'min' => 0, 'max' => 12]),
        F('pages.city_template.more_title', 'text', 'Yan kart: başlık'), F('pages.city_template.more_all', 'text', 'Yan kart: tüm bölgeler bağlantısı'), F('pages.city_template.more_contact', 'text', 'Yan kart: iletişim bağlantısı'),
    ]],
    /* ------------------------------------------------------------------ */
    'projectpage' => ['title' => 'Proje Sayfaları (ortak)', 'icon' => 'cam', 'intro' => 'Projeler sayfası, ana sayfadaki "Recent Projects" bölümü ve her proje sayfasındaki ortak yazılar. {project} = proje adı, {summary} = özet, {service} = hizmet.', 'fields' => [
        HD('Projeler sayfası (projects.html)'),
        F('pages.projects.seo_title', 'text', 'SEO başlığı', ['req' => true, 'seo' => 'title']), F('pages.projects.seo_description', 'textarea', 'Meta açıklama', ['req' => true, 'rows' => 3, 'seo' => 'desc']),
        F('pages.projects.title', 'text', 'Başlık (H1)', ['req' => true]), F('pages.projects.lead', 'textarea', 'Alt yazı', ['rows' => 2]),
        F('pages.projects.eyebrow', 'text', 'Üst etiket'), F('pages.projects.list_title', 'text', 'Liste başlığı'), F('pages.projects.list_text', 'text', 'Liste açıklaması'),
        F('pages.projects.card_button', 'text', 'Kart bağlantı yazısı'),
        HD('Ana sayfadaki projeler bölümü'),
        F('pages.projects.home_eyebrow', 'text', 'Üst etiket'), F('pages.projects.home_title', 'text', 'Başlık'), F('pages.projects.home_text', 'text', 'Açıklama'), F('pages.projects.home_button', 'text', 'Buton'),
        F('pages.projects.home_count', 'number', 'Ana sayfada kaç proje gösterilsin', ['int' => true, 'min' => 1, 'max' => 9]),
        HD('Her proje sayfasında'),
        F('pages.project_template.seo_title', 'text', 'Varsayılan SEO başlığı'), F('pages.project_template.seo_description', 'text', 'Varsayılan meta açıklama'),
        F('pages.project_template.breadcrumb', 'text', 'Menüdeki ad (Projects)'),
        F('pages.project_template.gallery_title', 'text', 'Galeri başlığı'), F('pages.project_template.about_title', 'text', 'Açıklama başlığı'), F('pages.project_template.highlights_title', 'text', 'Yapılanlar başlığı'),
        F('pages.project_template.facts_title', 'text', 'Bilgi kartı başlığı'), F('pages.project_template.location_label', 'text', 'Yer etiketi'), F('pages.project_template.service_label', 'text', 'Hizmet etiketi'), F('pages.project_template.date_label', 'text', 'Tarih etiketi'),
        F('pages.project_template.service_link', 'text', 'Hizmet bağlantısı'), F('pages.project_template.side_title', 'text', 'Yan kart başlığı'), F('pages.project_template.side_text', 'text', 'Yan kart yazısı'),
        F('pages.project_template.more_title', 'text', 'Diğer projeler başlığı'), F('pages.project_template.all_link', 'text', 'Tüm projeler bağlantısı'), F('pages.project_template.photos', 'text', 'Fotoğraf sayısı yazısı', ['h' => '{count} = sayı']),
    ]],
    /* ------------------------------------------------------------------ */
    'notfound' => ['title' => '404 Sayfası', 'icon' => 'alert', 'fields' => [
        F('pages.notfound.title', 'text', 'Başlık', ['req' => true]), F('pages.notfound.lead', 'textarea', 'Alt yazı', ['rows' => 2]),
        F('pages.notfound.eyebrow', 'text', 'Üst etiket'), F('pages.notfound.list_title', 'text', 'Liste başlığı'),
        F('pages.notfound.button_services', 'text', 'Buton 1'), F('pages.notfound.button_areas', 'text', 'Buton 2'), F('pages.notfound.button_contact', 'text', 'Buton 3'),
        F('pages.notfound.seo_title', 'text', 'Tarayıcı başlığı'), F('pages.notfound.seo_description', 'textarea', 'Açıklama', ['rows' => 2]),
    ]],
    /* ------------------------------------------------------------------ */
    'custom' => ['title' => 'Özel Kod (CSS / JS)', 'icon' => 'code', 'intro' => 'Geliştiriciler için: sitenin bütün sayfalarına kendi CSS, JavaScript veya hazır HTML kodunuzu ekleyin (tasarım ince ayarları, Meta Pixel, Google Tag Manager, canlı destek vb.). Kaydetmeden önce Önizle ile kontrol edin. Bir şey bozulursa "Özel kodları uygula" işaretini kaldırıp kaydedin ya da Yedekler\'den önceki hale dönün.', 'fields' => [
        F('custom.enabled', 'check', 'Özel kodları uygula', ['h' => 'İşareti kaldırırsanız kodlar silinmez, sadece siteden kalkar. Sorun ararken işe yarar.']),
        HD('CSS', 'Sitenin kendi stillerinden sonra yüklenir, yani onları ezer. Tek bir sayfa için: body[data-page="drain-cleaning"] h1 { … }. Bir türdeki bütün sayfalar için: body[data-type="service"] … — türler: home, services, service, areas, city, projects, project, about, contact, faq, 404.'),
        F('custom.css', 'code', 'Özel CSS', ['lang' => 'css', 'rows' => 14, 'ph' => "/* Örnek: ana sayfa başlığını büyüt */\nbody[data-page=\"index\"] .hero h1 { font-size: 3.4rem; }"]),
        HD('JavaScript', 'Her sayfanın en sonunda, sitenin kendi kodundan sonra çalışır. Buraya <script> etiketi yazmayın; etiketli hazır kodlar aşağıdaki HTML alanlarına gider.'),
        F('custom.js', 'code', 'Özel JavaScript', ['lang' => 'js', 'rows' => 10, 'ph' => "// Örnek\ndocument.documentElement.classList.add('ozel');"]),
        HD('Hazır kod parçaları (HTML)', 'Meta Pixel, Google Tag Manager, Hotjar, canlı destek gibi servislerin verdiği kodları olduğu gibi yapıştırın. Google Analytics için WhatsApp & Bağlantılar\'daki alanı kullanın (iki kez yüklenmesin). Bu kodlar Claude önizlemesinde çalışmaz, sadece canlı sitede.'),
        F('custom.head', 'code', '<head> içine', ['lang' => 'html', 'rows' => 8, 'h' => 'Doğrulama etiketleri ve takip kodları (ör. Google Tag Manager\'ın ilk kodu, Meta Pixel).']),
        F('custom.body_start', 'code', '<body> açılışından hemen sonra', ['lang' => 'html', 'rows' => 5, 'h' => 'Ör. Google Tag Manager\'ın <noscript> kodu.']),
        F('custom.body_end', 'code', '</body> kapanmadan hemen önce', ['lang' => 'html', 'rows' => 8, 'h' => 'Canlı destek / sohbet kutuları ve diğer ek scriptler.']),
    ]],
    ];
}

/* ---------------- item editors (one service / city / project) ---------------- */
function schema_service(): array {
    return [
        HD('Hizmet'),
        F('name', 'text', 'Hizmet adı', ['req' => true, 'max' => 60]),
        F('slug', 'text', 'Sayfa adresi', ['v' => 'slug', 'h' => 'Örnek: drain-cleaning → drain-cleaning.html. Değiştirirseniz eski adres yeni sayfaya yönlenir.']),
        F('icon', 'icon', 'Simge'),
        F('emergency', 'check', '7/24 acil hizmet (kartta "24/7 Emergency" etiketi)'),
        F('short', 'textarea', 'Kısa açıklama (kartlarda ve sayfa başında)', ['req' => true, 'rows' => 2, 'max' => 220]),
        F('image', 'image', 'Fotoğraf', ['kind' => 'photo', 'h' => 'Yatay 16:10 (örnek 1600×1000).']),
        HD('Sayfa içeriği'),
        F('intro', 'textarea', 'Giriş paragrafı', ['req' => true, 'rows' => 5]),
        F('included', 'lines', 'Neler dahil (her satıra bir)', ['rows' => 6]),
        F('signs', 'lines', 'Bu hizmete ihtiyacınız olduğunun işaretleri (her satıra bir)', ['rows' => 4]),
        F('closing', 'text', 'Kapanış cümlesi'),
        HD('SEO (boş bırakılırsa otomatik)'),
        F('seo_title', 'text', 'SEO başlığı', ['seo' => 'title']),
        F('seo_description', 'textarea', 'Meta açıklama', ['rows' => 2, 'seo' => 'desc']),
    ];
}
function schema_city(): array {
    return [
        HD('Şehir'),
        F('name', 'text', 'Şehir / semt adı', ['req' => true, 'max' => 40, 'h' => 'Sayfa adresi otomatik: plumber-şehir.html']),
        F('region', 'text', 'Bölge', ['req' => true, 'h' => 'Örnek: York Region, City of Toronto, Peel Region']),
        F('blurb', 'textarea', 'Bu şehre özel paragraf', ['req' => true, 'rows' => 3]),
        F('neighbourhoods', 'lines', 'Hizmet verilen semtler (her satıra bir)', ['req' => true, 'rows' => 6]),
        F('lat', 'number', 'Enlem', ['req' => true, 'h' => 'Yakın bölgeleri bulmak için. Google Maps\'te sağ tık → koordinat.']),
        F('lng', 'number', 'Boylam', ['req' => true]),
        F('image', 'image', 'Fotoğraf', ['kind' => 'photo']),
        HD('SEO (boş bırakılırsa otomatik)'),
        F('seo_title', 'text', 'SEO başlığı', ['seo' => 'title']),
        F('seo_description', 'textarea', 'Meta açıklama', ['rows' => 2, 'seo' => 'desc']),
    ];
}
function schema_project(): array {
    $svc = ['' => '— Seçin —'];
    foreach (services() as $s) $svc[$s['slug']] = $s['name'];
    return [
        HD('Proje'),
        F('title', 'text', 'Proje başlığı', ['req' => true, 'max' => 90, 'h' => 'Örnek: Riser Replacement — North York Condo (site İngilizce)']),
        F('slug', 'text', 'Sayfa adresi', ['v' => 'slug', 'h' => 'Boş bırakırsanız başlıktan üretilir: project-…html']),
        F('published', 'check', 'Sitede yayında'),
        F('location', 'text', 'Yer', ['h' => 'Örnek: North York, ON']),
        F('service', 'select', 'Hizmet türü', ['opts' => $svc]),
        F('date', 'text', 'Tamamlanma tarihi', ['h' => 'YYYY-AA (2026-09) veya serbest yazı (Spring 2026)']),
        F('summary', 'textarea', 'Kısa özet (kartlarda ve sayfa başında)', ['req' => true, 'rows' => 2, 'max' => 260]),
        HD('Fotoğraflar', 'İlk fotoğraf kapak olur; oklarla (bilgisayarda sürükleyerek) sıralayın. Alt metin fotoğrafta ne olduğunu İngilizce anlatır (Google için); boş kalırsa proje başlığı kullanılır.'),
        F('photos', 'gallery', 'Fotoğraflar', ['kind' => 'project']),
        HD('Detaylar'),
        F('description', 'textarea', 'Proje açıklaması', ['rows' => 7, 'h' => 'Paragrafları boş satırla ayırın.']),
        F('highlights', 'lines', 'Yapılanlar (her satıra bir)', ['rows' => 5]),
        HD('SEO (boş bırakılırsa otomatik)'),
        F('seo_title', 'text', 'SEO başlığı', ['seo' => 'title']),
        F('seo_description', 'textarea', 'Meta açıklama', ['rows' => 2, 'seo' => 'desc']),
    ];
}
