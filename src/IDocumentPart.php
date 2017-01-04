<?php

namespace Billdu\Wkhtmltopdf;


/**
 * @author Martin Bažík <martin@bazo.sk>
 */
interface IDocumentPart
{

	/**
	 * @param  Document
	 * @return string
	 */
	function buildShellArgs(Document $document);
}
