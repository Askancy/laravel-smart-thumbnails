# Laravel Smart Thumbnails

Un package avanzato per Laravel che genera thumbnail intelligenti con algoritmi di smart crop, supporto multi-disk e **gestione errori robusta** che non blocca mai l'applicazione.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/askancy/laravel-smart-thumbnails.svg?style=flat-square)](https://packagist.org/packages/askancy/laravel-smart-thumbnails)
[![Total Downloads](https://img.shields.io/packagist/dt/askancy/laravel-smart-thumbnails.svg?style=flat-square)](https://packagist.org/packages/askancy/laravel-smart-thumbnails)
[![Tests](https://img.shields.io/github/actions/workflow/status/askancy/laravel-smart-thumbnails/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/askancy/laravel-smart-thumbnails/actions)

## Caratteristiche

✨ **Smart Crop** - Algoritmo intelligente basato su [dont-crop](https://github.com/jwagner/dont-crop/)  
🚀 **Generazione Lazy** - Thumbnail creati solo alla prima richiesta  
💾 **Multi-Disk** - Supporto completo per dischi Laravel (S3, local, scoped, etc.)  
🎨 **Varianti Multiple** - Diverse dimensioni per lo stesso preset  
🗑️ **Purge Command** - Comando Artisan per pulizia thumbnail  
⚡ **Performance** - Cache automatica e ottimizzazioni  
🛡️ **Error-Safe** - **MAI più pagine bianche!** Gestione errori intelligente  
🔄 **Fallback Automatici** - Placeholder e immagini alternative  
🧪 **Testato** - Suite completa di test PHPUnit

## Requisiti

- PHP 8.1+
- Laravel 10.0+
- Intervention Image 2.7+ o 3.0+
- Estensione GD

## Installazione

Installa il package via Composer:

```bash
composer require askancy/laravel-smart-thumbnails
```

Pubblica la configurazione:

```bash
php artisan vendor:publish --tag=laravel-smart-thumbnails-config
```

## Utilizzo Base

### **Modalità Standard (con eccezioni)**

```blade
{{-- Può lanciare eccezioni se l'immagine non esiste --}}
<img src="{{ Thumbnail::set('gallery')->src($photo->path, 's3_gallery')->url() }}" alt="Gallery">

{{-- Con variante specifica --}}
<img src="{{ Thumbnail::set('gallery')->src($photo->path, 's3_gallery')->url('thumbnail') }}" alt="Thumbnail">
```

### **🛡️ Modalità Sicura (mai errori!)**

```blade
{{-- NON lancia MAI eccezioni, mostra sempre qualcosa --}}
<img src="{{ Thumbnail::set('gallery')->src($photo->path, 's3_gallery')->urlSafe() }}" alt="Gallery">

{{-- Modalità silenziosa esplicita --}}
<img src="{{ Thumbnail::silent()->set('gallery')->src($photo->path, 's3_gallery')->url('thumbnail') }}" alt="Thumbnail">
```

## 🛡️ Gestione Errori Avanzata

### **1. Modalità Operative**

Il package offre due modalità:

#### **Strict Mode (Default)**

```blade
{{-- Lancia eccezioni in caso di errore --}}
<img src="{{ Thumbnail::strict()->set('news')->src($image, 's3')->url() }}" alt="News">
```

#### **Silent Mode (Error-Safe)**

```blade
{{-- Non lancia MAI eccezioni, usa sempre fallback --}}
<img src="{{ Thumbnail::silent()->set('news')->src($image, 's3')->url() }}" alt="News">

{{-- Oppure con il metodo urlSafe() --}}
<img src="{{ Thumbnail::set('news')->src($image, 's3')->urlSafe() }}" alt="News">
```

### **2. Fallback Automatici**

Quando un thumbnail fallisce, il sistema prova automaticamente:

1. ✅ **Immagine originale** - Se accessibile
2. ✅ **Placeholder configurato** - URL personalizzato
3. ✅ **Placeholder generato** - Immagine con icona errore
4. ✅ **SVG di emergenza** - Fallback finale garantito

```blade
{{-- Questo non fallirà MAI, anche se l'immagine non esiste --}}
<img src="{{ Thumbnail::silent()->set('gallery')->src('non-esistente.jpg', 's3')->url() }}" alt="Always works">
```

### **3. Configurazione per Preset**

Puoi configurare alcuni preset per essere sempre silenziosi:

```php
// config/thumbnails.php
'presets' => [
    'slider' => [
        'format' => 'webp',
        'smartcrop' => '800x400',
        'destination' => ['disk' => 'public', 'path' => 'crops/slider/'],
        'silent_mode' => true,  // ✅ Sempre silenzioso
        'variants' => [
            'mobile' => ['smartcrop' => '400x200'],
        ]
    ],
    'admin_gallery' => [
        'format' => 'webp',
        'smartcrop' => '300x200',
        'destination' => ['disk' => 's3', 'path' => 'admin/crops/'],
        'silent_mode' => false,  // ❌ Mostra errori agli admin
    ],
],
```

### **4. Placeholder Personalizzati**

```php
// config/thumbnails.php
return [
    'placeholder_url' => '/images/custom-error.png',     // URL placeholder
    'placeholder_color' => '#f8f9fa',                    // Colore background
    'placeholder_text_color' => '#6c757d',               // Colore testo
    'fallback_to_original' => true,                      // Usa immagine originale
    'generate_placeholders' => true,                     // Genera placeholder automatici
];
```

## 📋 Esempi Pratici

### **E-commerce Product Gallery**

```blade
{{-- Gallery principale - errori visibili per debug --}}
@if(auth()->user()?->isAdmin())
    <img src="{{ Thumbnail::strict()->set('products')->src($product->image, 's3_products')->url('large') }}" alt="Product">
@else
    <img src="{{ Thumbnail::silent()->set('products')->src($product->image, 's3_products')->url('large') }}" alt="Product">
@endif

{{-- Thumbnails nella lista - sempre silenziosi --}}
@foreach($products as $product)
    <div class="product-card">
        <img src="{{ Thumbnail::set('products')->src($product->image, 's3_products')->urlSafe('thumb') }}"
             alt="{{ $product->name }}"
             loading="lazy">
    </div>
@endforeach
```

### **Slider Homepage (mai rotto)**

```blade
<div class="homepage-slider">
    @foreach($slides as $slide)
        <div class="slide">
            {{-- Questo slider non si romperà MAI --}}
            <img src="{{ Thumbnail::silent()->set('slider')->src($slide->image, 's3')->url('hero') }}"
                 alt="Slide"
                 loading="lazy">
        </div>
    @endforeach
</div>
```

### **Sistema di Avatar**

```blade
{{-- Avatar utente con fallback elegante --}}
@if($user->avatar)
    <img src="{{ Thumbnail::set('avatars')->src($user->avatar, 's3_avatars')->urlSafe('medium') }}"
         alt="Avatar"
         class="rounded-full">
@else
    <div class="default-avatar">{{ substr($user->name, 0, 1) }}</div>
@endif
```

### **Dashboard Admin vs Utenti**

```blade
{{-- Admin: vede errori per debugging --}}
@admin
    @try
        <img src="{{ Thumbnail::set('gallery')->src($image, 's3')->url('large') }}" alt="Gallery">
    @catch(Exception $e)
        <div class="alert alert-danger">
            <strong>Thumbnail Error:</strong> {{ $e->getMessage() }}
            <br><small>Path: {{ $image }}</small>
        </div>
    @endtry
@else
    {{-- Utenti: esperienza fluida senza errori --}}
    <img src="{{ Thumbnail::set('gallery')->src($image, 's3')->urlSafe('large') }}" alt="Gallery">
@endadmin
```

### **Responsive Images con Fallback**

```blade
<picture>
    <source media="(max-width: 640px)"
            srcset="{{ Thumbnail::silent()->set('blog')->src($post->image, 's3')->url('mobile') }}">
    <source media="(max-width: 1024px)"
            srcset="{{ Thumbnail::silent()->set('blog')->src($post->image, 's3')->url('tablet') }}">
    <img src="{{ Thumbnail::silent()->set('blog')->src($post->image, 's3')->url('desktop') }}"
         alt="{{ $post->title }}"
         loading="lazy">
</picture>
```

## ⚙️ Configurazione Avanzata

### **1. Configurazione Multi-Disk**

```php
// config/filesystems.php
'disks' => [
    's3_gallery' => [
        'driver' => 'scoped',
        'disk' => 's3',
        'prefix' => 'gallery',
    ],
    's3_products' => [
        'driver' => 'scoped',
        'disk' => 's3',
        'prefix' => 'products',
    ],
],
```

### **2. Preset Configurazione Completa**

```php
// config/thumbnails.php
'presets' => [
    'products' => [
        'format' => 'webp',
        'smartcrop' => '400x400',
        'destination' => ['disk' => 's3_products', 'path' => 'thumbs/'],
        'quality' => 85,
        'smart_crop_enabled' => true,
        'silent_mode' => false,  // Strict per admin
        'variants' => [
            'thumb' => ['smartcrop' => '150x150', 'quality' => 70],
            'medium' => ['smartcrop' => '300x300', 'quality' => 80],
            'large' => ['smartcrop' => '600x600', 'quality' => 90],
            'zoom' => ['smartcrop' => '1200x1200', 'quality' => 95],
        ]
    ],
    'user_content' => [
        'format' => 'webp',
        'smartcrop' => '500x300',
        'destination' => ['disk' => 'public', 'path' => 'user-thumbs/'],
        'quality' => 80,
        'smart_crop_enabled' => true,
        'silent_mode' => true,   // ✅ Sempre silenzioso per contenuti utente
    ],
],
```

### **3. Gestione Errori Globale**

```php
// config/thumbnails.php
return [
    // Comportamento di default
    'silent_mode_default' => false,
    'log_errors' => true,
    'generate_placeholders' => true,
    'fallback_to_original' => true,

    // Placeholder settings
    'placeholder_url' => '/images/thumbnail-error.svg',
    'placeholder_color' => '#f8f9fa',
    'placeholder_text_color' => '#6c757d',
];
```

## 🚀 Comandi Artisan

### **Purge Thumbnails**

```bash
# Purge tutti i thumbnail
php artisan thumbnail:purge

# Purge solo un preset specifico
php artisan thumbnail:purge gallery

# Purge senza conferma (per script automatici)
php artisan thumbnail:purge --confirm
php artisan thumbnail:purge gallery --confirm
```

## 🧠 Smart Crop Algorithm

Il package implementa un algoritmo di smart crop ispirato a [dont-crop](https://github.com/jwagner/dont-crop/) che:

- **Analizza l'energia dell'immagine** usando il gradient magnitude
- **Trova aree di interesse** basandosi su contrasto e dettagli
- **Evita crop troppo aggressivi** mantenendo soggetti importanti
- **Usa la regola dei terzi** per posizionamento ottimale

### **Abilitare/Disabilitare Smart Crop**

```php
// Nel config
'presets' => [
    'gallery' => [
        'smart_crop_enabled' => true,  // Usa algoritmo intelligente
        // oppure
        'smart_crop_enabled' => false, // Usa crop centrale classico
    ],
],
```

## 🔍 Debug e Monitoring

### **Test Connessioni Dischi**

```blade
{{-- Debug view per verificare dischi --}}
@foreach(Thumbnail::getAvailableDisks() as $disk)
    @php $status = Thumbnail::testDisk($disk); @endphp
    <p>{{ $disk }}: {{ $status['accessible'] ? '✅ OK' : '❌ ' . $status['error'] }}</p>
@endforeach
```

### **Logging Intelligente**

Il package logga automaticamente:

```
[INFO] Thumbnail generated successfully
[WARNING] Thumbnail generation failed (silent mode) - using original image
[ERROR] Source image not found: path/to/missing.jpg (handled gracefully)
```

### **Route di Debug**

```php
// routes/web.php - solo per development
Route::get('/debug-thumbnails', function() {
    return [
        'disks' => Thumbnail::getAvailableDisks(),
        'scoped_disks' => Thumbnail::getScopedDisks(),
        'variants' => Thumbnail::getVariants('products'),
    ];
})->middleware('admin');
```

## 🎯 Best Practices

### **1. Quando Usare Strict vs Silent**

```blade
{{-- ✅ STRICT: Per admin, development, contenuti critici --}}
@if(app()->environment('local') || auth()->user()?->isAdmin())
    {{ Thumbnail::strict()->set('products')->src($image, 's3')->url() }}
@else
    {{ Thumbnail::silent()->set('products')->src($image, 's3')->url() }}
@endif

{{-- ✅ SILENT: Per slider, gallery pubbliche, contenuti utente --}}
{{ Thumbnail::silent()->set('slider')->src($slide->image, 's3')->url() }}

{{-- ✅ URL_SAFE: Metodo rapido per contenuti pubblici --}}
{{ Thumbnail::set('gallery')->src($photo->path, 's3')->urlSafe() }}
```

### **2. Gestione Errori per Tipo di Contenuto**

```blade
{{-- Prodotti E-commerce: fallback a placeholder --}}
<img src="{{ Thumbnail::set('products')->src($product->image ?? '', 's3')->urlSafe('thumb') }}"
     alt="{{ $product->name }}"
     onerror="this.src='/images/no-product.png'">

{{-- Avatar utenti: fallback a iniziali --}}
@if($user->avatar)
    <img src="{{ Thumbnail::set('avatars')->src($user->avatar, 's3')->urlSafe('small') }}" alt="Avatar">
@else
    <div class="avatar-placeholder">{{ strtoupper(substr($user->name, 0, 2)) }}</div>
@endif

{{-- Contenuti critici: mostra errore se necessario --}}
@try
    <img src="{{ Thumbnail::set('documents')->src($doc->cover, 's3')->url() }}" alt="Document">
@catch(Exception $e)
    <div class="document-error">
        <p>⚠️ Anteprima non disponibile</p>
        <small>{{ $doc->filename }}</small>
    </div>
@endtry
```

### **3. Performance e SEO**

```blade
{{-- Lazy loading con placeholder inline --}}
<img src="{{ Thumbnail::set('gallery')->src($photo->path, 's3')->urlSafe('thumb') }}"
     alt="{{ $photo->title }}"
     loading="lazy"
     style="background: #f0f0f0;">

{{-- Critical images (above fold) --}}
<img src="{{ Thumbnail::set('hero')->src($banner->image, 's3')->urlSafe('desktop') }}"
     alt="Hero banner"
     loading="eager"
     fetchpriority="high">
```

## 🔧 Troubleshooting

### **Disk non accessibile**

```bash
# Verifica configurazione dischi
php artisan tinker
>>> Thumbnail::testDisk('s3_gallery')
```

### **Thumbnails non generati**

1. Verifica permessi directory
2. Controlla log Laravel per errori
3. Verifica che Intervention Image sia installato
4. Testa con disco local prima di S3

### **Performance**

- Usa formato WebP quando possibile
- Imposta qualità appropriata (70-85 per web)
- Considera CDN per delivery
- Monitor dimensioni cache thumbnail

### **Problema: Immagini non generate**

```bash
# 1. Verifica configurazione
php artisan config:clear

# 2. Test connessioni dischi
php artisan tinker
>>> Thumbnail::testDisk('s3_gallery')

# 3. Verifica permessi storage
php artisan storage:link
```

### **Problema: Errori di memoria**

```php
// config/thumbnails.php - riduci qualità per immagini grandi
'large_images' => [
    'smartcrop' => '1920x1080',
    'quality' => 75,  // ✅ Riduci qualità per file grandi
    'variants' => [
        'web' => ['smartcrop' => '800x600', 'quality' => 85],
    ]
],
```

### **Problema: Placeholder non mostrati**

```blade
{{-- Assicurati di usare silent mode --}}
{{ Thumbnail::silent()->set('gallery')->src($image, 's3')->url() }}

{{-- Oppure urlSafe --}}
{{ Thumbnail::set('gallery')->src($image, 's3')->urlSafe() }}

{{-- Verifica configurazione placeholder --}}
@if(config('thumbnails.placeholder_url'))
    Placeholder URL configurato: {{ config('thumbnails.placeholder_url') }}
@endif
```

## 📊 Monitoring e Statistiche

```bash
# Comando per statistiche thumbnail
php artisan thumbnail:stats

# Output esempio:
# gallery: 1,234 files, 45.2 MB
# products: 5,678 files, 123.4 MB
# Total: 6,912 files, 168.6 MB
```

## 🆕 Novità Versione 2.0

- 🛡️ **Error-Safe Mode** - Mai più pagine bianche
- 🔄 **Fallback Automatici** - Sistema a cascata di fallback
- 🎨 **Placeholder Intelligenti** - Generazione automatica con icone
- ⚙️ **Configurazione per Preset** - Silent mode per preset specifici
- 📊 **Logging Avanzato** - Tracking completo errori e successi
- 🚀 **Performance** - Gestione memoria ottimizzata

## 📚 API Reference

### **ThumbnailService Methods**

```php
// Configurazione
Thumbnail::set(string $preset): self
Thumbnail::src(string $path, string $disk = 'public'): self

// Modalità operative
Thumbnail::silent(): self         // Modalità silenziosa
Thumbnail::strict(): self         // Modalità con eccezioni

// Generazione URL
Thumbnail::url(string $variant = null): string      // Standard
Thumbnail::urlSafe(string $variant = null): string  // Sempre sicuro

// Utility
Thumbnail::getAvailableDisks(): array
Thumbnail::getScopedDisks(): array
Thumbnail::testDisk(string $disk): array
Thumbnail::getVariants(string $preset = null): array

// Maintenance
Thumbnail::purgeAll(): int
Thumbnail::purgePreset(string $preset): int
```

## 🤝 Contributing

Le Pull Request sono benvenute! Per contribuire:

1. Fork il repository
2. Crea un branch per la tua feature
3. Scrivi test per le nuove funzionalità
4. Assicurati che tutti i test passino
5. Crea una Pull Request

## 📄 License

MIT License. Vedi [LICENSE.md](LICENSE.md) per i dettagli.

## 🙏 Credits

- [Askancy](https://github.com/askancy)
- [Intervention Image](https://github.com/Intervention/image)
- [dont-crop algorithm](https://github.com/jwagner/dont-crop/)
- Tutti i [contributors](https://github.com/askancy/laravel-smart-thumbnails/contributors)

---

> 💡 **Pro Tip**: Usa sempre `urlSafe()` o `silent()` per contenuti pubblici e riservati `strict()` solo per admin e development!
