<?php
// src/Service/Pdf/DocRaptorService.php
namespace App\Service\Loan;

use DocRaptor\Doc;
use DocRaptor\DocApi;
use DocRaptor\PrinceOptions;

class DocRaptorService
{
    private DocApi $docApi;
    private bool $testMode;

    public function __construct(string $apiKey, bool $testMode = false)
    {
        $this->docApi = new DocApi();
        $this->docApi->getConfig()->setUsername($apiKey);
        $this->testMode = $testMode;
    }

    /**
     * Generate PDF from HTML content
     */
    public function generatePdf(string $htmlContent, string $filename = 'document.pdf'): string
    {
        $doc = new Doc();
        $doc->setTest($this->testMode);
        $doc->setDocumentType("pdf");
        $doc->setDocumentContent($htmlContent);
        $doc->setName($filename);

        // Prince PDF options for better styling
        $princeOptions = new PrinceOptions();
        $princeOptions->setMedia("print"); // Use print CSS media
        $princeOptions->setBaseurl("https://yourdomain.com"); // For relative URLs
        $doc->setPrinceOptions($princeOptions);

        try {
            return $this->docApi->createDoc($doc);
        } catch (\DocRaptor\ApiException $e) {
            throw new \RuntimeException('PDF generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate PDF from URL
     */
    public function generatePdfFromUrl(string $url, string $filename = 'document.pdf'): string
    {
        $doc = new Doc();
        $doc->setTest($this->testMode);
        $doc->setDocumentType("pdf");
        $doc->setDocumentUrl($url);
        $doc->setName($filename);

        try {
            return $this->docApi->createDoc($doc);
        } catch (\DocRaptor\ApiException $e) {
            throw new \RuntimeException('PDF generation failed: ' . $e->getMessage());
        }
    }
}