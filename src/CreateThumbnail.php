<?php
/**
 * Creates thumbnail through either ImageMagick or GD
 *
 * @copyright 2010-2016 phlyLabs, Berlin (http://phlylabs.de)
 */

namespace Phlylabs\CreateThumbnail;

class CreateThumbnail
{
    /**
     * @var int $jpegCompressionQuality When using JPEG as output define the quality of the compression
     */
    protected $jpegCompressionQuality = 100;

    /**
     * @var int $sourceMaxBytes max. allowed size in bytes of the source file; adapt to your system's setup
     */
    protected $sourceMaxBytes = 500000;

    /**
     * @var int $sourceMaxPixels max. number of pixels, the source image is allowed to measure (width x height) 
     */
    protected $sourceMaxPixels = 20000000;

    /**
     * @var int $targetWidth max. width of the thumbnail's bounding box; adjusted by the longest side
     */
    protected $targetWidth = 32;
    
    /**
     * @var int $targetHeight max. height of the thumbnail's bounding box; adjusted by the longest side
     */
    protected $targetHeight = 32;

    /**
     * @var string $targetType, one of JPEG, PNG, GIF
     */
    protected $targetType = 'JPEG';

    public function __construct()
    {
        return $this;
    }
    
    /**
     * Creates a thumbnail from a source file and returns it with some info as a string.
     * If the source is not processable or not accessible the function returns false
     *
     * @param string $sourceFile Path to the source file
     * @param string $targetFile Path to the thumbnail file
     * @return bool
     * @throws CreateThumbnailException
     */
    public function create($sourceFile, $targetFile)
    {
        // File not accessible
        if (!file_exists($sourceFile) || !is_readable($sourceFile)) {
            throw new CreateThumbnailException(sprintf('File %s not found or not readable', $sourceFile));
        }

        $generated = false;
        // File supposedly too large
        $sourceFileSize = filesize($sourceFile);
        if ($sourceFileSize > $this->sourceMaxBytes) {

            throw new CreateThumbnailException(sprintf('File too large: %d bytes, allowed: %d bytes', $sourceFileSize, $this->sourceMaxBytes));
        }

        // Try ImageMagick on command line
        try {
            exec(sprintf(
                    'convert %s -resize %dx%d -background white -alpha remove -quality %d %s:%s',
                    $sourceFile,
                    $this->targetWidth,
                    $this->targetHeight,
                    $this->jpegCompressionQuality,
                    \strtolower($this->targetType),
                    $targetFile
            ));
            if (file_exists($targetFile)
                    && is_readable($targetFile)
                    && filesize($targetFile)) {
                $generated = true;
            }
        } catch (\Exception $e) {
            // regular exceptions are caught to try the next mechanism
        }

        // Try Imagick's PHP API
        // This is really much of a shot in the dark, no checking, nothing!
        if (!$generated && class_exists('Imagick')) {
            // path to the sRGB ICC profile
            $srgbPath = __DIR__.'/srgb_v4_icc_preference.icc';
            // load the original image
            $image = new \Imagick($sourceFile);

            // get the original dimensions
            $owidth = $image->getImageWidth();
            $oheight = $image->getImageHeight();
            if ($owidth * $oheight < $this->sourceMaxPixels) {
                // set colour profile
                // this step is necessary even though the profiles are stripped out in the next step to reduce file size
                $srgb = file_get_contents($srgbPath);
                $image->profileImage('icc', $srgb);
                // strip colour profiles
                $image->stripImage();
                // set colorspace
                $image->setImageColorspace(\Imagick::COLORSPACE_SRGB);
                // determine which dimension to fit to
                $fitWidth = ($this->targetWidth / $owidth) < ($this->targetHeight / $oheight);
                // create thumbnail
                $image->thumbnailImage($fitWidth ? $this->targetWidth : 0, $fitWidth ? 0 : $this->targetHeight);

                $image->setImageFormat(strtolower($this->targetType));
                if ($this->targetType == 'JPEG') {
                    $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
                    $image->setImageCompressionQuality($this->jpegCompressionQuality);
                }

                $image->writeImage($targetFile);
                $image->clear();
                $image->destroy();

                if (file_exists($targetFile)
                        && is_readable($targetFile)
                        && filesize($targetFile)) {
                    $generated = true;
                }
            } else {

                throw new CreateThumbnailException(sprintf('File pixel count is too large: %d x %d', $owidth, $oheight));
            }
        }

        // Try GD
        if (!$generated && function_exists('imagecreatetruecolor')) {
            $ii = @getimagesize($sourceFile);
            // Only try creating the thumbnail with the correct GD support.
            // GIF got dropped a while ago, then reappeared again; JPEG or PNG might not be compiled in
            if ($ii[2] == 1 && !function_exists('imagecreatefromgif')) {
                $ii[2] = 0;
            }
            if ($ii[2] == 2 && !function_exists('imagecreatefromjpeg')) {
                $ii[2] = 0;
            }
            if ($ii[2] == 3 && !function_exists('imagecreatefrompng')) {
                $ii[2] = 0;
            }
            if ($ii[2] == 15 && !function_exists('imagecreatefromwbmp')) {
                $ii[2] = 0;
            }
            // a supported source image file type, pixel dimensions small enough and source file not too big
            if (!empty($ii[2]) && $ii[0]*$ii[1] < $this->sourceMaxPixels) {
                $ti = $ii;
                if ($ti[0] > $this->targetWidth || $ti[1] > $this->targetHeight) {
                    $wf = $ti[0] / $this->targetWidth; // Calculate width factor
                    $hf = $ti[1] / $this->targetHeight; // Calculate height factor
                    if ($wf >= $hf && $wf > 1) {
                        $ti[0] /= $wf;
                        $ti[1] /= $wf;
                    } elseif ($hf > 1) {
                        $ti[0] /= $hf;
                        $ti[1] /= $hf;
                    }
                    $ti[0] = round($ti[0], 0);
                    $ti[1] = round($ti[1], 0);
                }
                if ($ii[2] == 1) {
                    $si = imagecreatefromgif($sourceFile);
                } elseif ($ii[2] == 2) {
                    $si = imagecreatefromjpeg($sourceFile);
                } elseif ($ii[2] == 3) {
                    $si = imagecreatefrompng($sourceFile);
                    imagesavealpha($si, true);
                } elseif ($ii[2] == 15) {
                    $si = imagecreatefromwbmp($sourceFile);
                }
                if (!empty($si)) {
                    $tn = imagecreatetruecolor($ti[0], $ti[1]);
                    // The following four lines prevent transparent source images from being converted with a black background
                    imagealphablending($tn, false);
                    imagesavealpha($tn, true);
                    $transparent = imagecolorallocatealpha($tn, 255, 255, 255, 0);
                    imagefilledrectangle($tn, 0, 0, $ti[0], $ti[1], $transparent);
                    //
                    imagecopyresized($tn, $si, 0, 0, 0, 0, $ti[0], $ti[1], $ii[0], $ii[1]);
                    // Get the thumbnail
                    ob_start();
                    if (imagetypes() & IMG_JPG && $this->targetType == 'JPEG') {
                        imagejpeg($tn, null, $this->jpegCompressionQuality);
                    } elseif (imagetypes() & IMG_PNG && $this->targetType == 'PNG') {
                        imagepng($tn, null);
                    } elseif (imagetypes() & IMG_GIF && $this->targetType == 'GIF') {
                        imagegif($tn, null);
                    } else {

                        throw new CreateThumbnailException(sprintf('Your desired output type %s is not available', $this->targetType));
                    }
                    file_put_contents($targetFile, ob_get_clean());

                    imagedestroy($tn);
                }
            } else {
                
                throw new CreateThumbnailException(sprintf('File pixel count is too large: %d x %d', $ii[0], $ii[1]));
            }
        } elseif (!$generated) {

            throw new CreateThumbnailException('GD library not installed, ImageMagick neither');
        }

        return true;
    }

    /**
     * @return int
     */
    public function getJpegCompressionQuality()
    {
        return $this->jpegCompressionQuality;
    }

    /**
     * @param int $jpegCompressionQuality
     * @return CreateThumbnail
     */
    public function setJpegCompressionQuality($jpegCompressionQuality)
    {
        $this->jpegCompressionQuality = $jpegCompressionQuality;
        return $this;
    }

    /**
     * @return int
     */
    public function getSourceMaxBytes()
    {
        return $this->sourceMaxBytes;
    }

    /**
     * @param int $sourceMaxBytes
     * @return CreateThumbnail
     */
    public function setSourceMaxBytes($sourceMaxBytes)
    {
        $this->sourceMaxBytes = $sourceMaxBytes;
        return $this;
    }

    /**
     * @return int
     */
    public function getSourceMaxPixels()
    {
        return $this->sourceMaxPixels;
    }

    /**
     * @param int $sourceMaxPixels
     * @return CreateThumbnail
     */
    public function setSourceMaxPixels($sourceMaxPixels)
    {
        $this->sourceMaxPixels = $sourceMaxPixels;
        return $this;
    }

    /**
     * @return int
     */
    public function getTargetWidth()
    {
        return $this->targetWidth;
    }

    /**
     * @param int $targetWidth
     * @return CreateThumbnail
     */
    public function setTargetWidth($targetWidth)
    {
        $this->targetWidth = $targetWidth;
        return $this;
    }

    /**
     * @return int
     */
    public function getTargetHeight()
    {
        return $this->targetHeight;
    }

    /**
     * @param int $targetHeight
     * @return CreateThumbnail
     */
    public function setTargetHeight($targetHeight)
    {
        $this->targetHeight = $targetHeight;
        return $this;
    }

    /**
     * @return string
     */
    public function getTargetType()
    {
        return $this->targetType;
    }

    /**
     * @param string $targetType
     * @return CreateThumbnail
     */
    public function setTargetType($targetType)
    {
        $targetType = strtoupper($targetType);
        if ($targetType == 'JPG') {
            $targetType = 'JPEG';
        }
        $this->targetType = $targetType;

        return $this;
    }
    
}