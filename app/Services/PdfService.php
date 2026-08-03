<?php

declare(strict_types=1);

namespace BYD\Services;

use Smalot\PdfParser\Parser;
use BYD\Models\RedisClient;
use RuntimeException;

/**
 * PdfService - Extract and cache text from PDF files
 * Used by queue workers to process uploaded car manuals/specs
 */
final class PdfService
{
    private Parser $parser;
    private RedisClient $redis;

    private const CACHE_TTL    = 86400; // 24 hours
    private const CACHE_PREFIX = 'pdf:';

    public function __construct()
    {
        $this->parser = new Parser();
        $this->redis  = RedisClient::getInstance();
    }

    /**
     * Extract text from a PDF file path
     * Returns cached result if available
     */
    public function extractText(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("PDF file not found: {$filePath}");
        }

        $cacheKey = self::CACHE_PREFIX . md5($filePath . filemtime($filePath));

        // Try cache first
        $cached = $this->redis->get($cacheKey);
        if ($cached !== null && is_string($cached)) {
            return $cached;
        }

        try {
            $pdf  = $this->parser->parseFile($filePath);
            $text = $pdf->getText();
            $text = $this->cleanText($text);

            // Cache the result
            $this->redis->set($cacheKey, $text, self::CACHE_TTL);

            return $text;
        } catch (\Exception $e) {
            throw new RuntimeException("Failed to parse PDF: {$e->getMessage()}");
        }
    }

    /**
     * Extract text per page (useful for large manuals)
     * @return array<int, string>
     */
    public function extractByPage(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("PDF file not found: {$filePath}");
        }

        $cacheKey = self::CACHE_PREFIX . 'pages:' . md5($filePath . filemtime($filePath));
        $cached   = $this->redis->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $pdf   = $this->parser->parseFile($filePath);
            $pages = $pdf->getPages();
            $result = [];

            foreach ($pages as $index => $page) {
                $result[$index + 1] = $this->cleanText($page->getText());
            }

            $this->redis->set($cacheKey, $result, self::CACHE_TTL);
            return $result;
        } catch (\Exception $e) {
            throw new RuntimeException("Failed to parse PDF pages: {$e->getMessage()}");
        }
    }

    /**
     * Extract metadata from PDF (author, creation date, etc.)
     */
    public function extractMetadata(string $filePath): array
    {
        $pdf      = $this->parser->parseFile($filePath);
        $details  = $pdf->getDetails();

        return [
            'title'    => $details['Title']        ?? null,
            'author'   => $details['Author']        ?? null,
            'pages'    => count($pdf->getPages()),
            'created'  => $details['CreationDate'] ?? null,
            'producer' => $details['Producer']     ?? null,
        ];
    }

    /**
     * Search for a keyword inside a PDF and return matching pages
     * @return array<int, string>
     */
    public function searchInPdf(string $filePath, string $keyword): array
    {
        $pages   = $this->extractByPage($filePath);
        $matches = [];
        $keyword = mb_strtolower($keyword);

        foreach ($pages as $pageNum => $pageText) {
            if (str_contains(mb_strtolower($pageText), $keyword)) {
                $matches[$pageNum] = $pageText;
            }
        }

        return $matches;
    }

    /**
     * Clean and normalize extracted text
     */
    private function cleanText(string $text): string
    {
        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        // Remove non-printable characters (except Arabic Unicode range)
        $text = preg_replace('/[^\P{C}\t\n\r]/u', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Extract specifications from a structured BYD spec PDF
     * Returns structured key-value pairs
     */
    public function extractCarSpecs(string $filePath): array
    {
        $text  = $this->extractText($filePath);
        $specs = [];

        // Pattern for "Label: Value" or "Label ......... Value"
        $pattern = '/([^:\n]+?)[\s\.]{2,}([^\n]+)/m';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key         = trim($match[1]);
                $value       = trim($match[2]);
                if (strlen($key) > 2 && strlen($value) > 0) {
                    $specs[$key] = $value;
                }
            }
        }

        return $specs;
    }
}
