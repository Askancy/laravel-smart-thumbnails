/**
 * Client-side smart crop implementation
 * Implementazione JavaScript dell'algoritmo dont-crop
 */
class SmartCrop {
  constructor(options = {}) {
    this.options = {
      enableFaceDetection: false,
      minScale: 1.0,
      maxScale: 1.0,
      ...options,
    };
  }

  /**
   * Analizza un'immagine e trova la migliore area di crop
   */
  async analyze(imageElement, targetWidth, targetHeight) {
    return new Promise((resolve) => {
      const canvas = document.createElement("canvas");
      const ctx = canvas.getContext("2d");

      canvas.width = imageElement.naturalWidth;
      canvas.height = imageElement.naturalHeight;

      ctx.drawImage(imageElement, 0, 0);

      const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
      const result = this.calculateBestCrop(
        imageData,
        targetWidth,
        targetHeight
      );

      resolve(result);
    });
  }

  /**
   * Calcola la migliore area di crop basata su algoritmi di saliency
   */
  calculateBestCrop(imageData, targetWidth, targetHeight) {
    const { width, height, data } = imageData;

    // Calcola le dimensioni del crop
    const originalRatio = width / height;
    const targetRatio = targetWidth / targetHeight;

    let cropWidth, cropHeight;

    if (originalRatio > targetRatio) {
      cropHeight = height;
      cropWidth = height * targetRatio;
    } else {
      cropWidth = width;
      cropHeight = width / targetRatio;
    }

    // Trova la posizione ottimale
    const bestPosition = this.findBestPosition(
      imageData,
      cropWidth,
      cropHeight
    );

    return {
      x: bestPosition.x,
      y: bestPosition.y,
      width: cropWidth,
      height: cropHeight,
    };
  }

  /**
   * Trova la posizione migliore analizzando l'energia dell'immagine
   */
  findBestPosition(imageData, cropWidth, cropHeight) {
    const { width, height, data } = imageData;
    const maxX = width - cropWidth;
    const maxY = height - cropHeight;

    let bestScore = -1;
    let bestX = 0;
    let bestY = 0;

    // Campiona diverse posizioni (ottimizzazione: non tutte le posizioni)
    const step = Math.max(1, Math.floor(Math.min(maxX, maxY) / 20));

    for (let x = 0; x <= maxX; x += step) {
      for (let y = 0; y <= maxY; y += step) {
        const score = this.calculateCropScore(
          imageData,
          x,
          y,
          cropWidth,
          cropHeight
        );

        if (score > bestScore) {
          bestScore = score;
          bestX = x;
          bestY = y;
        }
      }
    }

    return { x: bestX, y: bestY };
  }

  /**
   * Calcola lo score di una determinata area di crop
   */
  calculateCropScore(imageData, x, y, cropWidth, cropHeight) {
    const { width, data } = imageData;
    let totalEnergy = 0;
    let pixelCount = 0;

    // Analizza un sottocampione dell'area per performance
    const sampleStep = Math.max(
      1,
      Math.floor(Math.min(cropWidth, cropHeight) / 50)
    );

    for (let dx = 0; dx < cropWidth; dx += sampleStep) {
      for (let dy = 0; dy < cropHeight; dy += sampleStep) {
        const px = x + dx;
        const py = y + dy;

        if (px < width - 1 && py < imageData.height - 1) {
          const energy = this.calculatePixelEnergy(imageData, px, py);
          totalEnergy += energy;
          pixelCount++;
        }
      }
    }

    return pixelCount > 0 ? totalEnergy / pixelCount : 0;
  }

  /**
   * Calcola l'energia di un pixel (gradient magnitude)
   */
  calculatePixelEnergy(imageData, x, y) {
    const { width, data } = imageData;

    const getPixel = (px, py) => {
      const index = (py * width + px) * 4;
      return {
        r: data[index],
        g: data[index + 1],
        b: data[index + 2],
      };
    };

    const current = getPixel(x, y);
    const right = getPixel(x + 1, y);
    const bottom = getPixel(x, y + 1);

    // Calcola i gradienti
    const gradX =
      Math.abs(current.r - right.r) +
      Math.abs(current.g - right.g) +
      Math.abs(current.b - right.b);

    const gradY =
      Math.abs(current.r - bottom.r) +
      Math.abs(current.g - bottom.g) +
      Math.abs(current.b - bottom.b);

    return Math.sqrt(gradX * gradX + gradY * gradY);
  }

  /**
   * Utility per visualizzare il crop suggerito su un canvas
   */
  visualizeCrop(imageElement, cropArea, canvasElement) {
    const ctx = canvasElement.getContext("2d");

    canvasElement.width = imageElement.naturalWidth;
    canvasElement.height = imageElement.naturalHeight;

    // Disegna l'immagine originale
    ctx.drawImage(imageElement, 0, 0);

    // Overlay semi-trasparente
    ctx.fillStyle = "rgba(0, 0, 0, 0.5)";
    ctx.fillRect(0, 0, canvasElement.width, canvasElement.height);

    // Area di crop evidenziata
    ctx.clearRect(cropArea.x, cropArea.y, cropArea.width, cropArea.height);
    ctx.drawImage(
      imageElement,
      cropArea.x,
      cropArea.y,
      cropArea.width,
      cropArea.height,
      cropArea.x,
      cropArea.y,
      cropArea.width,
      cropArea.height
    );

    // Bordo del crop
    ctx.strokeStyle = "#00ff00";
    ctx.lineWidth = 2;
    ctx.strokeRect(cropArea.x, cropArea.y, cropArea.width, cropArea.height);
  }
}

// Export per uso in Node.js se disponibile
if (typeof module !== "undefined" && module.exports) {
  module.exports = SmartCrop;
}
