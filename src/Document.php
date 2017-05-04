<?php

namespace Billdu\Wkhtmltopdf;


/**
 * @author Martin Bažík <martin@bazo.sk>
 */
class Document
{

	/** @var string	NULL means autodetect */
	public static $executable;

	/** @var array possible executables */
	public static $executables = ['wkhtmltopdf', 'wkhtmltopdf-amd64', 'wkhtmltopdf-i386'];

	/** @var int */
	public $dpi = 200;

	/** @var array */
	public $margin = [10, 10, 10, 10];

	/** @var string */
	public $orientation = 'portrait';

	/** @var string */
	public $size = 'A4';

	/** @var float */
	public $zoom;

	/** @var string */
	public $title;

	/** @var string */
	public $encoding;

	/** @var bool */
	public $usePrintMediaType = TRUE;

	/** @var string */
	public $styleSheet;

	/** @var bool */
	public $disableSmartShrinking = TRUE;

	/** @var bool */
	public $disableInternalLinks = TRUE;

	/** @var PageMeta */
	private $header;

	/** @var PageMeta */
	private $footer;

	/** @var array|Page[] */
	private $pages = [];

	/** @var string */
	public $tmpDir;

	/** @var array */
	private $tmpFiles = [];

	/** @var resource */
	private $p;

	/** @var array */
	private $pipes;

	/**
	 * @param string
	 */
	public function __construct($tmpDir)
	{
		$this->tmpDir = $tmpDir;
	}


	/**
	 * @return PageMeta
	 */
	public function getHeader()
	{
		if ($this->header === NULL) {
			$this->header = new PageMeta('header');
		}
		return $this->header;
	}


	/**
	 * @return PageMeta
	 */
	public function getFooter()
	{
		if ($this->footer === NULL) {
			$this->footer = new PageMeta('footer');
		}
		return $this->footer;
	}


	/**
	 * @param  string
	 * @param  bool
	 * @return Page
	 */
	public function addHtml($html, $isCover = FALSE)
	{
		$this->pages[]	 = $page			 = $this->createPage();
		$page->html		 = $html;
		$page->isCover	 = $isCover;
		return $page;
	}


	/**
	 * @param string
	 * @param bool
	 * @return Page
	 */
	public function addFile($file, $isCover = FALSE)
	{
		$this->pages[]	 = $page			 = $this->createPage();
		$page->file		 = $file;
		$page->isCover	 = $isCover;
		return $page;
	}


	/**
	 * @param  string
	 * @return Toc
	 */
	public function addToc($header = NULL)
	{
		$this->pages[]	 = $toc			 = new Toc;
		if ($header !== NULL) {
			$toc->header = $header;
		}
		return $toc;
	}


	/**
	 * @param  IDocumentPart
	 * @return Document
	 */
	public function addPart(IDocumentPart $part)
	{
		$this->pages[] = $part;
		return $this;
	}


	/**
	 * @return Page
	 */
	private function createPage()
	{
		$page					 = new Page;
		$page->encoding			 = $this->encoding;
		$page->usePrintMediaType = $this->usePrintMediaType;
		$page->styleSheet		 = $this->styleSheet;
		return $page;
	}


	/**
	 * @internal
	 * @param  string
	 * @return string
	 */
	public function saveTempFile($content)
	{
		do {
			$file = $this->tmpDir . '/' . md5($content . '.' . lcg_value()) . '.html';
		} while (file_exists($file));
		file_put_contents($file, $content);
		return $this->tmpFiles[] = $file;
	}


	/**
	 * Save PDF document to file.
	 * @param  string
	 * @throws \RuntimeException
	 */
	public function save($file)
	{
		$f = fopen($file, 'w');
		$this->convert();
		stream_copy_to_stream($this->pipes[1], $f);
		fclose($f);
		$this->close();
	}


	/**
	 * Returns PDF document as string.
	 * @return string
	 */
	public function __toString()
	{
		try {
			$this->convert();
			$s = stream_get_contents($this->pipes[1]);
			$this->close();
			return $s;
		} catch (\Exception $e) {
			trigger_error($e->getMessage(), E_USER_ERROR);
		}
	}


	private function convert()
	{
		if (self::$executable === NULL) {
			self::$executable = $this->detectExecutable() ?: FALSE;
		}

		if (self::$executable === FALSE) {
			throw new \RuntimeException('Wkhtmltopdf executable not found');
		}

		$cmd = self::$executable . ' -q';

		if ($this->disableSmartShrinking) {
			$cmd .= ' --disable-smart-shrinking';
		}

		if ($this->disableInternalLinks) {
			$cmd .= ' --disable-internal-links';
		}

		$margins = ['T' => 0, 'R' => 1, 'B' => 2, 'L' => 3];

		foreach ($margins as $margin => $index) {
			$marginValue = $this->margin[$index];
			if ($marginValue > -1) {
				$cmd .= ' -' . $margin . ' ' . escapeshellarg($marginValue);
			}
		}

		if (!is_null($this->dpi)) {
			$cmd .= ' --dpi ' . escapeshellarg($this->dpi);
		}

		if (!is_null($this->orientation)) {
			$cmd .= ' --orientation ' . escapeshellarg($this->orientation);
		}

		if (!is_null($this->title)) {
			$cmd .= ' --title ' . escapeshellarg($this->title);
		}

		if (is_array($this->size)) {
			$cmd .= ' --page-width ' . escapeshellarg($this->size[0]);
			$cmd .= ' --page-height ' . escapeshellarg($this->size[1]);
		} else {
			$cmd .= ' --page-size ' . escapeshellarg($this->size);
		}

		if (!is_null($this->zoom)) {
			$cmd .= ' --zoom ' . escapeshellarg($this->zoom);
		}

		if (!is_null($this->header)) {
			$cmd .= ' ' . $this->header->buildShellArgs($this);
		}
		if (!is_null($this->footer)) {
			$cmd .= ' ' . $this->footer->buildShellArgs($this);
		}
		foreach ($this->pages as $page) {
			$cmd .= ' ' . $page->buildShellArgs($this);
		}
		$this->p = $this->openProcess($cmd . ' -', $this->pipes);
	}


	/**
	 * Returns path to executable.
	 * @return string
	 */
	protected function detectExecutable()
	{
		foreach (self::$executables as $exec) {
			if (proc_close($this->openProcess("$exec -v", $tmp)) === 1) {
				return $exec;
			}
		}
	}


	private function openProcess($cmd, & $pipes)
	{
		static $spec = [
			1	 => ['pipe', 'w'],
			2	 => ['pipe', 'w'],
		];
		return proc_open($cmd, $spec, $pipes);
	}


	private function close()
	{
		stream_get_contents($this->pipes[1]); // wait for process
		$error = stream_get_contents($this->pipes[2]);
		if (proc_close($this->p) > 0) {
			throw new \RuntimeException($error);
		}
		foreach ($this->tmpFiles as $file) {
			@unlink($file);
		}
		$this->tmpFiles = [];
	}


}
