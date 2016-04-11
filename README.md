# CreateThumbnail
This is just a simple class to create a thumbnail from a given source image.

You'll need one of these packages installed and available:

- the "convert" command line tool from ImageMagick
- the ImageMagick PHP extension
- the GD libarary PHP extension

Depending on your choices of these tools the available source and target image formats vary. JPEG always is a safe bet, but in properly set up environments most of the usual formats like GIF or PNG will be avilable as well.

Purpose of this class is only thumbnail generation, so there's not many options available.

Usage example:

```php
use Phlylabs\CreateThumbnail\CreateThumbnail;

$thumbnail = new CreateThumbnail();

$thumbnail->setTargetWidth($width)
        ->setTargetHeight($height)
        ->setTargetType('JPEG')
        ->setJpegCompressionQuality(90)
        ->create($inputFile, $outputFile);
```

Need anything? Just drop me a line

- Matthias
