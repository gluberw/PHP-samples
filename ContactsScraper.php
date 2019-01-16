<?php
namespace Superhost;

use Superhost\Repository\ContactsQueryRepository;
use Superhost\UrlFinder;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

/**
 *
 */
class ContactsScraper implements LoggerAwareInterface
{
    use LoggerTrait;

    /**
     *
     * @var array
     */
    protected $config;

    /**
     * @var IHttpClient
     */
    protected $httpClient;

    /**
     * @var ContactsQueryRepository
     */
    protected $repository;


    /**
     *
     * @param array $config
     */
    public function __construct(array $config, IHttpClient $client)
    {
        $this->config = $config;
        $this->httpClient = $client;
        $this->repository = new ContactsQueryRepository($this->config['database']);
    }

    /**
     * Schedule domain for analysis
     *
     * @param string $text
     * @return intteger queue position
     */
    public function enqueueDomainForAnalysis($domain)
    {
        $status = $this->repository->schedule($domain);
        if ($status && $this->logger) {
            $this->logger->addNotice('Scheduled domain: "' . $domain . '"');
        }

        return $status;
    }

    /**
     * Start analysing queued domains
     *
     * @param number $maxEntries maximum number of scheduled entries to analyze or zero for all
     * @return number of entries processed
     */
    public function startAnalysis($maxEntries = 0)
    {
        //fetch entries scheduled for analysis
        $entries = $this->repository->fetchScheduled($maxEntries);

        $count = count($entries);
        if ($this->logger) {
            $this->logger->addNotice("Starting contacts scraping of $count entries");
        }

        foreach ($entries as $entry) {
            $this->processEntry($entry);
        }

        return $count;
    }

    /**
     * Process a single entry
     *
     * @param \stdClass $entry
     */
    public function processEntry($entry)
    {
        if ($this->logger) {
            $this->logger->addNotice('Processing entry: "' . $entry->domain . '"');
        }

        //processing main page
        $protocol = $this->config['default-protocol'];
        $url = $protocol . '://' . $entry->domain;
        $curlResults = $this->httpClient->fetch($url);
        $response = $curlResults['output'];
        $collectedContacts = $this->findContacts($response);
        if ($this->logger) {
            $this->logger->addInfo('Found ' . count($collectedContacts) . ' contact(s)');
        }

        //processing sub-pages
        $urls = $this->findInternalUrls($url, $response, $entry->domain);
        $urls = array_unique($urls); //eliminate duplicates

        foreach ($urls as $url) {
            try {
                $curlResults = $this->httpClient->fetch($url);
                $response = $curlResults['output'];
                $contacts = $this->findContacts($response);
                if ($this->logger) {
                    $this->logger->addInfo('Found ' . count($contacts) . ' contact(s)');
                }
                $collectedContacts = array_merge($collectedContacts, $contacts);
            } catch (HttpClientException $e) {
                //skip broken sub-pages
            }
        }

        //take unique contacts from all pages
        $collectedContacts = array_unique($collectedContacts);

        //store results to db
        $this->repository->storeContacts($entry->id, $collectedContacts);
    }

    /**
     * Parses response to find internal urls
     *
     * @param string $response HTML to parse
     * @param string $domain constrain urls to this domain
     * @param string $documentProtocol protocol of undelying document
     * @return array of strings
     */
    protected function findInternalUrls($documentUrl, $response, $domain)
    {
        $finder = new UrlFinder();
        $urls = $finder->findInternalUrls($documentUrl, $response, $domain);
        return $urls;
    }

    /**
     * Parses response to find contacts (e.g. emails and phone numbers)
     *
     * @param string $response
     * @return array of strings
     */
    protected function findContacts($response)
    {
        $crawler = new DomCrawler($response);

        $body = $crawler->filterXPath('//body//text()')->extract(array('_text'));
        $hrefs = $crawler->filter('a')->extract(array('href'));

        $contacts = array_merge(
            $this->findEmails($body),
            $this->findEmails($hrefs),
            $this->findPhones($body)
        );

        return array_unique($contacts);
    }

    /**
     * Parses text to find emails
     *
     * @param array $texts
     * @return array of strings
     */
    protected function findEmails($texts)
    {
        $emails = array();

        array_walk($texts, function($text) use (&$emails) {
            $matches = array();
            if (preg_match_all('/([a-z]+:\/\/)?([a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+(?:@|\(at\))[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)*)/', $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if ('' == $match[1]) { //skip entries that look like emails but are urls using http authentication (e.g. ftp://someone@domain.com)
                        $emails[] = $match[2];
                    }
                }
            }
        });

        return $emails;
    }

    /**
     * Parses text to find phone numbers
     *
     * @param array $texts
     * @return array of strings
     */
    protected function findPhones($texts)
    {
        $phones = array();

        array_walk($texts, function($text) use (&$phones) {
            $matches = array();
            if (preg_match_all('/(?<![\d-])(?:\+\d{2,4}\s?)?(\d[\s-]?){9}(?![\d-])/', $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $phones[] = $match[0];
                }
            }
        });

        return $phones;
    }

}