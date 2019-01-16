<?php
namespace Superhost;

use Symfony\Component\DomCrawler\Crawler as DomCrawler;

/**
 *
 */
class UrlFinder
{    
    /**
     * Parses response to find internal urls
     *
     * @param string $response HTML to parse
     * @param string $domain constrain urls to this domain
     * @param string $documentProtocol protocol of undelying document
     * @return array of strings
     */
    public function findInternalUrlsForBot($documentUrl, $response, $domain, $tld)
    {
        $matches = array();
        if (!preg_match('|^(https?)://|', $documentUrl, $matches)) {
            throw new \Exception('Unsupported protocol');
        }
        $documentProtocol = $matches[1];

        $crawler = new DomCrawler($response);
        
        $hrefs = $crawler->filter('a')->extract(array('href'));
        $urls = $this->prepareUrlsForBot($hrefs, $domain, $documentUrl, $documentProtocol, $tld);
        
        $canonicalLinks = array();
        $canonicalLinks = $crawler->filter('link[rel="canonical"]')->extract(array('href'));
        $canonicalLinks = $this->prepareUrlsForBot($canonicalLinks, $domain, $documentUrl, $documentProtocol, $tld);
        
        $returns = array();
        foreach ($canonicalLinks as $key => $item) {
            $returns[] = array_merge($item, array('canonical' => true));
        }
        
        return array_merge($urls,$returns);
    }
    
    public function prepareLinkForBot ($documentUrl, $domain, $tld)
    {
        $matches = array();
        if (!preg_match('|^(https?)://|', $documentUrl, $matches)) {
            throw new \Exception('Unsupported protocol');
        }
        $documentProtocol = $matches[1];
        
        $url = $this->prepareUrlsForBot(array($documentUrl), $domain, $documentUrl, $documentProtocol, $tld);
        return isset($url[0]) ? $url[0] : false ;
    }
    
    public function prepareUrlsForBot($hrefs, $domain, $documentUrl, $documentProtocol, $tld)
    {
        $urls = $this->resolveUrls($hrefs, $documentUrl, $documentProtocol);       
        $urls = $this->getRelativeUrlsWithDomains( $urls, $tld);
        return $urls;
    }
    
    public function findInternalUrls($documentUrl, $response, $domain)
    {
        $matches = array();
        if (!preg_match('|^(https?)://|', $documentUrl, $matches)) {
            throw new \Exception('Unsupported protocol');
        }
        $documentProtocol = $matches[1];

        $crawler = new DomCrawler($response);
        
        $hrefs = $crawler->filter('a')->extract(array('href'));
        
        
        $urls = $this->resolveUrls($hrefs, $documentUrl, $documentProtocol);
        $urls = $this->detectInternalLinks($urls, $domain);
        
        return $urls;
    }
    
    protected function detectInternalLinksWithSubdomain($urls, $domain)
    {
        $tld = explode('www.', $domain);
        if (is_array($tld)) $domain = end($tld);
        
        $urls = array_filter($urls, function($url) use ($domain) {            
            return preg_match('~^https?://([-\w\.]*\.)?' . $domain . '(/|$)~', $url);
        });
        return $urls;
    }
    
    protected function detectInternalLinks($urls, $domain)
    {
        $urls = array_filter($urls, function($url) use ($domain) {
            return preg_match('~^https?://(www\.)?' . $domain . '(/|$)~', $url);
        });
        return $urls;
    }
    
    protected function resolveUrls( $hrefs, $documentUrl, $documentProtocol )
    {
        //resolve urls
        $urls = array_map(function($url) use ($documentUrl, $documentProtocol) {
            //handle protocol-less urls (http://tools.ietf.org/html/rfc3986#section-4.2)
            $url = preg_replace('|^//|', $documentProtocol . '://', $url);

            //handle absolute urls
            $url = preg_replace('|^/|', $documentUrl . '/', $url);

            //ignore urls with protocol
            if (preg_match('|^[a-z]+://|', $url)) {
                return $url;
            }

            //ignore javascript
            if (preg_match('/^javascript:/', $url)) {
                return $url;
            }

            //relative url
            return $documentUrl . '/' .  $url;
        }, $hrefs);
        return $urls;
    }
    protected function getRelativeUrlsWithDomains( $hrefs, $tld )
    {
                
        $urls = array_map(function($url) use($tld) {
            
            $domain = preg_replace('~^https?://~', '', $url);
            $domain = explode('/',$domain);
            $domain = is_array($domain) ?  $domain[0] : $domain;
            
            $domainParts = explode('.', $domain);
            if( end($domainParts) == $tld) {  
                $url = preg_replace('~^https?://(www\.)?' . $domain . '(/|$)~', '', $url);
    
                return array(
                             'url' => $url,
                             'domain' => $domain,
                             );
            } 
        }, $hrefs);
        
        return array_filter($urls);
    }
    protected function getRelativeUrls( $hrefs, $domain )
    {
        //resolve urls
        $urls = array_map(function($url) use ($domain) {
            $url = preg_replace('~^https?://(www\.)?' . $domain . '(/|$)~', '', $url);
            return $url;        
        }, $hrefs);
        return $urls;
    }
}