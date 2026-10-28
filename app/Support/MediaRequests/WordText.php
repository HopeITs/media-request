<?php

namespace App\Support\MediaRequests;

use ZipArchive;

/**
 * Plain text in and out of Word files, for the proofreader.
 *
 * Reading keeps one line per paragraph (tables and text boxes included, in
 * document order). Writing makes a simple .docx: one paragraph per line, in the
 * right direction for Arabic or English. The original layout and styles are not
 * copied; the checked text is meant to be pasted back or used as is.
 */
class WordText
{
    /** Text of a .docx, one paragraph per line; null when it is not a readable Word file. */
    public function read(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            return null;
        }

        // No network access and no entity expansion: a Word file is untrusted input.
        $doc = new \DOMDocument;
        if (! @$doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            return null;
        }

        $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $lines = [];
        foreach ($doc->getElementsByTagNameNS($w, 'p') as $p) {
            $line = '';
            foreach ($p->getElementsByTagNameNS($w, '*') as $node) {
                $line .= match ($node->localName) {
                    't' => $node->textContent,
                    'tab' => "\t",
                    'br', 'cr' => ' ',
                    default => '',
                };
            }
            $lines[] = rtrim($line);
        }

        // Collapse runs of empty paragraphs to one blank line.
        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /** A simple .docx with $text, one paragraph per line, returned as bytes. */
    public function write(string $text, bool $rtl): string
    {
        $paragraphs = '';
        foreach (preg_split("/\r?\n/", $text) as $line) {
            $dir = $rtl ? '<w:pPr><w:bidi/></w:pPr>' : '';
            $run = $rtl ? '<w:rPr><w:rtl/></w:rPr>' : '';
            $paragraphs .= '<w:p>'.$dir.'<w:r>'.$run.'<w:t xml:space="preserve">'.htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t></w:r></w:p>';
        }

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                .'</Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
                .$paragraphs.'<w:sectPr/></w:body></w:document>',
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'proof');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }
}
