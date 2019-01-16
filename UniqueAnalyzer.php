<?php
namespace Superhost;

use Superhost\Repository\TextsRepository;

/**
 * Analyzes text uniqueness
 */
class UniqueAnalyzer implements LoggerAwareInterface
{
    use LoggerTrait;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var IQueryProvider
     */
    protected $provider;

    /**
     * @var ProxyManager
     */
    protected $proxyManager;

    /**
     * @var TextsRepository
     */
    protected $repository;

    /**
     * @var TextProcessor
     */
    protected $textProcessor;


    /**
     * @param array $config
     * @param IQueryProvider $provider
     * @param ProxyManager $proxy
     */
    public function __construct(array $config, IQueryProvider $provider, ProxyManager $proxy)
    {
        $this->config = $config;
        $this->provider = $provider;
        $this->proxyManager = $proxy;
        $this->textProcessor = new TextProcessor(array(
            'maxBlockSize' => $this->config['max-block-size'],
            'encoding' => $this->config['text-encoding'],
            'tags' => $this->config['html-tags'],
        ));
    }

    /**
     * Check correct parsing
     *
     * @return integer positive if parsing works correctly
     * @throws HttpClientException
     */
    public function testParsing()
    {
        $query = $this->config['sample-text'];
        $response = $this->provider->query($query);
        $count = $response->getResultsCount();

        return $count;
    }

    /**
     * Schedule html for analysis
     *
     * @param string $html
     * @return intteger queue position
     */
    public function enqueueForAnalysis($html)
    {
        $status = $this->getRepository()->schedule($html);
        if ($status && $this->logger) {
            $this->logger->addNotice('Scheduled analysis for HTML');
        }

        return $status;
    }

    /**
     * Start analysing queued texts
     *
     * @param number $maxEntries maximum number of scheduled entries to analyze or zero for all
     * @return number of entries processed
     */
    public function startAnalysis($maxEntries = 0)
    {
        //fetch entries scheduled for analysis
        $entries = $this->getRepository()->fetchScheduled($maxEntries);

        $count = count($entries);
        if ($this->logger) {
            $this->logger->addNotice("Starting text analyzing of $count entries");
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
            $this->logger->addNotice('Processing entry: "' . $entry->id . '"');
        }

        $blockSize = $this->config['block-size'];
        $blockCount = $this->config['block-count'];
        $stripPunctuation = $this->config['strip-punctuation'];
        $respectSentences = $this->config['respect-sentences'];
        $drawCount = $this->config['draw-count'];
        $minTextSize = $this->config['min-text-size'];
        $maxTextSize = $this->config['max-text-size'];

        //find blocks of texts
        $blocks = $this->textProcessor->getTextBlocks($entry->html, $maxTextSize, $minTextSize);

        $results = array();
        foreach ($blocks as $block) {
            $result = $this->analyzeText($block, $blockSize, $blockCount, $stripPunctuation, $respectSentences, $drawCount);
            $result['block'] = $block;
            $results[] = $result;
        }

        $this->getRepository()->storeResults($entry->id, $results);
    }

    /**
     * Analyzes text uniqueness by splitting into blocks and asking Google (through proxy) about their occurrences
     *
     * @param string $text input text
     * @param integer $blockSize numer of chars in block (rounded-up to full word)
     * @param integer $blockCount number of blocks to return
     * @param boolean $stripPunctuation strip punctuatuion marks from input text
     * @param boolean $respectSentences try to return blocks not spanning multiple sentences
     * @param integer $drawCount number of tries before respectSentences gives up and returns false as a block
     * @return array string indexed array where:
     *      totalBlocks is the number of blocks checked
     *      uniqueBlocks is the number of unique blocks
     */
    public function analyzeText($text, $blockSize, $blockCount, $stripPunctuation, $respectSentences, $drawCount)
    {
        //prepare blocks for querying
        $blocks = $this->textProcessor->getBlocks($text, $blockSize, $blockCount, $stripPunctuation, $respectSentences, $drawCount);

        $uniqueBlocks = 0;
        $queriedBlocks = array();
        foreach ($blocks as $block) {
            if ($block) { //block marked as false when not found for respectSentences = true
                $results = $this->analyzeBlock($block);
                $unique = ($results <= $this->config['query-unique-threshold']); //treat result as unique

                $uniqueBlocks += $unique;
                $queriedBlocks[] = array(
                    'url' => $this->provider->getLastQueryUrl(),
                    'unique' => $unique,
                );
            }
        }

        $result = array(
            'totalBlocks' => count($blocks),
            'uniqueBlocks' => $uniqueBlocks,
        );
        if (!empty($this->config['debug'])) {
            $result['queriedBlocks'] = $queriedBlocks;
        }

        return $result;
    }

    /**
     * Analyzes block occurrences by asking Google (through proxy)
     *
     * @param string $block
     * @throws HttpClientException
     * @return integer number of occurrences
     */
    public function analyzeBlock($block)
    {
        if ($this->logger) {
            $this->logger->addInfo('Querying for block: "' . $block . '"');
        }

        $results = false;

        //try every possible proxy
        $this->proxyManager->proxy(function ($proxy) use ($block, &$results) {
            $options = array();
            if ($proxy) {
                $options = array_merge($options, array(
                    'proxy' => $proxy->ip,
                    'proxyPort' => $proxy->port,
                ));
            }

            try {
                $query = '"' . $block . '"';
                $response = $this->provider->query($query, 0, $options);
                $results = $response->getResultsCount();

                if ($this->logger) {
                    $this->logger->addInfo("Query returned $results result(s)");
                }
            } catch (HttpClientException $e) {
                if ($proxy) {
                    throw new ProxyException($e->getMessage()); //signal to try another proxy
                } else {
                    throw $e;
                }
            }
        });

        return $results;
    }

    /**
     * @return \Superhost\TextProcessor
     */
    public function getTextProcessor()
    {
        return $this->textProcessor;
    }

    /**
     * @return \Superhost\Repository\TextsRepository
     */
    protected function getRepository()
    {
        //lazy initialization
        if (!isset($this->repository)) {
            $this->repository = new TextsRepository($this->config['database']);
        }

        return $this->repository;
    }

}