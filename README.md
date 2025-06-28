# Laravel Smart Thumbnails

Un package avanzato per Laravel che genera thumbnail intelligenti con algoritmi di smart crop, supporto multi-disk e varianti configurabili.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/Askancy/laravel-smart-thumbnails.svg?style=flat-square)](https://packagist.org/packages/Askancy/laravel-smart-thumbnails)
[![Total Downloads](https://img.shields.io/packagist/dt/Askancy/laravel-smart-thumbnails.svg?style=flat-square)](https://packagist.org/packages/Askancy/laravel-smart-thumbnails)
[![Tests](https://img.shields.io/github/actions/workflow/status/Askancy/laravel-smart-thumbnails/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/Askancy/laravel-smart-thumbnails/actions)

## Caratteristiche

✨ **Smart Crop** - Algoritmo intelligente basato su [dont-crop](https://github.com/jwagner/dont-crop/)  
🚀 **Generazione Lazy** - Thumbnail creati solo alla prima richiesta  
💾 **Multi-Disk** - Supporto completo per dischi Laravel (S3, local, scoped, etc.)  
🎨 **Varianti Multiple** - Diverse dimensioni per lo stesso preset  
🗑️ **Purge Command** - Comando Artisan per pulizia thumbnail  
⚡ **Performance** - Cache automatica e ottimizzazioni  
🧪 **Testato** - Suite completa di test PHPUnit

## Installazione

Installa il package via Composer:

```bash
composer require Askancy/laravel-smart-thumbnails
```

Pubblica la configurazione:

```bash
php artisan vendor:publish --tag=laravel-smart-thumbnails-config
```

## Requisiti

- PHP 8.1+
- Laravel 10.0+
- Intervention Image 2.7+ o 3.0+
- Estensione GD

## Configurazione Base

### 1. Configura i tuoi dischi in `config/filesystems.php`:

```php
'disks' => [
    's3_gallery' => [
        'driver' => 'scoped',
        'disk' => 's3',
        'prefix' => 'gamelite/gallery',
    ],
    's3_news' => [
        'driver' => 'scoped',
        'disk' => 's3',
        'prefix' => 'gamelite/news',
    ],
],
```

### 2. Configura i preset in `config/thumbnails.php`:

```php
'presets' => [
    'gallery' => [
        'format' => 'webp',
        'smartcrop' => '300x200',
        'destination' => ['disk' => 's3_gallery', 'path' => 'crops/'],
        'quality' => 85,
        'smart_crop_enabled' => true,
        'variants' => [
            'thumbnail' => ['smartcrop' => '150x150'],
            'preview' => ['smartcrop' => '500x300'],
            'mobile' => ['smartcrop' => '80x80', 'quality' => 70],
        ]
    ],
    'news' => [
        'format' => 'webp',
        'smartcrop' => '130x130',
        'destination' => ['disk' => 's3_news', 'path' => 'crops/'],
        'variants' => [
            'mobile' => ['smartcrop' => '80x80'],
            'desktop' => ['smartcrop' => '200x150'],
        ]
    ],
],
```

## Utilizzo

### Utilizzo Base nei Blade

```blade
{{-- Thumbnail standard --}}
<img src="{{ Thumbnail::set('gallery')->src($photo->path, 's3_gallery')->url() }}" alt="Gallery">

{{-- Con variante specifica --}}
<img src="{{ Thumbnail::set('gallery')->src($photo->path, 's3_gallery')->url('thumbnail') }}" alt="Thumbnail">

{{-- Responsive con varianti multiple --}}
<picture>
    <source media="(max-width: 768px)"
            srcset="{{ Thumbnail::set('news')->src($article->image, 's3_news')->url('mobile') }}">
    <source media="(min-width: 769px)"
            srcset="{{ Thumbnail::set('news')->src($article->image, 's3_news')->url('desktop') }}">
    <img src="{{ Thumbnail::set('news')->src($article->image, 's3_news')->url() }}" alt="News">
</picture>
```

### Gestione Errori

```blade
@try
    <img src="{{ Thumbnail::set('gallery')->src($photo->path, 's3_gallery')->url('thumbnail') }}" alt="Gallery">
@catch(Exception $e)
    @if(str_contains($e->getMessage(), 'not accessible'))
        <div class="alert alert-warning">Storage non disponibile</div>
    @else
        <img src="/images/placeholder.jpg" alt="Placeholder">
    @endif
@endtry
```

### Utilizzo Programmatico

```php
use Askancy\LaravelSmartThumbnails\Facades\Thumbnail;

// Genera thumbnail
$url = Thumbnail::set('gallery')
                ->src('photos/image.jpg', 's3_gallery')
                ->url('thumbnail');

// Ottieni varianti disponibili
$variants = Thumbnail::getVariants('gallery');

// Testa accessibilità disco
$diskStatus = Thumbnail::testDisk('s3_gallery');

// Purge thumbnails
$purgedCount = Thumbnail::purgePreset('gallery');
```

## Comandi Artisan

### Purge Thumbnails

```bash
# Purge tutti i thumbnail
php artisan thumbnail:purge

# Purge solo un preset specifico
php artisan thumbnail:purge gallery

# Purge senza conferma (per script automatici)
php artisan thumbnail:purge --confirm
php artisan thumbnail:purge gallery --confirm
```

## Smart Crop Algorithm

Il package implementa un algoritmo di smart crop ispirato a [dont-crop](https://github.com/jwagner/dont-crop/) che:

- **Analizza l'energia dell'immagine** usando il gradient magnitude
- **Trova aree di interesse** basandosi su contrasto e dettagli
- **Evita crop troppo aggressivi** mantenendo soggetti importanti
- **Usa la regola dei terzi** per posizionamento ottimale

### Abilitare/Disabilitare Smart Crop

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

## Esempi Pratici

### E-commerce con Varianti Responsive

```php
// config/thumbnails.php
'products' => [
    'format' => 'webp',
    'smartcrop' => '400x400',
    'destination' => ['disk' => 's3_products', 'path' => 'thumbs/'],
    'variants' => [
        'grid' => ['smartcrop' => '200x200'],
        'list' => ['smartcrop' => '150x100'],
        'zoom' => ['smartcrop' => '800x800', 'quality' => 95],
        'mobile' => ['smartcrop' => '120x120', 'quality' => 70],
    ]
],
```

```blade
{{-- Product grid --}}
<div class="product-grid">
    @foreach($products as $product)
        <div class="product-card">
            <img src="{{ Thumbnail::set('products')->src($product->image, 's3_products')->url('grid') }}"
                 alt="{{ $product->name }}">
        </div>
    @endforeach
</div>

{{-- Product detail responsive --}}
<div class="product-images">
    <picture>
        <source media="(max-width: 640px)"
                srcset="{{ Thumbnail::set('products')->src($product->image, 's3_products')->url('mobile') }}">
        <source media="(max-width: 1024px)"
                srcset="{{ Thumbnail::set('products')->src($product->image, 's3_products')->url('grid') }}">
        <img src="{{ Thumbnail::set('products')->src($product->image, 's3_products')->url('zoom') }}"
             alt="{{ $product->name }}">
    </picture>
</div>
```

### Blog con Lazy Loading

```php
// config/thumbnails.php
'blog' => [
    'format' => 'webp',
    'smartcrop' => '600x300',
    'destination' => ['disk' => 's3_blog', 'path' => 'thumbs/'],
    'variants' => [
        'hero' => ['smartcrop' => '1200x600', 'quality' => 90],
        'card' => ['smartcrop' => '300x200'],
        'preview' => ['smartcrop' => '150x100', 'quality' => 70],
    ]
],
```

```blade
{{-- Hero article --}}
<article class="hero-article">
    <img src="{{ Thumbnail::set('blog')->src($featured->image, 's3_blog')->url('hero') }}"
         alt="{{ $featured->title }}"
         loading="eager">
</article>

{{-- Article cards con lazy loading --}}
<div class="article-grid">
    @foreach($articles as $article)
        <article class="article-card">
            <img src="{{ Thumbnail::set('blog')->src($article->image, 's3_blog')->url('card') }}"
                 alt="{{ $article->title }}"
                 loading="lazy">
        </article>
    @endforeach
</div>
```

### Gallery con Lightbox

```php
// config/thumbnails.php
'gallery' => [
    'format' => 'webp',
    'smartcrop' => '400x300',
    'destination' => ['disk' => 's3_gallery', 'path' => 'thumbs/'],
    'variants' => [
        'thumb' => ['smartcrop' => '200x150'],
        'medium' => ['smartcrop' => '600x400'],
        'large' => ['smartcrop' => '1200x800', 'quality' => 95],
    ]
],
```

```blade
<div class="gallery-grid">
    @foreach($photos as $photo)
        <a href="{{ Thumbnail::set('gallery')->src($photo->path, 's3_gallery')->url('large') }}"
           class="gallery-item"
           data-lightbox="gallery">
            <img src="{{ Thumbnail::set('gallery')->src($photo->path, 's3_gallery')->url('thumb') }}"
                 alt="Gallery image"
                 loading="lazy">
        </a>
    @endforeach
</div>
```

## API Reference

### ThumbnailService

```php
// Imposta preset
Thumbnail::set(string $configKey): self

// Imposta sorgente
Thumbnail::src(string $imagePath, string $sourceDisk = 'public'): self

// Genera URL
Thumbnail::url(string $variant = null): string

// Utility
Thumbnail::getAvailableDisks(): array
Thumbnail::getScopedDisks(): array
Thumbnail::testDisk(string $disk): array
Thumbnail::getVariants(string $configKey = null): array

// Pulizia
Thumbnail::purgeAll(): int
Thumbnail::purgePreset(string $preset): int
```

## Testing

Esegui i test:

```bash
composer test

# Con coverage
composer test-coverage
```

### Scrivere Test Custom

```php
use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Askancy\LaravelSmartThumbnails\Facades\Thumbnail;

class MyThumbnailTest extends TestCase
{
    public function test_custom_thumbnail_generation()
    {
        // Il tuo test qui
        $url = Thumbnail::set('gallery')
                       ->src('test-image.jpg', 'local')
                       ->url('thumbnail');

        $this->assertNotEmpty($url);
    }
}
```

## Troubleshooting

### Disk non accessibile

```bash
# Verifica configurazione dischi
php artisan tinker
>>> Thumbnail::testDisk('s3_gallery')
```

### Thumbnails non generati

1. Verifica permessi directory
2. Controlla log Laravel per errori
3. Verifica che Intervention Image sia installato
4. Testa con disco local prima di S3

### Performance

- Usa formato WebP quando possibile
- Imposta qualità appropriata (70-85 per web)
- Considera CDN per delivery
- Monitor dimensioni cache thumbnail

## Changelog

Vedi [CHANGELOG.md](CHANGELOG.md) per la lista delle modifiche.

## Contributing

Le Pull Request sono benvenute! Vedi [CONTRIBUTING.md](CONTRIBUTING.md) per i dettagli.

## Security

Se scopri vulnerabilità di sicurezza, invia un'email a security@Askancy.net.

## Credits

- [Askancy](https://github.com/Askancy)
- [Intervention Image](https://github.com/Intervention/image)
- [dont-crop algorithm](https://github.com/jwagner/dont-crop/)

## License

MIT License. Vedi [LICENSE.md](LICENSE.md) per i dettagli.
