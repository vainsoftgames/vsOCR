<?php
	use parallel\Runtime;
	use parallel\Future;

	class vsOCR {
		public $file;
		public $imgs;
		public $pages;
		public $text;
		public $type;
		public $uniqID;
		public $output_dir = '/var/www/html/Convert/tmp';
		public $progressUpdate;
		public $threads;
		public $threads_enabled;

		public function __construct($filePath){
			$this->file = $filePath;
			$this->pages = 0;
			$this->imgs = [];
			$this->text = '';
			$this->uniqID = time();
			$this->threads = (int)ceil(shell_exec('nproc') / 3);
			$this->threads_enabled = extension_loaded('parallel');
		}
		
		private function cuda_ffmpeg(){
			return (strpos(shell_exec('ffmpeg -hwaccels 2>&1'), 'cuda') !== false);
		}

		private function splitStringByMultipleDelimiters($string, $delimiters) {
			$pattern = implode('|', array_map('preg_quote', $delimiters));
			return preg_split("/$pattern/", $string);
		}
		private function processOCRText(&$ocrTextArray, $uniqueOnly = false) {
			$separators = [',', "\n", "\r\n", '|', '(', ')']; // Add more separators as needed
			$uniqueEntries = [];

			foreach ($ocrTextArray as $entry) {
				$entry = trim($entry);
				$sections = $this->splitStringByMultipleDelimiters($entry, $separators);

				foreach ($sections as $section) {
					$trimmedSection = trim($section);
					$trimmedSection = trim($trimmedSection, '.');

					if ($uniqueOnly) {
						if (!in_array($trimmedSection, $uniqueEntries)) {
							$uniqueEntries[] = $trimmedSection;
						}
					}
					else {
						$uniqueEntries[] = $trimmedSection;
					}
				}
			}

			$ocrTextArray = $uniqueEntries;
		}


		// Instead of detecting which method to use, have it auto detect
		public function extract($placeholders=4){
			if(!file_exists($this->file)) return false;

			$fileEXT = strtolower(pathinfo($this->file, PATHINFO_EXTENSION));
			if(in_array($fileEXT, ['mp4','avi','mpeg','m4v','mov'])) return $this->extractFrames($placeholders);
			else if(in_array($fileEXT, ['pdf','jpg','jpeg','png','gif','ai','psd'])) return $this->extractPages();
			else return false;
		}

		// Video method for extraction
		public function extractFrames($placeholders=4){
			if(!file_exists($this->file)) return false;
			$this->type = 'vid';

			$cmd = [];
			$cmd[] = 'ffmpeg';
			if($this->cuda_ffmpeg()){
				$cmd[] = '-hwaccel cuda';
			}
			$cmd[] = '-i '. escapeshellarg($this->file);
			$cmd[] = '-vf "setsar=1, fps=5"';
			$cmd[] = '-q:v 9';
			$cmd[] = '-threads 4';
			$cmd[] = escapeshellarg($this->output_dir .'/output_'. $this->uniqID .'-%0'. $placeholders .'d.jpg');

			exec(implode(' ', $cmd));

			$num_pages = count(glob("{$this->output_dir}/output_{$this->uniqID}-*.jpg"));
			if($num_pages == 0) return false;

			$this->pages = $num_pages;

			for ($i = 0; $i < $num_pages; $i++) {
				$this->imgs[] = "{$this->output_dir}/output_{$this->uniqID}-". str_pad($i, $placeholders, 0, STR_PAD_LEFT) .".jpg";
			}

			return ($this->pages > 0);
		}

		// Document method for extraction
		public function extractPages(){
			if(!file_exists($this->file)) return false;
			$this->type = 'doc';

			$cmd = [];
			$cmd[] = 'convert';
			// Helps with reducing warning messages that dont hurt the end results
			$cmd[] = '-quiet';
			// Need to modify /etc/ImageMagick-6/policy.xml disk policy to 8gb, D=300 uses more memory
			$cmd[] = '-density 600';
			// Some ducments freak out if you dont give it a size
			$cmd[] = '-resize 7680x4320';
//                      $cmd[] = '-resize 1920x1080';
			// Helpful on multi-layer documents, sets the background to white and removes background transparency
			$cmd[] = '-background White';
			$cmd[] = '-alpha background';
			$cmd[] = '-alpha off +adjoin';
			// removes padding around document
			$cmd[] = '-trim';
			// Flatten makes a multi-page document into a single doc, so no go!
//                      $cmd[] = '-flatten';
			$cmd[] = escapeshellarg($this->file);
			$cmd[] = '-quality 90';
			$cmd[] = escapeshellarg($this->output_dir .'/output_'. $this->uniqID .'-%d.jpg');

			exec(implode(' ', $cmd));

			$num_pages = count(glob("{$this->output_dir}/output_{$this->uniqID}-*.jpg"));
			if($num_pages == 0) return false;

			$this->pages = $num_pages;

			for ($i = 0; $i < $num_pages; $i++) {
				$this->imgs[] = "{$this->output_dir}/output_{$this->uniqID}-$i.jpg";
			}

			return ($this->pages > 0);
		}

		
		public function extractText($trim=true, $byPage=false, $removeDups=false){
			if($this->pages == 0) return false;

			if($byPage) $this->text = [];
			else $this->text = '';

			if($this->threads_enabled) return $this->extractTextThread($trim, $byPage, $removeDups);
			else return $this->extractTextSingle($trim, $byPage, $removeDups);
		}
		
		
		public function extractTextThread($trim = true, $byPage = false, $removeDups = false) {
			$runtimes = [];
			$futures = [];
			$wordCount = 0;
			$completedPages = 0;

			for ($i = 0; $i < $this->pages; $i++) {
				if (!file_exists($this->imgs[$i])) continue;

				$runtime = new Runtime();
				$runtimes[] = $runtime;

				$futures[] = $runtime->run(function ($image, $trim) {
					$cmd = [];
					$cmd[] = 'tesseract';
					$cmd[] = escapeshellarg($image);
					$cmd[] = 'stdout';
					$cmd[] = '-l eng';

					$v = shell_exec(implode(' ', $cmd));

					if ($trim) {
						$v = str_replace(["\n\n", "\n \n"], "\n", $v);
						$v = str_replace("\f", '', $v);
						$v = trim($v);
					}

					return $v;
				}, [$this->imgs[$i], $trim]);

				if (count($futures) == $this->threads || $i == $this->pages - 1) {
					foreach ($futures as $future) {
						$v = $future->value();

						if ($byPage) $this->text[] = $v;
						else $this->text .= $v;

						if (!$removeDups) $wordCount += str_word_count($v);
						$completedPages++;

						if (isset($this->progressUpdate) && is_callable($this->progressUpdate)) {
							call_user_func($this->progressUpdate, $this->pages, $completedPages);
						}
					}
					$futures = [];
				}
			}

			if ($removeDups) {
				$this->processOCRText($this->text, true);
				for ($i = 0; $i < count($this->text); $i++) {
					$wordCount += str_word_count($this->text[$i]);
				}
			}

			return !($wordCount == 0);
		}
		
		
		public function extractTextSingle($trim=true, $byPage=false, $removeDups=false){
			$fileExt = pathinfo($this->file, PATHINFO_EXTENSION);

			$wordCount = 0;
			for($i=0; $i < $this->pages; $i++){
				if(!file_exists($this->imgs[$i])) { continue; }

				$cmd = [];
				$cmd[] = 'tesseract';
				$cmd[] = escapeshellarg($this->imgs[$i]);
				$cmd[] = 'stdout';
				$cmd[] = '-l eng';
				$cmd[] = '$fileExt';

				$v = shell_exec(implode(' ', $cmd));

				if($trim) {
					$v = str_replace(["\n\n", "\n \n"], "\n", $v);
					$v = str_replace("\f", '', $v);
					$v = trim($v);
				}

				if($byPage) $this->text[] = $v;
				else $this->text .= $v;

				if(!$removeDups) $wordCount += str_word_count($v);
				// Call the progress update callback if it is set
				if (isset($this->progressUpdate) && is_callable($this->progressUpdate)) {
					call_user_func($this->progressUpdate, $this->pages, $i);
				}
			}

			if($removeDups){
				$this->processOCRText($this->text, true);
				for($i=0; $i < count($this->text); $i++){
					$wordCount += str_word_count($this->text[$i]);
				}
			}

			return !($wordCount == 0);
		}


		public function cleanup(){
			if(count($this->imgs) > 0){
				foreach($this->imgs as $imgPath){
					@unlink($imgPath);
				}
			}
		}
	}
?>
