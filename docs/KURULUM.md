# Demir Mechanical — Yönetim Paneli: Kurulum ve Kullanım

Panel adresi: **https://demirmechanical.com/admin/**

## 1. İlk kurulum (bir kez yapılır)
1. Hostinger hPanel → **Dosya Yöneticisi** → `public_html` klasörünü açın.
2. Size gönderilen **demir-admin-kurulum.zip** dosyasını buraya yükleyin. Dosyaya sağ tıklayıp **Extract (Çıkart)** deyin,
   hedef olarak `public_html` kalsın. "Üzerine yazılsın mı?" sorusuna **Evet** deyin.
   Zipte `form-config.php` ve `data` klasörü yoktur; e-posta ayarınız korunur.
3. Tarayıcıda **demirmechanical.com/admin/** adresini açın. "Paneli kurun" ekranı gelir.
4. Güvenlik için önce **info@demirmechanical.com e-posta şifresini** yazın (daha önce `form-config.php` dosyasına yazdığınız şifre).
   Eğer o dosyada şifre yoksa ekran bir **kurulum kodu** ister: Dosya Yöneticisi'nde `data/setup-code.txt` dosyasını açıp kodu kopyalayın.
5. Panel için yeni bir şifre belirleyin (en az 10 karakter, e-posta şifresinden farklı) → **Kurulumu tamamla**.
6. Panel açılır ve sitenin bütün sayfaları yeniden oluşturulur. Eski `site-links.js` dosyasındaki bağlantılar panele aktarılır;
   o dosya artık kullanılmaz, isterseniz silebilirsiniz.

## 2. Neler yapılabilir
| Ne yapmak istiyorsunuz | Panelde nereden |
|---|---|
| Telefon, adres, çalışma saatleri | Site Ayarları → Firma Bilgileri |
| Logo, footer logosu, site ikonu | Site Ayarları → Logo & Görseller |
| Menüdeki sıra, isimler, gizleme, yeni bağlantı | Site Ayarları → Menü & Footer |
| WhatsApp numarası ve hazır mesajlar, Facebook/Instagram/Google, **Google Analytics** | Site Ayarları → WhatsApp & Bağlantılar |
| Hizmet ekleme / silme / sıralama | İçerik → Hizmetler |
| Yeni şehir sayfası | İçerik → Bölgeler |
| **Proje ekleme** (fotoğraf galerisiyle) | İçerik → Projeler → Yeni proje ekle |
| Müşteri yorumları | İçerik → Yorumlar |
| Sık sorulan sorular | İçerik → Sık Sorulan Sorular |
| Her sayfanın yazıları ve fotoğrafları | Sayfalar → … |
| Sitedeki bütün fotoğrafları bir arada görmek, dosya adı ve alt metnini değiştirmek (sağdaki panelde) | İçerik → Galeri → fotoğrafa tıklayın |
| Galeriye yeni fotoğraf eklemek, kullanılmayan fotoğrafı silmek | İçerik → Galeri → Fotoğraf ekle (veya sürükleyip bırakın) |
| Galerideki bir fotoğrafı bir hizmette, bölgede, projede veya sayfada kullanmak | Fotoğraf alanında **Galeriden seç** (projelerde **Galeriden ekle**) |
| **Müşteriye fatura** hazırlamak, PDF indirmek, ödenenleri takip etmek | İşletme → Faturalar → Yeni fatura |
| HST numarası, fatura numarası ön eki, ödeme süresi ve ödeme bilgileri | İşletme → Fatura Ayarları |
| Google başlıkları, açıklamalar, anahtar kelimeler, paylaşım görselleri, fotoğraf adları ve alt metinleri (Google önizlemesi, öneriler, SEO kontrolü) | Araçlar → SEO Paneli → Düzenle |
| Bir değişikliği geri almak, yedek indirmek | Araçlar → Yedekler |
| Şifre değiştirme | Araçlar → Hesap & Güvenlik |
| Geliştirici için özel CSS / JavaScript / takip kodları, sitenin kendi CSS ve JavaScript kodunu düzenleme | Araçlar → Özel Kod |

- **Kaydet ve yayınla** düğmesi değişikliği hemen siteye koyar (önbellek temizlemeye gerek yok).
- **Önizle** düğmesi kaydetmeden önce sayfanın nasıl görüneceğini yeni sekmede gösterir.
- Her kayıttan önce sitenin önceki hali otomatik yedeklenir. **Yedekler** sayfasından tek tıkla geri dönebilirsiniz.
- Site İngilizce olduğu için sitede görünen yazıları (başlıklar, açıklamalar, alt metinler) İngilizce yazın.

### Proje eklerken
1. Başlık (örnek: *Riser Replacement — North York Condo*), yer, hizmet türü, tarih ve kısa özet yazın.
2. **Fotoğraf ekle** ile birden fazla fotoğrafı aynı anda seçin (telefondan da olur). Fotoğraflar otomatik küçültülür.
3. İlk fotoğraf kapak olur; oklarla (bilgisayarda sürükleyerek) sıralayın. Her fotoğrafa kısa bir açıklama yazabilirsiniz.
4. **Kaydet ve yayınla**. Proje sayfası, Projeler sayfası, ana sayfadaki "Recent Projects" bölümü ve menüdeki "Projects" kendiliğinden çıkar.
   Taslak olarak tutmak için "Sitede yayında" işaretini kaldırın.

### Fatura kesmek
1. İlk seferde **İşletme → Fatura Ayarları**'ndan HST numaranızı (ör. 123456789 RT0001) yazıp kaydedin.
2. **İşletme → Faturalar → Yeni fatura**. Numara ve tarih kendiliğinden gelir.
3. Müşterinin adını, adresini, e-postasını yazın. Daha önce fatura kestiğiniz bir müşteriyi listeden seçerseniz bilgileri kendiliğinden dolar.
4. Yapılan işleri ve malzemeleri **İngilizce** yazın: açıklama, adet/saat, birim fiyat (vergisiz). Toplam ve HST (%13) yazarken hesaplanır.
5. Kapora aldıysanız "Alınan ödeme" alanına yazın; faturada kalan tutar çıkar. **PDF önizle** ile kontrol edip **Kaydet**'e basın.
6. **PDF indir** ile dosyayı alıp müşteriye e-posta veya WhatsApp ile gönderin. Ödeme gelince durumu **Ödendi** yapıp kaydedin
   (PDF'te PAID damgası çıkar). Son ödeme tarihi geçen faturalar listede ve panel ana sayfasında **Gecikmiş** olarak görünür.
- Faturalar sadece sunucuda, korumalı `data/invoices.json` dosyasında durur; sitede ve GitHub'da görünmez. Her değişiklikten
  önce bir kopyası `data/backups` klasörüne alınır. Tam yedek (.zip) faturaları da içerir: o dosyayı güvenli yerde saklayın.
- Gönderilmiş bir faturayı silmek yerine durumunu **İptal** yapın (faturada VOID yazar, numara sırası bozulmaz).

### Google Analytics bağlamak
analytics.google.com → Yönetici → Veri akışları → web akışı → **Ölçüm Kimliği** (G-XXXXXXXXXX). Bu kimliği
Site Ayarları → WhatsApp & Bağlantılar → "Google Analytics 4 ölçüm kimliği" alanına yapıştırıp kaydedin.
Ziyaretler, telefon tıklamaları, WhatsApp ve form gönderimleri ölçülmeye başlar.

## 3. Şifremi unuttum
Dosya Yöneticisi → `public_html/data/admin-auth.php` dosyasını silin → /admin/ adresini açın → kurulum yeniden açılır
(içerik ve sayfalar etkilenmez).

## 4. Siteyi bir geliştiriciye devretmek
- **Kod:** GitHub deposu `bugrahanunsal/demir-mechanical-website` (geliştiriciyi depoya ekleyin veya depoyu devredin).
- **İçerik ve fotoğraflar:** Panel → Yedekler → **Tam yedek (.zip)**. Bu dosya sitenin o anki eksiksiz kopyasıdır (şifreler hariç;
  faturalar ve müşteri bilgileri dahil, bu yüzden güvenli bir yoldan iletin).
- **Erişimler:** Hostinger hesabı, alan adı, info@ e-posta hesabı ve panel şifresi (bunları güvenli bir yoldan iletin, GitHub'a yazmayın).
