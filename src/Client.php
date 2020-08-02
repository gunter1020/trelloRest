<?php

namespace trelloRest;

use nueip\curl\Crawler;
use nueip\curl\CrawlerConfig;

class Client
{
    /**
     * Trello API Site
     */
    protected $apiSite = 'https://api.trello.com/1/';

    /**
     * Board resources id
     *
     * @var string
     */
    protected $idBoard = '';

    /**
     * Cards resources id
     *
     * @var string|array
     */
    protected $idCards = [];

    /**
     * Crawler config
     *
     * @var CrawlerConfig
     */
    private $_crawlerConfig = null;

    /**
     * Construct
     */
    public function __construct($apiKey, $apiToken)
    {
        $this->_crawlerConfig = new CrawlerConfig([
            'site' => $this->apiSite,
            'data' => [
                'key' => $apiKey,
                'token' => $apiToken,
            ],
        ]);
    }

    /**
     * Set board resources id
     *
     * @param string $idBoard
     * @return Client
     */
    public function setBoard($idBoard)
    {
        $this->idBoard = $idBoard;
        return $this;
    }

    /**
     * Set card resources id
     *
     * @param string|array $idCards
     * @return Client
     */
    public function setCards($idCards)
    {
        $this->idCards = $idCards;
        return $this;
    }

    /**
     * Reset resources id
     *
     * @return void
     */
    public function resetRes()
    {
        $this->idBoard = '';
        $this->idCards = [];
    }

    /**
     * Send curl
     *
     * @param CrawlerConfig $config
     * @return string
     */
    protected function restAPI($config)
    {
        $this->resetRes();
        return Crawler::run($config);
    }

    /**
     * GET /1/boards/{id}/customFields
     *
     * @return array
     */
    public function getBoardCustomFields()
    {
        $config = clone $this->_crawlerConfig;
        $config
            ->setType('GET')
            ->setUri("/boards/{$this->idBoard}/customFields");

        return json_decode($this->restAPI($config), true);
    }

    /**
     * GET /1/boards/{id}/cards
     *
     * @return array
     */
    public function getBoardCards()
    {
        $config = clone $this->_crawlerConfig;
        $config
            ->setType('GET')
            ->setUri("/boards/{$this->idBoard}/cards");

        return json_decode($this->restAPI($config), true);
    }

    /**
     * GET /1/boards/{id}/lists
     *
     * @return array
     */
    public function getBoardLists()
    {
        $config = clone $this->_crawlerConfig;
        $config
            ->setType('GET')
            ->setUri("/boards/{$this->idBoard}/lists");

        return json_decode($this->restAPI($config), true);
    }

    /**
     * GET /1/boards/{id}/members
     *
     * @return array
     */
    public function getBoardMembers()
    {
        $config = clone $this->_crawlerConfig;
        $config
            ->setType('GET')
            ->setUri("/boards/{$this->idBoard}/members");

        return json_decode($this->restAPI($config), true);
    }

    /**
     * GET /1/cards/{id}/customFieldItems
     *
     * @return array
     */
    public function getCardsCustomFields()
    {
        return $this->_batch('/cards/%s/customFieldItems', $this->idCards);
    }

    /**
     * GET /1/members/{id}/cards
     *
     * @return array
     */
    public function getMemberCards($idMember)
    {
        $config = clone $this->_crawlerConfig;
        $config
            ->setType('GET')
            ->setUri("/members/{$idMember}/cards");

        return json_decode($this->restAPI($config), true);
    }

    /**
     * GET /batch
     *
     * @param string $uri
     * @param array|string $idList
     * @return array
     */
    private function _batch($uri, $idList)
    {
        $result = [];

        $config = clone $this->_crawlerConfig;
        $config->setType('GET');

        if (is_array($idList)) {
            $config->setUri('/batch');

            // Builder uri string
            $idList = array_map(function ($id) use ($uri) {
                return sprintf($uri, $id);
            }, $idList);

            // Batch get result
            foreach (array_chunk($idList, 10) as $urls) {
                // Set batch requests string
                $config->appendData([
                    'urls' => implode(',', $urls),
                ]);
                $response = json_decode($this->restAPI($config), true);

                // collect requests
                foreach ($response as $res) {
                    // filter success requests
                    if (isset($res[200]) && count($res[200])) {
                        $result = array_merge($result, $res[200]);
                    }
                }
            }
        } else {
            $config->setUri(sprintf($uri, $idList));
            $result = json_decode($this->restAPI($config), true);
        }

        return $result;
    }
}
