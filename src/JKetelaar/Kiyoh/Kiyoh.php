<?php

declare(strict_types=1);

namespace Keuze\Kiyoh;

use Exception;
use GuzzleHttp\Client;
use Keuze\Kiyoh\Factory\ReviewFactory;
use Keuze\Kiyoh\Model\Company;
use Keuze\Kiyoh\Model\Review;
use Keuze\Kiyoh\Model\ReviewContent;

class Kiyoh
{
    public const COMPANY_REVIEWS_URL = 'https://www.kiyoh.com/v1/review/feed.xml?hash=%s&limit=%s';

    private Client $client;
    private Company|null $fallback;
    private string $cacheFile;
    private int $cacheExpiry;

    /**
     * Kiyoh constructor.
     *
     * @param int    $reviewCount  A number of reviews to retrieve
     * @param int    $cacheExpiry  Cache expiry time in seconds (default: 1 hour)
     */
    public function __construct(private string $connectorCode, private int $reviewCount = 10, private int $requestTimeout = 2, int $cacheExpiry = 3600)
    {
        $this->client = new Client();
        $this->cacheExpiry = $cacheExpiry;
        $this->cacheFile = sys_get_temp_dir() . '/kiyoh_cache_' . md5($this->connectorCode) . '.json';
    }

    /**
     * Retrieve the company information from the Kiyoh API.
     *
     * @return Company The company information.
     * @throws Exception If an error occurs during the operation.
     */
    public function getCompany(): ?Company
    {
        try {
            return $this->parseData($this->getContent());
        } catch (Exception $e) {
            // Return fallback data if available
            if ($this->getFallbackCompany() !== null) {
                return $this->getFallbackCompany();
            }
            throw $e;
        }
    }

    protected function parseData(?string $content = null): Company
    {
        if ($content === null) {
            $content = $this->getContent();
        }

        $content = simplexml_load_string($content);

        return ReviewFactory::createCompany($content);
    }

    public function getContent(): string
    {
        // Try to get from cache first
        $cachedContent = $this->getCachedContent();
        if ($cachedContent !== null) {
            return $cachedContent;
        }
        
        try {
            $content = $this->getClient()->request('GET', $this->getCompanyURL(), ['timeout' => $this->requestTimeout])->getBody()->getContents();
            $this->setCachedContent($content);
            return $content;
        } catch (Exception $e) {
            // Try to get expired cache as fallback
            $expiredCache = $this->getCachedContent(true);
            if ($expiredCache !== null) {
                return $expiredCache;
            }
            
            if ($this->getFallbackCompany() !== null) {
                throw new Exception('API request failed, fallback data available: ' . $e->getMessage());
            }
            throw new Exception('API request failed: ' . $e->getMessage());
        }
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Returns parsed Recent Company Reviews URL.
     */
    public function getCompanyURL(): string
    {
        return sprintf(self::COMPANY_REVIEWS_URL, $this->connectorCode, $this->reviewCount);
    }

    /*
     * Set the fallback company information.
     * 
     * @param array $data
     */
    public function setFallbackCompany(array $data): void
    {
        $company = new Company(
            (float) ($data['averageRating'] ?? 0.0),
            (int) ($data['numberReviews'] ?? 0),
            (float) ($data['last12MonthAverageRating'] ?? 0.0),
            (int) ($data['last12MonthNumberReviews'] ?? 0),
            (int) ($data['percentageRecommendation'] ?? 0),
            (int) ($data['locationId'] ?? 0),
            locationName: $data['locationName'] ?? ''
        );

        $reviews = [];

        if (!array_key_exists('reviews', $data)) {
            $data['reviews'] = [];
        }
        foreach ($data['reviews'] as $fallBackReview) {
            $review = new Review(
                (string) ($fallBackReview['id'] ?? ''),
                (string) ($fallBackReview['reviewAuthor'] ?? ''),
                (string) ($fallBackReview['city'] ?? ''),
                (float) ($fallBackReview['rating'] ?? 0.0),
                (string) ($fallBackReview['comment'] ?? ''),
                (string) ($fallBackReview['dateSince'] ?? ''),
                (string) ($fallBackReview['updatedSince'] ?? ''),
                (string) ($fallBackReview['referenceCode'] ?? '')
            );

            $review->setContent(
                [
                    new ReviewContent(
                        (string) ($fallBackReview['questionGroup'] ?? 'DEFAULT_OPINION'),
                        (string) ($fallBackReview['questionType'] ?? 'TEXT'),
                        (string) ($fallBackReview['content'] ?? ''),
                        (int) ($fallBackReview['order'] ?? 0),
                        (string) ($fallBackReview['questionTranslation'] ?? '')
                    )
                ],
            );

            $reviews[] = $review;
        }

        $company->setReviews($reviews);

        $this->fallback = $company;
    }

    public function getFallbackCompany(): Company|null
    {
        return $this->fallback ?? null;
    }

    private function getCachedContent(bool $ignoreExpiry = false): ?string
    {
        if (!file_exists($this->cacheFile)) {
            return null;
        }

        $cacheData = json_decode(file_get_contents($this->cacheFile), true);
        if (!$cacheData || !isset($cacheData['content'], $cacheData['timestamp'])) {
            return null;
        }

        if (!$ignoreExpiry && (time() - $cacheData['timestamp']) > $this->cacheExpiry) {
            return null;
        }

        return $cacheData['content'];
    }

    private function setCachedContent(string $content): void
    {
        $cacheData = [
            'content' => $content,
            'timestamp' => time()
        ];
        file_put_contents($this->cacheFile, json_encode($cacheData));
    }
}
