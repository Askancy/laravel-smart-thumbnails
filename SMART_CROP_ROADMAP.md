# Smart Crop Algorithm Roadmap

## Current Implementation

The current smart crop implementation uses a **Rule of Thirds** algorithm that positions the crop area at strategic points in the image:

### Features
- ✅ Rule of thirds positioning for horizontal crops
- ✅ Upper-third preference for vertical crops (better for portraits and landscapes)
- ✅ Energy map with weighted interest points
- ✅ Fallback to center positioning
- ✅ Compatible with Intervention Image 3.x

### Algorithm Flow
```
1. Calculate aspect ratios (original vs target)
2. Determine crop direction (horizontal or vertical)
3. Apply rule of thirds positioning
4. Crop to calculated coordinates
5. Resize to target dimensions
```

## Future Enhancements

### Phase 1: Edge Detection (Priority: High)

**Goal:** Detect edges and high-contrast areas to identify important content.

**Implementation:**
```php
protected function detectEdges(ImageInterface $image): array
{
    // Convert image to GD resource for edge detection
    $gdImage = $image->core()->native();

    // Apply Sobel filter for edge detection
    // Horizontal Sobel kernel
    $sobelX = [
        [-1, 0, 1],
        [-2, 0, 2],
        [-1, 0, 1]
    ];

    // Vertical Sobel kernel
    $sobelY = [
        [-1, -2, -1],
        [ 0,  0,  0],
        [ 1,  2,  1]
    ];

    // Use imageconvolution() to apply filters
    imageconvolution($gdImage, $sobelX, 1, 0);
    imageconvolution($gdImage, $sobelY, 1, 0);

    // Calculate gradient magnitude
    // Return energy map based on edge intensity

    return $energyMap;
}
```

**Benefits:**
- Identifies areas with high visual interest
- Prevents cropping important content
- Better results for images with clear subjects

**Libraries to consider:**
- GD's `imageconvolution()` (built-in)
- OpenCV PHP extension (advanced)

---

### Phase 2: Entropy Analysis (Priority: Medium)

**Goal:** Measure information density to find areas with rich textures and details.

**Implementation:**
```php
protected function calculateEntropy(ImageInterface $image): array
{
    $histogram = [];
    $entropy = [];

    // Divide image into grid (e.g., 3x3 or 5x5)
    $gridSize = 5;
    $cellWidth = $image->width() / $gridSize;
    $cellHeight = $image->height() / $gridSize;

    for ($row = 0; $row < $gridSize; $row++) {
        for ($col = 0; $col < $gridSize; $col++) {
            // Extract cell
            $cell = $image->crop(
                $cellWidth,
                $cellHeight,
                $col * $cellWidth,
                $row * $cellHeight
            );

            // Calculate histogram for this cell
            // Higher entropy = more detail/texture
            $cellEntropy = $this->calculateCellEntropy($cell);

            $entropy[$row][$col] = [
                'x' => $col * $cellWidth + $cellWidth / 2,
                'y' => $row * $cellHeight + $cellHeight / 2,
                'entropy' => $cellEntropy,
                'weight' => $cellEntropy / 8.0 // Normalize to 0-1
            ];
        }
    }

    return $entropy;
}

protected function calculateCellEntropy($cell): float
{
    // Calculate Shannon entropy for pixel values
    // H(X) = -Σ(p(x) * log2(p(x)))

    $histogram = [];
    // Count pixel values
    // Calculate probability distribution
    // Compute entropy

    return $entropy;
}
```

**Benefits:**
- Identifies textured vs flat areas
- Better handling of landscapes and nature photos
- Complements edge detection

---

### Phase 3: Face Detection (Priority: High)

**Goal:** Detect and prioritize faces in the image to ensure they're never cropped out.

**Implementation Options:**

**Option A: OpenCV (Most Accurate)**
```php
protected function detectFaces(ImageInterface $image): array
{
    // Requires: opencv PHP extension

    $cascade = new \OpenCV\Cascade(
        '/path/to/haarcascade_frontalface_default.xml'
    );

    $faces = $cascade->detect($image->core()->native());

    return array_map(function($face) {
        return [
            'x' => $face['x'] + $face['w'] / 2,
            'y' => $face['y'] + $face['h'] / 2,
            'width' => $face['w'],
            'height' => $face['h'],
            'weight' => 3.0, // Very high priority
        ];
    }, $faces);
}
```

**Option B: External API (Cloud-based)**
```php
protected function detectFacesAPI(ImageInterface $image): array
{
    // Use Google Cloud Vision, AWS Rekognition, or Azure Face API

    $client = new \Google\Cloud\Vision\VisionClient([
        'keyFile' => json_decode(file_get_contents(
            config('thumbnails.google_vision_key_path')
        ), true)
    ]);

    $imageData = $image->encode()->toString();
    $annotation = $client->annotate($imageData, ['FACE_DETECTION']);

    $faces = [];
    foreach ($annotation->faces() as $face) {
        $bounds = $face->boundingPoly();
        $vertices = $bounds->vertices();

        // Calculate center point and dimensions
        $faces[] = [
            'x' => ($vertices[0]['x'] + $vertices[2]['x']) / 2,
            'y' => ($vertices[0]['y'] + $vertices[2]['y']) / 2,
            'confidence' => $face->detectionConfidence(),
            'weight' => 3.0 * $face->detectionConfidence(),
        ];
    }

    return $faces;
}
```

**Option C: PHP-based Libraries**
```php
// Use: facedetection/detection package
protected function detectFacesSimple(ImageInterface $image): array
{
    $detector = new \FaceDetector\Detector();
    $detector->faceDetect($image->core()->native());

    $faces = $detector->getFaceCoordinates();

    return array_map(function($face) {
        return [
            'x' => $face['x'] + $face['w'] / 2,
            'y' => $face['y'] + $face['h'] / 2,
            'weight' => 2.5, // High priority
        ];
    }, $faces);
}
```

**Benefits:**
- Ensures faces are never cropped
- Essential for portrait and group photos
- Significantly improves user experience

**Recommended Implementation:**
1. Start with Option C (simplest, no external dependencies)
2. Add Option B as optional (configurable API key)
3. Add Option A for advanced users (requires extension)

---

### Phase 4: Saliency Map (Priority: Low)

**Goal:** Generate a visual attention map showing where humans naturally look.

**Implementation:**
```php
protected function generateSaliencyMap(ImageInterface $image): array
{
    // Combine multiple factors:
    // 1. Edge detection results
    // 2. Color contrast
    // 3. Brightness variations
    // 4. Face detection (if available)

    $edges = $this->detectEdges($image);
    $entropy = $this->calculateEntropy($image);
    $faces = $this->detectFaces($image);

    // Weighted combination
    $saliencyMap = [];

    foreach ($edges as $point) {
        $saliency =
            $point['edgeStrength'] * 0.3 +
            $this->getEntropyAt($entropy, $point['x'], $point['y']) * 0.3 +
            $this->getFaceProximity($faces, $point['x'], $point['y']) * 0.4;

        $saliencyMap[] = [
            'x' => $point['x'],
            'y' => $point['y'],
            'saliency' => $saliency,
            'weight' => $saliency,
        ];
    }

    return $saliencyMap;
}
```

**Benefits:**
- Most comprehensive approach
- Mimics human visual attention
- Best results for complex images

---

### Phase 5: Machine Learning Integration (Priority: Future)

**Goal:** Use pre-trained models to identify objects and subjects.

**Potential Libraries:**
- TensorFlow PHP
- ONNX Runtime PHP
- Cloud ML APIs (Google Cloud Vision, AWS Rekognition)

**Example:**
```php
protected function detectObjects(ImageInterface $image): array
{
    // Use object detection model (YOLO, SSD, etc.)
    // Identify: people, animals, vehicles, landmarks, etc.
    // Weight by object importance and size

    return [
        ['type' => 'person', 'x' => 120, 'y' => 80, 'confidence' => 0.95, 'weight' => 3.0],
        ['type' => 'dog', 'x' => 200, 'y' => 150, 'confidence' => 0.89, 'weight' => 2.5],
        ['type' => 'building', 'x' => 300, 'y' => 100, 'confidence' => 0.78, 'weight' => 1.5],
    ];
}
```

---

## Configuration Options

Add these to `config/thumbnails.php` for advanced smart crop:

```php
'smart_crop' => [
    'enabled' => true,

    // Algorithm selection
    'algorithm' => 'auto', // auto, rule_of_thirds, edge_detection, entropy, saliency

    // Feature toggles
    'use_edge_detection' => true,
    'use_entropy_analysis' => true,
    'use_face_detection' => true,
    'use_saliency_map' => false,

    // Face detection options
    'face_detection' => [
        'method' => 'simple', // simple, opencv, api
        'api_provider' => null, // google, aws, azure
        'api_key' => env('VISION_API_KEY'),
        'cascade_path' => storage_path('ml/haarcascade_frontalface_default.xml'),
    ],

    // Weights for combining multiple algorithms
    'weights' => [
        'rule_of_thirds' => 0.2,
        'edges' => 0.3,
        'entropy' => 0.2,
        'faces' => 0.3,
    ],

    // Performance settings
    'max_analysis_size' => 800, // Analyze at smaller size for performance
    'grid_size' => 5, // For entropy analysis
    'enable_cache' => true, // Cache analysis results
    'cache_ttl' => 3600,
],
```

---

## Implementation Priorities

### Must Have (Phase 1)
- ✅ Rule of thirds (DONE)
- 🔲 Edge detection with Sobel filter
- 🔲 Basic face detection

### Should Have (Phase 2)
- 🔲 Entropy analysis
- 🔲 Configurable algorithm weights
- 🔲 Cache analysis results

### Nice to Have (Phase 3)
- 🔲 Saliency maps
- 🔲 API-based face detection
- 🔲 Object detection

### Future Considerations
- 🔲 Machine learning models
- 🔲 Custom training for specific use cases
- 🔲 Real-time preview in admin panel

---

## Performance Considerations

1. **Analyze at smaller resolution:**
   - Resize to max 800px before analysis
   - Much faster, minimal quality loss

2. **Cache analysis results:**
   - Store interest points in cache
   - Reuse for multiple variants

3. **Progressive enhancement:**
   - Start with rule of thirds
   - Add edge detection if available
   - Add face detection if configured

4. **Async processing:**
   - Queue complex analysis
   - Use simple algorithm for real-time

---

## Testing Strategy

### Unit Tests
```php
public function test_edge_detection_finds_high_contrast_areas()
{
    $image = $this->createTestImage();
    $service = new SmartCropService();
    $edges = $service->detectEdges($image);

    $this->assertNotEmpty($edges);
    $this->assertArrayHasKey('edgeStrength', $edges[0]);
}

public function test_face_detection_finds_faces()
{
    $imageWithFace = $this->loadTestImage('portrait.jpg');
    $service = new SmartCropService();
    $faces = $service->detectFaces($imageWithFace);

    $this->assertGreaterThan(0, count($faces));
}
```

### Integration Tests
```php
public function test_smart_crop_preserves_faces()
{
    $portrait = $this->loadTestImage('portrait.jpg');
    $cropped = $this->thumbnailService
        ->set('portraits')
        ->src($portrait)
        ->generateCropped(500, 500);

    // Verify face is still visible in cropped version
    $faces = $this->detectFaces($cropped);
    $this->assertGreaterThan(0, count($faces));
}
```

### Visual Regression Tests
- Generate test suite of before/after crops
- Compare with reference images
- Flag significant differences for review

---

## Contributing

If you'd like to implement any of these enhancements:

1. Open an issue to discuss the approach
2. Fork the repository
3. Create a feature branch
4. Add tests for the new algorithm
5. Update documentation
6. Submit a pull request

### Code Style
- Follow PSR-12 coding standards
- Add PHPDoc comments for all public methods
- Include inline comments for complex logic
- Use type hints for all parameters and return values

---

## Resources

### Libraries
- [Intervention Image](https://image.intervention.io/) - Image manipulation
- [PHP-ML](https://php-ml.readthedocs.io/) - Machine learning
- [OpenCV PHP](https://github.com/php-opencv/php-opencv) - Computer vision
- [facedetection/detection](https://github.com/mauricesvay/php-facedetection) - Simple face detection

### Algorithms
- [Sobel Operator](https://en.wikipedia.org/wiki/Sobel_operator) - Edge detection
- [Shannon Entropy](https://en.wikipedia.org/wiki/Entropy_(information_theory)) - Information theory
- [Viola-Jones](https://en.wikipedia.org/wiki/Viola%E2%80%93Jones_object_detection_framework) - Face detection
- [Saliency Map](https://en.wikipedia.org/wiki/Saliency_map) - Visual attention

### APIs
- [Google Cloud Vision](https://cloud.google.com/vision/docs/detecting-faces)
- [AWS Rekognition](https://aws.amazon.com/rekognition/)
- [Azure Face API](https://azure.microsoft.com/en-us/services/cognitive-services/face/)

---

**Last Updated:** December 2025
**Maintainer:** Askancy
**Status:** Planning Phase
