# vsOCR
PHP OCR wrapper for tesseract


vsOCR is a PHP class designed for optical character recognition (OCR) from various types of files, including video and document formats. This class leverages the parallel extension for multithreading and CUDA acceleration for improved performance during OCR extraction.

# Features
- Multi-threading: Utilizes PHP's parallel extension to enhance OCR processing speed.
- CUDA Acceleration: Detects and uses CUDA for faster frame extraction from videos.
- Multiple File Support: Processes video files (mp4, avi, mpeg, m4v, mov) and document/image files (pdf, jpg, jpeg, png, gif, ai, psd).
- Duplicate Text Removal: Removes duplicate or highly similar text entries to ensure clean output.
- Progress Updates: Supports progress update callbacks to monitor the extraction process.

## Requirements
- PHP 7.4 or higher
- `parallel` extension (optional for multithreading)
- `ffmpeg` for video frame extraction
- `tesseract-ocr` for text recognition
- `ImageMagick` for image processing

## Installation
- Install PHP and required extensiosn
     ```sh
     sudo apt-get install php php-cli php-parallel
- Install FFMPEG
  ```sh
  sudo apt-get install ffmpeg
- Install Tesseract OCR
  ```sh
  sudo apt-get install tesseract-ocr
- Install ImageMagick
  ```sh
  sudo apt-get install imagemagick

# Usage
- Initialize vsOCR
  ```php
  require 'path/to/vsOCR.php';

  $filePath = 'path/to/your/file.pdf';
  $ocr = new vsOCR($filePath);


- Extract Frames or Pages
  Will auto detect based on file extension and use the correct method for frame/page extraction
  ```php
  $ocr->extract();

- Extract Text
  ```php
  $trim = true; // Removing white space around text
  $byPage = false; // If multi-page doc or video, will split out for each page/frame being its own block of text
  $removeDups = false; // Remove duplicate words, if you want unique word text block
  $ocr->extractText($trim, $byPage, $removeDups);

- Clean Up
  ```php
  $ocr->cleanup();


## Example (Doc)
  ```php
  require('vsOCR.php');

  $filePath = 'example.pdf';
  $ocr = new vsOCR($filePath);
  $ocr->output_dir = sys_get_temp_dir();

  if($ocr->extract()){
    $ocr->extractText(true, false, true);
    print_r($ocr->text);
    $ocr->cleanup();
  }
  else {
    echo 'Unable to extract content';
  }
  ```


## Example (Video)
  ```php
  require('vsOCR.php');

  $filePath = 'example.mp4';
  $ocr = new vsOCR($filePath);
  $ocr->output_dir = sys_get_temp_dir();

  if($ocr->extract()){
    $ocr->extractText(true, true, true);
    print_r($ocr->text);
    $ocr->cleanup();
  }
  else {
    echo 'Unable to extract content';
  }
  ```

# Methods
`__construct($filePath)`
Initializes the vsOCR object with the specified file path.

`extract($placeholders=4)`
Automatically detects the file type and extracts frames (for videos) or pages (for documents).

`extractFrames($placeholders=4)`
Extracts frames from a video file.

`extractPages()`
Extracts pages from a document/image file.

`extractText($trim=true, $byPage=false, $removeDups=false)`
Extracts text from the extracted frames or pages. Will auto detect parallel and select the best method.

`extractTextThread($trim=true, $byPage=false, $removeDups=false)`
Multithreaded version of text extraction.

`extractTextSingle($trim=true, $byPage=false, $removeDups=false)`
Single-threaded version of text extraction.

`cleanup()`
Cleans up the extracted frames or pages from the temporary directory.
